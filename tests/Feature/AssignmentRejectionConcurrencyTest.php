<?php

namespace Tests\Feature;

use App\Models\ReadConfirmation;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AssignmentRejectionConcurrencyTest extends TestCase
{
    use SignatureFixtures;

    public function test_concurrent_signature_and_rejection_commit_only_one_outcome_in_mysql(): void
    {
        if (getenv('RUN_MYSQL_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped('Opt in with RUN_MYSQL_CONCURRENCY_TESTS=1; creates and removes an isolated MySQL test database.');
        }

        $database = 'dk_be05_test_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/\Adk_be05_test_[a-f0-9]{16}\z/', $database);
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
            $signer = User::factory()->create();
            $version = $this->publishedVersion($owner, [$signer]);
            $assignment = $version->assignments()->firstOrFail();
            ReadConfirmation::factory()->create(['assignment_guid' => $assignment->guid, 'version_content_hash' => $version->content_hash]);
            $asset = $this->asset($signer);
            $gate = Storage::disk('local')->path('race-reject-'.bin2hex(random_bytes(8)).'.start');
            $storageRoot = Storage::disk('local')->path('');
            $workerEnv = [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
                'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => false, 'CACHE_STORE' => 'array',
            ];

            $processes[] = new Process([
                PHP_BINARY, base_path('tests/Feature/SignatureSubmitWorker.php'), $database,
                $signer->guid, $assignment->guid, $asset->guid, $version->content_hash, 'reject-race-signature',
                $storageRoot, $gate,
            ], base_path(), $workerEnv, timeout: 20);
            $processes[] = new Process([
                PHP_BINARY, base_path('tests/Feature/AssignmentRejectWorker.php'), $database,
                $signer->guid, $assignment->guid, $storageRoot, $gate,
            ], base_path(), $workerEnv, timeout: 20);
            foreach ($processes as $process) {
                $process->start();
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
            $this->assertTrue($bothReady, 'Both workers must reach the submission barrier.');

            file_put_contents($gate, 'go');
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertGreaterThanOrEqual(100, max(array_column($results, 'lock_wait_ms')));
            $this->assertSame(1, count(array_filter($results, fn (array $result): bool => isset($result['error']))));
            if ($version->fresh()->status->value === 'rejected') {
                $this->assertSame(0, Signature::count());
                $this->assertSame('rejected', $assignment->fresh()->status);
                $this->assertSame(1, $assignment->response()->where('response_type', 'rejected')->count());
                $this->assertDatabaseHas('audit_events', ['action' => 'version.rejected']);
            } else {
                $this->assertSame('signed', $assignment->fresh()->status);
                $this->assertSame(1, Signature::count());
                $this->assertSame(0, $assignment->response()->count());
                $this->assertDatabaseHas('audit_events', ['action' => 'assignment.signed']);
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
            if ($created && preg_match('/\Adk_be05_test_[a-f0-9]{16}\z/', $database) === 1) {
                DB::connection('be05-admin')->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('be05-mysql');
            DB::purge('be05-admin');
        }
    }

    public function test_concurrent_rejection_and_revision_commit_only_one_outcome_in_mysql(): void
    {
        if (getenv('RUN_MYSQL_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped('Opt in with RUN_MYSQL_CONCURRENCY_TESTS=1; creates and removes an isolated MySQL test database.');
        }

        $database = 'dk_be05_test_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/\Adk_be05_test_[a-f0-9]{16}\z/', $database);
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
            $signer = User::factory()->create();
            $version = $this->publishedVersion($owner, [$signer]);
            $assignment = $version->assignments()->firstOrFail();
            $gate = Storage::disk('local')->path('race-reject-revision-'.bin2hex(random_bytes(8)).'.start');
            $storageRoot = Storage::disk('local')->path('');
            $workerEnv = [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
                'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => false, 'CACHE_STORE' => 'array',
            ];

            foreach (['reject', 'revision'] as $operation) {
                $process = new Process([
                    PHP_BINARY, base_path('tests/Feature/AssignmentRejectWorker.php'), $database,
                    $signer->guid, $assignment->guid, $storageRoot, $gate, $operation,
                ], base_path(), $workerEnv, timeout: 20);
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
            $this->assertTrue($bothReady, 'Both workers must reach the submission barrier.');

            file_put_contents($gate, 'go');
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertGreaterThanOrEqual(100, max(array_column($results, 'lock_wait_ms')));
            $this->assertSame(1, count(array_filter($results, fn (array $result): bool => isset($result['error']))));
            $this->assertSame(1, $assignment->response()->count());
            $this->assertContains($version->fresh()->status->value, ['rejected', 'revision_requested']);
            if ($version->fresh()->status->value === 'revision_requested') {
                $this->assertSame(2, $version->document->versions()->count());
                $this->assertSame(1, $assignment->response()->where('response_type', 'revision_requested')->count());
                $this->assertDatabaseHas('audit_events', ['action' => 'version.revision_requested']);
            } else {
                $this->assertSame(1, $version->document->versions()->count());
                $this->assertSame(1, $assignment->response()->where('response_type', 'rejected')->count());
                $this->assertDatabaseHas('audit_events', ['action' => 'version.rejected']);
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
            if ($created && preg_match('/\Adk_be05_test_[a-f0-9]{16}\z/', $database) === 1) {
                DB::connection('be05-admin')->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('be05-mysql');
            DB::purge('be05-admin');
        }
    }
}
