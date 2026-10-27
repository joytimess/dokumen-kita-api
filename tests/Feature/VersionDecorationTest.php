<?php

namespace Tests\Feature;

use App\Enums\PreviewStatus;
use App\Enums\SourceType;
use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Jobs\GenerateDocumentPreview;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignaturePlaceholder;
use App\Models\SignerAssignment;
use App\Models\User;
use App\Services\DocumentPipelineService;
use App\Services\PdfDocumentRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class VersionDecorationTest extends TestCase
{
    use RefreshDatabase;

    private function draft(User $owner, array $attributes = []): DocumentVersion
    {
        $document = Document::factory()->create(['owner_guid' => $owner->guid]);

        return DocumentVersion::factory()->create(['document_guid' => $document->guid] + $attributes);
    }

    public function test_owner_can_upload_replace_change_pages_and_remove_a_private_decoration(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $version = $this->draft($owner, ['preview_status' => PreviewStatus::Ready, 'content_hash' => str_repeat('a', 64), 'preview_page_count' => 1]);
        $assignment = SignerAssignment::factory()->create(['version_guid' => $version->guid]);
        $placeholder = SignaturePlaceholder::factory()->create(['assignment_guid' => $assignment->guid, 'needs_review' => false]);
        $url = "/api/v1/versions/{$version->guid}/decorations/letterhead";

        $this->actingAs($other)->post($url, ['editor_revision' => 1, 'file' => UploadedFile::fake()->image('kop.png')], ['Accept' => 'application/json'])->assertNotFound();
        $uploaded = $this->actingAs($owner)->post($url, [
            'editor_revision' => 1,
            'file' => UploadedFileFactory::withoutRealPath(UploadedFile::fake()->image('kop.png', 400, 80)),
            'pages' => ['mode' => 'range', 'ranges' => [[3, 4], [1, 1]]],
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.editor_revision', 2)
            ->assertJsonPath('data.preview_status', 'stale')
            ->assertJsonPath('data.preview_page_count', null)
            ->assertJsonPath('data.letterhead.pages.ranges', [[1, 1], [3, 4]]);
        $imageUrl = $uploaded->json('data.letterhead.image_url');
        $this->get($imageUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($other)->get($imageUrl)->assertNotFound();
        $this->assertNull($version->fresh()->content_hash);
        $this->assertTrue($placeholder->fresh()->needs_review);

        $this->actingAs($owner)->patchJson($url, ['editor_revision' => 1, 'pages' => ['mode' => 'all']])
            ->assertConflict()->assertJsonPath('error.code', 'EDITOR_REVISION_CONFLICT');
        $this->patchJson($url, ['editor_revision' => 2, 'pages' => ['mode' => 'all']])
            ->assertOk()->assertJsonPath('data.letterhead.pages.mode', 'all');
        $this->deleteJson($url, ['editor_revision' => 3])->assertOk()
            ->assertJsonPath('data.letterhead', null)
            ->assertJsonPath('data.editor_revision', 4);
        $this->get($imageUrl)->assertNotFound();

        $version->update(['status' => VersionStatus::InProgress]);
        $this->post($url, [
            'editor_revision' => 4, 'file' => UploadedFile::fake()->image('baru.png'),
        ], ['Accept' => 'application/json'])->assertConflict()->assertJsonPath('error.code', 'VERSION_NOT_EDITABLE');
    }

    public function test_invalid_images_and_ranges_are_rejected_without_changing_draft(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->draft($owner);
        $url = "/api/v1/versions/{$version->guid}/decorations/footer";

        $this->actingAs($owner)->post($url, [
            'editor_revision' => 1, 'file' => UploadedFileFactory::withoutRealPath(UploadedFile::fake()->image('footer.png', 1201, 50)),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->post($url, [
            'editor_revision' => 1, 'file' => UploadedFile::fake()->createWithContent('footer.png', 'not an image'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $invalidRanges = $this->post($url, [
            'editor_revision' => 1, 'file' => UploadedFile::fake()->image('footer.png', 100, 30),
            'pages' => ['mode' => 'range', 'ranges' => [[1, 3], [3, 5]]],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame('Rentang halaman tidak boleh tumpang tindih atau duplikat.', $invalidRanges->json('error.errors')['pages.ranges'][0]);
        $this->assertNull($version->fresh()->footer);
        $this->assertSame(1, $version->fresh()->editor_revision);
    }

    public function test_pdf_preview_embeds_decoration_on_selected_pages_and_hashes_the_result(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->draft($owner, ['editor_data_json' => ['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => str_repeat('Konten halaman. ', 700)]],
        ]]]);
        $this->actingAs($owner)->post("/api/v1/versions/{$version->guid}/decorations/letterhead", [
            'editor_revision' => 1,
            'file' => UploadedFile::fake()->image('kop.png', 400, 80),
            'pages' => ['mode' => 'range', 'ranges' => [[2, 2]]],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->postJson("/api/v1/versions/{$version->guid}/preview", ['source' => 'editor'])->assertAccepted();
        $this->app->make(DocumentPipelineService::class)->generatePreview(
            $version->guid, $version->fresh()->preview_generation, $this->app->make(PdfDocumentRenderer::class),
        );
        $version->refresh();
        $bytes = Storage::disk('local')->get($version->preview_pdf_path);
        $pages = (new Parser)->parseContent($bytes)->getPages();
        $this->assertGreaterThanOrEqual(2, count($pages));
        $this->assertSame(count($pages), $version->preview_page_count);
        $this->assertSame(hash('sha256', $bytes), $version->content_hash);
        $this->assertStringNotContainsString('/I1 Do', $pages[0]->get('Contents')->getContent());
        $this->assertStringContainsString('/I1 Do', $pages[1]->get('Contents')->getContent());
        Storage::disk('local')->delete($version->letterhead['path']);
        $this->assertStringStartsWith('%PDF-', $this->app->make(PdfDocumentRenderer::class)->finalize($version->load('assignments')));
    }

    public function test_standard_letterhead_and_footer_fill_content_width_and_final_pdf_reuses_preview(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->draft($owner, ['editor_data_json' => ['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Body below letterhead']],
        ]]]);
        foreach (['letterhead', 'footer'] as $index => $kind) {
            $this->actingAs($owner)->post("/api/v1/versions/{$version->guid}/decorations/{$kind}", [
                'editor_revision' => $index + 1,
                'file' => UploadedFile::fake()->image($kind.'.png', 1200, 400),
            ], ['Accept' => 'application/json'])->assertOk();
        }
        $this->postJson("/api/v1/versions/{$version->guid}/preview", ['source' => 'editor'])->assertAccepted();
        $renderer = $this->app->make(PdfDocumentRenderer::class);
        $this->app->make(DocumentPipelineService::class)->generatePreview(
            $version->guid, $version->fresh()->preview_generation, $renderer,
        );
        $version->refresh();
        $bytes = Storage::disk('local')->get($version->preview_pdf_path);
        $document = (new Parser)->parseContent($bytes);
        $this->assertSame(1, $version->preview_page_count);
        $commands = $document->getPages()[0]->get('Contents')->getContent();
        preg_match_all('/([\d.]+) 0 0 ([\d.]+) ([\d.]+) ([\d.]+) cm \/I\d+ Do/', $commands, $images);
        $this->assertCount(2, $images[0], $commands);
        foreach ([0, 1] as $index) {
            $this->assertEqualsWithDelta(174 * 72 / 25.4, (float) $images[1][$index], 0.02);
            $this->assertEqualsWithDelta(58 * 72 / 25.4, (float) $images[2][$index], 0.02);
            $this->assertEqualsWithDelta(18 * 72 / 25.4, (float) $images[3][$index], 0.02);
        }
        $this->assertEqualsWithDelta((297 - 7 - 58) * 72 / 25.4, (float) $images[4][0], 0.02);
        $this->assertEqualsWithDelta(7 * 72 / 25.4, (float) $images[4][1], 0.02);
        $this->assertStringContainsString('Body below letterhead', $document->getText());
        Storage::disk('local')->delete([$version->letterhead['path'], $version->footer['path']]);
        $final = $renderer->finalize($version->load('assignments'));
        $this->assertStringContainsString('Body below letterhead', (new Parser)->parseContent($final)->getText());
    }

    public function test_out_of_range_page_selection_fails_preview_with_actionable_message(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->draft($owner);
        $this->actingAs($owner)->post("/api/v1/versions/{$version->guid}/decorations/footer", [
            'editor_revision' => 1,
            'file' => UploadedFile::fake()->image('footer.png', 400, 80),
            'pages' => ['mode' => 'range', 'ranges' => [[3, 5]]],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->postJson("/api/v1/versions/{$version->guid}/preview", ['source' => 'editor'])->assertAccepted();
        $generation = $version->fresh()->preview_generation;
        try {
            $this->app->make(DocumentPipelineService::class)->generatePreview(
                $version->guid, $generation, $this->app->make(PdfDocumentRenderer::class),
            );
            $this->fail('Expected an out-of-range preview error.');
        } catch (ApiException $exception) {
            $this->assertSame('DECORATION_PAGE_OUT_OF_RANGE', $exception->errorCode);
            $this->assertStringContainsString('3-5', $exception->getMessage());
            (new GenerateDocumentPreview($version->guid, $generation))->failed($exception);
        }
        $this->getJson("/api/v1/versions/{$version->guid}")
            ->assertOk()
            ->assertJsonPath('data.preview_status', 'failed')
            ->assertJsonPath('data.preview_error_code', 'DECORATION_PAGE_OUT_OF_RANGE')
            ->assertJsonPath('data.preview_error_message', 'Rentang footer 3-5 melebihi 1 halaman preview.');
    }

    public function test_source_pdf_preview_refuses_decoration_without_reserved_space(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $owner = User::factory()->create();
        $version = $this->draft($owner, ['source_type' => SourceType::Pdf, 'source_path' => 'documents/source.pdf']);
        $this->actingAs($owner)->post("/api/v1/versions/{$version->guid}/decorations/footer", [
            'editor_revision' => 1, 'file' => UploadedFile::fake()->image('footer.png', 400, 80),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->postJson("/api/v1/versions/{$version->guid}/preview", ['source' => 'upload'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'EDITOR_PREVIEW_REQUIRED');
        $this->assertSame(PreviewStatus::None, $version->fresh()->preview_status);
    }
}
