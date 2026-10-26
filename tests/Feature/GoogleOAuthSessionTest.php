<?php

namespace Tests\Feature;

use App\Models\AuthIdentity;
use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleOAuthSessionTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-staging.dokumenkita.online';

    private const CALLBACK = '/api/v1/auth/google/callback';

    private array $history = [];

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => self::API, 'app.frontend_url' => 'http://localhost:5173',
            'app.key' => 'base64:'.base64_encode(str_repeat('o', 32)), 'app.previous_keys' => [],
            'session.driver' => 'database', 'session.connection' => config('database.default'),
            'session.cookie' => 'dokumen-kita-session', 'session.domain' => 'api-staging.dokumenkita.online',
            'session.path' => '/', 'session.secure' => true, 'session.http_only' => true,
            'session.same_site' => 'lax', 'session.encrypt' => false, 'session.serialization' => 'json',
            'services.google.client_id' => 'oauth-test-client', 'services.google.client_secret' => 'oauth-test-secret',
            'services.google.redirect' => self::API.self::CALLBACK,
            'services.google.session_diagnostics' => false,
            'sanctum.stateful' => ['localhost:5173'], 'cors.allowed_origins' => ['http://localhost:5173'],
        ]);
        $this->app->forgetInstance('encrypter');
        $this->logs = new TestHandler;
        Log::swap(new \Illuminate\Log\Logger(new Logger('oauth-test', [$this->logs])));
        $this->mockGoogle();
    }

    private function mockGoogle(bool $verified = true, ?array $responses = null): void
    {
        $stack = HandlerStack::create(new MockHandler($responses ?? [
            new Response(200, [], '{"access_token":"oauth-test-access-token","expires_in":3600}'),
            new Response(200, [], json_encode([
                'sub' => 'google-session-user', 'email' => 'session@example.test',
                'email_verified' => $verified, 'name' => 'Session User',
            ])),
        ]));
        $stack->push(Middleware::history($this->history));
        config(['services.google.guzzle.handler' => $stack]);
    }

    /** Only the encrypted wire cookie survives between fresh stores, guards and providers. */
    private function navigate(string $path, array $cookies = [], string $referer = 'https://accounts.google.com/', array $headers = [], bool $https = true, string $method = 'GET'): TestResponse
    {
        $this->app->make('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        Socialite::forgetDrivers();

        $base = $https ? self::API : str_replace('https://', 'http://', self::API);

        return $this->call($method, $base.$path, [], $cookies, [], array_replace([
            'HTTPS' => $https ? 'on' : 'off', 'SERVER_PORT' => $https ? 443 : 80,
            'HTTP_REFERER' => $referer, 'HTTP_ACCEPT' => 'application/json',
            'HTTP_SEC_FETCH_MODE' => str_starts_with($path, '/api/v1/auth/google/') ? 'navigate' : 'cors',
            'HTTP_SEC_FETCH_DEST' => str_starts_with($path, '/api/v1/auth/google/') ? 'document' : 'empty',
            'HTTP_COOKIE' => implode('; ', array_map(fn ($name, $value) => $name.'='.$value, array_keys($cookies), $cookies)),
        ], $headers));
    }

    private function cookies(TestResponse $response): array
    {
        return ['dokumen-kita-session' => $response->getCookie('dokumen-kita-session', false)->getValue()];
    }

    private function begin(string $intended = '/documents', array $cookies = []): array
    {
        $response = $this->navigate('/api/v1/auth/google/redirect?'.http_build_query(['redirect' => $intended]), $cookies, 'http://localhost:5173/login');
        $response->assertRedirectContains('https://accounts.google.com/');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return [$response, $query['state']];
    }

    private function complete(string $state, array $cookies): TestResponse
    {
        return $this->navigate(self::CALLBACK.'?'.http_build_query(['state' => $state, 'code' => 'oauth-test-code']), $cookies);
    }

    private function payload(string $id): array
    {
        return json_decode(base64_decode(DB::table('sessions')->where('id', $id)->value('payload')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_redirect_persists_json_state_and_intended_before_returning_a_secure_cookie(): void
    {
        [$response, $state] = $this->begin();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $cookie = $response->getCookie('dokumen-kita-session');
        $this->assertSame(self::API.self::CALLBACK, $query['redirect_uri']);
        $this->assertSame('oauth-test-client', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(40, strlen($state));
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('api-staging.dokumenkita.online', $cookie->getDomain());
        $payload = $this->payload($cookie->getValue());
        $this->assertSame($state, $payload['state']);
        $this->assertSame('/documents', $payload['oauth']['intended']);
        $this->assertSame([], $this->history);
    }

    public function test_valid_callback_reloads_session_logs_in_rotates_id_and_reaches_sanctum(): void
    {
        [$redirect, $state] = $this->begin('/invitations/abc?tab=pending');
        $oldId = $redirect->getCookie('dokumen-kita-session')->getValue();
        $oldToken = $this->payload($oldId)['_token'];
        $callback = $this->complete($state, $this->cookies($redirect));
        $callback->assertRedirect('http://localhost:5173/invitations/abc?tab=pending');
        $newId = $callback->getCookie('dokumen-kita-session')->getValue();
        $user = User::where('email', 'session@example.test')->sole();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertDatabaseCount('auth_identities', 1);
        $this->assertNotSame($oldId, $newId);
        $this->assertDatabaseMissing('sessions', ['id' => $oldId]);
        $this->assertSame($user->id, DB::table('sessions')->where('id', $newId)->value('user_id'));
        $payload = $this->payload($newId);
        $this->assertNotSame($oldToken, $payload['_token']);
        $this->assertArrayNotHasKey('state', $payload);
        $this->assertArrayNotHasKey('intended', $payload['oauth'] ?? []);
        $this->assertCount(2, $this->history);
        parse_str((string) $this->history[0]['request']->getBody(), $tokenRequest);
        $this->assertSame('oauth-test-code', $tokenRequest['code']);
        $this->assertSame(self::API.self::CALLBACK, $tokenRequest['redirect_uri']);
        $this->navigate('/api/v1/me', $this->cookies($callback), 'http://localhost:5173/', ['HTTP_ORIGIN' => 'http://localhost:5173'])
            ->assertOk()->assertJsonPath('data.email', 'session@example.test')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->navigate('/api/v1/me', $this->cookies($redirect), 'http://localhost:5173/', ['HTTP_ORIGIN' => 'http://localhost:5173'])
            ->assertUnauthorized();
    }

    public static function invalidStates(): array
    {
        return array_map(fn ($scenario) => [$scenario], ['missing', 'mismatch', 'no_stored_state', 'different_session', 'bad_cookie']);
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_state_never_contacts_google_or_logs_in(string $scenario): void
    {
        [$redirect, $state] = $this->begin();
        $cookies = $this->cookies($redirect);
        if ($scenario === 'no_stored_state') {
            $id = $redirect->getCookie('dokumen-kita-session')->getValue();
            $payload = $this->payload($id);
            unset($payload['state']);
            DB::table('sessions')->where('id', $id)->update(['payload' => base64_encode(json_encode($payload))]);
        } elseif ($scenario === 'different_session') {
            [$other] = $this->begin();
            $cookies = $this->cookies($other);
        } elseif ($scenario === 'bad_cookie') {
            $cookies = ['dokumen-kita-session' => 'unreadable-cookie'];
        }
        $this->complete(match ($scenario) {
            'missing' => '', 'mismatch' => 'wrong-state', default => $state,
        }, $cookies)->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('auth_identities', 0);
        $this->assertSame([], $this->history);
        $records = $this->logs->getRecords();
        $failure = $records[array_key_last($records)];
        $this->assertSame('invalid_state', $failure->context['failure']);
        $this->assertSame('database', $failure->context['session_driver']);
        $this->assertSame('json', $failure->context['session_serialization']);
    }

    public function test_repeated_callback_cannot_reauthenticate_with_consumed_state(): void
    {
        [$redirect, $state] = $this->begin();
        $callback = $this->complete($state, $this->cookies($redirect));
        $callback->assertRedirect('http://localhost:5173/documents');
        $this->complete($state, $this->cookies($callback))->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $this->assertCount(2, $this->history);
        $this->complete($state, $this->cookies($redirect))->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $this->assertGuest('web');
        $this->assertCount(2, $this->history);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_restarting_oauth_overwrites_state_and_rejects_the_older_callback(): void
    {
        [$first, $firstState] = $this->begin('/first');
        [$second, $secondState] = $this->begin('/second', $this->cookies($first));
        $this->assertSame($first->getCookie('dokumen-kita-session')->getValue(), $second->getCookie('dokumen-kita-session')->getValue());
        $this->assertNotSame($firstState, $secondState);
        $this->complete($firstState, $this->cookies($second))->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $this->assertGuest('web');
        $this->assertSame([], $this->history);
        $records = $this->logs->getRecords();
        $this->assertFalse($records[0]->context['session_state_preexisting']);
        $this->assertTrue($records[1]->context['session_state_preexisting']);
        $this->assertTrue($records[0]->context['session_state_present']);
        $this->assertTrue($records[1]->context['session_state_present']);
        $this->assertSame($records[0]->context['session_fingerprint'], $records[2]->context['session_fingerprint']);
        $this->assertSame('invalid_state', $records[2]->context['failure']);
        $this->assertTrue($records[2]->context['session_cookie_readable']);
        $this->assertTrue($records[2]->context['session_state_present']);
        $this->assertTrue($records[2]->context['callback_state_present']);
        $this->assertTrue($records[2]->context['request_secure']);
    }

    public function test_denied_consent_clears_pending_state_and_does_not_contact_google(): void
    {
        [$redirect, $state] = $this->begin();
        $denied = $this->navigate(self::CALLBACK.'?'.http_build_query(['error' => 'access_denied', 'state' => $state]), $this->cookies($redirect));
        $denied->assertRedirect('http://localhost:5173/login?error=oauth_denied');
        $payload = $this->payload($denied->getCookie('dokumen-kita-session')->getValue());
        $this->assertArrayNotHasKey('state', $payload);
        $this->assertArrayNotHasKey('intended', $payload['oauth'] ?? []);
        $this->complete($state, $this->cookies($denied))->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $this->assertGuest('web');
        $this->assertSame([], $this->history);
    }

    public static function providerFailures(): array
    {
        return ['invalid code' => [false], 'userinfo failed' => [true]];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_has_no_partial_login_or_sensitive_logs(bool $userinfo): void
    {
        $failure = new Response(400, [], json_encode(['error' => 'invalid_grant', 'error_description' => 'oauth-test-code oauth-test-secret oauth-test-access-token']));
        $this->mockGoogle(responses: $userinfo ? [new Response(200, [], '{"access_token":"oauth-test-access-token"}'), $failure] : [$failure]);
        [$redirect, $state] = $this->begin();
        $callback = $this->complete($state, $this->cookies($redirect));
        $callback->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('auth_identities', 0);
        $id = $callback->getCookie('dokumen-kita-session')->getValue();
        $this->assertNull(DB::table('sessions')->where('id', $id)->value('user_id'));
        $this->assertArrayNotHasKey('state', $this->payload($id));
        $this->assertCount($userinfo ? 2 : 1, $this->history);
        $this->assertNotEmpty($this->logs->getRecords());
        $logText = implode('', array_map(fn ($record) => (new LineFormatter)->format($record), $this->logs->getRecords()));
        foreach (['oauth-test-code', 'oauth-test-secret', 'oauth-test-access-token', $state, $redirect->getCookie('dokumen-kita-session')->getValue()] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $logText);
        }
    }

    public function test_unverified_profile_is_rejected_after_real_state_validation(): void
    {
        $this->mockGoogle(verified: false);
        [$redirect, $state] = $this->begin();
        $this->complete($state, $this->cookies($redirect))->assertRedirect('http://localhost:5173/login?error=email_not_verified');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('auth_identities', 0);
        $this->assertCount(2, $this->history);
    }

    public function test_existing_identity_is_resolved_after_real_state_validation(): void
    {
        $user = User::factory()->create(['email' => 'session@example.test']);
        AuthIdentity::create(['user_guid' => $user->guid, 'provider' => 'google', 'provider_user_id' => 'google-session-user']);
        [$redirect, $state] = $this->begin();
        $this->complete($state, $this->cookies($redirect))->assertRedirect('http://localhost:5173/documents');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('auth_identities', 1);
    }

    public static function unsafeRedirects(): array
    {
        return array_map(fn ($path) => [$path], ['https://evil.test', '//evil.test', '/\\evil.test', 'javascript:alert(1)', "/\r\nLocation: https://evil.test"]);
    }

    #[DataProvider('unsafeRedirects')]
    public function test_unsafe_intended_path_is_replaced_with_root(string $path): void
    {
        [$redirect, $state] = $this->begin($path);
        $this->complete($state, $this->cookies($redirect))->assertRedirect('http://localhost:5173/');
    }

    public static function unreadableCookies(): array
    {
        return array_map(fn ($scenario) => [$scenario], ['wrong_key', 'plaintext', 'wrong_prefix', 'double_encryption', 'renamed_cookie', 'missing_cookie', 'duplicate_cookie']);
    }

    #[DataProvider('unreadableCookies')]
    public function test_cookie_failures_are_distinguished_without_accepting_invalid_state(string $scenario): void
    {
        [$redirect, $state] = $this->begin();
        $id = $redirect->getCookie('dokumen-kita-session')->getValue();
        $cookies = $this->cookies($redirect);
        $encrypter = $this->app->make('encrypter');
        $headers = [];
        if ($scenario === 'wrong_key') {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => []]);
            $this->app->forgetInstance('encrypter');
        } elseif ($scenario === 'plaintext') {
            $cookies['dokumen-kita-session'] = $id;
        } elseif ($scenario === 'wrong_prefix') {
            $cookies['dokumen-kita-session'] = $encrypter->encrypt(CookieValuePrefix::create('another-session', $encrypter->getKey()).$id, false);
        } elseif ($scenario === 'double_encryption') {
            $cookies['dokumen-kita-session'] = $encrypter->encrypt(CookieValuePrefix::create('dokumen-kita-session', $encrypter->getKey()).$cookies['dokumen-kita-session'], false);
        } elseif ($scenario === 'renamed_cookie') {
            config(['session.cookie' => 'dokumenkita-session']);
        } elseif ($scenario === 'missing_cookie') {
            $cookies = [];
        } elseif ($scenario === 'duplicate_cookie') {
            $headers['HTTP_COOKIE'] = 'dokumen-kita-session=corrupted; dokumen-kita-session='.$cookies['dokumen-kita-session'];
            $cookies['dokumen-kita-session'] = 'corrupted';
        }

        $this->navigate(self::CALLBACK.'?'.http_build_query(['state' => $state, 'code' => 'oauth-test-code']), $cookies, headers: $headers)
            ->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->assertSame([], $this->history);
        $records = $this->logs->getRecords();
        $context = $records[array_key_last($records)]->context;
        $received = ! in_array($scenario, ['renamed_cookie', 'missing_cookie'], true);
        $this->assertFalse($context['session_cookie_readable']);
        $this->assertSame($received, $context['session_cookie_received']);
        $this->assertSame($scenario === 'duplicate_cookie' ? 2 : (int) $received, $context['session_cookie_header_count']);
        $this->assertFalse($context['session_id_matches_cookie']);
        $this->assertFalse($context['session_state_present']);
        $this->assertSame('invalid_state', $context['failure']);
        $this->assertSame(config('session.cookie'), $context['session_cookie_name']);
        $this->assertSame(config('session.cookie'), $context['session_store_cookie_name']);
        $logText = implode('', array_map(fn ($record) => (new LineFormatter)->format($record), $records));
        foreach ([$id, $state, 'oauth-test-code', $this->cookies($redirect)['dokumen-kita-session'], config('app.key')] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $logText);
        }
    }

    public function test_configured_previous_key_preserves_the_real_state_check_during_key_rotation(): void
    {
        [$redirect, $state] = $this->begin();
        $previousKey = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$previousKey]]);
        $this->app->forgetInstance('encrypter');

        $this->complete($state, $this->cookies($redirect))->assertRedirect('http://localhost:5173/documents');
        $this->assertAuthenticated('web');
        $this->assertCount(2, $this->history);
        $records = $this->logs->getRecords();
        $this->assertTrue($records[1]->context['session_id_matches_cookie']);
        $this->assertTrue($records[1]->context['session_state_present']);
        $this->assertNotSame($records[0]->context['session_fingerprint'], $records[1]->context['session_fingerprint']);
        $this->assertSame($records[0]->context['session_id_fingerprint'], $records[1]->context['session_id_fingerprint']);
        $this->assertNotSame($records[0]->context['app_key_fingerprint'], $records[1]->context['app_key_fingerprint']);
    }

    public static function lostSessionData(): array
    {
        return array_map(fn ($scenario) => [$scenario], ['deleted', 'expired', 'serialization_changed']);
    }

    #[DataProvider('lostSessionData')]
    public function test_readable_cookie_with_lost_session_data_is_distinct_from_decryption_failure(string $scenario): void
    {
        [$redirect, $state] = $this->begin();
        $id = $redirect->getCookie('dokumen-kita-session')->getValue();
        if ($scenario === 'deleted') {
            DB::table('sessions')->where('id', $id)->delete();
        } elseif ($scenario === 'expired') {
            DB::table('sessions')->where('id', $id)->update(['last_activity' => now()->subMinutes(config('session.lifetime') + 1)->timestamp]);
        } else {
            config(['session.serialization' => 'php']);
        }
        $this->complete($state, $this->cookies($redirect))->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $this->assertGuest('web');
        $this->assertSame([], $this->history);
        $records = $this->logs->getRecords();
        $context = $records[array_key_last($records)]->context;
        $this->assertTrue($context['session_cookie_received']);
        $this->assertTrue($context['session_cookie_readable']);
        $this->assertTrue($context['session_id_matches_cookie']);
        $this->assertFalse($context['session_state_present']);
        $this->assertSame($records[0]->context['session_fingerprint'], $context['session_fingerprint']);
    }

    #[DataProvider('proxyTrust')]
    public function test_proxy_scheme_does_not_destroy_a_received_session_cookie(bool $trusted): void
    {
        config(['trustedproxy.proxies' => $trusted ? ['192.0.2.10'] : []]);
        [$redirect, $state] = $this->begin();
        $response = $this->navigate(self::CALLBACK.'?'.http_build_query(['state' => $state, 'code' => 'oauth-test-code']), $this->cookies($redirect), headers: [
            'REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_PROTO' => 'https',
        ], https: false);
        $response->assertRedirect('http://localhost:5173/documents');
        $this->assertAuthenticated('web');
        $this->assertTrue($response->getCookie('dokumen-kita-session')->isSecure());
        $this->assertCount(2, $this->history);
        $records = $this->logs->getRecords();
        $context = $records[array_key_last($records)]->context;
        $this->assertSame($trusted, $context['request_secure']);
        $this->assertTrue($context['session_cookie_readable']);
        $this->assertTrue($context['session_id_matches_cookie']);
    }

    public static function proxyTrust(): array
    {
        return ['untrusted' => [false], 'trusted' => [true]];
    }

    public function test_domain_and_path_configuration_do_not_prevent_decryption_of_a_received_cookie(): void
    {
        [$redirect, $state] = $this->begin();
        config(['session.domain' => '.dokumenkita.online', 'session.path' => '/api']);
        $this->complete($state, $this->cookies($redirect))->assertRedirect('http://localhost:5173/documents');
        $this->assertAuthenticated('web');
        $this->assertCount(2, $this->history);
    }

    public static function sessionEncryption(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('sessionEncryption')]
    public function test_opt_in_response_diagnostics_verify_saved_state_and_correlate_encrypted_cookies_safely(bool $encrypted): void
    {
        config(['services.google.session_diagnostics' => true, 'session.encrypt' => $encrypted]);
        [$redirect, $state] = $this->begin();
        $records = $this->logs->getRecords();
        $started = $records[0]->context;
        $saved = $records[1]->context;
        $this->assertSame('Google OAuth session response.', $records[1]->message);
        $this->assertSame($started['request_id'], $saved['request_id']);
        $this->assertTrue($saved['session_database_row_present']);
        $this->assertTrue($saved['session_database_payload_readable']);
        $this->assertTrue($saved['session_database_state_present']);
        $this->assertTrue($saved['session_database_state_matches']);
        $this->assertSame($started['session_state_fingerprint'], $saved['session_state_fingerprint']);
        $this->assertSame([hash('sha256', $this->cookies($redirect)['dokumen-kita-session'])], $saved['response_cookie_fingerprints']);
        $this->complete($state, $this->cookies($redirect))->assertRedirect('http://localhost:5173/documents');
        $records = $this->logs->getRecords();
        $this->assertSame($started['session_state_fingerprint'], $records[2]->context['callback_state_fingerprint']);
        $this->assertSame($saved['response_cookie_fingerprints'][0], $records[3]->context['incoming_cookie_fingerprint']);
        $this->assertTrue($records[3]->context['session_id_rotated']);
        $logText = implode('', array_map(fn ($record) => (new LineFormatter)->format($record), $records));
        foreach ([$state, $redirect->getCookie('dokumen-kita-session')->getValue(), $this->cookies($redirect)['dokumen-kita-session'], config('app.key'), app('encrypter')->getKey(), 'oauth-test-code'] as $secret) {
            $this->assertStringNotContainsString($secret, $logText);
        }
    }

    public static function lateSessionResponses(): array
    {
        return [['/api/v1/me'], ['/sanctum/csrf-cookie']];
    }

    #[DataProvider('lateSessionResponses')]
    public function test_late_response_with_another_session_cookie_reproduces_readable_cookie_without_oauth_state(string $path): void
    {
        config(['services.google.session_diagnostics' => true]);
        $initial = $this->navigate('/sanctum/csrf-cookie');
        // Hold this response's cookie until after the OAuth redirect response is applied.
        $late = $this->navigate($path, [], 'http://localhost:5173/');
        $late->assertStatus($path === '/api/v1/me' ? 401 : 204);
        [$redirect, $state] = $this->begin(cookies: $this->cookies($initial));
        $id = $redirect->getCookie('dokumen-kita-session')->getValue();
        $this->assertSame($state, $this->payload($id)['state']);
        $this->complete($state, $this->cookies($late))->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $records = $this->logs->getRecords();
        $started = array_values(array_filter($records, fn ($record) => $record->message === 'Google OAuth redirect started.'))[0]->context;
        $failed = array_values(array_filter($records, fn ($record) => $record->message === 'Google OAuth callback failed.'))[0]->context;
        $this->assertTrue($failed['session_cookie_readable']);
        $this->assertTrue($failed['session_id_matches_cookie']);
        $this->assertFalse($failed['session_state_present']);
        $this->assertFalse($failed['intended_present']);
        $this->assertSame($started['app_key_fingerprint'], $failed['app_key_fingerprint']);
        $this->assertNotSame($started['session_id_fingerprint'], $failed['session_id_fingerprint']);
        $this->assertSame($state, $this->payload($id)['state']);
        $this->assertSame([], $this->history);
    }

    public function test_stale_database_session_save_loses_state_without_changing_the_session_id(): void
    {
        $initial = $this->navigate('/sanctum/csrf-cookie');
        $id = $initial->getCookie('dokumen-kita-session')->getValue();
        $stale = new Store(config('session.cookie'), new DatabaseSessionHandler(DB::connection(), 'sessions', 120), $id, 'json');
        $stale->start();
        [$redirect, $state] = $this->begin(cookies: $this->cookies($initial));
        $this->assertSame($state, $this->payload($id)['state']);
        $stale->save();
        $this->complete($state, $this->cookies($redirect))->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $records = $this->logs->getRecords();
        $this->assertSame($records[0]->context['session_id_fingerprint'], $records[1]->context['session_id_fingerprint']);
        $this->assertFalse($records[1]->context['session_state_present']);
        $this->assertSame([], $this->history);
    }

    public function test_a_delayed_logout_cookie_replaces_the_pending_oauth_session_after_rotation(): void
    {
        config(['services.google.session_diagnostics' => true]);
        [$initial, $firstState] = $this->begin();
        $loggedIn = $this->complete($firstState, $this->cookies($initial));
        $oldId = $loggedIn->getCookie('dokumen-kita-session')->getValue();
        $late = $this->navigate('/api/v1/auth/logout', $this->cookies($loggedIn), 'http://localhost:5173/', method: 'POST');
        $late->assertNoContent();
        $this->assertNotSame($oldId, $late->getCookie('dokumen-kita-session')->getValue());
        [$redirect, $state] = $this->begin(cookies: $this->cookies($loggedIn));
        $this->assertSame($state, $this->payload($redirect->getCookie('dokumen-kita-session')->getValue())['state']);
        $this->complete($state, $this->cookies($late))->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $this->assertGuest('web');
        $records = $this->logs->getRecords();
        $logout = array_values(array_filter($records, fn ($record) => ($record->context['request_route'] ?? null) === 'api/v1/auth/logout'))[0]->context;
        $this->assertTrue($logout['session_id_rotated']);
        $this->assertCount(2, $this->history);
    }
}
