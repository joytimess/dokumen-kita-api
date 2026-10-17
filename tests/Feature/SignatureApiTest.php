<?php

namespace Tests\Feature;

use App\Enums\VersionStatus;
use App\Models\AuditEvent;
use App\Models\EmailNotification;
use App\Models\ReadConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignatureApiTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    public function test_explicit_read_confirmation_is_hash_bound_and_idempotent(): void
    {
        $this->freezeTime();
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        $payload = ['confirmed' => true, 'version_content_hash' => $version->content_hash];

        $response = $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/read-confirmation", $payload)
            ->assertCreated()->assertJsonPath('data.version_content_hash', $version->content_hash);
        $this->postJson("/api/v1/assignments/{$assignment->guid}/read-confirmation", $payload)
            ->assertOk()->assertJsonPath('data', $response->json('data'));

        $this->assertDatabaseCount('read_confirmations', 1);
        $this->assertSame(1, AuditEvent::where('action', 'assignment.read_confirmed')->count());
    }

    public function test_read_confirmation_requires_explicit_acceptance_and_matching_hash(): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();

        $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/read-confirmation", [
            'confirmed' => false, 'version_content_hash' => $version->content_hash,
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['confirmed']]]);
        $this->postJson("/api/v1/assignments/{$assignment->guid}/read-confirmation", ['confirmed' => true, 'version_content_hash' => str_repeat('b', 64)])
            ->assertConflict()->assertJsonPath('error.code', 'CONTENT_HASH_MISMATCH');

        $this->assertDatabaseCount('read_confirmations', 0);
    }

    public function test_signature_is_idempotent_fills_all_placeholders_and_activates_next_signer_once(): void
    {
        $this->freezeTime();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$first, $second], 2);
        $assignment = $version->assignments()->where('sequence_number', 1)->firstOrFail();
        $next = $version->assignments()->where('sequence_number', 2)->firstOrFail();
        ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
        $asset = $this->asset($first);
        $payload = ['asset_guid' => $asset->guid, 'confirmed' => true, 'version_content_hash' => $version->content_hash, 'idempotency_key' => 'signature-request-1'];

        $response = $this->actingAs($first)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", $payload)
            ->assertCreated()->assertJsonPath('data.asset_guid', $asset->guid)->assertJsonMissingPath('data.idempotency_key');
        $this->postJson("/api/v1/assignments/{$assignment->guid}/signatures", $payload)
            ->assertOk()->assertJsonPath('data', $response->json('data'));

        $this->assertDatabaseCount('signatures', 1);
        $this->assertSame('signed', $assignment->fresh()->status);
        $this->assertSame('ready', $next->fresh()->status);
        $this->assertSame(2, $assignment->placeholders()->count());
        $this->assertSame(VersionStatus::InProgress, $version->fresh()->status);
        $this->assertSame(1, AuditEvent::where('action', 'assignment.signed')->count());
        $this->assertSame(
            ['name' => $first->name, 'email' => $first->email],
            AuditEvent::where('action', 'assignment.signed')->firstOrFail()->metadata_json['actor_snapshot'],
        );
        $this->assertSame(
            ['name' => $first->name, 'email' => $first->email],
            AuditEvent::where('action', 'assignment.signed')->firstOrFail()->metadata_json['actor_snapshot'],
        );
        $this->assertSame(1, AuditEvent::where('action', 'assignment.activated')->count());
        $this->assertDatabaseHas('user_notifications', ['user_guid' => $version->document->owner_guid, 'event_type' => 'assignment.signed', 'assignment_guid' => $assignment->guid]);
        $this->assertDatabaseHas('user_notifications', ['user_guid' => $second->guid, 'event_type' => 'assignment.ready', 'assignment_guid' => $next->guid]);
        $this->assertSame(1, EmailNotification::where('assignment_guid', $next->guid)->where('notification_type', 'invitation_ready')->where('status', 'queued')->count());
        $this->assertSame(0, EmailNotification::where('assignment_guid', $next->guid)->where('notification_type', 'invitation_waiting')->count());
        $this->assertSame(0, $assignment->invitationLinks()->whereNull('revoked_at')->count());
    }

    public function test_last_signature_moves_document_to_finalizing_and_retry_does_not_duplicate_outbox(): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
        $asset = $this->asset($signer);
        $payload = ['asset_guid' => $asset->guid, 'confirmed' => true, 'version_content_hash' => $version->content_hash, 'idempotency_key' => 'last-signature'];

        $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", $payload)->assertCreated();
        $this->postJson("/api/v1/assignments/{$assignment->guid}/signatures", $payload)->assertOk();

        $this->assertSame(VersionStatus::Finalizing, $version->fresh()->status);
        $this->assertDatabaseHas('documents', ['guid' => $version->document_guid, 'status' => 'finalizing']);
        $this->assertNull($version->fresh()->final_pdf_path);
        $this->assertSame(1, EmailNotification::where('notification_type', 'version_finalizing')->count());
        $this->assertSame(1, AuditEvent::where('action', 'version.finalizing')->count());
    }

    public function test_waiting_signer_including_owner_cannot_confirm_or_sign_and_gets_409(): void
    {
        $owner = User::factory()->create();
        $version = $this->publishedVersion($owner, [User::factory()->create(), $owner]);
        $assignment = $version->assignments()->where('sequence_number', 2)->firstOrFail();
        $asset = $this->asset($owner);

        $this->actingAs($owner)->postJson("/api/v1/assignments/{$assignment->guid}/read-confirmation", [
            'confirmed' => true, 'version_content_hash' => $version->content_hash,
        ])->assertConflict()->assertJsonPath('error.code', 'ASSIGNMENT_NOT_READY');
        $this->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [
            'confirmed' => true, 'version_content_hash' => $version->content_hash, 'asset_guid' => $asset->guid, 'idempotency_key' => 'waiting-signature',
        ])->assertConflict()->assertJsonPath('error.code', 'ASSIGNMENT_NOT_READY');

        $this->assertDatabaseCount('signatures', 0);
        $this->assertDatabaseCount('read_confirmations', 0);
    }

    public function test_signature_without_read_confirmation_returns_409(): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        $asset = $this->asset($signer);

        $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [
            'confirmed' => true, 'version_content_hash' => $version->content_hash, 'asset_guid' => $asset->guid, 'idempotency_key' => 'no-confirmation',
        ])->assertConflict()->assertJsonPath('error.code', 'READ_CONFIRMATION_REQUIRED');

        $this->assertDatabaseCount('signatures', 0);
        $this->assertSame('ready', $assignment->fresh()->status);
    }

    public function test_other_users_asset_returns_404_and_stale_read_confirmation_returns_409(): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        $confirmation = ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
        $asset = $this->asset(User::factory()->create());
        $payload = ['confirmed' => true, 'version_content_hash' => $version->content_hash, 'asset_guid' => $asset->guid, 'idempotency_key' => 'wrong-asset'];

        $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", $payload)->assertNotFound();
        $confirmation->update(['version_content_hash' => str_repeat('c', 64)]);
        $this->postJson("/api/v1/assignments/{$assignment->guid}/signatures", $payload)
            ->assertConflict()->assertJsonPath('error.code', 'READ_CONFIRMATION_REQUIRED');

        $this->assertDatabaseCount('signatures', 0);
    }

    public function test_modified_asset_returns_409_without_signature_or_turn_change(): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
        $asset = $this->asset($signer);
        Storage::disk('local')->put($asset->file_path, 'tampered');

        $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [
            'confirmed' => true, 'version_content_hash' => $version->content_hash, 'asset_guid' => $asset->guid, 'idempotency_key' => 'tampered-asset',
        ])->assertConflict()->assertJsonPath('error.code', 'SIGNATURE_ASSET_CHANGED');

        $this->assertDatabaseCount('signatures', 0);
        $this->assertSame('ready', $assignment->fresh()->status);
    }

    public function test_reusing_saved_asset_still_requires_confirmation_for_each_assignment(): void
    {
        $signer = User::factory()->create();
        $first = $this->publishedVersion(User::factory()->create(), [$signer]);
        $asset = $this->asset($signer);
        $second = $this->draft(User::factory()->create());
        $this->storePreview($second);
        $assignment = $second->assignments()->create(['recipient_email' => $signer->email, 'normalized_recipient_email' => $signer->email, 'sequence_number' => 1, 'user_guid' => $signer->guid, 'status' => 'ready']);
        $second->update(['status' => VersionStatus::InProgress]);
        ReadConfirmation::factory()->create(['assignment_guid' => $first->assignments()->firstOrFail()->guid, 'version_content_hash' => $first->content_hash]);

        $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [
            'confirmed' => true, 'version_content_hash' => $second->content_hash, 'asset_guid' => $asset->guid, 'idempotency_key' => 'reuse-asset',
        ])->assertConflict()->assertJsonPath('error.code', 'READ_CONFIRMATION_REQUIRED');

        $this->assertDatabaseCount('signatures', 0);
    }

    public function test_retry_with_different_payload_returns_409(): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
        $asset = $this->asset($signer);
        $payload = ['confirmed' => true, 'version_content_hash' => $version->content_hash, 'asset_guid' => $asset->guid, 'idempotency_key' => 'original'];

        $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", $payload)->assertCreated();
        $this->postJson("/api/v1/assignments/{$assignment->guid}/signatures", array_replace($payload, ['idempotency_key' => 'different']))
            ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertDatabaseCount('signatures', 1);
    }

    /** @return array<string, array{VersionStatus}> */
    public static function inactiveStates(): array
    {
        return ['revision' => [VersionStatus::RevisionRequested], 'superseded' => [VersionStatus::Superseded], 'rejected' => [VersionStatus::Rejected]];
    }

    #[DataProvider('inactiveStates')]
    public function test_stopped_version_returns_409_without_signature(VersionStatus $state): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        $asset = $this->asset($signer);
        $version->update(['status' => $state]);

        $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [
            'confirmed' => true, 'version_content_hash' => $version->content_hash, 'asset_guid' => $asset->guid, 'idempotency_key' => 'stopped-version',
        ])->assertConflict()->assertJsonPath('error.code', 'VERSION_NOT_SIGNABLE');

        $this->assertDatabaseCount('signatures', 0);
    }

    public function test_outsider_gets_404_and_owner_cannot_sign_someone_elses_assignment(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->publishedVersion($owner, [$signer]);
        $assignment = $version->assignments()->firstOrFail();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [])->assertNotFound();
        $this->postJson("/api/v1/assignments/{$assignment->guid}/read-confirmation", [])->assertNotFound();
        $this->actingAs($owner)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [])->assertForbidden();

        $this->assertDatabaseCount('signatures', 0);
    }

    public function test_required_fields_are_validated_before_signature(): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();

        $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['asset_guid', 'confirmed', 'version_content_hash', 'idempotency_key']]]);

        $this->assertDatabaseCount('signatures', 0);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidSignaturePayloads(): array
    {
        return [
            'no explicit consent' => [['confirmed' => false]],
            'invalid asset guid' => [['asset_guid' => 'not-a-guid']],
            'invalid hash' => [['version_content_hash' => 'bad']],
            'empty key' => [['idempotency_key' => '']],
            'long key' => [['idempotency_key' => str_repeat('x', 65)]],
            'unsafe key' => [['idempotency_key' => '<script>']],
        ];
    }

    #[DataProvider('invalidSignaturePayloads')]
    public function test_invalid_signature_payload_returns_422_without_state_change(array $changes): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        $asset = $this->asset($signer);
        $payload = ['confirmed' => true, 'version_content_hash' => $version->content_hash, 'asset_guid' => $asset->guid, 'idempotency_key' => 'valid-key'];

        $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", array_replace($payload, $changes))
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseCount('signatures', 0);
        $this->assertSame('ready', $assignment->fresh()->status);
    }

    public function test_failed_turn_transition_rolls_back_signature_audit_and_invitation_revocation(): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer, User::factory()->create()]);
        $assignment = $version->assignments()->where('sequence_number', 1)->firstOrFail();
        $version->assignments()->where('sequence_number', 2)->update(['status' => 'ready']);
        ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
        $asset = $this->asset($signer);

        $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [
            'confirmed' => true, 'version_content_hash' => $version->content_hash, 'asset_guid' => $asset->guid, 'idempotency_key' => 'invalid-transition',
        ])->assertConflict()->assertJsonPath('error.code', 'SIGNING_ORDER_CONFLICT');

        $this->assertDatabaseCount('signatures', 0);
        $this->assertSame('ready', $assignment->fresh()->status);
        $this->assertSame(0, AuditEvent::where('action', 'assignment.signed')->count());
        $this->assertSame(1, $assignment->invitationLinks()->whereNull('revoked_at')->count());
        $this->assertSame('queued', EmailNotification::where('assignment_guid', $assignment->guid)->firstOrFail()->status);
    }
}
