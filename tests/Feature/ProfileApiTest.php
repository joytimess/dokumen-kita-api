<?php

namespace Tests\Feature;

use App\Models\AuthIdentity;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Notifications\EmailChangeVerification;
use App\Services\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_profile_can_edit_name_and_private_photo_and_reports_read_only_email_and_password(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $user = User::factory()->create(['password' => Hash::make('password')]);
        AuthIdentity::create(['user_guid' => $user->guid, 'provider' => 'google', 'provider_user_id' => 'google-profile']);
        $user->forceFill(['google_photo_url' => 'https://google.example/photo.jpg'])->save();

        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.login_methods', ['email_password', 'google'])
            ->assertJsonPath('data.capabilities.can_change_email', false)
            ->assertJsonPath('data.capabilities.can_change_password', false)
            ->assertJsonPath('data.photo_url', 'https://google.example/photo.jpg');

        $this->patchJson('/api/v1/me/profile', ['name' => '  Nama Baru  '])
            ->assertOk()->assertJsonPath('data.name', 'Nama Baru');

        $this->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('avatar.png', 120, 120)])
            ->assertOk()->assertJsonPath('data.photo_url', route('me.photo'));
        $path = $user->fresh()->profile_photo_path;
        Storage::disk('local')->assertExists($path);
        $this->get('/api/v1/me/photo')->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->deleteJson('/api/v1/me/photo')->assertOk()->assertJsonPath('data.photo_url', null);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($user->fresh()->google_photo_url);
    }

    public function test_google_only_and_hybrid_accounts_cannot_change_email_or_password(): void
    {
        foreach ([null, Hash::make('password')] as $password) {
            $user = User::factory()->create(['password' => $password]);
            AuthIdentity::create(['user_guid' => $user->guid, 'provider' => 'google', 'provider_user_id' => (string) Str::uuid()]);

            $this->actingAs($user)->postJson('/api/v1/me/email-change', [
                'email' => 'new-'.$user->id.'@example.test',
            ])->assertForbidden();
            $this->postJson('/api/v1/me/password', [
                'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('email_change_requests', 0);
    }

    public function test_invalid_profile_photo_is_rejected_without_writing_a_file(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api/v1/me/photo', [
            'photo' => UploadedFile::fake()->createWithContent('invalid.png', 'not an image'),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['errors' => ['photo']], 'meta' => ['request_id']]);

        $this->assertNull($user->fresh()->profile_photo_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_profile_photo_accepts_allowed_formats_and_unrestricted_dimensions(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $user = User::factory()->create();

        foreach ([['jpg', 1, 1], ['jpeg', 3000, 20], ['png', 20, 3000], ['webp', 120, 120]] as [$format, $width, $height]) {
            $oldPath = $user->fresh()->profile_photo_path;
            $this->actingAs($user)->post('/api/v1/me/photo', [
                'photo' => UploadedFile::fake()->image('avatar.'.$format, $width, $height),
            ])->assertOk()->assertJsonPath('data.guid', $user->guid)
                ->assertJsonPath('data.photo_url', route('me.photo'));

            Storage::disk('local')->assertExists($user->fresh()->profile_photo_path);
            if ($oldPath !== null) {
                Storage::disk('local')->assertMissing($oldPath);
            }
        }

        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_profile_photo_accepts_eight_megabytes_and_rejects_a_larger_file(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api/v1/me/photo', [
            'photo' => UploadedFile::fake()->image('avatar.png')->size(8192),
        ])->assertOk();
        $path = $user->fresh()->profile_photo_path;

        $this->post('/api/v1/me/photo', [
            'photo' => UploadedFile::fake()->image('avatar.png')->size(8193),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['errors' => ['photo']]]);

        $this->assertSame($path, $user->fresh()->profile_photo_path);
        Storage::disk('local')->assertExists($path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_profile_photo_rejects_an_unsupported_image_format(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api/v1/me/photo', [
            'photo' => UploadedFile::fake()->image('avatar.gif'),
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['photo']]]);

        $this->assertNull($user->fresh()->profile_photo_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_profile_photo_requires_authentication(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);

        $this->post('/api/v1/me/photo', [
            'photo' => UploadedFile::fake()->image('avatar.png'),
        ], ['Accept' => 'application/json'])->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_profile_photo_only_updates_the_authenticated_user(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $user = User::factory()->create();
        $other = User::factory()->create(['profile_photo_path' => 'profile-photos/other.png']);
        Storage::disk('local')->put($other->profile_photo_path, 'existing photo');

        $this->actingAs($user)->post('/api/v1/me/photo', [
            'photo' => UploadedFile::fake()->image('avatar.png'),
            'user_guid' => $other->guid,
        ])->assertOk()->assertJsonPath('data.guid', $user->guid);

        $this->assertNotNull($user->fresh()->profile_photo_path);
        $this->assertSame('profile-photos/other.png', $other->fresh()->profile_photo_path);
        Storage::disk('local')->assertExists($other->profile_photo_path);
    }

    public function test_email_change_waits_for_signed_link_and_consumes_it_once(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/me/email-change', [
            'email' => 'new.address@example.test',
        ])->assertAccepted()->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.url');

        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame(1, EmailChangeRequest::count());
        $url = null;
        Notification::assertSentOnDemand(EmailChangeVerification::class, function ($notification, $channels, $notifiable) use (&$url): bool {
            $url = $notification->url;

            return $notifiable->routes['mail'] === 'new.address@example.test';
        });

        $this->get($url)->assertRedirect();
        $this->assertSame('new.address@example.test', $user->fresh()->email);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('email_change_requests', 0);
        $this->get($url)->assertRedirect()->assertRedirectContains('status=invalid');
    }

    public function test_replacing_pending_email_invalidates_the_old_link_and_expiry_does_not_change_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/me/email-change', [
            'email' => 'first@example.test',
        ])->assertAccepted();
        $oldUrl = null;
        Notification::assertSentOnDemand(EmailChangeVerification::class, function ($notification) use (&$oldUrl): bool {
            $oldUrl = $notification->url;

            return true;
        });

        RateLimiter::clear('email-change:'.$user->guid);
        $this->postJson('/api/v1/me/email-change', ['email' => 'second@example.test'])->assertAccepted();
        $newUrl = null;
        Notification::assertSentOnDemand(EmailChangeVerification::class, function ($notification) use (&$newUrl): bool {
            if (str_contains($notification->url, 'second%40example.test')) {
                $newUrl = $notification->url;

                return true;
            }

            return false;
        });

        $this->get($oldUrl)->assertRedirect()->assertRedirectContains('status=invalid');
        $this->assertSame($user->email, $user->fresh()->email);
        $this->get($newUrl)->assertRedirect()->assertRedirectContains('status=verified');
        $this->assertSame('second@example.test', $user->fresh()->email);

        $expired = User::factory()->create();
        RateLimiter::clear('email-change:'.$expired->guid);
        $this->actingAs($expired)->postJson('/api/v1/me/email-change', [
            'email' => 'expired@example.test',
        ])->assertAccepted();
        $expiredUrl = null;
        Notification::assertSentOnDemand(EmailChangeVerification::class, function ($notification) use (&$expiredUrl): bool {
            if (str_contains($notification->url, 'expired%40example.test')) {
                $expiredUrl = $notification->url;

                return true;
            }

            return false;
        });

        $this->travel(61)->minutes();
        $this->get($expiredUrl)->assertForbidden();
        $this->assertSame($expired->email, $expired->fresh()->email);
    }

    public function test_password_change_keeps_the_current_database_session_and_revokes_other_sessions_and_tokens(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $otherSession = 'other-session-id';
        $currentSession = 'current-session-id';
        foreach ([$currentSession, $otherSession] as $id) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
            ]);
        }
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => $user->getMorphClass(), 'tokenable_id' => $user->id,
            'name' => 'api', 'token' => hash('sha256', 'secret-token'), 'abilities' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ProfileService::class)->changePassword($user, 'StrongNewPass123!', $currentSession);

        $this->assertTrue(Hash::check('StrongNewPass123!', $user->fresh()->password));
        $this->assertDatabaseHas('sessions', ['id' => $currentSession, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => $otherSession]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_local_password_change_endpoint_updates_the_hash_without_current_password(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/me/password', [
            'password' => 'StrongNewPass123!', 'password_confirmation' => 'StrongNewPass123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('StrongNewPass123!', $user->fresh()->password));
    }
}
