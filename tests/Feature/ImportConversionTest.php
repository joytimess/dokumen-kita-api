<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentConversionService;
use App\Services\DocumentPipelineService;
use App\Services\OcrSpaceOcrClient;
use App\Services\PdfDocumentRenderer;
use App\Services\SourceDocumentConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

class ImportConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_readable_upload_without_a_resolvable_realpath_is_stored_and_converted(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $documentGuid = $this->actingAs($owner)->postJson('/api/v1/documents', ['title' => 'Upload temporary path'])
            ->assertCreated()->json('data.guid');
        $oldPath = null;

        foreach (['pdf' => $this->pdf(), 'docx' => $this->docx()] as $extension => $bytes) {
            $fixture = UploadedFileFactory::content('source.'.$extension, $bytes);
            // Windows FastCGI uploads can be readable while realpath() cannot resolve the temp directory.
            $file = new class($fixture->getPathname(), 'source.'.$extension, null, UPLOAD_ERR_OK, true) extends UploadedFile
            {
                public function getRealPath(): string|false
                {
                    return false;
                }
            };
            $this->assertTrue($file->isValid());
            $this->assertTrue($file->isReadable());
            $queued = $this->post('/api/v1/documents/'.$documentGuid.'/imports', [
                'file' => $file, 'replace_editor_data' => '1', 'editor_revision' => $extension === 'pdf' ? 1 : 2,
            ], ['Accept' => 'application/json'])->assertAccepted()->assertJsonPath('data.conversion_status', 'queued');
            $version = DocumentVersion::where('guid', $queued->json('data.guid'))->firstOrFail();
            $disk = Storage::disk('local');
            $this->assertSame($bytes, $disk->get($version->source_path));
            $this->assertStringStartsWith('documents/'.$documentGuid.'/sources/', $version->source_path);
            if ($oldPath !== null) {
                $disk->assertMissing($oldPath);
            }
            $oldPath = $version->source_path;

            $this->app->make(DocumentConversionService::class)->convert($version->guid, $version->conversion_generation);
            $converted = $this->getJson('/api/v1/versions/'.$version->guid)
                ->assertOk()->assertJsonPath('data.conversion_status', 'ready')
                ->assertJsonPath('data.conversion_error_code', null);
            $this->assertStringContainsString($extension === 'pdf' ? 'First page editable' : 'Judul kontrak',
                $converted->json('data.editor_data.blocks.0.data.text'));
            $this->assertSame($extension === 'pdf' ? 2 : 3, $converted->json('data.editor_revision'));
            $this->assertCount(1, $disk->allFiles());
        }
    }

    public function test_pdf_import_becomes_editable_and_save_uses_latest_revision(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local', 'documents.ocr.max_pages' => 1, 'documents.ocr.max_bytes' => 1]);
        Http::fake();
        $owner = User::factory()->create();
        $documentGuid = $this->actingAs($owner)->postJson('/api/v1/documents', ['title' => 'PDF editable'])
            ->assertCreated()->json('data.guid');
        $queued = $this->postJson("/api/v1/documents/{$documentGuid}/imports", [
            'file' => UploadedFileFactory::content('sample.pdf', $this->pdf()),
        ])->assertAccepted()->assertJsonPath('data.conversion_status', 'queued');
        $versionGuid = $queued->json('data.guid');
        $this->app->make(DocumentConversionService::class)->convert($versionGuid, $queued->json('data.conversion_generation'));

        $converted = $this->getJson("/api/v1/versions/{$versionGuid}")
            ->assertOk()->assertJsonPath('data.conversion_status', 'ready')
            ->assertJsonPath('data.conversion_page_count', 2);
        $this->assertStringContainsString('First page editable', $converted->json('data.editor_data.blocks.0.data.text'));
        $this->assertSame(2, $converted->json('data.editor_revision'));
        $this->patchJson("/api/v1/versions/{$versionGuid}", [
            'editor_revision' => 1,
            'editor_data' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Terlambat']]]],
        ])->assertConflict()->assertJsonPath('error.code', 'EDITOR_REVISION_CONFLICT');
        $this->patchJson("/api/v1/versions/{$versionGuid}", [
            'editor_revision' => 2,
            'editor_data' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Sudah diubah']]]],
        ])->assertOk()->assertJsonPath('data.editor_revision', 3);
        $this->getJson("/api/v1/versions/{$versionGuid}")
            ->assertJsonPath('data.editor_data.blocks.0.data.text', 'Sudah diubah');
        $this->postJson("/api/v1/versions/{$versionGuid}/preview", ['source' => 'editor'])
            ->assertAccepted();
        $version = DocumentVersion::where('guid', $versionGuid)->firstOrFail();
        $this->app->make(DocumentPipelineService::class)->generatePreview(
            $versionGuid, $version->preview_generation, $this->app->make(PdfDocumentRenderer::class),
        );
        $renderedText = (new Parser)->parseContent(Storage::disk('local')->get($version->fresh()->preview_pdf_path))->getText();
        $this->assertStringContainsString('Sudah diubah', $renderedText);
        $this->assertStringNotContainsString('First page editable', $renderedText);
        $this->get("/api/v1/versions/{$versionGuid}/source")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs(User::factory()->create())->get("/api/v1/versions/{$versionGuid}/source")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_scanned_pdf_import_uses_ocr_text_without_importing_pdf_images(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local', 'documents.ocr.enabled' => true, 'documents.ocr.api_key' => 'test-key']);
        $pdf = $this->scannedPdf();
        Http::fake(['https://api.ocr.space/parse/image' => Http::response($this->ocrSpaceResponse([
            'OCR page one', '', 'OCR page three',
        ]), 200)]);

        $owner = User::factory()->create();
        $created = $this->actingAs($owner)->postJson('/api/v1/documents', [
            'title' => 'PDF scan',
        ])->assertCreated();
        $versionGuid = $created->json('data.current_version.guid');
        $queued = $this->postJson('/api/v1/documents/'.$created->json('data.guid').'/imports', [
            'file' => UploadedFileFactory::content('scan.pdf', $this->scannedPdf()),
        ])->assertAccepted();

        $this->app->make(DocumentConversionService::class)->convert($versionGuid, $queued->json('data.conversion_generation'));

        $result = $this->getJson('/api/v1/versions/'.$versionGuid)->assertJsonPath('data.conversion_status', 'ready');
        $blocks = $result->json('data.editor_data.blocks');
        $this->assertSame(3, $result->json('data.conversion_page_count'));
        $texts = array_column(array_values(array_filter($blocks, fn (array $block): bool => $block['type'] === 'paragraph')), 'data');
        $this->assertSame(['OCR page one', 'OCR page three'], array_column($texts, 'text'));
        $this->assertNotContains('image', array_column($blocks, 'type'));
        $this->assertContains('PDF_OCR_APPLIED', $result->json('data.conversion_warnings'));
        $this->assertContains('PDF_OCR_PARTIAL', $result->json('data.conversion_warnings'));
        Http::assertSentCount(1);
        $request = Http::recorded()->first()[0];
        $this->assertSame('https://api.ocr.space/parse/image', $request->url());
        $this->assertTrue($request->hasHeader('apikey', 'test-key'));
        $this->assertTrue($request->isMultipart());
        $this->assertTrue($request->hasFile('file', $pdf, 'source.pdf'));
        $fields = collect($request->data())->keyBy('name');
        $this->assertSame('2', $fields['OCREngine']['contents']);
        $this->assertSame('auto', $fields['language']['contents']);
        $this->assertSame('false', $fields['isOverlayRequired']['contents']);
    }

    public function test_mixed_pdf_falls_back_to_native_text_when_total_pdf_exceeds_ocr_page_limit(): void
    {
        Storage::fake('local');
        config([
            'documents.disk' => 'local',
            'documents.processing.pdftotext_binary' => 'missing-pdftotext-binary',
            'documents.ocr.max_pages' => 1,
        ]);
        Http::fake();
        $owner = User::factory()->create();
        $documentGuid = $this->actingAs($owner)->postJson('/api/v1/documents', ['title' => 'Mixed PDF'])
            ->assertCreated()->json('data.guid');
        $queued = $this->postJson("/api/v1/documents/{$documentGuid}/imports", [
            'file' => UploadedFileFactory::content('mixed.pdf', $this->pdfWithBlankLastPage()),
        ])->assertAccepted();

        $this->app->make(DocumentConversionService::class)->convert(
            $queued->json('data.guid'), $queued->json('data.conversion_generation'),
        );

        $result = $this->getJson('/api/v1/versions/'.$queued->json('data.guid'))
            ->assertJsonPath('data.conversion_status', 'ready')
            ->assertJsonPath('data.conversion_error_code', null);
        $this->assertStringContainsString('First page editable', $result->json('data.editor_data.blocks.0.data.text'));
        $this->assertContains('PDF_OCR_SKIPPED_LIMIT', $result->json('data.conversion_warnings'));
        $this->assertNotContains('PDF_OCR_APPLIED', $result->json('data.conversion_warnings'));
        Http::assertNothingSent();
    }

    public function test_mixed_pdf_falls_back_to_native_text_when_provider_quota_is_exhausted(): void
    {
        Storage::fake('local');
        config([
            'documents.disk' => 'local',
            'documents.processing.pdftotext_binary' => 'missing-pdftotext-binary',
            'documents.ocr.enabled' => true,
            'documents.ocr.api_key' => 'test-key',
        ]);
        Http::fake(['https://api.ocr.space/parse/image' => Http::response(['ErrorMessage' => 'Daily rate limit reached.'], 429)]);
        $owner = User::factory()->create();
        $documentGuid = $this->actingAs($owner)->postJson('/api/v1/documents', ['title' => 'Quota fallback'])
            ->assertCreated()->json('data.guid');
        $queued = $this->postJson("/api/v1/documents/{$documentGuid}/imports", [
            'file' => UploadedFileFactory::content('mixed.pdf', $this->pdfWithBlankLastPage()),
        ])->assertAccepted();

        $this->app->make(DocumentConversionService::class)->convert(
            $queued->json('data.guid'), $queued->json('data.conversion_generation'),
        );

        $result = $this->getJson('/api/v1/versions/'.$queued->json('data.guid'))
            ->assertJsonPath('data.conversion_status', 'ready')
            ->assertJsonPath('data.conversion_error_code', null);
        $this->assertStringContainsString('First page editable', $result->json('data.editor_data.blocks.0.data.text'));
        $this->assertContains('PDF_OCR_SKIPPED_QUOTA', $result->json('data.conversion_warnings'));
        $this->assertNotContains('PDF_OCR_APPLIED', $result->json('data.conversion_warnings'));
        Http::assertSentCount(1);
    }

    public function test_scan_only_pdf_over_limit_fails_without_calling_provider(): void
    {
        Storage::fake('local');
        config([
            'documents.disk' => 'local',
            'documents.processing.pdftotext_binary' => 'missing-pdftotext-binary',
            'documents.ocr.max_pages' => 2,
        ]);
        Storage::disk('local')->put('scan.pdf', $this->scannedPdf());
        Http::fake();

        try {
            app(SourceDocumentConverter::class)->convert(SourceType::Pdf, 'scan.pdf');
            $this->fail('A scan-only PDF above the OCR page limit must fail.');
        } catch (ApiException $exception) {
            $this->assertSame('OCR_LIMIT_EXCEEDED', $exception->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_scan_only_pdf_fails_with_quota_code_instead_of_becoming_a_successful_empty_import(): void
    {
        Storage::fake('local');
        config([
            'documents.disk' => 'local',
            'documents.processing.pdftotext_binary' => 'missing-pdftotext-binary',
            'documents.ocr.enabled' => true,
            'documents.ocr.api_key' => 'test-key',
        ]);
        Storage::disk('local')->put('scan.pdf', $this->scannedPdf());
        Http::fake(['https://api.ocr.space/parse/image' => Http::response(['ErrorMessage' => 'Daily request quota reached.'], 429)]);

        try {
            app(SourceDocumentConverter::class)->convert(SourceType::Pdf, 'scan.pdf');
            $this->fail('A scan-only PDF without an available OCR quota must fail.');
        } catch (ApiException $exception) {
            $this->assertSame('OCR_QUOTA_EXHAUSTED', $exception->errorCode);
            $this->assertStringNotContainsString('Daily request quota', $exception->getMessage());
        }
    }

    public function test_ocr_space_auth_error_is_structured_and_does_not_expose_provider_details(): void
    {
        config(['documents.ocr.enabled' => true, 'documents.ocr.api_key' => 'test-key']);
        Http::fake(['https://api.ocr.space/parse/image' => Http::response(['ErrorMessage' => 'Invalid API key: private-provider-detail'], 403)]);

        try {
            app(OcrSpaceOcrClient::class)->extractText('%PDF-test', 1);
            $this->fail('An invalid provider key must fail with a structured error.');
        } catch (ApiException $exception) {
            $this->assertSame('OCR_PROVIDER_NOT_CONFIGURED', $exception->errorCode);
            $this->assertStringNotContainsString('private-provider-detail', $exception->getMessage());
        }
    }

    public function test_ocr_space_keeps_page_order_when_a_page_fails(): void
    {
        config(['documents.ocr.enabled' => true, 'documents.ocr.api_key' => 'test-key']);
        Http::fake(['https://api.ocr.space/parse/image' => Http::response([
            'OCRExitCode' => 2,
            'IsErroredOnProcessing' => true,
            'ParsedResults' => [
                ['FileParseExitCode' => 1, 'ParsedText' => 'Halaman satu'],
                ['FileParseExitCode' => -10],
                ['FileParseExitCode' => 1, 'ParsedText' => ''],
            ],
        ], 200)]);

        $this->assertSame(['Halaman satu', null, ''], app(OcrSpaceOcrClient::class)->extractText('%PDF-test', 3));
    }

    public function test_ocr_space_rejects_null_text_on_a_successful_page(): void
    {
        config(['documents.ocr.enabled' => true, 'documents.ocr.api_key' => 'test-key']);
        Http::fake(['https://api.ocr.space/parse/image' => Http::response([
            'OCRExitCode' => 1,
            'IsErroredOnProcessing' => false,
            'ParsedResults' => [['FileParseExitCode' => 1, 'ParsedText' => null]],
        ], 200)]);

        try {
            app(OcrSpaceOcrClient::class)->extractText('%PDF-test', 1);
            $this->fail('A successful page must contain parsed text, even when it is empty.');
        } catch (ApiException $exception) {
            $this->assertSame('OCR_SERVICE_UNAVAILABLE', $exception->errorCode);
        }
    }

    public function test_import_requires_replace_confirmation_and_old_job_cannot_overwrite_edit(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $created = $this->actingAs($owner)->postJson('/api/v1/documents', [
            'title' => 'Kontrak',
            'editor_data' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Sebelumnya']]]],
        ])->assertCreated();
        $documentGuid = $created->json('data.guid');
        $versionGuid = $created->json('data.current_version.guid');
        $this->postJson("/api/v1/documents/{$documentGuid}/imports", [
            'file' => UploadedFileFactory::content('sample.pdf', $this->pdf()),
        ])->assertConflict()->assertJsonPath('error.code', 'REPLACE_EDITOR_DATA_REQUIRED');
        $queued = $this->postJson("/api/v1/documents/{$documentGuid}/imports", [
            'file' => UploadedFileFactory::content('sample.pdf', $this->pdf()), 'replace_editor_data' => true,
            'editor_revision' => 1,
        ])->assertAccepted();

        $this->patchJson("/api/v1/versions/{$versionGuid}", [
            'editor_revision' => 1,
            'editor_data' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Edit pemilik']]]],
        ])->assertOk()->assertJsonPath('data.conversion_error_code', 'DRAFT_CHANGED');
        $this->app->make(DocumentConversionService::class)->convert($versionGuid, $queued->json('data.conversion_generation'));
        $this->getJson("/api/v1/versions/{$versionGuid}")
            ->assertJsonPath('data.editor_data.blocks.0.data.text', 'Edit pemilik');
    }

    public function test_docx_import_uses_same_editable_contract(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $documentGuid = $this->actingAs($owner)->postJson('/api/v1/documents', ['title' => 'DOCX'])
            ->assertCreated()->json('data.guid');
        $queued = $this->postJson("/api/v1/documents/{$documentGuid}/imports", [
            'file' => UploadedFileFactory::content('sample.docx', $this->docx()),
        ])->assertAccepted();
        $this->app->make(DocumentConversionService::class)->convert(
            $queued->json('data.guid'), $queued->json('data.conversion_generation'),
        );
        $this->getJson('/api/v1/versions/'.$queued->json('data.guid'))
            ->assertJsonPath('data.conversion_status', 'ready')
            ->assertJsonPath('data.editor_data.blocks.0.type', 'header')
            ->assertJsonPath('data.editor_data.blocks.0.data.text', 'Judul kontrak')
            ->assertJsonPath('data.editor_data.blocks.1.type', 'table')
            ->assertJsonPath('data.editor_data.blocks.1.data.showBorders', true)
            ->assertJsonPath('data.editor_data.blocks.2.type', 'image');
    }

    public function test_php_pdf_fallback_converts_when_binary_is_unavailable(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local', 'documents.processing.pdftotext_binary' => 'missing-pdftotext-binary']);
        $owner = User::factory()->create();
        $documentGuid = $this->actingAs($owner)->postJson('/api/v1/documents', ['title' => 'Fallback'])
            ->assertCreated()->json('data.guid');
        $queued = $this->postJson("/api/v1/documents/{$documentGuid}/imports", [
            'file' => UploadedFileFactory::content('sample.pdf', $this->pdf()),
        ])->assertAccepted();
        $this->app->make(DocumentConversionService::class)->convert(
            $queued->json('data.guid'), $queued->json('data.conversion_generation'),
        );
        $result = $this->getJson('/api/v1/versions/'.$queued->json('data.guid'))
            ->assertJsonPath('data.conversion_status', 'ready');
        $this->assertContains('PDF_PHP_FALLBACK', $result->json('data.conversion_warnings'));
    }

    public function test_php_fallback_keeps_a_blank_trailing_page_and_partial_ocr_warning(): void
    {
        Storage::fake('local');
        config([
            'documents.disk' => 'local',
            'documents.processing.pdftotext_binary' => 'missing-pdftotext-binary',
            'documents.ocr.enabled' => true,
            'documents.ocr.api_key' => 'test-key',
        ]);
        Http::fake(['https://api.ocr.space/parse/image' => Http::response($this->ocrSpaceResponse([
            'First page editable', '',
        ]), 200)]);
        $owner = User::factory()->create();
        $documentGuid = $this->actingAs($owner)->postJson('/api/v1/documents', ['title' => 'Blank page'])
            ->assertCreated()->json('data.guid');
        $queued = $this->postJson("/api/v1/documents/{$documentGuid}/imports", [
            'file' => UploadedFileFactory::content('blank-tail.pdf', $this->pdfWithBlankLastPage()),
        ])->assertAccepted();

        $this->app->make(DocumentConversionService::class)->convert(
            $queued->json('data.guid'), $queued->json('data.conversion_generation'),
        );

        $result = $this->getJson('/api/v1/versions/'.$queued->json('data.guid'))
            ->assertJsonPath('data.conversion_status', 'ready')
            ->assertJsonPath('data.conversion_page_count', 2);
        $this->assertContains('PDF_OCR_PARTIAL', $result->json('data.conversion_warnings'));
        $this->assertStringContainsString('First page editable', $result->json('data.editor_data.blocks.0.data.text'));
    }

    public function test_pdf_with_supported_embedded_image_is_omitted_from_import(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $image = imagecreatetruecolor(20, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 80, 140));
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $owner = User::factory()->create();
        $created = $this->actingAs($owner)->postJson('/api/v1/documents', [
            'title' => 'Gambar',
            'editor_data' => ['blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Teks sebelum gambar']],
                ['type' => 'image', 'data' => ['file' => ['url' => 'data:image/jpeg;base64,'.base64_encode($jpeg)], 'caption' => 'Foto']],
            ]],
        ])->assertCreated();
        $version = DocumentVersion::where('guid', $created->json('data.current_version.guid'))->firstOrFail();
        $pdf = $this->app->make(PdfDocumentRenderer::class)->preview($version)['bytes'];
        $queued = $this->postJson('/api/v1/documents/'.$created->json('data.guid').'/imports', [
            'file' => UploadedFileFactory::content('image.pdf', $pdf),
            'replace_editor_data' => true, 'editor_revision' => 1,
        ])->assertAccepted();
        $this->app->make(DocumentConversionService::class)->convert($version->guid, $queued->json('data.conversion_generation'));
        $blocks = $this->getJson('/api/v1/versions/'.$version->guid)->assertJsonPath('data.conversion_status', 'ready')
            ->json('data.editor_data.blocks');
        $this->assertNotContains('image', array_column($blocks, 'type'));
    }

    private function pdf(): string
    {
        $first = "BT /F1 12 Tf 72 720 Td (First page editable) Tj ET\n";
        $second = "BT /F1 12 Tf 72 720 Td (Second page editable) Tj ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 7 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($first)." >>\nstream\n".$first.'endstream',
            '<< /Length '.strlen($second)." >>\nstream\n".$second.'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 8\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function pdfWithBlankLastPage(): string
    {
        $text = "BT /F1 12 Tf 72 720 Td (First page editable) Tj ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($text).">>\nstream\n".$text.'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 7\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function ocrSpaceResponse(array $texts, int $exitCode = 1): array
    {
        return [
            'OCRExitCode' => $exitCode,
            'IsErroredOnProcessing' => false,
            'ParsedResults' => array_map(fn (?string $text): array => [
                'FileParseExitCode' => 1,
                'ParsedText' => $text,
            ], $texts),
        ];
    }

    private function scannedPdf(): string
    {
        $image = imagecreatetruecolor(20, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 80, 140));
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $content = "q 500 0 0 700 50 50 cm /Im0 Do Q\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R 4 0 R 5 0 R] /Count 3 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im0 6 0 R >> >> /Contents 7 0 R >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im0 6 0 R >> >> /Contents 8 0 R >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im0 6 0 R >> >> /Contents 9 0 R >>',
            '<< /Type /XObject /Subtype /Image /Width 20 /Height 20 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >>\nstream\n".$jpeg."\nendstream",
            '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
            '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
            '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 10\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 10 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function docx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx-fixture-');
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::OVERWRITE);
        $archive->addFromString('[Content_Types].xml', '<Types/>');
        $archive->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Judul kontrak</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Kolom A</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Kolom B</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:body></w:document>');
        $image = imagecreatetruecolor(12, 12);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 80, 120));
        ob_start();
        imagepng($image);
        $archive->addFromString('word/media/image1.png', ob_get_clean());
        imagedestroy($image);
        $archive->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
