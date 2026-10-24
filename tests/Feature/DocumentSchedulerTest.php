<?php

namespace Tests\Feature;

use App\Jobs\ConvertDocumentImport;
use App\Jobs\DeliverDocumentEmail;
use App\Jobs\DeliverRealtimeNotification;
use App\Jobs\FinalizeDocument;
use App\Jobs\GenerateDocumentPreview;
use App\Jobs\ProcessDocumentOcr;
use App\Models\DocumentOcrPage;
use App\Models\EmailNotification;
use App\Models\User;
use App\Notifications\EmailChangeVerification;
use App\Services\PdfDocumentRenderer;
use App\Services\RealtimeNotificationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class DocumentSchedulerTest extends TestCase
{
    use RefreshDatabase, SigningFixtures;

    public function test_document_schedule_runs_once_per_minute_and_rejects_an_existing_lock(): void
    {
        config(['cache.default' => 'database']);
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command, 'documents:process'));
        $this->assertCount(1, $events);
        $event = $events->first();

        $this->assertSame('* * * * *', $event->expression);
        $this->assertFalse($event->isRepeatable());
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(1440, $event->expiresAt);
        $this->assertTrue($event->mutex->create($event));
        try {
            $this->assertTrue($event->shouldSkipDueToOverlapping());
        } finally {
            $event->mutex->forget($event);
        }
        $this->assertFalse($event->mutex->exists($event));
    }

    public function test_repeated_dispatch_keeps_one_database_job_for_each_pending_work_item(): void
    {
        config(['documents.processing.connection' => 'database', 'documents.processing.queue' => 'documents']);
        $owner = User::factory()->create();
        $this->draft($owner, ['conversion_status' => 'queued', 'conversion_generation' => fake()->uuid()]);
        $preview = $this->draft($owner, ['preview_status' => 'queued', 'preview_generation' => fake()->uuid()]);
        $final = $this->draft($owner, ['status' => 'finalizing']);
        EmailNotification::create([
            'version_guid' => $final->guid,
            'recipient_email' => $owner->email,
            'notification_type' => 'version_finalizing',
            'deduplication_key' => 'finalizing:'.$final->guid,
            'status' => 'queued',
        ]);
        $this->app->make(RealtimeNotificationService::class)->notifyOwner($final, 'version.final_pdf_ready');
        DocumentOcrPage::create([
            'version_guid' => $preview->guid, 'pdf_hash' => str_repeat('a', 64),
            'page_number' => 1, 'source' => 'ocr', 'status' => 'queued',
        ]);

        $this->artisan('documents:process')->assertSuccessful();
        $this->artisan('documents:process')->assertSuccessful();

        $jobs = DB::table('jobs')->get();
        $this->assertCount(6, $jobs);
        $this->assertSame(['documents'], $jobs->pluck('queue')->unique()->values()->all());
        $this->assertEqualsCanonicalizing([
            ConvertDocumentImport::class, GenerateDocumentPreview::class, FinalizeDocument::class,
            DeliverDocumentEmail::class, DeliverRealtimeNotification::class, ProcessDocumentOcr::class,
        ], $jobs->map(fn ($job): string => json_decode($job->payload, true)['displayName'])->all());
        foreach ($jobs as $job) {
            $this->assertGreaterThan(json_decode($job->payload, true)['timeout'], config('queue.connections.database.retry_after'));
        }
        $this->assertGreaterThan(180, config('queue.connections.database.retry_after'));
    }

    public function test_bounded_worker_processes_account_mail_and_document_preview_from_database(): void
    {
        config([
            'queue.default' => 'database', 'queue.connections.database.queue' => 'default',
            'documents.processing.connection' => 'database', 'documents.processing.queue' => 'documents',
            'documents.disk' => 'local',
        ]);
        Storage::fake('local');
        Event::fake([MessageSent::class]);
        $preview = $this->draft(User::factory()->create(), [
            'preview_status' => 'queued', 'preview_generation' => fake()->uuid(),
        ]);
        $this->mock(PdfDocumentRenderer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('preview')->once()->andReturn(['bytes' => '%PDF-test-preview', 'pages' => 1]);
        });
        Notification::route('mail', 'queue-check@example.test')->notify(
            (new EmailChangeVerification('https://example.test/verify'))->beforeCommit()
        );
        $this->artisan('documents:process')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 2);

        $this->artisan('queue:work', [
            'connection' => 'database', '--queue' => 'default,documents',
            '--stop-when-empty' => true, '--max-time' => 50, '--sleep' => 1,
            '--tries' => 3, '--timeout' => 180,
        ])->assertSuccessful();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame('ready', $preview->fresh()->preview_status->value);
        Event::assertDispatchedTimes(MessageSent::class, 1);
    }

    public function test_interrupted_database_reservation_is_retried_after_retry_window(): void
    {
        $this->freezeTime();
        config(['documents.processing.connection' => 'database', 'documents.processing.queue' => 'documents', 'documents.disk' => 'local']);
        Storage::fake('local');
        $preview = $this->draft(User::factory()->create(), [
            'preview_status' => 'queued', 'preview_generation' => fake()->uuid(),
        ]);
        $this->mock(PdfDocumentRenderer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('preview')->once()->andReturn(['bytes' => '%PDF-recovered-preview', 'pages' => 1]);
        });
        $this->artisan('documents:process')->assertSuccessful();
        $queue = Queue::connection('database');
        $reserved = $queue->pop('documents');
        $this->assertNotNull($reserved);
        $this->assertSame(1, $reserved->attempts());
        $this->assertNull($queue->pop('documents'));
        $this->assertDatabaseCount('jobs', 1);

        $this->travel(config('queue.connections.database.retry_after') + 1)->seconds();
        $recovered = $queue->pop('documents');
        $this->assertNotNull($recovered);
        $this->assertSame($reserved->getJobId(), $recovered->getJobId());
        $this->assertSame(2, $recovered->attempts());
        $recovered->fire();

        $this->assertSame('ready', $preview->fresh()->preview_status->value);
        $this->assertDatabaseCount('jobs', 0);
    }
}
