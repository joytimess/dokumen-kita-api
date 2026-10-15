<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentConversionService;
use App\Services\DocumentPipelineService;
use App\Services\PdfDocumentRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

class ImportConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_import_becomes_editable_and_save_uses_latest_revision(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
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

    public function test_pdf_with_supported_embedded_image_yields_image_block(): void
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
        $this->assertContains('image', array_column($blocks, 'type'));
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
