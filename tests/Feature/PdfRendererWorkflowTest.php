<?php

namespace Tests\Feature;

use App\Enums\PreviewStatus;
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

    public function test_editor_preview_reflects_independent_table_border_choices(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $tables = ['blocks' => [
            ['type' => 'table', 'data' => ['withHeadings' => true, 'showBorders' => true, 'content' => [['Garis aktif'], ['A']]]],
            ['type' => 'table', 'data' => ['withHeadings' => false, 'showBorders' => false, 'content' => [['Tanpa garis'], ['B']]]],
        ]];
        $version = $this->draft($owner, ['editor_data_json' => $tables]);
        $renderer = $this->app->make(PdfDocumentRenderer::class);

        $mixed = $renderer->preview($version);
        $legacyBordered = $renderer->preview($version->setAttribute('editor_data_json', [
            'blocks' => [
                $tables['blocks'][0],
                ['type' => 'table', 'data' => ['withHeadings' => false, 'content' => [['Tanpa garis'], ['B']]]],
            ],
        ]));

        $this->assertSame(1, $mixed['pages']);
        $this->assertSame(1, $legacyBordered['pages']);
        $this->assertNotSame($mixed['bytes'], $legacyBordered['bytes']);
        $parser = new Parser;
        $mixedDocument = $parser->parseContent($mixed['bytes']);
        $borderedDocument = $parser->parseContent($legacyBordered['bytes']);
        $this->assertStringContainsString('Garis aktif', $mixedDocument->getText());
        $this->assertStringContainsString('Tanpa garis', $mixedDocument->getText());
        $mixedCommands = $mixedDocument->getPages()[0]->get('Contents')->getContent();
        $borderedCommands = $borderedDocument->getPages()[0]->get('Contents')->getContent();
        $this->assertLessThan(substr_count($borderedCommands, " S\n"), substr_count($mixedCommands, " S\n"));
    }

    public function test_preview_and_final_pdf_keep_the_delimiter_between_paragraphs(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $version = $this->draft(User::factory()->create(), [
            'editor_data_json' => ['blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Sebelum garis']],
                ['type' => 'delimiter', 'data' => []],
                ['type' => 'paragraph', 'data' => ['text' => 'Sesudah garis']],
            ]],
        ]);
        $renderer = $this->app->make(PdfDocumentRenderer::class);
        $preview = $renderer->preview($version);
        $plainVersion = clone $version;
        $plainPreview = $renderer->preview($plainVersion->setAttribute('editor_data_json', ['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Sebelum garis']],
            ['type' => 'paragraph', 'data' => ['text' => 'Sesudah garis']],
        ]]));
        $parser = new Parser;
        $previewDocument = $parser->parseContent($preview['bytes']);
        $plainDocument = $parser->parseContent($plainPreview['bytes']);
        $previewCommands = $previewDocument->getPages()[0]->get('Contents')->getContent();
        $plainCommands = $plainDocument->getPages()[0]->get('Contents')->getContent();

        $this->assertSame(1, $preview['pages']);
        $this->assertSame(1, $plainPreview['pages']);
        $this->assertGreaterThan(substr_count($plainCommands, " S\n"), substr_count($previewCommands, " S\n"));
        $this->assertStringContainsString('Sebelum garis', $previewDocument->getText());
        $this->assertStringContainsString('Sesudah garis', $previewDocument->getText());

        $path = "documents/{$version->document_guid}/previews/{$version->guid}-delimiter.pdf";
        Storage::disk('local')->put($path, $preview['bytes']);
        $version->update([
            'preview_status' => PreviewStatus::Ready,
            'preview_source' => 'editor',
            'preview_pdf_path' => $path,
            'preview_page_count' => $preview['pages'],
            'content_hash' => hash('sha256', $preview['bytes']),
        ]);
        $version->update(['editor_data_json' => ['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Editor berubah setelah snapshot']],
        ]]]);
        $finalDocument = $parser->parseContent($renderer->finalize($version->fresh()));

        $this->assertCount(1, $finalDocument->getPages());
        $this->assertStringContainsString('Sebelum garis', $finalDocument->getText());
        $this->assertStringContainsString('Sesudah garis', $finalDocument->getText());
        $this->assertStringNotContainsString('Editor berubah setelah snapshot', $finalDocument->getText());
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
        $this->assertDatabaseHas('user_notifications', ['user_guid' => $version->document->owner_guid, 'event_type' => 'version.final_pdf_ready', 'version_guid' => $version->guid]);
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
