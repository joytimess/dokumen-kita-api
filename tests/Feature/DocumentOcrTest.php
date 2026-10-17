<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentOcr;
use App\Models\DocumentOcrPage;
use App\Models\User;
use App\Services\DocumentOcrService;
use App\Services\OcrSpaceOcrClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class DocumentOcrTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    public function test_ocr_is_unavailable_when_disabled(): void
    {
        config(['documents.ocr.enabled' => false]);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr", ['expected_hash' => $version->content_hash])
            ->assertAccepted()->assertJsonPath('data.processing_status', 'unavailable')
            ->assertJsonPath('data.error_code', 'OCR_PROVIDER_NOT_CONFIGURED');
        $this->assertDatabaseCount('document_ocr_pages', 0);
        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/ocr?expected_hash=".str_repeat('0', 64))
            ->assertConflict()->assertJsonPath('error.code', 'PDF_VERSION_CHANGED');
        $this->actingAs(User::factory()->create())->getJson("/api/v1/versions/{$version->guid}/ocr?expected_hash={$version->content_hash}")
            ->assertNotFound();
    }

    public function test_mixed_pdf_skips_native_page_and_returns_search_hits_without_unverified_boxes(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        $bytes = $this->mixedPdf();
        Storage::disk('local')->put($version->preview_pdf_path, $bytes);
        $version->update(['content_hash' => hash('sha256', $bytes), 'preview_page_count' => 2]);
        $this->mock(OcrSpaceOcrClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('extractText')->once()->withArgs(fn (string $pdf, int $pages): bool => $pages === 2)
                ->andReturn(['Native searchable text', 'Laporan']);
        });

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr", ['expected_hash' => $version->content_hash])
            ->assertAccepted()->assertJsonPath('data.processing_status', 'queued')
            ->assertJsonPath('data.page_counts.total', 2);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 1, 'source' => 'native', 'status' => 'completed']);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 2, 'source' => 'ocr', 'status' => 'queued']);
        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);

        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/ocr?expected_hash={$version->content_hash}&query=Laporan")
            ->assertOk()->assertJsonPath('data.processing_status', 'completed')
            ->assertJsonPath('data.matches.0.page_number', 2)
            ->assertJsonPath('data.matches.0.snippet', 'Laporan')
            ->assertJsonPath('data.matches.0.page_dimensions.width', null)
            ->assertJsonPath('data.matches.0.page_dimensions.height', null)
            ->assertJsonCount(0, 'data.matches.0.polygons');
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
        $this->mock(OcrSpaceOcrClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('extractText')->once()->andReturn(['Native searchable text', '']);
        });

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr", ['expected_hash' => $version->content_hash])->assertAccepted();
        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);

        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/ocr?expected_hash={$version->content_hash}")
            ->assertOk()->assertJsonPath('data.processing_status', 'completed')
            ->assertJsonPath('data.ocr_page_counts.total', 1)
            ->assertJsonPath('data.ocr_page_counts.recognized', 0)
            ->assertJsonCount(0, 'data.matches');
    }

    public function test_viewer_keeps_successful_pages_when_ocr_space_returns_a_page_error(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        $this->mock(OcrSpaceOcrClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('extractText')->once()->andReturn(['Laporan', null]);
        });

        app(DocumentOcrService::class)->start($version, $version->content_hash);
        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);

        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/ocr?expected_hash={$version->content_hash}&query=Laporan")
            ->assertOk()->assertJsonPath('data.processing_status', 'partial')
            ->assertJsonPath('data.matches.0.page_number', 1)
            ->assertJsonPath('data.failed_pages.0.error_code', 'OCR_TEXT_UNRECOGNIZED')
            ->assertJsonPath('data.failed_pages.0.retryable', false);
    }

    public function test_failed_pages_can_be_retried_without_resetting_completed_pages(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        $this->mock(OcrSpaceOcrClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->times(3)->andReturn(true);
            $mock->shouldReceive('extractText')->twice()->andReturn(['Laporan', 'Laporan kedua']);
        });
        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr", ['expected_hash' => $version->content_hash])->assertAccepted();
        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 1, 'status' => 'completed', 'text' => 'Laporan']);
        DocumentOcrPage::where('version_guid', $version->guid)->where('page_number', 2)->update([
            'status' => 'failed', 'error_code' => 'OCR_QUOTA_EXHAUSTED', 'retryable' => true,
        ]);
        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr/retry", [
            'expected_hash' => $version->content_hash, 'pages' => [2],
        ])->assertAccepted()->assertJsonPath('data.page_counts.completed', 1);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 1, 'status' => 'completed', 'text' => 'Laporan']);
        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr/retry", [
            'expected_hash' => $version->content_hash, 'pages' => [1],
        ])->assertConflict()->assertJsonPath('error.code', 'OCR_PAGES_NOT_RETRYABLE');

        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);
        $ocrPage = DocumentOcrPage::where('version_guid', $version->guid)->where('page_number', 1)->firstOrFail();
        $this->assertSame('completed', $ocrPage->status, $ocrPage->error_code ?? 'OCR returned no error code');
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 1, 'status' => 'completed']);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 2, 'status' => 'completed', 'text' => 'Laporan kedua']);
        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/ocr?expected_hash={$version->content_hash}&query=Laporan")
            ->assertOk()->assertJsonPath('data.matches.0.page_number', 1);
    }

    public function test_ocr_page_budget_fails_scanned_pages_without_provider_calls(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local', 'documents.ocr.max_pages' => 1]);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        $bytes = $this->mixedPdf();
        Storage::disk('local')->put($version->preview_pdf_path, $bytes);
        $version->update(['content_hash' => hash('sha256', $bytes), 'preview_page_count' => 2]);
        $this->mock(OcrSpaceOcrClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldNotReceive('extractText');
        });

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr", ['expected_hash' => $version->content_hash])
            ->assertAccepted()->assertJsonPath('data.processing_status', 'partial')
            ->assertJsonPath('data.page_counts.failed', 1)
            ->assertJsonPath('data.failed_pages.0.error_code', 'OCR_LIMIT_EXCEEDED');
        $this->assertDatabaseHas('document_ocr_pages', [
            'version_guid' => $version->guid, 'page_number' => 1, 'source' => 'native', 'status' => 'completed',
        ]);
    }

    public function test_legacy_ocr_retry_is_rejected_when_the_pdf_exceeds_the_current_limit(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local', 'documents.ocr.max_pages' => 1]);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        DocumentOcrPage::create([
            'version_guid' => $version->guid,
            'pdf_hash' => $version->content_hash,
            'page_number' => 1,
            'source' => 'ocr',
            'status' => 'failed',
            'retryable' => true,
        ]);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/ocr/retry", [
            'expected_hash' => $version->content_hash, 'pages' => [1],
        ])->assertConflict()->assertJsonPath('error.code', 'OCR_LIMIT_EXCEEDED');
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
        $this->mock(OcrSpaceOcrClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('extractText')->once()->andReturn(['', 'Laporan']);
        });
        app(DocumentOcrService::class)->start($version, $version->content_hash);
        DocumentOcrPage::where('version_guid', $version->guid)->update(['status' => 'processing']);

        app(DocumentOcrService::class)->process($version->guid, $version->content_hash);

        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 1, 'status' => 'completed']);
        $this->assertDatabaseHas('document_ocr_pages', ['version_guid' => $version->guid, 'page_number' => 2, 'status' => 'completed', 'text' => 'Laporan']);
    }

    public function test_legacy_quota_failure_can_be_retried_without_resetting_other_pages(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        $this->mock(OcrSpaceOcrClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->twice()->andReturn(true);
            $mock->shouldReceive('extractText')->once()->andReturnUsing(fn (string $pdf, int $pageCount): array => array_fill(0, $pageCount, 'Laporan'));
        });
        app(DocumentOcrService::class)->start($version, $version->content_hash);
        DocumentOcrPage::where('version_guid', $version->guid)->update([
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
