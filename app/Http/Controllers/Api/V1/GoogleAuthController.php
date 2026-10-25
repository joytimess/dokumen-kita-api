<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GoogleAuthService;
use App\Services\UnverifiedGoogleEmailException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * Browser-navigation endpoints for Google OAuth. They run on the `web` middleware group
 * (session + state check) and always answer with a redirect back to the SPA.
 */
class GoogleAuthController extends Controller
{
    private const INTENDED_KEY = 'oauth.intended';

    public function __construct(private readonly GoogleAuthService $google) {}

    public function redirect(Request $request): RedirectResponse
    {
        $statePreexisting = $request->session()->has('state');
        $request->session()->put(self::INTENDED_KEY, $this->internalPath($request->query('redirect')));

        $response = Socialite::driver(GoogleAuthService::PROVIDER)->redirect();
        Log::info('Google OAuth redirect started.', $this->sessionContext($request) + [
            'session_state_preexisting' => $statePreexisting,
        ]);

        return $response;
    }

    public function callback(Request $request): RedirectResponse
    {
        $context = $this->sessionContext($request);
        $intended = (string) $request->session()->pull(self::INTENDED_KEY, '/');

        if ($request->filled('error')) {
            $request->session()->forget('state');

            return $this->toFrontend('/login', ['error' => 'oauth_denied']);
        }

        try {
            $profile = Socialite::driver(GoogleAuthService::PROVIDER)->user();
            $user = $this->google->resolveUser($profile);
        } catch (UnverifiedGoogleEmailException) {
            return $this->toFrontend('/login', ['error' => 'email_not_verified']);
        } catch (Throwable $e) {
            // Provider exception messages and responses can contain OAuth credentials.
            Log::warning('Google OAuth callback failed.', $context + [
                'failure' => $e instanceof InvalidStateException ? 'invalid_state' : 'provider_or_account',
                'exception_type' => $e::class,
            ]);

            return $this->toFrontend('/login', ['error' => 'oauth_failed']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        Log::info('Google OAuth callback succeeded.', $context);

        return $this->toFrontend($intended);
    }

    /**
     * Only same-site absolute paths are accepted, never a full URL or protocol-relative target.
     */
    private function internalPath(mixed $path): string
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return '/';
        }

        return $path;
    }

    /** @return array<string, bool|int|string|null> */
    private function sessionContext(Request $request): array
    {
        $cookie = $request->cookies->all()[config('session.cookie')] ?? null;
        $readable = is_string($cookie) && $request->session()->isValidId($cookie);
        $state = $request->session()->get('state');
        $callbackState = $request->query('state');

        return [
            'request_id' => $request->attributes->get('request_id'),
            'session_fingerprint' => hash_hmac('sha256', $request->session()->getId(), config('app.key')),
            // Independent of APP_KEY so a key mismatch cannot look like an ID change.
            'session_id_fingerprint' => hash('sha256', 'oauth-session:'.$request->session()->getId()),
            'app_key_fingerprint' => substr(hash_hmac('sha256', 'google-oauth-diagnostics', app('encrypter')->getKey()), 0, 16),
            'session_state_fingerprint' => is_string($state) && $state !== '' ? hash('sha256', 'oauth-state:'.$state) : null,
            'callback_state_fingerprint' => is_string($callbackState) && $callbackState !== '' ? hash('sha256', 'oauth-state:'.$callbackState) : null,
            'session_cookie_received' => $request->attributes->get('oauth_session_cookie_received', false),
            'session_cookie_header_count' => $request->attributes->get('oauth_session_cookie_header_count', 0),
            'cookie_header_present' => $request->headers->has('cookie'),
            'session_cookie_readable' => $readable,
            'session_id_matches_cookie' => $readable && hash_equals($request->session()->getId(), $cookie),
            'session_cookie_name' => config('session.cookie'),
            'session_store_cookie_name' => $request->session()->getName(),
            'session_cookie_domain' => config('session.domain'),
            'session_cookie_path' => config('session.path'),
            'session_cookie_secure' => config('session.secure'),
            'session_cookie_same_site' => config('session.same_site'),
            'session_state_present' => $request->session()->has('state'),
            'callback_state_present' => $request->filled('state'),
            'intended_present' => $request->session()->has(self::INTENDED_KEY),
            'request_secure' => $request->isSecure(),
            'request_host' => $request->getHost(),
            'config_cached' => app()->configurationIsCached(),
            'session_driver' => config('session.driver'),
            'session_serialization' => config('session.serialization'),
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    private function toFrontend(string $path, array $query = []): RedirectResponse
    {
        $url = rtrim(config('app.frontend_url'), '/').$path;

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return redirect()->away($url);
    }
}
