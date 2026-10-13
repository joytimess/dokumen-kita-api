<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GoogleAuthService;
use App\Services\UnverifiedGoogleEmailException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
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
        $request->session()->put(self::INTENDED_KEY, $this->internalPath($request->query('redirect')));

        return Socialite::driver(GoogleAuthService::PROVIDER)->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $intended = (string) $request->session()->pull(self::INTENDED_KEY, '/');

        if ($request->filled('error')) {
            return $this->toFrontend('/login', ['error' => 'oauth_denied']);
        }

        try {
            $profile = Socialite::driver(GoogleAuthService::PROVIDER)->user();
            $user = $this->google->resolveUser($profile);
        } catch (UnverifiedGoogleEmailException) {
            return $this->toFrontend('/login', ['error' => 'email_not_verified']);
        } catch (Throwable $e) {
            report($e);

            return $this->toFrontend('/login', ['error' => 'oauth_failed']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->toFrontend($intended);
    }

    /**
     * Only same-site absolute paths are accepted, never a full URL or protocol-relative target.
     */
    private function internalPath(mixed $path): string
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return '/';
        }

        return $path;
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
