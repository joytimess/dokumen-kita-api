<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\EmailNotification;
use App\Models\ReadConfirmation;
use App\Models\Signature;
use App\Models\User;
use App\Services\PublishService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ParallelSigningConcurrencyTest extends TestCase
{
    use SignatureFixtures;

    /** @return array<string, array{bool, bool, bool}> */
    public static function requests(): array
    {
        return [
            'different signers with one remaining' => [false, false, true],
            'simultaneous last signatures' => [false, false, false],
            'same assignment same key' => [true, true, true],
            'same assignment different keys' => [true, false, true],
        ];
    }

    #[DataProvider('requests')]
    public function test_parallel_submissions_preserve_signatures_and_finalize_exactly_once_in_mysql(bool $sameAssignment, bool $sameKey, bool $pendingSigner): void
    {
        if (getenv('RUN_MYSQL_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped('Opt in with RUN_MYSQL_CONCURRENCY_TESTS=1; creates and removes an isolated MySQL test database.');
        }

        $database = 'dk_be05_test_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/\\Adk_be05_test_[a-f0-9]{16}\\z/', $database);
        $mysql = config('database.connections.mysql');
        $mysql['url'] = null;
        config([
            'database.connections.be05-admin' => array_replace($mysql, ['database' => null]),
            'database.connections.be05-mysql' => array_replace($mysql, ['database' => $database]),
        ]);
        $previous = DB::getDefaultConnection();
        $created = false;
        $processes = [];
        $gate = null;

        try {
            DB::connection('be05-admin')->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.default' => 'be05-mysql']);
            DB::setDefaultConnection('be05-mysql');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'be05-mysql', '--force' => true, '--no-interaction' => true]));

            $owner = User::factory()->create();
            $first = User::factory()->create();
            $second = User::factory()->create();
            $signers = [$first, $second];
            if ($pendingSigner) {
                $signers[] = User::factory()->create();
            }
            $version = $this->preparedDraft($owner, array_map(fn (User $user): string => $user->email, $signers));
            $version->update(['signing_mode' => 'parallel']);
            app(PublishService::class)->publish($owner, $version);
            foreach ($signers as $index => $signer) {
                $version->assignments()->where('sequence_number', $index + 1)->update(['user_guid' => $signer->guid, 'claimed_at' => now()]);
            }
            $assignment = $version->assignments()->where('sequence_number', 1)->firstOrFail();
            $next = $version->assignments()->where('sequence_number', 2)->firstOrFail();
            ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
            ReadConfirmation::factory()->create(['assignment_guid' => $next->guid, 'version_content_hash' => $version->content_hash]);
            $assets = [$this->asset($first), $this->asset($second)];
            $gate = Storage::disk('local')->path('race-'.bin2hex(random_bytes(8)).'.start');

            for ($index = 0; $index < 2; $index++) {
                $key = $sameKey ? 'parallel-request' : 'parallel-request-'.$index;
                $process = new Process([
                    PHP_BINARY, base_path('tests/Feature/SignatureSubmitWorker.php'), $database,
                    $sameAssignment || $index === 0 ? $first->guid : $second->guid,
                    $sameAssignment || $index === 0 ? $assignment->guid : $next->guid,
                    $assets[$sameAssignment ? 0 : $index]->guid, $version->content_hash, $key,
                    Storage::disk('local')->path(''),
                    $gate,
                ], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
                    'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => false, 'CACHE_STORE' => 'array',
                ], timeout: 20);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 10;
            do {
                $bothReady = true;
                foreach ($processes as $process) {
                    $process->isRunning();
                    $bothReady = $bothReady && str_contains($process->getOutput(), "ready\n");
                }
                if (! $bothReady) {
                    usleep(10_000);
                }
            } while (! $bothReady && microtime(true) < $deadline);
            $this->assertTrue($bothReady, 'Both isolated workers must reach the submission barrier.');

            file_put_contents($gate, 'go');
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }
            $expectedCount = $sameAssignment ? 1 : 2;
            $this->assertSame($expectedCount, Signature::count());
            $this->assertGreaterThanOrEqual(100, max(array_column($results, 'lock_wait_ms')), 'Workers must contend for the document lock.');
            $this->assertSame('signed', $assignment->fresh()->status);
            $this->assertSame($sameAssignment ? 'ready' : 'signed', $next->fresh()->status);
            $this->assertSame($expectedCount, AuditEvent::where('action', 'assignment.signed')->count());
            $this->assertSame($expectedCount, EmailNotification::where('notification_type', 'assignment_signed')->count());
            $this->assertSame(0, AuditEvent::where('action', 'assignment.activated')->count());
            $finished = ! $sameAssignment && ! $pendingSigner;
            $this->assertSame($finished ? 'finalizing' : 'in_progress', $version->fresh()->status->value);
            $this->assertSame($finished ? 1 : 0, AuditEvent::where('action', 'version.finalizing')->count());
            $this->assertSame($finished ? 1 : 0, EmailNotification::where('notification_type', 'version_finalizing')->count());
            if (! $sameAssignment) {
                $this->assertCount(2, array_unique(array_column($results, 'signature_guid')));
                $this->assertSame(2, Signature::pluck('assignment_guid')->unique()->count());
            } elseif ($sameKey) {
                $this->assertSame($results[0]['signature_guid'], $results[1]['signature_guid']);
            } else {
                $conflicts = array_values(array_filter($results, fn (array $result): bool => isset($result['error'])));
                $this->assertCount(1, $conflicts);
                $this->assertSame('IDEMPOTENCY_CONFLICT', $conflicts[0]['error']);
                $this->assertSame(409, $conflicts[0]['status']);
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if ($gate !== null && is_file($gate)) {
                unlink($gate);
            }
            DB::disconnect('be05-mysql');
            config(['database.default' => $previous]);
            DB::setDefaultConnection($previous);
            if ($created && preg_match('/\\Adk_be05_test_[a-f0-9]{16}\\z/', $database) === 1) {
                DB::connection('be05-admin')->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('be05-mysql');
            DB::purge('be05-admin');
        }
    }
}
