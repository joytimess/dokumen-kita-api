<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetOtp;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders(['Origin' => 'http://localhost:5173']);
    }

    public function test_forgot_returns_same_202_for_eligible_unknown_and_google_only_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $googleUser = User::factory()->create(['password' => null]);

        foreach ([$user->email, 'unknown@example.test', $googleUser->email] as $email) {
            $this->postJson('/api/v1/auth/password/forgot', ['email' => $email])
                ->assertAccepted()
                ->assertJsonPath('data', ['status' => 'accepted', 'otp_expires_in' => 300, 'resend_after' => 60])
                ->assertJsonMissingPath('data.otp');
        }

        Notification::assertSentTo($user, PasswordResetOtp::class);
        Notification::assertNotSentTo($googleUser, PasswordResetOtp::class);
        Notification::assertCount(1);
        $this->assertDatabaseCount('password_reset_challenges', 1);
    }

    public function test_forgot_queues_encrypted_email_without_sending_in_request(): void
    {
        $user = User::factory()->create();
        Event::fake([MessageSent::class]);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => '  '.Str::upper($user->email).'  '])->assertAccepted();

        Event::assertNotDispatched(MessageSent::class);
        $this->assertDatabaseCount('jobs', 1);
        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $job = unserialize(Crypt::decrypt($payload['data']['command']));
        $this->assertInstanceOf(SendQueuedNotifications::class, $job);
        $this->assertMatchesRegularExpression('/\\A[0-9]{6}\\z/', $job->notification->otp);
        $this->assertStringNotContainsString('otp";', $payload['data']['command']);
        $this->assertTrue(Hash::check($job->notification->otp, DB::table('password_reset_challenges')->value('otp_hash')));
        $this->assertSame(3, $payload['maxTries']);
        $this->assertSame(30, $payload['timeout']);
        $this->assertSame('30,120', $payload['backoff']);
    }

    public function test_worker_delivers_otp_email_without_a_reset_link(): void
    {
        $user = User::factory()->create();
        Event::fake([MessageSent::class]);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertAccepted();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

        Event::assertDispatched(MessageSent::class, function (MessageSent $event): bool {
            $html = $event->message->getHtmlBody();

            return str_contains($html, '5 menit') && ! str_contains($html, '/reset-password?');
        });
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_expired_queued_otp_is_not_sent(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        Event::fake([MessageSent::class]);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertAccepted();
        $this->travel(5)->minutes();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

        Event::assertNotDispatched(MessageSent::class);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_worker_skips_replaced_challenge_and_sends_latest_code(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        Event::fake([MessageSent::class]);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertAccepted();
        $this->travel(60)->seconds();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertAccepted();

        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();

        Event::assertDispatchedTimes(MessageSent::class, 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_enqueue_failure_returns_500_instead_of_accepting(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        Queue::shouldReceive('connection')->with('database')->andThrow(new \RuntimeException('Queue unavailable'));

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])
            ->assertInternalServerError()->assertJsonPath('error.code', 'INTERNAL_ERROR');
    }

    public function test_smtp_failure_is_retried_then_recorded_as_failed_job(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->times(3)->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->mock(MailFactory::class)->shouldReceive('mailer')->with(null)->times(3)->andReturn($mailer);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertAccepted();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertDatabaseHas('jobs', ['attempts' => 1, 'available_at' => now()->addSeconds(30)->timestamp]);
        $this->travel(30)->seconds();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertDatabaseHas('jobs', ['attempts' => 2, 'available_at' => now()->addSeconds(120)->timestamp]);
        $this->travel(120)->seconds();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertStringContainsString('SMTP unavailable', DB::table('failed_jobs')->value('exception'));
    }

    public function test_consumed_otp_is_not_sent_by_worker(): void
    {
        $user = User::factory()->create();
        Event::fake([MessageSent::class]);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertAccepted();
        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $job = unserialize(Crypt::decrypt($payload['data']['command']));
        $this->verify($user, $job->notification->otp)->assertOk();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

        Event::assertNotDispatched(MessageSent::class);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_wrong_otp_attempts_persist_and_fifth_failure_blocks_correct_code(): void
    {
        $user = User::factory()->create();
        $this->challenge($user, '012345');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->verify($user, '999999')->assertUnprocessable()->assertJsonValidationErrors('otp', 'error.errors');
            $this->assertDatabaseHas('password_reset_challenges', ['user_guid' => $user->guid, 'failed_attempts' => $attempt]);
        }

        $this->verify($user, '012345')->assertUnprocessable();
        $this->assertDatabaseHas('password_reset_challenges', ['user_guid' => $user->guid, 'reset_token_hash' => null]);
    }

    public function test_leading_zero_otp_is_exchanged_once_for_hashed_reset_token(): void
    {
        $user = User::factory()->create();
        $this->challenge($user, '012345');

        $token = $this->verify($user, '012345')->assertOk()->assertJsonPath('data.expires_in', 300)->json('data.reset_token');

        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('password_reset_challenges', [
            'user_guid' => $user->guid, 'otp_hash' => null, 'reset_token_hash' => hash('sha256', $token),
        ]);
        $this->verify($user, '012345')->assertUnprocessable();
    }

    public function test_otp_expired_at_exact_deadline_returns_422(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->challenge($user);
        $this->travel(300)->seconds();

        $this->verify($user)->assertUnprocessable()->assertJsonPath('error.errors.otp.0', 'Kode OTP tidak valid atau sudah kedaluwarsa.');
    }

    #[DataProvider('invalidOtps')]
    public function test_invalid_otp_format_returns_422(mixed $otp): void
    {
        $this->postJson('/api/v1/auth/password/verify-otp', ['email' => 'user@example.test', 'otp' => $otp])
            ->assertUnprocessable()->assertJsonValidationErrors('otp', 'error.errors');
    }

    public static function invalidOtps(): array
    {
        return ['number' => [123456], 'short' => ['12345'], 'long' => ['1234567'], 'letters' => ['abc123'], 'newline' => ["123456\n"]];
    }

    public function test_resend_invalidates_previous_otp_and_reset_token(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->create();
        $this->challenge($user);
        $token = $this->verify($user)->assertOk()->json('data.reset_token');
        $this->travel(60)->seconds();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertAccepted();

        $this->resetPassword($user, $token)->assertUnprocessable();
        $this->verify($user)->assertUnprocessable();
        $otp = Notification::sent($user, PasswordResetOtp::class)->last()->otp;
        $this->verify($user, $otp)->assertOk();
        Notification::assertSentTo($user, PasswordResetOtp::class);
    }

    public function test_resend_before_60_seconds_returns_429_with_retry_after(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertAccepted();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => '  '.Str::upper($user->email).'  '])
            ->assertTooManyRequests()->assertHeader('Retry-After', '60')->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');

        Notification::assertCount(1);
    }

    public function test_unknown_email_has_same_resend_limits(): void
    {
        Notification::fake();
        $this->freezeTime();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@example.test'])->assertAccepted();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@example.test'])
            ->assertTooManyRequests()->assertHeader('Retry-After', '60');

        Notification::assertNothingSent();
    }

    public function test_forgot_is_limited_to_five_requests_per_hour_for_each_email(): void
    {
        Notification::fake();
        $this->freezeTime();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@example.test'])->assertAccepted();
            $this->travel(60)->seconds();
        }

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@example.test'])->assertTooManyRequests()->assertHeader('Retry-After', '3300');
        Notification::assertNothingSent();
    }

    public function test_forgot_is_limited_by_ip_even_with_different_emails(): void
    {
        Notification::fake();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/password/forgot', ['email' => "unknown{$attempt}@example.test"])->assertAccepted();
        }

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'extra@example.test'])->assertTooManyRequests();
        Notification::assertNothingSent();
    }

    public function test_verify_and_reset_have_independent_ip_limits(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/password/verify-otp', ['email' => 'unknown@example.test', 'otp' => '123456'])->assertUnprocessable();
            $this->postJson('/api/v1/auth/password/reset', [
                'email' => 'unknown@example.test', 'reset_token' => str_repeat('a', 64),
                'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/password/verify-otp')->assertTooManyRequests()->assertHeader('Retry-After');
        $this->postJson('/api/v1/auth/password/reset')->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_reset_revokes_sessions_remember_cookie_and_tokens_and_emits_event_after_commit(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'OldPassword123!', 'remember_token' => 'old-remember-token']);
        $other = User::factory()->create();
        $this->challenge($user);
        $token = $this->verify($user)->assertOk()->json('data.reset_token');
        Event::fake([PasswordReset::class]);
        foreach ([$user, $other] as $owner) {
            DB::table('sessions')->insert(['id' => $owner->guid, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
            DB::table('personal_access_tokens')->insert([
                'tokenable_type' => $owner->getMorphClass(), 'tokenable_id' => $owner->id,
                'name' => 'mobile', 'token' => hash('sha256', $owner->guid), 'abilities' => '["*"]',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $cookieName = $this->app['auth']->guard('web')->getRecallerName();
        $oldCookie = $user->id.'|old-remember-token|'.$user->password;

        $this->resetPassword($user, $token)->assertOk()->assertJsonPath('data.status', 'reset');

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $other->id]);
        $this->assertDatabaseMissing('password_reset_challenges', ['user_guid' => $user->guid]);
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
        $this->assertNull($user->fresh()->email_verified_at);
        Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event): bool => $event->user->is($user) && DB::transactionLevel() === 0);
        $this->app['auth']->forgetGuards();
        $this->withCookie($cookieName, $oldCookie)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'OldPassword123!'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'NewPassword123!'])->assertOk();
    }

    public function test_reset_logs_out_current_session_and_cannot_be_replayed(): void
    {
        $user = User::factory()->create();
        $this->challenge($user);
        $token = $this->verify($user)->assertOk()->json('data.reset_token');

        $this->actingAs($user);
        $this->resetPassword($user, $token)->assertOk();
        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->resetPassword($user, $token)->assertUnprocessable();
    }

    public function test_reset_token_cannot_be_used_for_different_email(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['password' => 'Original123!']);
        $this->challenge($user);
        $token = $this->verify($user)->assertOk()->json('data.reset_token');

        $this->resetPassword($other, $token)->assertUnprocessable();
        $this->assertTrue(Hash::check('Original123!', $other->fresh()->password));
        $this->resetPassword($user, $token)->assertOk();
    }

    public function test_expired_reset_token_cannot_change_password(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['password' => 'Original123!']);
        $this->challenge($user);
        $token = $this->verify($user)->assertOk()->json('data.reset_token');
        $this->travel(300)->seconds();

        $this->resetPassword($user, $token)->assertUnprocessable()->assertJsonValidationErrors('reset_token', 'error.errors');
        $this->assertTrue(Hash::check('Original123!', $user->fresh()->password));
    }

    public function test_invalid_password_does_not_consume_reset_token(): void
    {
        $user = User::factory()->create();
        $this->challenge($user);
        $token = $this->verify($user)->assertOk()->json('data.reset_token');

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email, 'reset_token' => $token,
            'password' => 'x', 'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors('password', 'error.errors');

        $this->resetPassword($user, $token)->assertOk();
    }

    public function test_old_link_token_is_no_longer_accepted(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email, 'token' => Str::random(64),
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('reset_token', 'error.errors');
    }

    public function test_prune_preserves_valid_reset_tokens_even_when_otp_has_expired(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $expiredUser = User::factory()->create();
        $this->challenge($user);
        $this->challenge($expiredUser);
        $this->travel(290)->seconds();
        $token = $this->verify($user)->assertOk()->json('data.reset_token');
        $this->travel(10)->seconds();

        $this->artisan('auth:prune-password-challenges')->assertSuccessful();

        $this->assertDatabaseMissing('password_reset_challenges', ['user_guid' => $expiredUser->guid]);
        $this->resetPassword($user, $token)->assertOk();
    }

    private function challenge(User $user, string $otp = '123456'): void
    {
        DB::table('password_reset_challenges')->insert([
            'user_guid' => $user->guid, 'guid' => (string) Str::uuid(), 'otp_hash' => Hash::make($otp),
            'otp_expires_at' => now()->addSeconds(300), 'failed_attempts' => 0,
            'last_sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function verify(User $user, string $otp = '123456'): TestResponse
    {
        return $this->postJson('/api/v1/auth/password/verify-otp', ['email' => $user->email, 'otp' => $otp]);
    }

    private function resetPassword(User $user, string $token): TestResponse
    {
        return $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email, 'reset_token' => $token,
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ]);
    }
}
