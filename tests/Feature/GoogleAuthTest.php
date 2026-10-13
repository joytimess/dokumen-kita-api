<?php

namespace Tests\Feature;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = '/api/v1/auth/google/callback';

    private function fakeGoogleProfile(string $id, string $email, bool $emailVerified = true, string $name = 'Budi'): void
    {
        $profile = (new SocialiteUser)->map(['id' => $id, 'name' => $name, 'email' => $email]);
        $profile->user = ['sub' => $id, 'email' => $email, 'email_verified' => $emailVerified];

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($profile);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_redirect_sends_user_to_google_with_state(): void
    {
        $response = $this->get('/api/v1/auth/google/redirect');

        $response->assertRedirectContains('accounts.google.com');
        $this->assertStringContainsString('state=', $response->headers->get('Location'));
    }

    public function test_new_google_user_is_created_verified_and_logged_in(): void
    {
        $this->fakeGoogleProfile('g-1', 'Budi@Example.com');

        $this->get(self::CALLBACK)->assertRedirect(config('app.frontend_url').'/');

        $user = User::firstWhere('email', 'budi@example.com');
        $this->assertNotNull($user);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($user->password);
        $this->assertDatabaseHas('auth_identities', ['user_guid' => $user->guid, 'provider' => 'google', 'provider_user_id' => 'g-1']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_returning_google_identity_logs_into_same_account(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com']);
        AuthIdentity::create(['user_guid' => $user->guid, 'provider' => 'google', 'provider_user_id' => 'g-2']);
        $this->fakeGoogleProfile('g-2', 'changed@example.com');

        $this->get(self::CALLBACK)->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_existing_verified_account_is_linked_by_email(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => 'secret-pass']);
        $this->fakeGoogleProfile('g-3', 'a@example.com');

        $this->get(self::CALLBACK)->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
        $this->assertTrue($user->fresh()->identities()->where('provider_user_id', 'g-3')->exists());
        $this->assertNotNull($user->fresh()->password);
    }

    public function test_unverified_local_account_loses_its_password_when_claimed_by_google(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'a@example.com', 'password' => 'attacker-pass']);
        $this->fakeGoogleProfile('g-4', 'a@example.com');

        $this->get(self::CALLBACK)->assertRedirect();

        $user->refresh();
        $this->assertNull($user->password);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->fakeGoogleProfile('g-5', 'a@example.com', emailVerified: false);

        $this->get(self::CALLBACK)->assertRedirect(config('app.frontend_url').'/login?error=email_not_verified');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_user_denying_consent_is_sent_back_to_login(): void
    {
        $this->get(self::CALLBACK.'?error=access_denied')
            ->assertRedirect(config('app.frontend_url').'/login?error=oauth_denied');
    }

    public function test_provider_failure_is_reported_without_leaking_details(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('boom'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(self::CALLBACK)->assertRedirect(config('app.frontend_url').'/login?error=oauth_failed');
        $this->assertGuest();
    }

    public function test_redirect_target_must_be_an_internal_path(): void
    {
        $this->get('/api/v1/auth/google/redirect?redirect=//evil.example');
        $this->fakeGoogleProfile('g-6', 'a@example.com');

        $this->get(self::CALLBACK)->assertRedirect(config('app.frontend_url').'/');
    }

    public function test_internal_redirect_target_is_kept(): void
    {
        $this->get('/api/v1/auth/google/redirect?redirect=/invitations/abc');
        $this->fakeGoogleProfile('g-7', 'a@example.com');

        $this->get(self::CALLBACK)->assertRedirect(config('app.frontend_url').'/invitations/abc');
    }
}
