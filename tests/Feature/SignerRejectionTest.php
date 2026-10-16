<?php

namespace Tests\Feature;

use App\Enums\VersionStatus;
use App\Models\EmailNotification;
use App\Models\User;
use App\Notifications\DocumentWorkflowNotification;
use App\Services\DocumentEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SignerRejectionTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    public function test_ready_signer_rejects_version_and_stops_the_next_turn(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $version = $this->publishedVersion($owner, [$first, $second]);
        $assignments = $version->assignments()->get();

        $this->actingAs($first)->postJson("/api/v1/assignments/{$assignments[0]->guid}/reject", [
            'reason' => 'Informasi pada dokumen keliru.',
        ])->assertOk()->assertJsonPath('data.assignment_status', 'rejected')
            ->assertJsonPath('data.version_status', 'rejected')
            ->assertJsonPath('data.reason', 'Informasi pada dokumen keliru.');

        $this->assertSame(VersionStatus::Rejected, $version->fresh()->status);
        $this->assertSame(VersionStatus::Rejected, $version->document->fresh()->status);
        $this->assertSame('cancelled', $assignments[1]->fresh()->status);
        $this->assertSame('rejected', $assignments[0]->response()->firstOrFail()->response_type);
        $this->assertSame('Informasi pada dokumen keliru.', $assignments[0]->response->reason);
        $this->assertDatabaseHas('audit_events', ['action' => 'version.rejected', 'actor_guid' => $first->guid]);
        $this->assertDatabaseHas('email_notifications', ['notification_type' => 'version_rejected', 'status' => 'queued']);
        $email = EmailNotification::where('notification_type', 'version_rejected')->firstOrFail();
        $this->app->make(DocumentEmailService::class)->deliver($email->guid);
        Notification::assertSentOnDemand(DocumentWorkflowNotification::class, fn ($notification): bool => $notification->type === 'version_rejected'
            && $notification->reason === 'Informasi pada dokumen keliru.');

        $this->actingAs($first)->postJson("/api/v1/assignments/{$assignments[0]->guid}/reject", ['reason' => 'Duplikat'])
            ->assertConflict();
        $this->assertSame(1, $assignments[0]->response()->count());
        $this->assertSame(1, EmailNotification::where('notification_type', 'version_rejected')->count());
    }

    public function test_waiting_or_unrelated_users_cannot_reject(): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $version = $this->publishedVersion($owner, [$first, $second]);
        $waiting = $version->assignments()->where('sequence_number', 2)->firstOrFail();
        $url = "/api/v1/assignments/{$waiting->guid}/reject";

        $this->actingAs($second)->postJson($url, ['reason' => 'Mohon koreksi'])->assertConflict();
        $this->actingAs(User::factory()->create())->postJson($url, ['reason' => 'Mohon koreksi'])->assertNotFound();
        $this->assertSame(VersionStatus::InProgress, $version->fresh()->status);
        $this->assertDatabaseCount('recipient_responses', 0);
    }
}
