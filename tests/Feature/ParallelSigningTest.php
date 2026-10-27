<?php

namespace Tests\Feature;

use App\Enums\VersionStatus;
use App\Jobs\FinalizeDocument;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\EmailNotification;
use App\Models\ReadConfirmation;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\DocumentWorkflowNotification;
use App\Services\DocumentEmailService;
use App\Services\DocumentPipelineService;
use App\Services\PdfDocumentRenderer;
use App\Services\PublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParallelSigningTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    public function test_default_and_draft_mode_switches_preserve_assignments_positions_and_order(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $this->assertSame('sequential', $version->fresh()->signing_mode);
        $this->assertSame('sequential', (new DocumentVersion)->signing_mode);
        $assignments = $version->assignments()->with('placeholders')->get();
        $signers = $assignments->map(fn ($assignment): array => [
            'email' => $assignment->recipient_email, 'sequence_number' => $assignment->sequence_number,
        ])->all();
        foreach (['parallel', null, 'sequential'] as $mode) {
            $payload = ['signers' => $signers];
            if ($mode !== null) {
                $payload['signing_mode'] = $mode;
            }
            $this->actingAs($owner)->putJson("/api/v1/versions/{$version->guid}/signers", $payload)->assertOk()
                ->assertJsonPath('data.signing_mode', $mode ?? 'parallel');
            $this->assertSame($assignments->pluck('guid')->all(), $version->assignments()->pluck('guid')->all());
            foreach ($assignments as $assignment) {
                $this->assertModelExists($assignment->placeholders->first());
            }
        }
    }

    public static function invalidModes(): array
    {
        return ['unknown' => ['hybrid'], 'null' => [null], 'array' => [[]], 'wrong case' => ['Parallel']];
    }

    #[DataProvider('invalidModes')]
    public function test_invalid_mode_is_rejected_without_changing_draft(mixed $mode): void
    {
        $owner = User::factory()->create();
        $version = $this->draft($owner);
        $this->actingAs($owner)->putJson("/api/v1/versions/{$version->guid}/signers", [
            'signing_mode' => $mode, 'signers' => [],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame('sequential', $version->fresh()->signing_mode);
        $this->assertDatabaseCount('signer_assignments', 0);
    }

    public function test_mode_configuration_is_owner_only_and_immutable_after_publish(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $payload = ['signing_mode' => 'parallel', 'signers' => []];
        $url = "/api/v1/versions/{$version->guid}/signers";
        $this->actingAs(User::factory()->create())->putJson($url, $payload)->assertNotFound();
        app(PublishService::class)->publish($owner, $version);
        $this->actingAs($owner)->putJson($url, $payload)->assertConflict();
        $version->update(['status' => VersionStatus::Draft]);
        $this->putJson($url, $payload)->assertConflict();
        $this->assertSame('sequential', $version->fresh()->signing_mode);
        $this->assertSame(2, $version->assignments()->count());
    }

    public function test_parallel_publish_activates_and_invites_every_signer_once(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, ['a@example.test', 'b@example.test', 'c@example.test']);
        $version->update(['signing_mode' => 'parallel']);
        app(PublishService::class)->publish($owner, $version);
        app(PublishService::class)->publish($owner, $version);
        $this->assertSame(['ready', 'ready', 'ready'], $version->assignments()->pluck('status')->all());
        $this->assertDatabaseCount('invitation_links', 3);
        $this->assertSame(3, EmailNotification::where('notification_type', 'invitation_ready')->count());
        $this->assertSame(1, AuditEvent::where('action', 'version.published')->count());
        foreach ($version->assignments()->get() as $assignment) {
            $notification = UserNotification::where('event_type', 'assignment.ready')->where('assignment_guid', $assignment->guid)->firstOrFail();
            $this->assertSame('Tanda tangan Anda diminta', $notification->payload_json['title']);
            $signer = User::factory()->create(['email' => $assignment->recipient_email]);
            $this->actingAs($signer)->postJson('/api/v1/invitations/claim', ['token' => $this->invitationToken($assignment)])
                ->assertOk()->assertJsonPath('data.status', 'ready');
        }
    }

    public function test_one_failed_parallel_invitation_does_not_block_others_and_delivery_retry_is_deduplicated(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $version->update(['signing_mode' => 'parallel']);
        app(PublishService::class)->publish($owner, $version);
        $emails = EmailNotification::where('notification_type', 'invitation_ready')->orderBy('id')->get();
        $sent = [];
        Event::listen(MessageSending::class, function (MessageSending $event) use ($emails): void {
            if ($event->message->getTo()[0]->getAddress() === $emails[0]->recipient_email) {
                throw new \RuntimeException('Transport unavailable');
            }
        });
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
            $sent[] = $event->message->getTo()[0]->getAddress();
        });
        foreach ($emails as $email) {
            app(DocumentEmailService::class)->deliver($email->guid);
            app(DocumentEmailService::class)->deliver($email->guid);
        }
        $this->assertSame('queued', $emails[0]->fresh()->status);
        $this->assertSame(1, $emails[0]->fresh()->attempt_count);
        $this->assertSame('sent', $emails[1]->fresh()->status);
        $this->assertSame(1, $emails[1]->fresh()->attempt_count);
        $this->assertSame([$emails[1]->recipient_email], $sent);
        $this->assertSame(['ready', 'ready'], $version->assignments()->pluck('status')->all());
    }

    public function test_parallel_email_explains_independent_signing(): void
    {
        Notification::fake();
        $version = $this->parallelVersion(User::factory()->create(), User::factory()->count(2)->create()->all());
        $email = EmailNotification::where('version_guid', $version->guid)->where('notification_type', 'invitation_ready')->firstOrFail();
        app(DocumentEmailService::class)->deliver($email->guid);
        Notification::assertSentOnDemand(DocumentWorkflowNotification::class, function ($notification, $channels, $notifiable): bool {
            return $notification->signingMode === 'parallel'
                && str_contains($notification->toMail($notifiable)->viewData['description'], 'tanpa menunggu');
        });
    }

    public function test_sequential_waiting_signer_cannot_bypass_turn_using_parallel_payload(): void
    {
        $signers = User::factory()->count(2)->create()->all();
        $version = $this->publishedVersion(User::factory()->create(), $signers);
        $assignment = $version->assignments()->where('sequence_number', 2)->firstOrFail();
        $payload = $this->signingPayload($signers[1], $version, $assignment->guid) + ['signing_mode' => 'parallel'];
        $this->actingAs($signers[1])->postJson("/api/v1/assignments/{$assignment->guid}/signatures", $payload)
            ->assertConflict()->assertJsonPath('error.code', 'ASSIGNMENT_NOT_READY');
        $this->assertSame('sequential', $version->fresh()->signing_mode);
        $this->assertDatabaseCount('signatures', 0);
    }

    public function test_parallel_signing_c_a_b_completes_only_after_all_signers_and_finalizes_once(): void
    {
        $owner = User::factory()->create();
        $signers = User::factory()->count(3)->create()->all();
        $version = $this->parallelVersion($owner, $signers);
        $assignments = $version->assignments()->get();
        foreach ([2, 0, 1] as $step => $index) {
            $assignment = $assignments[$index];
            $payload = $this->signingPayload($signers[$index], $version, $assignment->guid);
            $this->actingAs($signers[$index])->postJson("/api/v1/assignments/{$assignment->guid}/signatures", $payload)->assertCreated();
            $this->postJson("/api/v1/assignments/{$assignment->guid}/signatures", $payload)->assertOk();
            $this->postJson("/api/v1/assignments/{$assignment->guid}/signatures", array_replace($payload, ['idempotency_key' => 'different']))
                ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
            $this->assertSame($step === 2 ? VersionStatus::Finalizing : VersionStatus::InProgress, $version->fresh()->status);
            $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}")->assertOk()
                ->assertJsonPath('data.signing_progress', ['signed_count' => $step + 1, 'signer_count' => 3]);
        }
        $this->assertDatabaseCount('signatures', 3);
        $this->assertSame(0, AuditEvent::where('action', 'assignment.activated')->count());
        $this->assertSame(1, AuditEvent::where('action', 'version.finalizing')->count());
        $this->assertSame(1, EmailNotification::where('notification_type', 'version_finalizing')->count());
        config(['documents.processing.connection' => 'database', 'documents.processing.queue' => 'documents']);
        $this->artisan('documents:process')->assertSuccessful();
        $this->artisan('documents:process')->assertSuccessful();
        $finalJobs = DB::table('jobs')->get()->filter(fn ($job): bool => json_decode($job->payload, true)['displayName'] === FinalizeDocument::class);
        $this->assertCount(1, $finalJobs);
        app(DocumentPipelineService::class)->finalize($version->guid, app(PdfDocumentRenderer::class));
        $finalPath = $version->fresh()->final_pdf_path;
        app(DocumentPipelineService::class)->finalize($version->guid, app(PdfDocumentRenderer::class));
        $this->assertSame(VersionStatus::Completed, $version->fresh()->status);
        $this->assertSame($finalPath, $version->fresh()->final_pdf_path);
        $this->assertSame(1, AuditEvent::where('action', 'version.completed')->count());
        $this->assertSame(4, EmailNotification::where('notification_type', 'version_completed')->count());
    }

    public function test_parallel_signer_still_requires_assignment_identity_read_confirmation_and_snapshot(): void
    {
        $owner = User::factory()->create();
        $signers = User::factory()->count(2)->create()->all();
        $version = $this->parallelVersion($owner, $signers);
        $assignment = $version->assignments()->where('sequence_number', 2)->firstOrFail();
        $asset = $this->asset($signers[1]);
        $payload = ['confirmed' => true, 'asset_guid' => $asset->guid, 'version_content_hash' => $version->content_hash, 'idempotency_key' => 'identity'];
        $url = "/api/v1/assignments/{$assignment->guid}/signatures";
        $this->actingAs(User::factory()->create())->postJson($url, $payload)->assertNotFound();
        $this->actingAs($owner)->postJson($url, $payload)->assertForbidden();
        $this->actingAs($signers[0])->postJson($url, $payload)->assertForbidden();
        $this->actingAs($signers[1])->postJson($url, $payload)->assertConflict()->assertJsonPath('error.code', 'READ_CONFIRMATION_REQUIRED');
        ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
        $this->postJson($url, array_replace($payload, ['version_content_hash' => str_repeat('a', 64)]))
            ->assertConflict()->assertJsonPath('error.code', 'CONTENT_HASH_MISMATCH');
        $this->getJson("/api/v1/versions/{$version->guid}")->assertOk()->assertJsonPath('data.signing_mode', 'parallel')
            ->assertJsonMissingPath('data.signing_progress')->assertJsonCount(1, 'data.assignments');
        $this->assertDatabaseCount('signatures', 0);
    }

    public static function stoppingActions(): array
    {
        return ['revision' => ['revision-request', 'revision_requested'], 'rejection' => ['reject', 'rejected']];
    }

    #[DataProvider('stoppingActions')]
    public function test_parallel_revision_or_rejection_stops_all_remaining_signers(string $action, string $status): void
    {
        $owner = User::factory()->create();
        $signers = User::factory()->count(3)->create()->all();
        $version = $this->parallelVersion($owner, $signers);
        $assignments = $version->assignments()->get();
        $payload = $this->signingPayload($signers[0], $version, $assignments[0]->guid);
        $this->actingAs($signers[0])->postJson("/api/v1/assignments/{$assignments[0]->guid}/signatures", $payload)->assertCreated();
        $this->actingAs($signers[2])->postJson("/api/v1/assignments/{$assignments[2]->guid}/{$action}", ['reason' => 'Informasi perlu diperbaiki.'])->assertOk();
        $this->assertSame($status, $version->fresh()->status->value);
        $this->assertSame('signed', $assignments[0]->fresh()->status);
        $this->assertSame('cancelled', $assignments[1]->fresh()->status);
        $this->assertSame(0, DB::table('invitation_links')->whereNull('revoked_at')->count());
        $otherPayload = $this->signingPayload($signers[1], $version, $assignments[1]->guid);
        $this->actingAs($signers[1])->postJson("/api/v1/assignments/{$assignments[1]->guid}/signatures", $otherPayload)->assertConflict();
        $this->assertDatabaseCount('signatures', 1);
        if ($action === 'revision-request') {
            $draft = $version->document->versions()->orderByDesc('version_number')->firstOrFail();
            $this->assertSame('sequential', $draft->signing_mode);
            $this->assertSame(VersionStatus::Draft, $draft->status);
            $this->assertSame(['waiting', 'waiting', 'waiting'], $draft->assignments()->pluck('status')->all());
            $this->assertSame(0, ReadConfirmation::whereIn('assignment_guid', $draft->assignments()->pluck('guid'))->count());
            foreach ($draft->assignments()->with('placeholders')->get() as $assignment) {
                $this->assertTrue($assignment->placeholders->first()->needs_review);
                $this->assertNull($assignment->signature);
            }
            $this->actingAs($owner)->putJson("/api/v1/versions/{$draft->guid}/signers", [
                'signing_mode' => 'sequential',
                'signers' => $draft->assignments()->get()->map(fn ($assignment): array => [
                    'email' => $assignment->recipient_email, 'sequence_number' => $assignment->sequence_number,
                ])->all(),
            ])->assertOk()->assertJsonPath('data.signing_mode', 'sequential');
            $this->storePreview($draft);
            foreach ($draft->assignments()->get() as $assignment) {
                $this->putJson("/api/v1/assignments/{$assignment->guid}/placeholders", ['placeholders' => [$this->position()]])->assertOk();
            }
            $this->postJson("/api/v1/versions/{$draft->guid}/publish")->assertOk();
            $this->assertSame(['ready', 'waiting', 'waiting'], $draft->assignments()->pluck('status')->all());
            $this->assertSame('parallel', $version->fresh()->signing_mode);
        }
    }

    private function parallelVersion(User $owner, array $signers): DocumentVersion
    {
        $version = $this->preparedDraft($owner, array_map(fn (User $user): string => $user->email, $signers));
        $version->update(['signing_mode' => 'parallel']);
        app(PublishService::class)->publish($owner, $version);
        foreach ($signers as $index => $signer) {
            $version->assignments()->where('sequence_number', $index + 1)->update(['user_guid' => $signer->guid, 'claimed_at' => now()]);
        }

        return $version->fresh();
    }

    private function signingPayload(User $signer, DocumentVersion $version, string $assignmentGuid): array
    {
        ReadConfirmation::factory()->create(['assignment_guid' => $assignmentGuid, 'version_content_hash' => $version->content_hash]);

        return ['confirmed' => true, 'asset_guid' => $this->asset($signer)->guid, 'version_content_hash' => $version->content_hash, 'idempotency_key' => 'sign-'.$assignmentGuid];
    }
}
