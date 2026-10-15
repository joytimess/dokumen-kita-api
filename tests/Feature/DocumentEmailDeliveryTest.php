<?php

namespace Tests\Feature;

use App\Jobs\DeliverDocumentEmail;
use App\Jobs\FinalizeDocument;
use App\Jobs\GenerateDocumentPreview;
use App\Models\EmailNotification;
use App\Models\User;
use App\Notifications\DocumentWorkflowNotification;
use App\Services\DocumentEmailService;
use App\Services\InvitationService;
use App\Services\PublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class DocumentEmailDeliveryTest extends TestCase
{
    use RefreshDatabase, SigningFixtures;

    public function test_delivery_is_once_and_clears_the_recoverable_invitation(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $email = EmailNotification::firstOrFail();
        $url = $email->payload['invitation_url'];
        $service = $this->app->make(DocumentEmailService::class);

        $service->deliver($email->guid);
        $service->deliver($email->guid);

        Notification::assertSentOnDemand(DocumentWorkflowNotification::class, fn ($notification, $channels, $notifiable): bool => $notification->invitationUrl === $url && $notifiable->routes['mail'] === $email->recipient_email);
        Notification::assertCount(1);
        $this->assertDatabaseHas('email_notifications', ['guid' => $email->guid, 'status' => 'sent', 'attempt_count' => 1, 'payload' => null]);
        $this->assertNotNull($email->fresh()->sent_at);
    }

    public function test_rotated_and_expired_invitations_are_never_delivered(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $email = EmailNotification::firstOrFail();
        $this->app->make(InvitationService::class)->resend($owner, $email->assignment);
        $this->app->make(DocumentEmailService::class)->deliver($email->guid);
        $new = EmailNotification::where('status', 'queued')->latest('id')->firstOrFail();
        $new->assignment->invitationLinks()->update(['expires_at' => now()->subMinute()]);
        $this->app->make(DocumentEmailService::class)->deliver($new->guid);

        Notification::assertNothingSent();
        $this->assertSame('cancelled', $new->fresh()->status);
    }

    public function test_transport_failure_is_bounded_and_does_not_leak_error_details(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $email = EmailNotification::firstOrFail();
        Event::listen(MessageSending::class, function (): never {
            throw new RuntimeException('Private transport detail');
        });
        $service = $this->app->make(DocumentEmailService::class);

        $service->deliver($email->guid);
        $this->assertSame(1, $email->fresh()->attempt_count);
        $service->deliver($email->guid);
        $this->assertSame(1, $email->fresh()->attempt_count);
        $this->travel(61)->seconds();
        $service->deliver($email->guid);
        $this->travel(301)->seconds();
        $service->deliver($email->guid);

        $this->assertDatabaseHas('email_notifications', [
            'guid' => $email->guid, 'status' => 'failed', 'attempt_count' => 3, 'last_error_code' => 'MAIL_DELIVERY_FAILED',
        ]);
        $this->assertNull($email->fresh()->sent_at);
    }

    public function test_message_distinguishes_waiting_ready_and_completed(): void
    {
        $waiting = (new DocumentWorkflowNotification('invitation_waiting', 'Agreement', 2, 'https://example.test/claim'))->toMail((object) []);
        $ready = (new DocumentWorkflowNotification('invitation_ready', 'Agreement', 2, 'https://example.test/claim'))->toMail((object) []);
        $completed = (new DocumentWorkflowNotification('version_completed', 'Agreement', 2, null))->toMail((object) []);

        $this->assertStringContainsString('belum aktif', implode(' ', $waiting->introLines));
        $this->assertStringContainsString('Giliran Anda', implode(' ', $ready->introLines));
        $this->assertStringContainsString('PDF final sudah tersedia', implode(' ', $completed->introLines));
        $this->assertNull($completed->actionUrl);
    }

    public function test_scheduler_dispatches_pending_work_but_not_failed_or_future_email(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $future = EmailNotification::latest('id')->firstOrFail();
        $future->update(['scheduled_at' => now()->addHour()]);
        $preview = $this->draft($owner, ['preview_status' => 'queued', 'preview_generation' => fake()->uuid()]);
        $final = $this->draft($owner, ['status' => 'finalizing']);
        $this->draft($owner, ['status' => 'finalizing', 'finalization_error_code' => 'FINALIZATION_FAILED']);

        $this->artisan('documents:process')->assertSuccessful();

        Queue::assertPushed(DeliverDocumentEmail::class, 1);
        Queue::assertPushed(GenerateDocumentPreview::class, fn ($job): bool => $job->versionGuid === $preview->guid);
        Queue::assertPushed(FinalizeDocument::class, fn ($job): bool => $job->versionGuid === $final->guid);
        Queue::assertCount(3);
    }
}
