<?php

namespace Tests\Feature;

use App\Enums\VersionStatus;
use App\Models\AuditEvent;
use App\Models\EmailNotification;
use App\Models\ReadConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevisionWorkflowTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    public function test_ready_signer_requests_revision_after_first_signature_and_new_draft_requires_fresh_approval(): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $version = $this->publishedVersion($owner, [$first, $second]);
        $version->update(['editor_data_json' => ['blocks' => [['type' => 'table', 'data' => [
            'withHeadings' => false, 'showBorders' => false, 'content' => [['Isi']],
        ]]]], 'letterhead' => [
            'path' => 'documents/kop.png', 'mime' => 'image/png', 'width' => 400, 'height' => 80,
            'pages' => ['mode' => 'all'],
        ]]);
        $firstAssignment = $version->assignments()->where('sequence_number', 1)->firstOrFail();
        $secondAssignment = $version->assignments()->where('sequence_number', 2)->firstOrFail();
        ReadConfirmation::factory()->create(['assignment_guid' => $firstAssignment->guid, 'version_content_hash' => $version->content_hash]);
        $this->actingAs($first)->postJson("/api/v1/assignments/{$firstAssignment->guid}/signatures", [
            'asset_guid' => $this->asset($first)->guid,
            'confirmed' => true,
            'version_content_hash' => $version->content_hash,
            'idempotency_key' => 'before-revision',
        ])->assertCreated();

        $response = $this->actingAs($second)->postJson("/api/v1/assignments/{$secondAssignment->guid}/revision-request", [
            'reason' => 'Alamat penerima perlu diperbaiki.',
        ])->assertOk()->assertJsonPath('data.previous_version_guid', $version->guid)
            ->assertJsonPath('data.previous_status', 'revision_requested')
            ->assertJsonPath('data.draft_status', 'draft');

        $draftGuid = $response->json('data.draft_version_guid');
        $draft = $version->document->versions()->where('guid', $draftGuid)->firstOrFail();
        $this->assertSame(2, $draft->version_number);
        $this->assertSame(VersionStatus::Draft, $draft->status);
        $this->assertSame(false, $draft->editor_data_json['blocks'][0]['data']['showBorders']);
        $this->assertSame($version->letterhead, $draft->letterhead);
        $this->assertSame(VersionStatus::Draft, $version->document->fresh()->status);
        $this->assertSame(1, $firstAssignment->signature()->count());
        $this->assertSame(0, $draft->assignments()->whereHas('signature')->count());
        $this->assertSame(0, $draft->assignments()->whereHas('readConfirmation')->count());
        $this->assertSame(0, $draft->assignments()->whereHas('invitationLinks')->count());
        $this->assertTrue($draft->assignments()->firstOrFail()->placeholders()->firstOrFail()->needs_review);
        $this->assertSame(0, $secondAssignment->invitationLinks()->whereNull('revoked_at')->count());
        $this->assertSame(1, EmailNotification::where('notification_type', 'revision_requested')->count());
        $this->assertSame(
            ['name' => $second->name, 'email' => $second->email],
            AuditEvent::where('action', 'version.revision_requested')->firstOrFail()->metadata_json['actor_snapshot'],
        );
        $this->assertSame(
            ['name' => $second->name, 'email' => $second->email],
            AuditEvent::where('action', 'version.revision_requested')->firstOrFail()->metadata_json['actor_snapshot'],
        );
        $this->assertDatabaseHas('user_notifications', ['user_guid' => $owner->guid, 'event_type' => 'version.revision_requested', 'version_guid' => $version->guid]);
        $this->assertSame(1, $secondAssignment->response()->count());

        $this->postJson("/api/v1/assignments/{$secondAssignment->guid}/revision-request", ['reason' => 'Lagi'])
            ->assertConflict();
        $this->postJson("/api/v1/assignments/{$secondAssignment->guid}/read-confirmation", [
            'confirmed' => true, 'version_content_hash' => $version->content_hash,
        ])->assertConflict();
        $this->actingAs($owner)->postJson("/api/v1/versions/{$draftGuid}/publish")
            ->assertConflict();
    }

    public function test_waiting_signer_and_outsider_cannot_request_revision(): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $version = $this->publishedVersion($owner, [$first, $second]);
        $waiting = $version->assignments()->where('sequence_number', 2)->firstOrFail();
        $url = "/api/v1/assignments/{$waiting->guid}/revision-request";

        $this->actingAs($second)->postJson($url, ['reason' => 'Perbaiki isinya'])->assertConflict()
            ->assertJsonPath('error.code', 'ASSIGNMENT_NOT_READY');
        $this->actingAs(User::factory()->create())->postJson($url, ['reason' => 'Perbaiki isinya'])->assertNotFound();
        $this->assertSame(1, $version->document->versions()->count());
    }
}
