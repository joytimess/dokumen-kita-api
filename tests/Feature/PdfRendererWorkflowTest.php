<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\ReadConfirmation;
use App\Models\SignaturePlaceholder;
use App\Models\SignerAssignment;
use App\Models\User;
use App\Services\DocumentPipelineService;
use App\Services\PdfDocumentRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class PdfRendererWorkflowTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    public function test_editor_preview_contains_saved_text_and_edit_invalidates_its_hash(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->draft($owner, ['editor_data_json' => ['blocks' => [
            ['type' => 'header', 'data' => ['text' => 'Perjanjian uji', 'level' => 1]],
            ['type' => 'paragraph', 'data' => ['text' => 'Isi yang disetujui pemilik.']],
            ['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['Nama', 'Nilai'], ['A', 'B']]]],
        ]]]);
        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/preview", ['source' => 'editor'])
            ->assertAccepted();
        $pipeline = $this->app->make(DocumentPipelineService::class);
        $pipeline->generatePreview($version->guid, $version->fresh()->preview_generation, $this->app->make(PdfDocumentRenderer::class));

        $version->refresh();
        $this->assertSame('ready', $version->preview_status->value);
        $bytes = Storage::disk('local')->get($version->preview_pdf_path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(hash('sha256', $bytes), $version->content_hash);
        $this->assertSame(1, $version->preview_page_count);
        $this->assertStringContainsString('Isi yang disetujui pemilik', (new Parser)->parseContent($bytes)->getText());
        $this->get("/api/v1/versions/{$version->guid}/pdf")->assertOk();
        $assignment = SignerAssignment::factory()->create([
            'version_guid' => $version->guid,
            'recipient_email' => 'signer@example.test',
            'normalized_recipient_email' => 'signer@example.test',
            'sequence_number' => 1,
        ]);
        SignaturePlaceholder::factory()->create(['assignment_guid' => $assignment->guid, 'page_number' => 1]);

        $this->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_revision' => 1,
            'editor_data' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Isi baru']]]],
        ])->assertOk()->assertJsonPath('data.preview_status', 'stale');
        $this->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertConflict();
    }

    public function test_final_pdf_embeds_each_signature_and_completes_only_after_file_is_saved(): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $version = $this->publishedVersion($owner, [$first, $second]);
        $previewBytes = Storage::disk('local')->get($version->preview_pdf_path);
        foreach ([$first, $second] as $index => $signer) {
            $assignment = $version->assignments()->where('sequence_number', $index + 1)->firstOrFail();
            ReadConfirmation::factory()->create([
                'assignment_guid' => $assignment->guid,
                'version_content_hash' => $version->content_hash,
            ]);
            $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [
                'asset_guid' => $this->asset($signer)->guid,
                'confirmed' => true,
                'version_content_hash' => $version->content_hash,
                'idempotency_key' => 'render-signature-'.($index + 1),
            ])->assertCreated();
        }
        $this->assertSame('finalizing', $version->fresh()->status->value);
        $this->assertNull($version->fresh()->final_pdf_path);

        $this->app->make(DocumentPipelineService::class)->finalize($version->guid, $this->app->make(PdfDocumentRenderer::class));
        $version->refresh();
        $this->assertSame('completed', $version->status->value);
        $final = Storage::disk('local')->get($version->final_pdf_path);
        $this->assertStringStartsWith('%PDF-', $final);
        $this->assertSame(hash('sha256', $final), $version->final_pdf_hash);
        $this->assertGreaterThan(strlen($previewBytes), strlen($final));
        $this->assertCount(2, (new Parser)->parseContent($final)->getPages());
        $this->actingAs($owner)->get("/api/v1/versions/{$version->guid}/pdf")->assertOk();
    }

    public function test_page_limit_rejects_render_before_preview_becomes_ready(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local', 'documents.processing.max_pages' => 0]);
        $version = $this->draft(User::factory()->create(), [
            'editor_data_json' => ['blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Isi']],
            ]],
        ]);
        $renderer = $this->app->make(PdfDocumentRenderer::class);

        try {
            $renderer->preview($version);
            $this->fail('Page limit should have rejected the preview.');
        } catch (ApiException $exception) {
            $this->assertSame('DOCUMENT_PAGE_LIMIT', $exception->errorCode);
        }
    }
}
