<?php

namespace Tests\Feature;

use App\Enums\PreviewStatus;
use App\Enums\SourceType;
use App\Enums\VersionStatus;
use App\Jobs\GenerateDocumentPreview;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DocumentPipelineApiTest extends TestCase
{
    use RefreshDatabase, SigningFixtures;

    public function test_preview_request_is_durable_and_repeat_while_queued_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);

        $this->actingAs($owner)->postJson('/api/v1/versions/'.$version->guid.'/preview', ['source' => 'editor'])
            ->assertAccepted()->assertJsonPath('data.preview_status', 'queued')->assertJsonPath('data.content_hash', null);
        $generation = $version->fresh()->preview_generation;
        $this->postJson('/api/v1/versions/'.$version->guid.'/preview', ['source' => 'editor'])->assertAccepted();

        $this->assertSame($generation, $version->fresh()->preview_generation);
        $this->assertTrue($version->assignments()->firstOrFail()->placeholders()->firstOrFail()->needs_review);
        $this->assertSame(1, AuditEvent::where('action', 'version.preview_requested')->count());
    }

    public function test_queued_preview_with_a_different_source_starts_a_new_generation(): void
    {
        $owner = User::factory()->create();
        $version = $this->draft($owner, [
            'source_type' => SourceType::Pdf,
            'source_path' => 'documents/source.pdf',
        ]);
        $url = '/api/v1/versions/'.$version->guid.'/preview';

        $this->actingAs($owner)->postJson($url, ['source' => 'editor'])->assertAccepted();
        $editorGeneration = $version->fresh()->preview_generation;

        $this->postJson($url, ['source' => 'upload'])->assertAccepted();

        $this->assertNotSame($editorGeneration, $version->fresh()->preview_generation);
        $this->assertSame('upload', $version->fresh()->preview_source);
        $this->assertSame(2, AuditEvent::where('action', 'version.preview_requested')->count());
    }

    public function test_preview_is_owner_only_and_requires_a_supported_source(): void
    {
        $owner = User::factory()->create();
        $version = $this->draft($owner);
        $url = '/api/v1/versions/'.$version->guid.'/preview';

        $this->postJson($url, ['source' => 'editor'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, ['source' => 'editor'])->assertNotFound();
        $this->actingAs($owner)->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['source' => 'remote'])->assertUnprocessable();
        $this->postJson($url, ['source' => 'upload'])->assertUnprocessable()->assertJsonPath('error.code', 'SOURCE_REQUIRED');
        $this->assertSame(PreviewStatus::None, $version->fresh()->preview_status);
    }

    public function test_published_and_old_draft_cannot_request_preview(): void
    {
        $owner = User::factory()->create();
        $version = $this->draft($owner, ['status' => 'in_progress']);
        $url = '/api/v1/versions/'.$version->guid.'/preview';
        $this->actingAs($owner)->postJson($url, ['source' => 'editor'])->assertConflict();
        $version->update(['status' => 'draft']);
        DocumentVersion::factory()->create(['document_guid' => $version->document_guid, 'version_number' => 2]);
        $this->postJson($url, ['source' => 'editor'])->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
    }

    public function test_edit_cancels_pending_generation_and_old_failure_cannot_overwrite_new_work(): void
    {
        $owner = User::factory()->create();
        $version = $this->draft($owner);
        $this->actingAs($owner)->postJson('/api/v1/versions/'.$version->guid.'/preview', ['source' => 'editor'])->assertAccepted();
        $oldGeneration = $version->fresh()->preview_generation;
        $this->patchJson('/api/v1/versions/'.$version->guid, ['editor_revision' => 1, 'editor_data' => ['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Updated content']],
        ]]])->assertOk()->assertJsonPath('data.preview_status', 'stale');
        $this->assertNull($version->fresh()->preview_generation);
        $this->postJson('/api/v1/versions/'.$version->guid.'/preview', ['source' => 'editor'])->assertAccepted();
        $this->assertNotSame($oldGeneration, $version->fresh()->preview_generation);
        (new GenerateDocumentPreview($version->guid, $oldGeneration))->failed(new RuntimeException('old failure'));

        $this->assertSame(PreviewStatus::Queued, $version->fresh()->preview_status);
        $this->assertNull($version->fresh()->preview_error_code);
    }

    public function test_finalization_retry_is_owner_only_and_cannot_skip_signing(): void
    {
        $owner = User::factory()->create();
        $version = $this->draft($owner);
        $url = '/api/v1/versions/'.$version->guid.'/finalize';
        $this->actingAs($owner)->postJson($url)->assertConflict()->assertJsonPath('error.code', 'VERSION_NOT_FINALIZING');
        $version->update(['status' => VersionStatus::Finalizing, 'finalization_error_code' => 'FINALIZATION_FAILED']);
        $this->actingAs(User::factory()->create())->postJson($url)->assertNotFound();
        $this->actingAs($owner)->postJson($url)->assertAccepted()->assertJsonPath('data.finalization_error_code', null);
        $this->postJson($url)->assertAccepted();
        $this->assertSame(1, AuditEvent::where('action', 'version.finalization_retried')->count());
    }
}
