<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\EmailNotification;
use App\Models\ReadConfirmation;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SignatureConcurrencyTest extends TestCase
{
    use SignatureFixtures;

    /** @return array<string, array{bool}> */
    public static function requests(): array
    {
        return ['same idempotency key' => [true], 'different keys' => [false]];
    }

    #[DataProvider('requests')]
    public function test_concurrent_submissions_commit_one_signature_and_one_turn_change_in_mysql(bool $sameKey): void
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
            $version = $this->publishedVersion($owner, [$first, $second]);
            $assignment = $version->assignments()->where('sequence_number', 1)->firstOrFail();
            $next = $version->assignments()->where('sequence_number', 2)->firstOrFail();
            ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
            $asset = $this->asset($first);
            $gate = Storage::disk('local')->path('race-'.bin2hex(random_bytes(8)).'.start');

            for ($index = 0; $index < 2; $index++) {
                $key = $sameKey ? 'parallel-request' : 'parallel-request-'.$index;
                $process = new Process([
                    PHP_BINARY, base_path('tests/Feature/SignatureSubmitWorker.php'), $database,
                    $first->guid, $assignment->guid, $asset->guid, $version->content_hash, $key,
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
            $this->assertSame(1, Signature::count());
            $this->assertGreaterThanOrEqual(100, max(array_column($results, 'lock_wait_ms')), 'One worker must wait for the document lock held by the other worker.');
            $this->assertSame('signed', $assignment->fresh()->status);
            $this->assertSame('ready', $next->fresh()->status);
            $this->assertSame(1, AuditEvent::where('action', 'assignment.signed')->count());
            $this->assertSame(1, AuditEvent::where('action', 'assignment.activated')->count());
            $this->assertSame(1, EmailNotification::where('assignment_guid', $next->guid)->where('notification_type', 'invitation_ready')->where('status', 'queued')->count());

            if ($sameKey) {
                $this->assertSame(Signature::firstOrFail()->guid, $results[0]['signature_guid']);
                $this->assertSame($results[0]['signature_guid'], $results[1]['signature_guid']);
            } else {
                $this->assertSame(1, count(array_filter($results, fn (array $result): bool => isset($result['signature_guid']))));
                $conflict = array_values(array_filter($results, fn (array $result): bool => isset($result['error'])))[0];
                $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict['error']);
                $this->assertSame(409, $conflict['status']);
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
