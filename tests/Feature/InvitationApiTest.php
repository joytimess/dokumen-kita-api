<?php

namespace Tests\Feature;

use App\Enums\VersionStatus;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\EmailNotification;
use App\Models\User;
use App\Services\PublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvitationApiTest extends TestCase
{
    use RefreshDatabase, SigningFixtures;

    public function test_matching_verified_recipient_claims_idempotently_without_changing_manifest(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'first@example.test']);
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->where('sequence_number', 1)->firstOrFail();
        $token = $this->invitationToken($assignment);
        $manifest = $version->fresh()->manifest_hash;

        $response = $this->actingAs($recipient)->postJson('/api/v1/invitations/claim', ['token' => $token])
            ->assertOk()->assertJsonPath('data.guid', $assignment->guid)
            ->assertJsonPath('data.status', 'ready')->assertJsonCount(1, 'data.placeholders')
            ->assertJsonMissingPath('data.user_guid')->assertJsonMissingPath('data.token_hash');
        $this->postJson('/api/v1/invitations/claim', ['token' => $token])
            ->assertOk()->assertJsonPath('data', $response->json('data'));

        $this->assertDatabaseHas('signer_assignments', ['guid' => $assignment->guid, 'user_guid' => $recipient->guid, 'claimed_at' => now()->toDateTimeString()]);
        $this->assertSame(1, AuditEvent::where('action', 'invitation.claimed')->count());
        $this->assertSame($manifest, $version->fresh()->manifest_hash);
        $this->assertDatabaseCount('email_notifications', 1);
    }

    public function test_pending_recipient_has_no_action_invitation_before_their_turn(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'second@example.test']);
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->where('sequence_number', 2)->firstOrFail();
        $this->assertDatabaseMissing('email_notifications', ['assignment_guid' => $assignment->guid]);
        $this->assertSame(0, $assignment->invitationLinks()->count());
        $this->actingAs($recipient)->getJson("/api/v1/documents/{$version->document_guid}")->assertNotFound();
        $this->actingAs($owner)->postJson("/api/v1/assignments/{$assignment->guid}/resend-invitation")
            ->assertConflict()->assertJsonPath('error.code', 'INVITATION_NOT_ACTIVE');
        $this->assertSame('waiting', $assignment->fresh()->status);
        $this->assertSame(1, $version->assignments()->where('status', 'ready')->count());
    }

    public function test_owner_as_last_signer_cannot_claim_before_their_turn(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $version = $this->preparedDraft($owner, ['first@example.test', 'owner@example.test']);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->where('sequence_number', 2)->firstOrFail();

        $this->assertSame(0, $assignment->invitationLinks()->count());
        $this->assertDatabaseHas('signer_assignments', ['guid' => $assignment->guid, 'user_guid' => null, 'status' => 'waiting']);
    }

    public function test_unverified_email_returns_403_without_claiming(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->unverified()->create(['email' => 'first@example.test']);
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->firstOrFail();

        $this->actingAs($recipient)->postJson('/api/v1/invitations/claim', ['token' => $this->invitationToken($assignment)])
            ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->assertNull($assignment->fresh()->user_guid);
        $this->assertSame(0, AuditEvent::where('action', 'invitation.claimed')->count());
    }

    public function test_forwarded_invitation_with_wrong_email_returns_422(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->firstOrFail();

        $this->actingAs(User::factory()->create())->postJson('/api/v1/invitations/claim', ['token' => $this->invitationToken($assignment)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVITATION_INVALID');

        $this->assertNull($assignment->fresh()->user_guid);
        $this->assertSame(0, AuditEvent::where('action', 'invitation.claimed')->count());
    }

    /** @return array<string, array{string}> */
    public static function invalidTokens(): array
    {
        return [
            'short' => ['short'],
            'non hex' => [str_repeat('z', 64)],
            'unknown' => [str_repeat('f', 64)],
        ];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_token_returns_422(string $token): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/invitations/claim', ['token' => $token])
            ->assertUnprocessable();

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_missing_token_returns_422(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/invitations/claim', [])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['token']]]);
    }

    public function test_expiry_at_exact_deadline_returns_422(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'first@example.test']);
        config(['documents.invitations.expires_days' => 2]);
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->firstOrFail();
        $token = $this->invitationToken($assignment);
        $this->travel(2)->days();

        $this->actingAs($recipient)->postJson('/api/v1/invitations/claim', ['token' => $token])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVITATION_INVALID');

        $this->assertNull($assignment->fresh()->user_guid);
    }

    public function test_revoked_token_returns_422(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'first@example.test']);
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->firstOrFail();
        $token = $this->invitationToken($assignment);
        $assignment->invitationLinks()->update(['revoked_at' => now()]);

        $this->actingAs($recipient)->postJson('/api/v1/invitations/claim', ['token' => $token])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVITATION_INVALID');

        $this->assertNull($assignment->fresh()->user_guid);
    }

    public function test_conflicting_account_binding_returns_409(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'first@example.test']);
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->firstOrFail();
        $bound = User::factory()->create();
        $assignment->update(['user_guid' => $bound->guid]);

        $this->actingAs($recipient)->postJson('/api/v1/invitations/claim', ['token' => $this->invitationToken($assignment)])
            ->assertConflict()->assertJsonPath('error.code', 'INVITATION_ALREADY_CLAIMED');

        $this->assertSame($bound->guid, $assignment->fresh()->user_guid);
    }

    /** @return array<string, array{VersionStatus}> */
    public static function inactiveVersions(): array
    {
        return [
            'revision requested' => [VersionStatus::RevisionRequested],
            'superseded' => [VersionStatus::Superseded],
            'rejected' => [VersionStatus::Rejected],
            'completed' => [VersionStatus::Completed],
        ];
    }

    #[DataProvider('inactiveVersions')]
    public function test_inactive_version_returns_409_for_claim_and_resend(VersionStatus $status): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'first@example.test']);
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->firstOrFail();
        $token = $this->invitationToken($assignment);
        $version->update(['status' => $status]);

        $this->actingAs($recipient)->postJson('/api/v1/invitations/claim', ['token' => $token])
            ->assertConflict()->assertJsonPath('error.code', 'INVITATION_NOT_ACTIVE');
        $this->actingAs($owner)->postJson("/api/v1/assignments/{$assignment->guid}/resend-invitation")
            ->assertConflict()->assertJsonPath('error.code', 'INVITATION_NOT_ACTIVE');

        $this->assertNull($assignment->fresh()->user_guid);
        $this->assertDatabaseCount('invitation_links', 1);
        $this->assertDatabaseCount('email_notifications', 1);
    }

    public function test_old_version_invitation_returns_409_after_new_version_exists(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'first@example.test']);
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->firstOrFail();
        DocumentVersion::factory()->create(['document_guid' => $version->document_guid, 'version_number' => 2]);

        $this->actingAs($recipient)->postJson('/api/v1/invitations/claim', ['token' => $this->invitationToken($assignment)])
            ->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $this->assertNull($assignment->fresh()->user_guid);
    }

    public function test_resend_rotates_token_cancels_old_outbox_and_new_token_can_be_claimed(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'first@example.test']);
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->firstOrFail();
        $oldToken = $this->invitationToken($assignment);
        $oldNotification = EmailNotification::where('assignment_guid', $assignment->guid)->firstOrFail();

        $this->actingAs($owner)->postJson("/api/v1/assignments/{$assignment->guid}/resend-invitation")
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonMissingPath('data.token');

        $this->assertDatabaseHas('invitation_links', ['assignment_guid' => $assignment->guid, 'token_hash' => hash('sha256', $oldToken), 'revoked_at' => now()->toDateTimeString()]);
        $this->assertSame('cancelled', $oldNotification->fresh()->status);
        $this->assertNull($oldNotification->fresh()->payload);
        $this->assertDatabaseCount('email_notifications', 2);
        $this->assertDatabaseHas('audit_events', ['action' => 'invitation.resent', 'actor_guid' => $owner->guid]);
        $newToken = $this->invitationToken($assignment);
        $this->assertNotSame($oldToken, $newToken);

        $this->actingAs($recipient)->postJson('/api/v1/invitations/claim', ['token' => $oldToken])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVITATION_INVALID');
        $this->postJson('/api/v1/invitations/claim', ['token' => $newToken])->assertOk();
        $this->assertSame($recipient->guid, $assignment->fresh()->user_guid);
    }

    public function test_outsider_cannot_resend_and_gets_404(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->firstOrFail();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/assignments/{$assignment->guid}/resend-invitation")->assertNotFound();

        $this->assertDatabaseCount('invitation_links', 1);
    }

    public function test_resend_is_rate_limited_per_assignment_and_returns_429(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->firstOrFail();

        $this->actingAs($owner);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson("/api/v1/assignments/{$assignment->guid}/resend-invitation")->assertOk();
        }
        $this->postJson("/api/v1/assignments/{$assignment->guid}/resend-invitation")
            ->assertTooManyRequests()->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');

        $this->assertDatabaseCount('invitation_links', 4);
    }
}
