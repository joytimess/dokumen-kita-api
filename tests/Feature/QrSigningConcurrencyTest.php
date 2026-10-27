<?php

namespace Tests\Feature;

use App\Enums\VersionStatus;
use App\Models\AuditEvent;
use App\Models\DocumentVersionQr;
use App\Models\EmailNotification;
use App\Models\Signature;
use App\Models\SignaturePlaceholder;
use App\Models\SignerAssignment;
use App\Models\User;
use App\Services\PublishService;
use App\Services\QrTokenService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class QrSigningConcurrencyTest extends TestCase
{
    use SignatureFixtures;

    public static function claims(): array
    {
        return ['same assignment' => [true], 'different assignments' => [false]];
    }

    #[DataProvider('claims')]
    public function test_concurrent_qr_claims_use_existing_signing_engine_and_finalize_once(bool $sameAssignment): void
    {
        if (getenv('RUN_MYSQL_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_MYSQL_CONCURRENCY_TESTS=1 to use an isolated MySQL database.');
        }
        $database = 'dk_qr_test_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/\Adk_qr_test_[a-f0-9]{16}\z/', $database);
        $mysql = config('database.connections.mysql');
        $mysql['url'] = null;
        config([
            'database.connections.qr-admin' => array_replace($mysql, ['database' => null]),
            'database.connections.qr-mysql' => array_replace($mysql, ['database' => $database]),
        ]);
        $previous = DB::getDefaultConnection();
        $created = false;
        $processes = [];
        $storageRoot = storage_path('framework/testing/disks/'.$database);
        $gate = $storageRoot.'/start';
        try {
            DB::connection('qr-admin')->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.default' => 'qr-mysql', 'documents.disk' => 'local']);
            DB::setDefaultConnection('qr-mysql');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'qr-mysql', '--force' => true, '--no-interaction' => true]));
            Storage::set('local', Storage::build(['driver' => 'local', 'root' => $storageRoot]));
            $owner = User::factory()->create();
            $signers = User::factory()->count($sameAssignment ? 1 : 2)->create();
            $version = $this->draft($owner, ['signing_mode' => 'parallel']);
            $this->storePreview($version);
            foreach ($signers as $index => $signer) {
                $assignment = SignerAssignment::factory()->create([
                    'version_guid' => $version->guid, 'recipient_email' => $signer->email,
                    'normalized_recipient_email' => $signer->email, 'sequence_number' => $index + 1,
                ]);
                SignaturePlaceholder::factory()->create(['assignment_guid' => $assignment->guid]);
            }
            app(PublishService::class)->publish($owner, $version);
            $qr = DocumentVersionQr::where('version_guid', $version->guid)->firstOrFail();
            $token = app(QrTokenService::class)->token($qr);
            $assets = $signers->map(fn (User $signer) => $this->asset($signer));
            for ($index = 0; $index < 2; $index++) {
                $signerIndex = $sameAssignment ? 0 : $index;
                $process = new Process([
                    PHP_BINARY, base_path('tests/Feature/QrClaimWorker.php'), $database,
                    $version->guid, $signers[$signerIndex]->guid, $assets[$signerIndex]->guid, $storageRoot, $gate,
                ], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
                    'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => false,
                    'CACHE_STORE' => 'array', 'BROADCAST_CONNECTION' => 'null',
                ], timeout: 25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            do {
                $ready = true;
                foreach ($processes as $process) {
                    $process->isRunning();
                    $ready = $ready && str_contains($process->getOutput(), "ready\n");
                }
                if (! $ready) {
                    usleep(10_000);
                }
            } while (! $ready && microtime(true) < $deadline);
            $this->assertTrue($ready, 'Both workers must reach the same barrier.');
            file_put_contents($gate, 'go');
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }
            $count = $sameAssignment ? 1 : 2;
            $this->assertGreaterThanOrEqual(100, max(array_column($results, 'lock_wait_ms')), 'Claims must contend for the actual document lock.');
            $this->assertSame($count, Signature::count());
            $this->assertSame($count, AuditEvent::where('action', 'invitation.claimed')->count());
            $this->assertSame($count, AuditEvent::where('action', 'assignment.signed')->count());
            $this->assertSame(1, AuditEvent::where('action', 'version.finalizing')->count());
            $this->assertSame(1, EmailNotification::where('notification_type', 'version_finalizing')->count());
            $this->assertSame(VersionStatus::Finalizing, $version->fresh()->status);
            $this->assertSame($count, $version->assignments()->where('status', 'signed')->count());
            $this->assertSame($count, $version->assignments()->whereNotNull('user_guid')->count());
            $this->assertSame(1, DocumentVersionQr::count());
            $this->assertSame($token, app(QrTokenService::class)->token($qr->fresh()));
            $this->assertStringNotContainsString($token, json_encode($results));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            DB::disconnect('qr-mysql');
            config(['database.default' => $previous]);
            DB::setDefaultConnection($previous);
            if ($created && preg_match('/\Adk_qr_test_[a-f0-9]{16}\z/', $database) === 1) {
                DB::connection('qr-admin')->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('qr-mysql');
            DB::purge('qr-admin');
            if ($storageRoot === storage_path('framework/testing/disks/'.$database)
                && preg_match('/\Adk_qr_test_[a-f0-9]{16}\z/', $database) === 1) {
                File::deleteDirectory($storageRoot);
            }
        }
    }
}
