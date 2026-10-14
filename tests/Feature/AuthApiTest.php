<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetOtp;
use App\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders(['Origin' => 'http://localhost:5173']);
    }

    public function test_register_creates_account_logs_in_and_sends_verification(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi',
            'email' => '  Budi@Example.COM ',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'budi@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonStructure(['data' => ['guid'], 'meta' => ['request_id']])
            ->assertJsonMissingPath('data.id');

        $user = User::firstWhere('email', 'budi@example.com');
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.guid', $user->guid);
    }

    public function test_register_queues_verification_and_logs_in_without_sending_mail(): void
    {
        Queue::fake([SendQueuedNotifications::class]);
        Mail::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi',
            'email' => 'queued@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated()->assertJsonPath('data.email_verified', false);

        $user = User::firstWhere('email', 'queued@example.com');
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof VerifyEmail && $job->notifiables->first()->is($user)
        );
        Mail::assertNothingOutgoing();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.guid', $user->guid);
    }

    public function test_resend_queues_verification_without_sending_mail(): void
    {
        $user = User::factory()->unverified()->create();
        Queue::fake([SendQueuedNotifications::class]);
        Mail::fake();

        $this->actingAs($user)->postJson('/api/v1/auth/email/resend')->assertAccepted();

        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof VerifyEmail && $job->notifiables->first()->is($user)
        );
        Mail::assertNothingOutgoing();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_does_not_queue_mail_for_verified_user(): void
    {
        $user = User::factory()->create();
        Queue::fake();

        $this->actingAs($user)->postJson('/api/v1/auth/email/resend')->assertAccepted();

        Queue::assertNothingPushed();
    }

    public function test_register_rejects_duplicate_email_with_error_envelope(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'X',
            'email' => 'DUP@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['errors' => ['email']], 'meta' => ['request_id']]);
    }

    public function test_login_logout_and_me(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => 'secret-pass']);

        $this->postJson('/api/v1/auth/login', ['email' => 'A@example.com', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.guid', $user->guid);

        $this->getJson('/api/v1/me')->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_login_with_wrong_password_fails_and_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => 'secret-pass']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_verification_link_marks_email_verified_by_guid(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'userGuid' => $user->guid,
            'hash' => sha1($user->email),
        ]);

        $this->get($url)->assertRedirect(config('app.frontend_url').'/email/verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_queued_verification_email_contains_a_valid_signed_guid_link(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = (new VerifyEmail)->toMail($user)->actionUrl;

        $this->get($verificationUrl)->assertRedirect(config('app.frontend_url').'/email/verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_link_with_wrong_hash_or_bad_signature_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $wrongHash = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'userGuid' => $user->guid,
            'hash' => sha1('other@example.com'),
        ]);
        $this->getJson($wrongHash)->assertForbidden();

        $this->getJson("/api/v1/auth/email/verify/{$user->guid}/".sha1($user->email))->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_password_reset_flow(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'a@example.com']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@example.com'])->assertAccepted();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'a@example.com'])->assertAccepted();

        Notification::assertSentTo($user, PasswordResetOtp::class);

        $otp = Notification::sent($user, PasswordResetOtp::class)->first()->otp;
        $token = $this->postJson('/api/v1/auth/password/verify-otp', [
            'email' => 'a@example.com', 'otp' => $otp,
        ])->assertOk()->json('data.reset_token');
        $this->postJson('/api/v1/auth/password/reset', [
            'reset_token' => $token,
            'email' => 'a@example.com',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'NewPassword123!'])->assertOk();
    }

    public function test_reset_with_invalid_token_fails(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $this->postJson('/api/v1/auth/password/reset', [
            'reset_token' => str_repeat('a', 64),
            'email' => 'a@example.com',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }
}
