<?php

namespace Tests\Feature;

use App\Enums\VersionStatus;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\DocumentVersionQr;
use App\Models\EmailNotification;
use App\Models\User;
use App\Services\PublishService;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QrSigningTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    private const RESOLVE = '/api/v1/signing-qr/resolve';

    private const CLAIM = '/api/v1/signing-qr/claim';

    private function token(DocumentVersion $version): string
    {
        return app(QrTokenService::class)->token(DocumentVersionQr::where('version_guid', $version->guid)->firstOrFail());
    }

    public function test_publish_creates_secure_token_once_without_changing_email_outbox(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        app(PublishService::class)->publish($owner, $version);
        $token = $this->token($version);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
        $qr = DocumentVersionQr::where('version_guid', $version->guid)->firstOrFail();
        $this->assertSame(hash('sha256', $token), $qr->token_hash);
        $this->assertStringNotContainsString($token, $qr->getRawOriginal('encrypted_token'));
        $this->assertArrayNotHasKey('encrypted_token', $qr->toArray());
        $this->assertArrayNotHasKey('token_hash', $qr->toArray());
        app(PublishService::class)->publish($owner, $version);
        $this->assertSame($token, $this->token($version));
        $this->assertDatabaseCount('document_version_qrs', 1);
        $this->assertSame(1, EmailNotification::where('notification_type', 'invitation_ready')->count());
        $this->assertStringNotContainsString($token, json_encode(AuditEvent::all()->toArray()));
    }

    public function test_failed_publish_creates_no_qr_and_draft_has_no_signing_link(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, []);
        $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")->assertUnprocessable();
        $this->assertDatabaseCount('document_version_qrs', 0);
        $this->getJson("/api/v1/versions/{$version->guid}/signing-qr")->assertConflict()->assertJsonMissingPath('data.signing_url');
    }

    public function test_owner_metadata_and_svg_are_private_repeatable_and_downloadable(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->publishedVersion($owner, [$signer]);
        $url = "/api/v1/versions/{$version->guid}/signing-qr";
        foreach ([$signer, User::factory()->create()] as $user) {
            $expected = $user->is($signer) ? 403 : 404;
            $this->actingAs($user)->getJson($url)->assertStatus($expected);
            $this->get($url, ['Accept' => 'image/svg+xml'])->assertStatus($expected);
        }
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.signing_url', rtrim(config('app.frontend_url'), '/').'/sign/qr/'.$this->token($version))
            ->assertJsonPath('data.svg_url', url($url));
        $image = $this->get($url, ['Accept' => 'image/svg+xml'])->assertOk()->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $image->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $image->headers->get('Cache-Control'));
        $xml = simplexml_load_string($image->getContent());
        $this->assertNotFalse($xml);
        $this->assertSame('svg', $xml->getName());
        $this->assertSame($image->getContent(), $this->get($url)->assertOk()->getContent());
        $download = $this->get($url.'?download=1', ['Accept' => 'image/svg+xml'])->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        $this->assertSame($image->getContent(), $download->getContent());
    }

    public function test_anonymous_resolution_exposes_only_status_and_claim_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        app(PublishService::class)->publish($owner, $version);
        $response = $this->postJson(self::RESOLVE, ['token' => $this->token($version)])->assertOk()
            ->assertJsonPath('data', ['status' => 'active'])->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->postJson(self::CLAIM, ['token' => $this->token($version)])->assertUnauthorized();
        $this->getJson("/api/v1/versions/{$version->guid}/signing-qr")->assertUnauthorized();
        $this->get("/api/v1/versions/{$version->guid}/signing-qr", ['Accept' => 'image/svg+xml'])->assertUnauthorized();
    }

    public function test_invalid_token_never_exposes_document_data(): void
    {
        foreach ([str_repeat('a', 64), 'malformed', ['nested']] as $token) {
            $response = $this->postJson(self::RESOLVE, ['token' => $token]);
            $this->assertContains($response->status(), [200, 422]);
            $response->assertJsonMissingPath('data.document_guid')->assertJsonMissingPath('data.assignment_guid');
            if ($response->isSuccessful()) {
                $response->assertJsonPath('data', ['status' => 'invalid']);
            }
        }
    }

    public function test_unverified_and_unassigned_users_cannot_claim_or_see_document(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->unverified()->create();
        $version = $this->preparedDraft($owner, [$signer->email]);
        app(PublishService::class)->publish($owner, $version);
        foreach ([[$signer, 'email_not_verified'], [User::factory()->create(), 'not_assigned']] as [$user, $status]) {
            $this->actingAs($user)->postJson(self::CLAIM, ['token' => $this->token($version)])->assertOk()
                ->assertJsonPath('data.status', $status)->assertJsonMissingPath('data.document_guid')->assertJsonMissingPath('data.assignment_guid');
            $this->getJson("/api/v1/versions/{$version->guid}/pdf")->assertNotFound();
        }
        $this->assertNull($version->assignments()->firstOrFail()->user_guid);
    }

    public static function modes(): array
    {
        return ['sequential' => ['sequential'], 'parallel' => ['parallel']];
    }

    #[DataProvider('modes')]
    public function test_shared_qr_preserves_order_read_gate_and_existing_engine_idempotency(string $mode): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $version = $this->preparedDraft($owner, [$first->email, $second->email]);
        $version->update(['signing_mode' => $mode]);
        app(PublishService::class)->publish($owner, $version);
        $token = $this->token($version);
        $assignments = $version->assignments()->orderBy('sequence_number')->get();
        $this->actingAs($second)->postJson(self::CLAIM, ['token' => $token])->assertOk()
            ->assertJsonPath('data.status', $mode === 'parallel' ? 'active' : 'waiting');
        foreach ([$first, $second] as $index => $signer) {
            $assignment = $assignments[$index];
            $this->actingAs($signer)->postJson(self::CLAIM, ['token' => $token])->assertOk()
                ->assertJsonPath('data.status', 'active')->assertJsonPath('data.assignment_guid', $assignment->guid)
                ->assertJsonPath('data.version_guid', $version->guid)->assertJsonPath('data.document_guid', $version->document_guid);
            $this->assertSame($signer->guid, $assignment->fresh()->user_guid);
            $other = $assignments[1 - $index];
            $this->postJson("/api/v1/assignments/{$other->guid}/read-confirmation", [
                'confirmed' => true, 'version_content_hash' => $version->content_hash,
            ])->assertForbidden();
            $payload = ['asset_guid' => $this->asset($signer)->guid, 'confirmed' => true,
                'version_content_hash' => $version->content_hash, 'idempotency_key' => 'qr-sign-'.$index];
            $path = "/api/v1/assignments/{$assignment->guid}/signatures";
            $this->postJson($path, $payload)->assertConflict()->assertJsonPath('error.code', 'READ_CONFIRMATION_REQUIRED');
            $this->postJson("/api/v1/assignments/{$assignment->guid}/read-confirmation", [
                'confirmed' => true, 'version_content_hash' => $version->content_hash,
            ])->assertCreated();
            $this->postJson($path, $payload)->assertCreated();
            $this->postJson($path, $payload)->assertOk();
            $this->postJson($path, array_replace($payload, ['idempotency_key' => 'different']))->assertConflict();
            if ($index === 0) {
                $this->postJson(self::CLAIM, ['token' => $token])->assertOk()->assertJsonPath('data.status', 'already_signed');
                $this->assertSame($token, $this->token($version));
            }
        }
        $this->assertDatabaseCount('signatures', 2);
        $this->assertDatabaseCount('document_version_qrs', 1);
        $this->assertSame(VersionStatus::Finalizing, $version->fresh()->status);
        $this->postJson(self::RESOLVE, ['token' => $token])->assertOk()->assertJsonPath('data.status', 'finalizing');
        $this->assertSame(1, EmailNotification::where('notification_type', 'version_finalizing')->count());
        $version->update(['status' => VersionStatus::Completed]);
        $this->postJson(self::RESOLVE, ['token' => $token])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_revision_revokes_old_token_and_new_publish_issues_distinct_token(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->preparedDraft($owner, [$signer->email]);
        app(PublishService::class)->publish($owner, $version);
        $token = $this->token($version);
        $claim = $this->actingAs($signer)->postJson(self::CLAIM, ['token' => $token])->assertOk();
        $assignment = $claim->json('data.assignment_guid');
        $revision = $this->postJson("/api/v1/assignments/{$assignment}/revision-request", ['reason' => 'Perbaiki alamat dokumen.'])->assertOk();
        $this->postJson(self::RESOLVE, ['token' => $token])->assertOk()->assertJsonPath('data.status', 'superseded');
        $this->assertNotNull(DocumentVersionQr::where('version_guid', $version->guid)->firstOrFail()->revoked_at);
        $draft = DocumentVersion::where('guid', $revision->json('data.draft_version_guid'))->firstOrFail();
        $draft->assignments()->each(fn ($item) => $item->placeholders()->update(['needs_review' => false]));
        $this->storePreview($draft);
        $this->actingAs($owner)->postJson("/api/v1/versions/{$draft->guid}/publish")->assertOk();
        $this->assertNotSame($token, $this->token($draft));
        $this->assertDatabaseCount('document_version_qrs', 2);
        $this->actingAs($signer)->postJson(self::CLAIM, ['token' => $token])->assertOk()->assertJsonMissingPath('data.assignment_guid');
    }

    public function test_rejection_revokes_qr_and_stale_claim_cannot_sign(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->preparedDraft($owner, [$signer->email]);
        app(PublishService::class)->publish($owner, $version);
        $token = $this->token($version);
        $claim = $this->actingAs($signer)->postJson(self::CLAIM, ['token' => $token])->assertOk();
        $assignment = $claim->json('data.assignment_guid');
        $this->postJson("/api/v1/assignments/{$assignment}/reject", ['reason' => 'Tidak menyetujui isi.'])->assertOk();
        $this->postJson(self::RESOLVE, ['token' => $token])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertNotNull(DocumentVersionQr::where('version_guid', $version->guid)->firstOrFail()->revoked_at);
        $this->postJson("/api/v1/assignments/{$assignment}/signatures", [
            'asset_guid' => $this->asset($signer)->guid, 'confirmed' => true,
            'version_content_hash' => $version->content_hash, 'idempotency_key' => 'after-rejection',
        ])->assertConflict();
        $this->assertDatabaseCount('signatures', 0);
    }

    public function test_newer_version_blocks_claim_even_when_old_version_status_is_active(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->preparedDraft($owner, [$signer->email]);
        app(PublishService::class)->publish($owner, $version);
        $token = $this->token($version);
        DocumentVersion::factory()->create(['document_guid' => $version->document_guid, 'version_number' => 2]);
        $this->actingAs($signer)->postJson(self::CLAIM, ['token' => $token])->assertOk()
            ->assertJsonPath('data.status', 'superseded')->assertJsonMissingPath('data.assignment_guid');
        $this->assertNull($version->assignments()->firstOrFail()->user_guid);
    }

    public function test_verified_email_change_after_claim_is_rechecked_before_reading_and_signing(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->preparedDraft($owner, [$signer->email]);
        app(PublishService::class)->publish($owner, $version);
        $this->actingAs($signer)->postJson(self::CLAIM, ['token' => $this->token($version)])->assertOk()->assertJsonPath('data.status', 'active');
        $assignment = $version->assignments()->firstOrFail();
        $asset = $this->asset($signer);
        $signer->update(['email' => 'changed@example.test', 'email_verified_at' => now()]);
        $this->postJson("/api/v1/assignments/{$assignment->guid}/read-confirmation", [
            'confirmed' => true, 'version_content_hash' => $version->content_hash,
        ])->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [
            'confirmed' => true, 'version_content_hash' => $version->content_hash,
            'asset_guid' => $asset->guid, 'idempotency_key' => 'changed-email',
        ])->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseCount('signatures', 0);
        $this->assertDatabaseCount('read_confirmations', 0);
    }

    public function test_assignment_bound_to_a_different_account_cannot_be_reclaimed_by_matching_email(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $other = User::factory()->create();
        $version = $this->preparedDraft($owner, [$signer->email]);
        app(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->firstOrFail();
        $assignment->update(['user_guid' => $other->guid, 'claimed_at' => now()]);
        $this->actingAs($signer)->postJson(self::CLAIM, ['token' => $this->token($version)])->assertOk()
            ->assertJsonPath('data', ['status' => 'not_assigned']);
        $this->assertSame($other->guid, $assignment->fresh()->user_guid);
    }

    public function test_email_and_qr_claims_converge_on_same_assignment_without_duplicate_claim_events(): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $version = $this->preparedDraft($owner, [$first->email, $second->email]);
        $version->update(['signing_mode' => 'parallel']);
        app(PublishService::class)->publish($owner, $version);
        $assignments = $version->assignments()->orderBy('sequence_number')->get();
        $token = $this->token($version);
        foreach ([$first, $second] as $index => $signer) {
            $assignment = $assignments[$index];
            $emailClaim = fn () => $this->postJson('/api/v1/invitations/claim', ['token' => $this->invitationToken($assignment)])
                ->assertOk()->assertJsonPath('data.guid', $assignment->guid);
            $qrClaim = fn () => $this->postJson(self::CLAIM, ['token' => $token])->assertOk()
                ->assertJsonPath('data.assignment_guid', $assignment->guid)->assertJsonPath('data.status', 'active');
            $this->actingAs($signer);
            if ($index === 0) {
                $emailClaim();
                $qrClaim();
            } else {
                $qrClaim();
                $emailClaim();
            }
        }
        $this->assertSame(2, AuditEvent::where('action', 'invitation.claimed')->count());
        $this->assertSame($token, $this->token($version));
        $this->assertDatabaseCount('document_version_qrs', 1);
    }

    public function test_legacy_published_version_gets_one_stable_qr_when_owner_opens_metadata(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $version->update(['status' => VersionStatus::InProgress, 'published_at' => now()]);
        $version->assignments()->where('sequence_number', 1)->update(['status' => 'ready']);
        $this->assertDatabaseCount('document_version_qrs', 0);
        $url = "/api/v1/versions/{$version->guid}/signing-qr";
        $first = $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('data.status', 'active')->json('data.signing_url');
        $this->getJson($url)->assertOk()->assertJsonPath('data.signing_url', $first);
        $this->assertDatabaseCount('document_version_qrs', 1);
        $this->assertDatabaseCount('email_notifications', 0);
    }

    public function test_qr_authentication_validation_and_throttle_errors_keep_private_response_headers(): void
    {
        $authError = $this->postJson(self::CLAIM, ['token' => str_repeat('f', 64)])->assertUnauthorized();
        $validationError = $this->postJson(self::RESOLVE, ['token' => ['bad']])->assertUnprocessable();
        for ($index = 0; $index < 30; $index++) {
            $response = $this->postJson(self::RESOLVE, ['token' => str_repeat('f', 64)]);
            if ($response->status() === 429) {
                break;
            }
        }
        $response->assertTooManyRequests()->assertHeader('Retry-After');
        foreach ([$authError, $validationError, $response] as $privateResponse) {
            $privateResponse->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('no-store', $privateResponse->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $privateResponse->headers->get('Cache-Control'));
        }
    }
}
