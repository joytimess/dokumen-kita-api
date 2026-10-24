<?php

namespace App\Providers;

use App\Contracts\SignatureImageClassifierInterface;
use App\Models\SignerAssignment;
use App\Models\User;
use App\Services\GoogleOAuthProvider;
use App\Services\GroqSignatureImageClassifier;
use App\Services\UnconfiguredSignatureImageClassifier;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Unknown driver names must also fail closed.
        $this->app->bind(SignatureImageClassifierInterface::class, fn () => match (config('documents.signature_assets.classifier')) {
            'groq' => new GroqSignatureImageClassifier,
            default => new UnconfiguredSignatureImageClassifier,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureAuthNotifications();
        Socialite::extend('google', fn (Application $app): GoogleOAuthProvider => Socialite::buildProvider(
            GoogleOAuthProvider::class, $app->make('config')->get('services.google')
        ));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('ai-chat', fn (Request $request) => [
            Limit::perMinute((int) config('chatbot.per_minute'))->by('ai-chat-user:'.$request->user()?->guid),
            Limit::perMinute(30)->by('ai-chat-ip:'.$request->ip()),
        ]);
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by((string) $request->user()?->guid));
        RateLimiter::for('signature-classification', fn (Request $request) => Limit::perMinute(config('documents.signature_assets.classification_per_minute'))->by('signature-classification:'.$request->user()?->guid));
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('password-forgot', function (Request $request): array {
            $emailKey = hash('sha256', Str::lower(trim((string) $request->input('email'))));

            return [
                Limit::perMinute(10)->by('password-forgot-ip:'.$request->ip()),
                Limit::perMinute(1)->by('password-forgot-minute:'.$emailKey),
                Limit::perHour(5)->by('password-forgot-hour:'.$emailKey),
            ];
        });
        RateLimiter::for('password-verify', fn (Request $request) => Limit::perMinute(10)->by('password-verify:'.$request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)->by('password-reset:'.$request->ip()));
        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->guid.'|'.$request->ip()));
        RateLimiter::for('invitation-resend', function (Request $request): Limit {
            $assignment = $request->route('assignment');
            $assignmentGuid = $assignment instanceof SignerAssignment ? $assignment->guid : (string) $assignment;

            return Limit::perMinute(3)->by($request->user()?->guid.'|'.$assignmentGuid);
        });
    }

    /**
     * Verification links point at the API using the user guid (never the numeric id).
     */
    private function configureAuthNotifications(): void
    {
        VerifyEmail::createUrlUsing(fn (User $user) => URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['userGuid' => $user->guid, 'hash' => sha1($user->getEmailForVerification())],
        ));

    }
}
