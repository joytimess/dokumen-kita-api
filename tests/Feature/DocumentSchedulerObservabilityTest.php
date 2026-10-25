<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DocumentSchedulerObservabilityTest extends TestCase
{
    use RefreshDatabase, SigningFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'database', 'documents.processing.connection' => 'database']);
        Log::spy();
    }

    public function test_successful_dispatch_reports_output_and_releases_the_scheduler_mutex(): void
    {
        $event = $this->documentEvent();
        $scheduled = $this->isolatedEvent($event);
        $scheduled->shouldReceive('execute')->once()->andReturnUsing(function () use ($event): int {
            $this->assertTrue($event->mutex->exists($event));

            return Artisan::call('documents:process');
        });
        $scheduled->run($this->app);

        $this->assertSame(0, $scheduled->exitCode);
        $this->assertFalse($event->mutex->exists($event));
        $this->assertStringContainsString('Document work dispatch completed.', Artisan::output());
        Log::shouldHaveReceived('info')->with('Document work dispatch started.')->once();
        Log::shouldHaveReceived('info')->with('Document work dispatch completed.', Mockery::on(
            fn (array $context): bool => array_keys($context) === ['elapsed_ms', 'exit_code']
                && $context['elapsed_ms'] >= 0 && $context['exit_code'] === 0
        ))->once();
    }

    public function test_scheduler_logs_a_skipped_tick_without_releasing_the_other_execution_lock(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 01:06:00', 'UTC'));
        $event = $this->documentEvent();
        $this->assertTrue($event->mutex->create($event));
        $owner = DB::table('cache_locks')->first()->owner;

        $this->artisan('schedule:run')->expectsOutputToContain('No scheduled commands are ready to run.')->assertSuccessful();

        $this->assertTrue($event->mutex->exists($event));
        $this->assertSame($owner, DB::table('cache_locks')->first()->owner);
        Log::shouldHaveReceived('warning')->with('Document scheduler execution skipped.', [
            'reason' => 'filter_or_pause', 'mutex_ttl_minutes' => 1440,
        ])->once();
        Log::shouldNotHaveReceived('info');
        $event->mutex->forget($event);
    }

    public function test_a_lock_acquired_after_filters_pass_also_logs_a_skip(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 01:06:00', 'UTC'));
        $event = $this->documentEvent();
        // Simulate another process acquiring the mutex after the filter's existence check.
        $event->mutex = Mockery::mock(EventMutex::class);
        $event->mutex->shouldReceive('exists')->once()->with($event)->andReturnFalse();
        $event->mutex->shouldReceive('create')->once()->with($event)->andReturnFalse();
        $event->mutex->shouldNotReceive('forget');

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertTrue($event->skippedBecauseOverlapping);
        Log::shouldHaveReceived('warning')->with('Document scheduler execution skipped.', [
            'reason' => 'overlap', 'mutex_ttl_minutes' => 1440,
        ])->once();
        Log::shouldNotHaveReceived('info');
    }

    public function test_an_orphan_mutex_blocks_six_minute_ticks_until_its_actual_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 01:00:00', 'UTC'));
        $event = $this->documentEvent();
        $createdAt = now()->timestamp;
        $this->assertTrue($event->mutex->create($event));
        $this->assertSame($createdAt + 86400, DB::table('cache_locks')->first()->expiration);

        $this->travel(6)->minutes();
        $this->assertFalse($event->filtersPass($this->app));
        $this->travelTo(Carbon::createFromTimestampUTC($createdAt + 86399));
        $this->assertTrue($event->shouldSkipDueToOverlapping());
        $this->travel(1)->seconds();
        $this->assertFalse($event->shouldSkipDueToOverlapping());
        $event->finish($this->app, 0);
        $this->assertFalse($event->mutex->exists($event));
    }

    public function test_dispatch_failure_is_sanitized_and_returns_a_nonzero_exit_code(): void
    {
        $this->draft(User::factory()->create(), ['preview_status' => 'queued', 'preview_generation' => fake()->uuid()]);
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(
            new RuntimeException('password=secret; token=123456; private document content')
        );

        $this->artisan('documents:process')->expectsOutputToContain('Document work dispatch failed. See application logs.')
            ->assertExitCode(1);

        Log::shouldHaveReceived('error')->with('Document work dispatch failed.', Mockery::on(
            fn (array $context): bool => array_keys($context) === ['elapsed_ms', 'exit_code', 'error']
                && $context['elapsed_ms'] >= 0 && $context['exit_code'] === 1 && $context['error'] === 'RuntimeException'
        ))->once();
        Log::shouldNotHaveReceived('info', ['Document work dispatch completed.', Mockery::any()]);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_scheduler_failure_reports_exit_code_and_releases_the_mutex(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 01:06:00', 'UTC'));
        $event = $this->documentEvent();
        $scheduled = $this->isolatedEvent($event);
        $scheduled->shouldReceive('execute')->once()->andReturn(1);
        $schedule = Mockery::mock(Schedule::class);
        $schedule->shouldReceive('dueEvents')->once()->andReturn(collect([$scheduled]));
        $this->app->instance(Schedule::class, $schedule);

        // Laravel reports task failures but schedule:run itself can still exit successfully.
        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertFalse($event->mutex->exists($event));
        Log::shouldHaveReceived('error')->with('Document scheduler execution failed.', [
            'exit_code' => 1, 'error' => 'Exception',
        ])->once();
    }

    public function test_six_minute_ticks_preserve_document_cadence_and_midnight_pruning(): void
    {
        $events = $this->app->make(Schedule::class)->events();
        $this->assertCount(2, $events);
        $document = $this->documentEvent();
        $prune = collect($events)->first(fn ($event): bool => str_contains($event->command, 'auth:prune-password-challenges'));
        $this->assertSame('* * * * *', $document->expression);
        $this->assertTrue($document->withoutOverlapping);
        $this->assertSame(1440, $document->expiresAt);
        $this->assertFalse($document->isRepeatable());
        $this->assertSame('0 0 * * *', $prune->expression);
        $hits = [0, 0];
        $offsetPruneHits = 0;
        $midnight = Carbon::parse('2026-10-25 00:00:00', 'UTC');
        for ($minute = 0; $minute < 1440; $minute += 6) {
            $this->travelTo($midnight->copy()->addMinutes($minute));
            $hits[0] += (int) $document->isDue($this->app);
            $hits[1] += (int) $prune->isDue($this->app);
            $this->travel(1)->minutes();
            $this->assertTrue($document->isDue($this->app));
            $offsetPruneHits += (int) $prune->isDue($this->app);
        }

        $this->assertSame([240, 1], $hits);
        $this->assertSame(0, $offsetPruneHits);
    }

    public function test_real_scheduler_subprocess_output_and_database_failure_use_isolated_storage(): void
    {
        $directory = sys_get_temp_dir().'/dokumen-kita-scheduler-'.bin2hex(random_bytes(8));
        mkdir($directory);
        touch($database = $directory.'/test.sqlite');
        $taskOutput = $directory.'/task.log';
        $runner = $directory.'/run.php';
        file_put_contents($runner, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
Illuminate\Support\Carbon::setTestNow('2026-10-25 01:06:00 UTC');
foreach ($app->make(Illuminate\Console\Scheduling\Schedule::class)->events() as $event) {
    if (str_contains($event->command, 'documents:process')) {
        $event->appendOutputTo($argv[2]);
        if ($argv[3] === 'lock') {
            $event->mutex->create($event);
        } elseif ($argv[3] === 'failure') {
            $event->mutex->forget($event);
            Illuminate\Support\Facades\DB::statement('DROP TABLE document_versions');
        }
    }
}
exit($kernel->handle(new Symfony\Component\Console\Input\ArrayInput(['command' => 'schedule:run', '--no-ansi' => true])));
PHP);
        $environment = [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_CONFIG_CACHE' => $directory.'/config.php',
            'DB_URL' => '', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database,
            'DB_CACHE_CONNECTION' => 'sqlite', 'DB_CACHE_LOCK_CONNECTION' => 'sqlite',
            'DB_CACHE_TABLE' => 'cache', 'DB_CACHE_LOCK_TABLE' => 'cache_locks',
            'CACHE_STORE' => 'database', 'CACHE_PREFIX' => 'scheduler-probe-',
            'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database',
            'DOCUMENT_QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'info',
        ];

        try {
            $migrate = new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], base_path(), $environment);
            $migrate->mustRun();
            $connection = new \PDO('sqlite:'.$database);
            foreach (['success', 'lock', 'failure'] as $mode) {
                $process = new Process([PHP_BINARY, $runner, base_path(), $taskOutput, $mode], base_path(), $environment);
                $process->mustRun();
                $output = $process->getOutput().$process->getErrorOutput();
                $taskLog = file_get_contents($taskOutput);
                if ($mode === 'success') {
                    $this->assertStringContainsString('DONE', $output);
                    $this->assertStringContainsString('Document work dispatch started.', $taskLog);
                    $this->assertStringContainsString('Document work dispatch completed.', $taskLog);
                    $this->assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM cache_locks')->fetchColumn());
                } elseif ($mode === 'lock') {
                    $this->assertStringContainsString('Document scheduler execution skipped.', $output);
                    $this->assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM cache_locks')->fetchColumn());
                    $this->assertSame(1, substr_count($taskLog, 'Document work dispatch started.'));
                } else {
                    $this->assertStringContainsString('Document scheduler execution failed.', $output);
                    $this->assertStringContainsString('"exit_code":1', $output);
                    $this->assertStringContainsString('Document work dispatch failed. See application logs.', $taskLog);
                    $this->assertStringContainsString('"error":"QueryException"', $taskLog);
                    $this->assertStringNotContainsString('no such table', $taskLog);
                    $this->assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM cache_locks')->fetchColumn());
                }
            }
        } finally {
            $connection = null;
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function documentEvent(): ScheduledEvent
    {
        return collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'documents:process'));
    }

    private function isolatedEvent(ScheduledEvent $event): ScheduledEvent
    {
        return Mockery::mock(ScheduledEvent::class.'[execute]', [$event->mutex, $event->command, $event->timezone])
            ->makePartial()->shouldAllowMockingProtectedMethods()->withoutOverlapping($event->expiresAt);
    }
}
