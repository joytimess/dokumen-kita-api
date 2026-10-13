<?php

namespace Tests\Feature;

use App\Enums\PreviewStatus;
use App\Enums\VersionStatus;
use App\Models\DocumentVersion;
use App\Models\EmailNotification;
use App\Models\InvitationLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublishApiTest extends TestCase
{
    use RefreshDatabase, SigningFixtures;

    public function test_publish_freezes_snapshot_activates_only_first_signer_and_is_idempotent(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, ['first@example.test', $owner->email]);
        $path = $version->preview_pdf_path;
        $contentHash = $version->content_hash;

        $first = $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertOk()->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.assignments.0.status', 'ready')
            ->assertJsonPath('data.assignments.1.status', 'waiting')
            ->assertJsonMissingPath('data.preview_pdf_path');

        $this->postJson("/api/v1/versions/{$version->guid}/publish")->assertOk()->assertJsonPath('data', $first->json('data'));
        $this->assertDatabaseCount('invitation_links', 2);
        $this->assertDatabaseCount('email_notifications', 2);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseHas('documents', ['guid' => $version->document_guid, 'status' => 'in_progress']);
        $this->assertDatabaseHas('email_notifications', ['notification_type' => 'invitation_ready', 'recipient_email' => 'first@example.test']);
        $this->assertDatabaseHas('email_notifications', ['notification_type' => 'invitation_waiting', 'recipient_email' => $owner->email]);
        $this->assertSame($path, $version->fresh()->preview_pdf_path);
        $this->assertSame($contentHash, $version->fresh()->content_hash);
        $this->assertNotNull($version->fresh()->manifest_hash);
        Storage::disk('local')->assertExists($path);
        $this->assertSame(now()->addDays(7)->toDateTimeString(), InvitationLink::firstOrFail()->expires_at->toDateTimeString());
    }

    public function test_invitation_url_is_encrypted_and_token_hash_is_not_serialized(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")->assertOk();

        $assignment = $version->assignments()->firstOrFail();
        $token = $this->invitationToken($assignment);
        $notification = EmailNotification::where('assignment_guid', $assignment->guid)->firstOrFail();
        $this->assertStringNotContainsString($token, $notification->getRawOriginal('payload'));
        $this->assertArrayNotHasKey('payload', $notification->toArray());
        $this->assertArrayNotHasKey('token_hash', InvitationLink::firstOrFail()->toArray());
        $this->assertDatabaseHas('invitation_links', ['assignment_guid' => $assignment->guid, 'token_hash' => hash('sha256', $token)]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unusablePreviews(): array
    {
        return [
            'none' => [['preview_status' => PreviewStatus::None]],
            'stale' => [['preview_status' => PreviewStatus::Stale]],
            'queued' => [['preview_status' => PreviewStatus::Queued]],
            'failed' => [['preview_status' => PreviewStatus::Failed]],
            'missing path' => [['preview_pdf_path' => null]],
            'missing hash' => [['content_hash' => null]],
            'missing page count' => [['preview_page_count' => null]],
        ];
    }

    #[DataProvider('unusablePreviews')]
    public function test_unusable_preview_returns_409_without_publish_side_effects(array $changes): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $version->update($changes);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertConflict()->assertJsonPath('error.code', 'PREVIEW_NOT_READY');

        $this->assertSame(VersionStatus::Draft, $version->fresh()->status);
        $this->assertDatabaseCount('invitation_links', 0);
        $this->assertDatabaseCount('email_notifications', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_missing_preview_file_returns_409(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        Storage::disk('local')->delete($version->preview_pdf_path);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertConflict()->assertJsonPath('error.code', 'PREVIEW_NOT_READY');

        $this->assertDatabaseCount('email_notifications', 0);
    }

    public function test_changed_preview_bytes_return_409(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        Storage::disk('local')->put($version->preview_pdf_path, '%PDF-1.4 changed');

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertConflict()->assertJsonPath('error.code', 'PREVIEW_MISMATCH');

        $this->assertSame(VersionStatus::Draft, $version->fresh()->status);
        $this->assertDatabaseCount('email_notifications', 0);
    }

    public function test_empty_signers_return_422(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertUnprocessable()->assertJsonPath('error.code', 'SIGNERS_INVALID');

        $this->assertDatabaseCount('email_notifications', 0);
    }

    public function test_sequence_gap_is_rechecked_at_publish_and_returns_422(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $version->assignments()->where('sequence_number', 2)->update(['sequence_number' => 3]);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertUnprocessable()->assertJsonPath('error.code', 'SIGNERS_INVALID');

        $this->assertDatabaseCount('email_notifications', 0);
    }

    public function test_missing_placeholder_returns_422_and_needs_review_returns_409(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $assignment = $version->assignments()->firstOrFail();
        $assignment->placeholders()->delete();

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertUnprocessable()->assertJsonPath('error.code', 'PLACEHOLDERS_REQUIRED');

        $assignment->placeholders()->create(['page_number' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.1, 'needs_review' => true]);
        $this->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertConflict()->assertJsonPath('error.code', 'PLACEHOLDERS_NEED_REVIEW');

        $this->assertDatabaseCount('invitation_links', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_placeholder_outside_preview_is_rechecked_at_publish_and_returns_422(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $version->assignments()->firstOrFail()->placeholders()->update(['page_number' => 3]);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertUnprocessable()->assertJsonPath('error.code', 'PLACEHOLDERS_INVALID');

        $this->assertDatabaseCount('email_notifications', 0);
    }

    public function test_another_active_version_prevents_publish_with_409(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $version->update(['version_number' => 2]);
        DocumentVersion::factory()->create(['document_guid' => $version->document_guid, 'version_number' => 1, 'status' => VersionStatus::InProgress]);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertConflict()->assertJsonPath('error.code', 'ACTIVE_VERSION_EXISTS');

        $this->assertDatabaseCount('email_notifications', 0);
    }

    public function test_old_draft_cannot_publish_over_newer_version_and_returns_409(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        DocumentVersion::factory()->create(['document_guid' => $version->document_guid, 'version_number' => 2]);

        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $this->assertDatabaseCount('email_notifications', 0);
    }

    public function test_outsider_cannot_publish_and_gets_404(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);

        $this->actingAs(User::factory()->create())->postJson("/api/v1/versions/{$version->guid}/publish")->assertNotFound();

        $this->assertDatabaseCount('email_notifications', 0);
    }

    public function test_guest_endpoints_return_401(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $assignment = $version->assignments()->firstOrFail();

        $this->putJson("/api/v1/versions/{$version->guid}/signers", ['signers' => []])->assertUnauthorized();
        $this->putJson("/api/v1/assignments/{$assignment->guid}/placeholders", ['placeholders' => []])->assertUnauthorized();
        $this->postJson("/api/v1/versions/{$version->guid}/publish")->assertUnauthorized();
        $this->postJson('/api/v1/invitations/claim', ['token' => str_repeat('a', 64)])->assertUnauthorized();
        $this->postJson("/api/v1/assignments/{$assignment->guid}/resend-invitation")->assertUnauthorized();
    }
}
