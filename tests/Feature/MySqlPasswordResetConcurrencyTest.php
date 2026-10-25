<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlPasswordResetConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql'
            || ! preg_match('/\Adokumen_kita_otp_test(?:_[a-f0-9]{8})?\z/', DB::getDatabaseName())) {
            $this->markTestSkipped('Requires the isolated MySQL database dokumen_kita_otp_test, optionally with an eight-hex-character suffix.');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public function test_concurrent_otp_verification_issues_only_one_token(): void
    {
        $user = User::factory()->create();
        $this->challenge($user);

        $results = $this->concurrently($user, ['verify', 'verify']);

        sort($results);
        $this->assertSame(['invalid', 'ok'], $results);
        $this->assertDatabaseHas('password_reset_challenges', ['user_guid' => $user->guid, 'otp_hash' => null]);
        $this->assertNotNull(DB::table('password_reset_challenges')->value('reset_token_hash'));
    }

    public function test_concurrent_wrong_attempts_are_not_lost_and_lock_out_correct_otp(): void
    {
        $user = User::factory()->create();
        $this->challenge($user);

        $results = $this->concurrently($user, array_fill(0, 5, 'wrong'));

        $this->assertSame(array_fill(0, 5, 'invalid'), $results);
        $this->assertDatabaseHas('password_reset_challenges', ['user_guid' => $user->guid, 'failed_attempts' => 5]);
        $this->postJson('/api/v1/auth/password/verify-otp', ['email' => $user->email, 'otp' => '123456'])->assertUnprocessable();
    }

    public function test_concurrent_reset_consumes_token_only_once(): void
    {
        $user = User::factory()->create();
        $this->challenge($user);
        $token = $this->app->make(PasswordResetService::class)->verifyOtp($user->email, '123456');

        $results = $this->concurrently($user, ['reset', 'reset'], $token);

        sort($results);
        $this->assertSame(['invalid', 'ok'], $results);
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_challenges', ['user_guid' => $user->guid]);
    }

    public function test_concurrent_send_respects_cooldown_without_queuing_otp(): void
    {
        $user = User::factory()->create();

        $results = $this->concurrently($user, ['send', 'send']);

        sort($results);
        $this->assertSame(['limited', 'ok'], $results);
        $this->assertDatabaseCount('password_reset_challenges', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    /** @param array<int, string> $operations
     * @return array<int, string>
     */
    private function concurrently(User $user, array $operations, string $token = ''): array
    {
        $code = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("SET SESSION innodb_lock_wait_timeout = 15");
$service = $app->make(App\Services\PasswordResetService::class);
echo "READY\n";
flush();
try {
    match ($argv[1]) {
        "verify" => $service->verifyOtp($argv[2], "123456"),
        "wrong" => $service->verifyOtp($argv[2], "999999"),
        "send" => $service->sendOtp($argv[2]),
        "reset" => $service->reset($argv[2], $argv[3], "NewPassword123!", Illuminate\Http\Request::create("/")),
    };
    echo "ok";
} catch (Illuminate\Validation\ValidationException) {
    echo "invalid";
} catch (Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
    echo "limited";
}
PHP;
        $connection = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'DB_SOCKET' => $connection['unix_socket'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'database', 'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'database',
        ];
        $processes = [];
        $ready = [];
        DB::beginTransaction();

        try {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            foreach ($operations as $index => $operation) {
                $process = new Process([PHP_BINARY, '-r', $code, $operation, $user->email, $token], base_path(), $environment);
                $process->setTimeout(30);
                $ready[$index] = false;
                $process->start(function (string $type, string $output) use (&$ready, $index): void {
                    if (str_contains($output, 'READY')) {
                        $ready[$index] = true;
                    }
                });
                $processes[] = $process;
            }

            foreach ($processes as $index => $process) {
                while (! $ready[$index] && $process->isRunning()) {
                    $process->checkTimeout();
                    usleep(1000);
                }

                $this->assertTrue($ready[$index], $process->getErrorOutput().$process->getOutput());
                $this->assertTrue($process->isRunning());
            }

            DB::commit();
            $results = [];

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = trim(str_replace("READY\n", '', $process->getOutput()));
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }

        }
    }

    private function challenge(User $user): void
    {
        DB::table('password_reset_challenges')->insert([
            'user_guid' => $user->guid, 'guid' => (string) Str::uuid(), 'otp_hash' => Hash::make('123456'),
            'otp_expires_at' => now()->addMinutes(5), 'last_sent_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
