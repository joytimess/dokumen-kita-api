<?php

namespace Tests\Feature;

use App\Events\DocumentStatusChanged;
use App\Jobs\DeliverRealtimeNotification;
use App\Jobs\FinalizeDocument;
use App\Models\RealtimeDelivery;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\PublishService;
use App\Services\RealtimeNotificationService;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class RealtimeNotificationTest extends TestCase
{
    use RefreshDatabase, SigningFixtures;

    public function test_private_status_events_are_minimal_and_persist_until_delivered(): void
    {
        Event::fake([DocumentStatusChanged::class]);
        $owner = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'first@example.test']);
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->where('sequence_number', 1)->firstOrFail();
        $this->actingAs($recipient)->postJson('/api/v1/invitations/claim', [
            'token' => $this->invitationToken($assignment),
        ])->assertOk();

        $this->assertSame(2, UserNotification::count());
        $signerNotification = UserNotification::where('user_guid', $recipient->guid)->firstOrFail();
        $delivery = RealtimeDelivery::where('notification_guid', $signerNotification->guid)->firstOrFail();
        $service = $this->app->make(RealtimeNotificationService::class);
        $service->notifyOwner($version, 'assignment.ready', $assignment);
        $service->deliver($delivery->guid);
        $service->deliver($delivery->guid);

        Event::assertDispatched(DocumentStatusChanged::class, fn (DocumentStatusChanged $event): bool => $event->recipientGuid === $recipient->guid
            && $event->broadcastOn()->name === 'private-users.'.$recipient->guid
            && $event->payload === [
                'event_type' => 'assignment.ready',
                'document_guid' => $version->document_guid,
                'version_guid' => $version->guid,
                'version_status' => 'in_progress',
                'assignment_guid' => $assignment->guid,
                'assignment_status' => 'ready',
                'notification_guid' => $signerNotification->guid,
            ]
        );
        $this->assertDatabaseHas('realtime_deliveries', ['guid' => $delivery->guid, 'status' => 'delivered', 'attempt_count' => 1]);
        $this->assertSame(1, UserNotification::where('user_guid', $owner->guid)->count());
        Event::assertDispatchedTimes(DocumentStatusChanged::class, 1);
        $this->assertArrayNotHasKey('token', $signerNotification->payload_json);
        $this->assertArrayNotHasKey('reason', $signerNotification->payload_json);
    }

    public function test_failed_realtime_delivery_retries_then_stops_after_three_attempts(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $delivery = RealtimeDelivery::firstOrFail();

        Broadcast::extend('test-failing', fn () => new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new RuntimeException('Pusher unavailable.');
            }
        });
        config(['broadcasting.connections.test-failing' => ['driver' => 'test-failing']]);
        Broadcast::setDefaultDriver('test-failing');
        Broadcast::purge('test-failing');

        $service = $this->app->make(RealtimeNotificationService::class);
        foreach ([1, 2, 3] as $attempt) {
            $service->deliver($delivery->guid);
            $this->assertSame($attempt, $delivery->fresh()->attempt_count);
            if ($attempt < 3) {
                $this->travel(6)->minutes();
                $this->assertSame('queued', $delivery->fresh()->status);
            }
        }

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->next_attempt_at);
        $this->assertNull($delivery->fresh()->delivered_at);
    }

    public function test_document_worker_dispatches_queued_realtime_outbox_rows(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $this->app->make(PublishService::class)->publish($owner, $this->preparedDraft($owner));
        $delivery = RealtimeDelivery::firstOrFail();

        Artisan::call('documents:process');

        Bus::assertDispatched(DeliverRealtimeNotification::class, fn (DeliverRealtimeNotification $job): bool => $job->deliveryGuid === $delivery->guid
        );
    }

    public function test_terminal_finalization_failure_queues_an_owner_refresh_event(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $version->update(['status' => 'finalizing']);
        $version->document->update(['status' => 'finalizing']);

        (new FinalizeDocument($version->guid))->failed(new RuntimeException('Renderer failed.'));

        $this->assertDatabaseHas('document_versions', ['guid' => $version->guid, 'finalization_error_code' => 'FINALIZATION_FAILED']);
        $this->assertDatabaseHas('user_notifications', [
            'user_guid' => $owner->guid, 'event_type' => 'version.final_pdf_failed', 'version_guid' => $version->guid,
        ]);
    }

    public function test_broadcast_auth_only_allows_the_matching_user_guid(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        config(['broadcasting.connections.pusher' => [
            'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test-app',
            'options' => ['cluster' => 'ap1', 'useTLS' => true],
        ]]);
        Broadcast::setDefaultDriver('pusher');
        Broadcast::purge('pusher');
        require base_path('routes/channels.php');

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => 'private-users.'.$owner->guid,
        ])->assertUnauthorized();
        $this->actingAs($owner)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => 'private-users.'.$owner->guid,
        ])->assertOk();
        $this->actingAs($outsider)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => 'private-users.'.$owner->guid,
        ])->assertForbidden();
    }
}
