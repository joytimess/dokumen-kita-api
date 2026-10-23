<?php

namespace Tests\Feature;

use App\Jobs\DeliverDocumentEmail;
use App\Jobs\FinalizeDocument;
use App\Models\DocumentVersion;
use App\Models\EmailNotification;
use App\Models\ReadConfirmation;
use App\Models\SignerAssignment;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\DocumentWorkflowNotification;
use App\Services\AssignmentRejectionService;
use App\Services\DocumentEmailService;
use App\Services\DocumentPipelineService;
use App\Services\PdfDocumentRenderer;
use App\Services\RevisionService;
use App\Services\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkflowEmailNotificationTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    public static function stopActions(): array
    {
        return [
            'revision' => ['revision-request', 'revision_requested', 'version.revision_requested'],
            'rejection' => ['reject', 'version_rejected', 'version.rejected'],
        ];
    }

    #[DataProvider('stopActions')]
    public function test_stop_action_queues_one_owner_email_and_blocks_signing_and_pending_invitation_jobs(string $action, string $type, string $event): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $next = User::factory()->create();
        $version = $this->publishedVersion($owner, [$signer, $next]);
        $assignments = $version->assignments()->orderBy('sequence_number')->get();
        $invitation = EmailNotification::where('notification_type', 'invitation_ready')->firstOrFail();
        $pendingJob = new DeliverDocumentEmail($invitation->guid);
        $url = "/api/v1/assignments/{$assignments[0]->guid}/{$action}";
        $this->actingAs($signer)->postJson($url, ['reason' => '  Isi perlu diperbaiki.  '])->assertOk();
        $this->postJson($url, ['reason' => 'Duplikat'])->assertConflict();
        $this->assertSame('Isi perlu diperbaiki.', $assignments[0]->response()->firstOrFail()->reason);
        $this->assertSame(1, EmailNotification::where('notification_type', $type)->count());
        $email = EmailNotification::where('notification_type', $type)->firstOrFail();
        $this->assertSame($owner->email, $email->recipient_email);
        $this->assertSame(1, UserNotification::where('user_guid', $owner->guid)->where('event_type', $event)->count());
        $notification = UserNotification::where('user_guid', $owner->guid)->where('event_type', $event)->firstOrFail();
        $this->actingAs($owner)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread_count', 1);
        $read = $this->patchJson('/api/v1/notifications/'.$notification->guid.'/read')->assertOk()
            ->assertJsonPath('meta.unread_count', 0)->json('data.read_at');
        $this->patchJson('/api/v1/notifications/'.$notification->guid.'/read')->assertOk()->assertJsonPath('data.read_at', $read);
        $this->actingAs($signer)->patchJson('/api/v1/notifications/'.$notification->guid.'/read')->assertNotFound();
        $this->assertSame(0, $assignments[0]->invitationLinks()->whereNull('revoked_at')->count());
        $this->assertSame(0, $assignments[1]->invitationLinks()->count());
        $this->postJson("/api/v1/assignments/{$assignments[0]->guid}/signatures", [
            'asset_guid' => $this->asset($signer)->guid, 'confirmed' => true,
            'version_content_hash' => $version->content_hash, 'idempotency_key' => 'stopped',
        ])->assertConflict();
        $this->actingAs($next)->postJson("/api/v1/assignments/{$assignments[1]->guid}/read-confirmation", [
            'confirmed' => true, 'version_content_hash' => $version->content_hash,
        ])->assertConflict();
        $this->assertDatabaseMissing('email_notifications', ['notification_type' => 'assignment_signed']);
        $service = app(DocumentEmailService::class);
        $pendingJob->handle($service);
        Notification::assertNothingSent();
        $service->deliver($email->guid);
        $service->deliver($email->guid);
        Notification::assertSentOnDemand(DocumentWorkflowNotification::class, fn ($notification, $channels, $notifiable): bool => $notification->type === $type
            && $notifiable->routes['mail'] === $owner->email && $notification->reason === 'Isi perlu diperbaiki.'
            && $notification->requester === $signer->name.' <'.$signer->email.'>'
            && $notification->invitationUrl === rtrim(config('app.frontend_url'), '/').'/documents/'.$version->document_guid);
        Notification::assertCount(1);
        $this->assertSame('cancelled', $invitation->fresh()->status);
        if ($action === 'reject') {
            $this->assertSame(1, $version->document->versions()->count());
            $this->assertSame('rejected', $version->document->fresh()->status->value);
            $this->actingAs($owner)->postJson("/api/v1/versions/{$version->guid}/publish")->assertConflict();
        }
    }

    public function test_each_signer_including_last_notifies_owner_and_finalization_emails_unique_recipients(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $version = $this->publishedVersion($owner, [$first, $second, $owner]);
        $version->assignments()->where('user_guid', $owner->guid)->update(['recipient_email' => ' OWNER@EXAMPLE.TEST ']);
        foreach ([$first, $second, $owner] as $index => $signer) {
            $assignment = $version->assignments()->where('sequence_number', $index + 1)->firstOrFail();
            $this->completeSigner($version, $assignment, $signer);
            $email = EmailNotification::where('notification_type', 'assignment_signed')->where('assignment_guid', $assignment->guid)->firstOrFail();
            $this->assertSame($index + 1, $email->payload['signed_count']);
            $this->assertSame(3, $email->payload['signer_count']);
            $this->assertSame($owner->email, $email->recipient_email);
            $this->assertSame($signer->name.' <'.$signer->email.'>', $email->payload['requester']);
            $this->assertNotEmpty($email->payload['completed_at']);
            if ($index < 2) {
                $next = $version->assignments()->where('sequence_number', $index + 2)->firstOrFail();
                $this->assertSame('ready', $next->status);
                $this->assertSame(1, EmailNotification::where('assignment_guid', $next->guid)->where('notification_type', 'invitation_ready')->where('status', 'queued')->count());
            }
        }
        $this->assertSame(3, EmailNotification::where('notification_type', 'assignment_signed')->count());
        $this->assertSame(3, UserNotification::where('event_type', 'assignment.signed')->where('user_guid', $owner->guid)->count());
        $this->assertDatabaseMissing('email_notifications', ['notification_type' => 'version_completed']);
        $pipeline = app(DocumentPipelineService::class);
        $pipeline->finalize($version->guid, app(PdfDocumentRenderer::class));
        $pipeline->finalize($version->guid, app(PdfDocumentRenderer::class));
        $version->refresh();
        $this->assertSame('completed', $version->status->value);
        $this->assertTrue(Storage::disk('local')->exists($version->final_pdf_path));
        $this->assertEqualsCanonicalizing([$owner->email, $first->email, $second->email], EmailNotification::where('notification_type', 'version_completed')->pluck('recipient_email')->all());
        $this->assertSame(3, UserNotification::where('event_type', 'version.final_pdf_ready')->count());
        foreach (EmailNotification::whereIn('notification_type', ['assignment_signed', 'version_completed'])->get() as $email) {
            (new DeliverDocumentEmail($email->guid))->handle(app(DocumentEmailService::class));
            (new DeliverDocumentEmail($email->guid))->handle(app(DocumentEmailService::class));
        }
        Notification::assertCount(6);
        Notification::assertSentOnDemand(DocumentWorkflowNotification::class, fn ($notification): bool => $notification->type === 'version_completed'
            && $notification->invitationUrl === rtrim(config('app.frontend_url'), '/')."/documents/{$version->document_guid}/versions/{$version->guid}/view"
            && $notification->completedAt !== null);
        foreach ([$owner, $first, $second] as $user) {
            $this->actingAs($user)->get("/api/v1/versions/{$version->guid}/pdf")->assertOk();
        }
        $this->actingAs(User::factory()->create())->getJson("/api/v1/versions/{$version->guid}/pdf")->assertNotFound();
        $last = $version->assignments()->where('user_guid', $owner->guid)->firstOrFail();
        foreach (['revision-request', 'reject'] as $action) {
            $this->actingAs($owner)->postJson("/api/v1/assignments/{$last->guid}/{$action}", ['reason' => 'Terlambat'])->assertConflict();
        }
        $this->assertDatabaseCount('recipient_responses', 0);
    }

    public static function finalizationFailures(): array
    {
        return ['renderer exception' => [false], 'invalid stored PDF' => [true]];
    }

    #[DataProvider('finalizationFailures')]
    public function test_failed_finalization_keeps_signer_email_and_only_successful_retry_creates_completion_events(bool $invalidPdf): void
    {
        Notification::fake();
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $this->completeSigner($version, $version->assignments()->firstOrFail(), $signer);
        $renderer = Mockery::mock(PdfDocumentRenderer::class);
        if ($invalidPdf) {
            $renderer->shouldReceive('finalize')->once()->andReturn('invalid PDF');
        } else {
            $renderer->shouldReceive('finalize')->once()->andThrow(new RuntimeException('Renderer unavailable'));
        }
        try {
            app(DocumentPipelineService::class)->finalize($version->guid, $renderer);
            $this->fail('Expected finalization failure');
        } catch (RuntimeException $exception) {
            (new FinalizeDocument($version->guid))->failed($exception);
        }
        $this->assertSame('finalizing', $version->fresh()->status->value);
        $this->assertNull($version->fresh()->final_pdf_path);
        $this->assertDatabaseMissing('email_notifications', ['notification_type' => 'version_completed']);
        $this->assertDatabaseMissing('user_notifications', ['event_type' => 'version.final_pdf_ready']);
        app(DocumentEmailService::class)->deliver(EmailNotification::where('notification_type', 'assignment_signed')->firstOrFail()->guid);
        Notification::assertCount(1);
        app(DocumentPipelineService::class)->retryFinalization($version->document->owner, $version);
        app(DocumentPipelineService::class)->finalize($version->guid, app(PdfDocumentRenderer::class));
        $this->assertSame(2, EmailNotification::where('notification_type', 'version_completed')->count());
    }

    public function test_missing_final_pdf_defers_completion_delivery_until_file_is_available(): void
    {
        Notification::fake();
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $this->completeSigner($version, $version->assignments()->firstOrFail(), $signer);
        app(DocumentPipelineService::class)->finalize($version->guid, app(PdfDocumentRenderer::class));
        $version->refresh();
        $disk = Storage::disk('local');
        $bytes = $disk->get($version->final_pdf_path);
        $disk->delete($version->final_pdf_path);
        $email = EmailNotification::where('notification_type', 'version_completed')->firstOrFail();
        app(DocumentEmailService::class)->deliver($email->guid);
        Notification::assertNothingSent();
        $this->assertSame('queued', $email->fresh()->status);
        $disk->put($version->final_pdf_path, $bytes);
        $this->travel(61)->seconds();
        app(DocumentEmailService::class)->deliver($email->guid);
        Notification::assertCount(1);
        $this->assertSame('sent', $email->fresh()->status);
    }

    public function test_smtp_failure_does_not_undo_signature_and_retry_survives_workflow_rejection(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$first, $second]);
        $assignment = $version->assignments()->where('sequence_number', 1)->firstOrFail();
        $this->completeSigner($version, $assignment, $first);
        $email = EmailNotification::where('notification_type', 'assignment_signed')->firstOrFail();
        Event::listen(MessageSending::class, function (): never {
            throw new RuntimeException('SMTP unavailable');
        });
        app(DocumentEmailService::class)->deliver($email->guid);
        $this->assertSame('queued', $email->fresh()->status);
        $this->assertSame('MAIL_DELIVERY_FAILED', $email->fresh()->last_error_code);
        $this->assertSame(1, $assignment->signature()->count());
        $next = $version->assignments()->where('sequence_number', 2)->firstOrFail();
        $this->assertSame('ready', $next->status);
        $this->actingAs($second)->postJson("/api/v1/assignments/{$next->guid}/reject", ['reason' => 'Mohon dihentikan'])->assertOk();
        Event::forget(MessageSending::class);
        $this->travel(61)->seconds();
        app(DocumentEmailService::class)->deliver($email->guid);
        app(DocumentEmailService::class)->deliver($email->guid);
        $this->assertSame('sent', $email->fresh()->status);
        $this->assertSame(2, $email->fresh()->attempt_count);
        $this->assertSame(1, $assignment->signature()->count());
        $this->assertSame(1, $version->document->versions()->count());
    }

    public function test_revision_republish_restarts_at_first_signer_without_transferring_signatures(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $version = $this->publishedVersion($owner, [$first, $second]);
        $assignments = $version->assignments()->orderBy('sequence_number')->get();
        $this->completeSigner($version, $assignments[0], $first);
        $token = $this->invitationToken($assignments[1]);
        $response = $this->actingAs($second)->postJson("/api/v1/assignments/{$assignments[1]->guid}/revision-request", ['reason' => 'Perbaiki dokumen'])->assertOk();
        $draft = DocumentVersion::where('guid', $response->json('data.draft_version_guid'))->firstOrFail();
        $this->storePreview($draft);
        foreach ($draft->assignments()->get() as $assignment) {
            $assignment->placeholders()->update(['needs_review' => false]);
        }
        $this->actingAs($second)->postJson("/api/v1/versions/{$draft->guid}/publish")->assertNotFound();
        $this->actingAs($owner)->postJson("/api/v1/versions/{$draft->guid}/publish")->assertOk();
        $this->assertSame(1, $assignments[0]->signature()->count());
        $this->assertSame(0, $draft->assignments()->whereHas('signature')->count());
        $this->assertSame(0, $draft->assignments()->whereHas('readConfirmation')->count());
        $this->assertSame(['ready', 'waiting'], $draft->assignments()->orderBy('sequence_number')->pluck('status')->all());
        $this->assertSame(1, EmailNotification::where('version_guid', $draft->guid)->where('notification_type', 'invitation_ready')->count());
        $this->actingAs($second)->postJson('/api/v1/invitations/claim', ['token' => $token])->assertUnprocessable();
        $this->postJson("/api/v1/assignments/{$assignments[1]->guid}/signatures", [
            'asset_guid' => $this->asset($second)->guid, 'confirmed' => true,
            'version_content_hash' => $version->content_hash, 'idempotency_key' => 'old-version',
        ])->assertConflict();
        foreach (EmailNotification::whereIn('notification_type', ['revision_requested', 'assignment_signed'])->get() as $email) {
            app(DocumentEmailService::class)->deliver($email->guid);
            $this->assertSame('sent', $email->fresh()->status);
        }
        Notification::assertCount(2);
    }

    public static function rollbackActions(): array
    {
        return ['sign' => ['sign'], 'revision' => ['revision'], 'reject' => ['reject']];
    }

    #[DataProvider('rollbackActions')]
    public function test_rollback_removes_workflow_events_without_dispatching_email(string $action): void
    {
        Notification::fake();
        Bus::fake();
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
        $asset = $this->asset($signer);
        $emailCount = EmailNotification::count();
        $notificationCount = UserNotification::count();
        try {
            DB::transaction(function () use ($action, $signer, $assignment, $asset, $version): void {
                match ($action) {
                    'sign' => app(SignatureService::class)->sign($signer, $assignment, $asset->guid, $version->content_hash, 'rollback'),
                    'revision' => app(RevisionService::class)->request($signer, $assignment, 'Perbaiki dokumen'),
                    'reject' => app(AssignmentRejectionService::class)->reject($signer, $assignment, 'Hentikan dokumen'),
                };
                throw new RuntimeException('Rollback');
            });
            $this->fail('Expected rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback', $exception->getMessage());
        }
        $this->assertSame($emailCount, EmailNotification::count());
        $this->assertSame($notificationCount, UserNotification::count());
        $this->assertDatabaseCount('signatures', 0);
        $this->assertDatabaseCount('recipient_responses', 0);
        $this->assertSame('ready', $assignment->fresh()->status);
        $this->assertSame('in_progress', $version->fresh()->status->value);
        Bus::assertNotDispatched(DeliverDocumentEmail::class);
        Notification::assertNothingSent();
    }

    private function completeSigner(DocumentVersion $version, SignerAssignment $assignment, User $signer): void
    {
        ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
        $payload = [
            'asset_guid' => $this->asset($signer)->guid, 'confirmed' => true,
            'version_content_hash' => $version->content_hash, 'idempotency_key' => 'sign-'.$assignment->guid,
        ];
        $url = "/api/v1/assignments/{$assignment->guid}/signatures";
        $this->actingAs($signer)->postJson($url, $payload)->assertCreated();
        $this->postJson($url, $payload)->assertOk();
    }

    public static function workflowTemplates(): array
    {
        return [
            'revision' => ['revision_requested', 'Permintaan Revisi Dokumen', 'Lihat Dokumen'],
            'rejected' => ['version_rejected', 'Dokumen Ditolak', 'Lihat Dokumen'],
            'signed' => ['assignment_signed', 'Tanda Tangan Baru', 'Lihat Progres Dokumen'],
            'completed' => ['version_completed', 'Dokumen Selesai Ditandatangani', 'Lihat / Unduh Dokumen'],
        ];
    }

    #[DataProvider('workflowTemplates')]
    public function test_workflow_mail_uses_branding_escaped_content_authenticated_cta_and_no_attachment(string $type, string $subject, string $cta): void
    {
        $title = '<img src=x onerror=alert(1)> & "Kontrak"';
        $reason = '<script>alert(1)</script> & perbaiki';
        $signer = '<b>Penandatangan</b>';
        $url = rtrim(config('app.frontend_url'), '/').'/documents/'.fake()->uuid();
        $notification = new DocumentWorkflowNotification($type, $title, 2, $url, $reason, $signer, '2026-10-23T10:00:00+00:00', 2, 3);
        $mail = $notification->toMail((object) []);
        $html = (string) $mail->render();
        $this->assertSame($subject.' — '.$title, $mail->subject);
        foreach ([$title, $reason, $signer] as $value) {
            $this->assertStringContainsString(e($value), $html);
            $this->assertStringNotContainsString($value, $html);
        }
        $this->assertStringContainsString('2 dari 3 penandatangan', $html);
        $this->assertStringContainsString('2026-10-23T10:00:00+00:00', $html);
        $this->assertStringContainsString($cta, $html);
        $this->assertStringContainsString('Tim DokumenKita', $html);
        $this->assertSame(2, substr_count($html, 'href="'.e($url).'"'));
        $messages = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$messages): void {
            $messages[] = $event->message;
        });
        Notification::route('mail', 'owner@example.test')->notify($notification);
        $this->assertCount(1, $messages);
        $this->assertCount(0, $messages[0]->getAttachments());
        $this->assertStringContainsString($url, $messages[0]->getTextBody());
        $this->assertStringContainsString($title, $messages[0]->getTextBody());
    }

    public static function invalidReasons(): array
    {
        return ['null' => [null], 'empty' => [''], 'whitespace' => [" \t\n "], 'too short' => ['  ab  ']];
    }

    #[DataProvider('invalidReasons')]
    public function test_revision_and_rejection_validate_trimmed_reason_before_creating_events(?string $reason): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        foreach (['revision-request', 'reject'] as $action) {
            $this->actingAs($signer)->postJson("/api/v1/assignments/{$assignment->guid}/{$action}", ['reason' => $reason])
                ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['reason']]]);
        }
        $this->assertDatabaseCount('recipient_responses', 0);
        $this->assertDatabaseMissing('email_notifications', ['notification_type' => 'revision_requested']);
        $this->assertDatabaseMissing('email_notifications', ['notification_type' => 'version_rejected']);
        $this->assertSame('in_progress', $version->fresh()->status->value);
    }
}
