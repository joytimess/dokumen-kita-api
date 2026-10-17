<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentOcr;
use App\Models\DocumentOcrPage;
use App\Models\User;
use App\Services\DocumentOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentOcrTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    public function test_ocr_is_disabled_without_credentials_and_does_not_send_a_request(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        Http::preventStrayRequests();

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr", ['expected_hash' => $version->content_hash])
            ->assertAccepted()->assertJsonPath('data.processing_status', 'unavailable')
            ->assertJsonPath('data.error_code', 'OCR_PROVIDER_NOT_CONFIGURED');
        $this->assertDatabaseCount('document_ocr_pages', 0);
        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/ocr?expected_hash=".str_repeat('0', 64))
            ->assertConflict()->assertJsonPath('error.code', 'PDF_VERSION_CHANGED');
        $this->actingAs(User::factory()->create())->getJson("/api/v1/versions/{$version->guid}/ocr?expected_hash={$version->content_hash}")
            ->assertNotFound();
    }

    public function test_mixed_pdf_skips_native_page_and_returns_page_scoped_word_boxes(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        $bytes = $this->mixedPdf();
        Storage::disk('local')->put($version->preview_pdf_path, $bytes);
        $version->update(['content_hash' => hash('sha256', $bytes), 'preview_page_count' => 2]);
        $this->enableVision();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://vision.googleapis.com/v1/files:annotate' => Http::response($this->visionResponse()),
        ]);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr", ['expected_hash' => $version->content_hash])
            ->assertAccepted()->assertJsonPath('data.processing_status', 'queued')
            ->assertJsonPath('data.page_counts.total', 2);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 1, 'source' => 'native', 'status' => 'completed']);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 2, 'source' => 'ocr', 'status' => 'queued']);
        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'files:annotate')
            && $request['requests'][0]['pages'] === [2]);
        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/ocr?expected_hash={$version->content_hash}&query=Laporan")
            ->assertOk()->assertJsonPath('data.processing_status', 'completed')
            ->assertJsonPath('data.matches.0.page_number', 2)
            ->assertJsonPath('data.matches.0.snippet', 'Laporan')
            ->assertJsonPath('data.matches.0.page_dimensions.width', 100)
            ->assertJsonPath('data.matches.0.page_dimensions.height', 200)
            ->assertJsonPath('data.matches.0.polygons.0.0.0', 0.1)
            ->assertJsonPath('data.matches.0.polygons.0.2.1', 0.25);
    }

    public function test_completed_ocr_with_no_recognized_text_is_reported_as_empty_result(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        $bytes = $this->mixedPdf();
        Storage::disk('local')->put($version->preview_pdf_path, $bytes);
        $version->update(['content_hash' => hash('sha256', $bytes), 'preview_page_count' => 2]);
        $this->enableVision();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://vision.googleapis.com/v1/files:annotate' => Http::response(['responses' => [[
                'context' => ['pageNumber' => 2],
                'fullTextAnnotation' => ['pages' => [['width' => 100, 'height' => 200, 'blocks' => []]]],
            ]]]),
        ]);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr", ['expected_hash' => $version->content_hash])->assertAccepted();
        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);

        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/ocr?expected_hash={$version->content_hash}")
            ->assertOk()->assertJsonPath('data.processing_status', 'completed')
            ->assertJsonPath('data.ocr_page_counts.total', 1)
            ->assertJsonPath('data.ocr_page_counts.recognized', 0)
            ->assertJsonCount(0, 'data.matches');
    }

    public function test_failed_pages_can_be_retried_without_resetting_completed_pages(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        $this->enableVision();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://vision.googleapis.com/v1/files:annotate' => Http::sequence()
                ->push(['error' => ['code' => 503]], 503)
                ->push($this->visionResponse(1)),
        ]);
        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr", ['expected_hash' => $version->content_hash])->assertAccepted();
        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 1, 'status' => 'failed', 'retryable' => true]);

        DocumentOcrPage::where('version_guid', $version->guid)->where('page_number', 2)->update([
            'status' => 'completed', 'text' => 'keep this page', 'words' => '[]',
        ]);
        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr/retry", [
            'expected_hash' => $version->content_hash, 'pages' => [1],
        ])->assertAccepted()->assertJsonPath('data.page_counts.completed', 1);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 2, 'status' => 'completed', 'text' => 'keep this page']);
        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr/retry", [
            'expected_hash' => $version->content_hash, 'pages' => [2],
        ])->assertConflict()->assertJsonPath('error.code', 'OCR_PAGES_NOT_RETRYABLE');

        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);
        $ocrPage = DocumentOcrPage::where('version_guid', $version->guid)->where('page_number', 1)->firstOrFail();
        $this->assertSame('completed', $ocrPage->status, $ocrPage->error_code ?? 'OCR returned no error code');
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 1, 'status' => 'completed']);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 2, 'status' => 'completed', 'text' => 'keep this page']);
        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/ocr?expected_hash={$version->content_hash}&query=Laporan")
            ->assertOk()->assertJsonPath('data.matches.0.page_number', 1);
    }

    public function test_ocr_page_budget_fails_scanned_pages_without_provider_calls(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local', 'documents.ocr.max_pages' => 1]);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        $this->enableVision();
        Http::preventStrayRequests();

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr", ['expected_hash' => $version->content_hash])
            ->assertAccepted()->assertJsonPath('data.processing_status', 'failed')
            ->assertJsonPath('data.page_counts.failed', 2)
            ->assertJsonPath('data.failed_pages.0.error_code', 'OCR_LIMIT_EXCEEDED');
    }

    public function test_documents_process_dispatches_durable_queued_ocr_pages(): void
    {
        $version = $this->preparedDraft(User::factory()->create(), []);
        DocumentOcrPage::create([
            'version_guid' => $version->guid,
            'pdf_hash' => $version->content_hash,
            'page_number' => 1,
            'source' => 'ocr',
            'status' => 'queued',
        ]);
        config(['documents.processing.connection' => 'database']);
        Queue::fake();

        $this->artisan('documents:process')->assertSuccessful();

        Queue::assertPushed(ProcessDocumentOcr::class, fn (ProcessDocumentOcr $job) => $job->versionGuid === $version->guid && $job->pdfHash === $version->content_hash);
    }

    public function test_queue_retry_resumes_pages_left_processing(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $version = $this->preparedDraft(User::factory()->create(), []);
        $this->enableVision();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://vision.googleapis.com/v1/files:annotate' => Http::response($this->visionResponse(2)),
        ]);
        app(DocumentOcrService::class)->start($version, $version->content_hash);
        DocumentOcrPage::where('version_guid', $version->guid)->update(['status' => 'processing']);

        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);

        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 1, 'status' => 'completed']);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 2, 'status' => 'completed', 'text' => 'Laporan']);
    }

    public function test_quota_failure_is_retryable_and_successful_retry_keeps_other_failed_page(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        $this->enableVision();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://vision.googleapis.com/v1/files:annotate' => Http::sequence()
                ->push(['error' => ['code' => 429, 'message' => 'Quota exceeded']], 429)
                ->push($this->visionResponse(1)),
        ]);
        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr", ['expected_hash' => $version->content_hash])
            ->assertAccepted();
        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);
        $this->assertDatabaseHas('document_ocr_pages', [
            'version_guid' => $version->guid, 'page_number' => 1,
            'status' => 'failed', 'error_code' => 'OCR_QUOTA_EXHAUSTED', 'retryable' => true,
        ]);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr/retry", [
            'expected_hash' => $version->content_hash, 'pages' => [1],
        ])->assertAccepted();
        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 1, 'status' => 'completed']);
        $this->assertDatabaseHas('document_ocr_pages', [
            'version_guid' => $version->guid, 'page_number' => 2,
            'status' => 'failed', 'error_code' => 'OCR_QUOTA_EXHAUSTED',
        ]);
    }

    private function enableVision(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);
        Storage::disk('local')->put('testing/vision.json', json_encode([
            'client_email' => 'ocr@example.test', 'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));
        config([
            'documents.ocr.enabled' => true,
            'documents.ocr.credentials' => Storage::disk('local')->path('testing/vision.json'),
        ]);
        Cache::flush();
    }

    private function visionResponse(int $pageNumber = 2): array
    {
        $word = [
            'symbols' => [['text' => 'Laporan']],
            'boundingBox' => ['normalizedVertices' => [
                ['x' => 0.1, 'y' => 0.2], ['x' => 0.4, 'y' => 0.2],
                ['x' => 0.4, 'y' => 0.25], ['x' => 0.1, 'y' => 0.25],
            ]],
        ];
        $page = [
            'width' => 100,
            'height' => 200,
            'blocks' => [
                ['paragraphs' => [['words' => [$word]]]],
            ],
        ];

        return ['responses' => [[
            'context' => ['pageNumber' => $pageNumber],
            'fullTextAnnotation' => ['pages' => [$page]],
        ]]];
    }

    private function mixedPdf(): string
    {
        $text = "BT /F1 12 Tf 72 720 Td (Native searchable text) Tj ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> /Contents 7 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($text).">>\nstream\n".$text.'endstream',
            "<< /Length 0 >>\nstream\nendstream",
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
}
