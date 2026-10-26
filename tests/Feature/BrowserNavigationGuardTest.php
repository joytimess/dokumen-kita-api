<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\User;
use App\Notifications\EmailChangeVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrowserNavigationGuardTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    private const NAVIGATION = ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'];

    public function test_base_urls_block_navigation_but_keep_existing_not_found_responses(): void
    {
        foreach (['/', '/api', '/api/v1'] as $url) {
            $this->get($url, self::NAVIGATION + ['Accept' => 'text/html'])
                ->assertUnauthorized()->assertContent('');
            $this->getJson($url, self::NAVIGATION)->assertUnauthorized()->assertContent('');
            $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    public function test_private_json_navigation_is_empty_with_and_without_authentication(): void
    {
        foreach (['/api/v1/me', '/api/v1/documents', '/api/v1/documents/missing'] as $url) {
            $this->get($url, self::NAVIGATION + ['Accept' => 'text/html'])
                ->assertUnauthorized()->assertContent('');
        }
        $this->actingAs(User::factory()->create())->get('/api/v1/me', self::NAVIGATION)
            ->assertUnauthorized()->assertContent('');
    }

    public static function applicationHeaders(): array
    {
        return [
            'missing metadata' => [[]],
            'axios cors' => [['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty']],
            'fetch same origin' => [['Sec-Fetch-Mode' => 'same-origin', 'Sec-Fetch-Dest' => 'empty']],
            'mode alone' => [['Sec-Fetch-Mode' => 'navigate']],
            'destination alone' => [['Sec-Fetch-Dest' => 'document']],
            'iframe' => [['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'iframe']],
            'image' => [['Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'image']],
        ];
    }

    #[DataProvider('applicationHeaders')]
    public function test_application_requests_keep_json_and_unauthenticated_contract(array $headers): void
    {
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')->assertJsonStructure(['error', 'meta' => ['request_id']]);

        $user = User::factory()->create();
        $this->actingAs($user)->get('/api/v1/me', $headers + [
            'Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0',
            'Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/documents',
        ])->assertOk()->assertJsonPath('data.guid', $user->guid);
    }

    public function test_valid_session_cookie_still_gets_empty_navigation_and_normal_axios_json(): void
    {
        config(['session.driver' => 'database', 'sanctum.stateful' => ['localhost:5173']]);
        $user = User::factory()->create(['password' => 'secret-pass']);
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'secret-pass',
        ], ['Origin' => 'http://localhost:5173'])->assertOk();
        $cookie = $login->getCookie(config('session.cookie'), false)->getValue();

        foreach ([false, true, false] as $navigation) {
            $this->app->make('session')->forgetDrivers();
            $this->app->forgetInstance('session.store');
            Auth::forgetGuards();
            $this->app->forgetInstance('auth.driver');

            $response = $this->call('GET', '/api/v1/me', [], [config('session.cookie') => $cookie], [], [
                'HTTP_ORIGIN' => 'http://localhost:5173',
                'HTTP_ACCEPT' => $navigation ? 'text/html' : 'application/json',
                'HTTP_SEC_FETCH_MODE' => $navigation ? 'navigate' : 'cors',
                'HTTP_SEC_FETCH_DEST' => $navigation ? 'document' : 'empty',
            ]);

            if ($navigation) {
                $response->assertUnauthorized()->assertContent('');
            } else {
                $response->assertOk()->assertJsonPath('data.guid', $user->guid);
            }
        }
    }

    public function test_application_write_methods_are_unchanged(): void
    {
        $this->actingAs(User::factory()->create())->withHeaders([
            'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty',
        ]);
        $category = $this->postJson('/api/v1/categories', ['name' => 'Reports', 'color_token' => 'blue'])
            ->assertCreated()->json('data.guid');
        $this->putJson('/api/v1/categories/'.$category, ['name' => 'Archive', 'color_token' => 'gray'])
            ->assertOk()->assertJsonPath('data.name', 'Archive');
        $this->patchJson('/api/v1/me/profile', ['name' => 'Updated Name'])
            ->assertOk()->assertJsonPath('data.name', 'Updated Name');
        $this->deleteJson('/api/v1/categories/'.$category)->assertNoContent();
    }

    public function test_signed_email_navigation_verifies_and_still_rejects_unsigned_links(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'userGuid' => $user->guid, 'hash' => sha1($user->email),
        ]);
        $this->get($url, self::NAVIGATION)->assertRedirect(config('app.frontend_url').'/email/verified');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->getJson('/api/v1/auth/email/verify/'.$user->guid.'/'.sha1($user->email), self::NAVIGATION)
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');

        Notification::fake();
        $this->actingAs($user)->postJson('/api/v1/me/email-change', ['email' => 'new@example.test'])->assertAccepted();
        $changeUrl = null;
        Notification::assertSentOnDemand(EmailChangeVerification::class, function ($notification) use (&$changeUrl): bool {
            $changeUrl = $notification->url;

            return true;
        });
        $this->get($changeUrl, self::NAVIGATION)->assertRedirectContains('/email/changed?status=verified');
        $this->assertSame('new@example.test', $user->fresh()->email);
    }

    public function test_binary_routes_allow_navigation_and_axios_without_changing_bytes_or_access(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $asset = $this->asset($owner);
        $disk = Storage::disk('local');
        $png = $disk->get($asset->file_path);
        $pdf = $disk->get($version->preview_pdf_path);
        $version->update([
            'source_type' => SourceType::Pdf, 'source_path' => $version->preview_pdf_path,
            'letterhead' => ['path' => $asset->file_path], 'footer' => ['path' => $asset->file_path],
        ]);
        $owner->forceFill(['profile_photo_path' => $asset->file_path])->save();

        $routes = [
            ["/api/v1/versions/{$version->guid}/pdf", 'application/pdf', $pdf],
            ["/api/v1/versions/{$version->guid}/pdf?download=1", 'application/pdf', $pdf],
            ["/api/v1/versions/{$version->guid}/source", 'application/pdf', $pdf],
            ["/api/v1/signature-assets/{$asset->guid}/image", 'image/png', $png],
            ["/api/v1/versions/{$version->guid}/decorations/letterhead/image", 'image/png', $png],
            ["/api/v1/versions/{$version->guid}/decorations/footer/image", 'image/png', $png],
            ['/api/v1/me/photo', 'image/png', $png],
        ];
        foreach ($routes as [$url]) {
            $this->getJson($url, self::NAVIGATION)->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        }
        foreach ([self::NAVIGATION, ['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty']] as $headers) {
            $this->actingAs($owner);
            foreach ($routes as [$url, $type, $bytes]) {
                $this->get($url, $headers)->assertOk()->assertHeader('Content-Type', $type)->assertStreamedContent($bytes);
            }
            $this->actingAs(User::factory()->create());
            foreach (array_slice($routes, 0, -1) as [$url]) {
                $this->getJson($url, $headers)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
            }
        }
    }

    public function test_unmatched_routes_keep_existing_fallback(): void
    {
        foreach (['/api/missing', '/api/v1/missing', '/missing'] as $url) {
            $this->getJson($url, self::NAVIGATION)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }
}
