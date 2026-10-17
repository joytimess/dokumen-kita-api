<?php

namespace App\Services;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class GoogleAuthService
{
    public const PROVIDER = 'google';

    /**
     * Resolve (or provision) the local account for a Google profile.
     *
     * Accounts are only linked by email when Google reports that email as verified. A
     * pre-existing local account whose email was never verified cannot be trusted (anyone
     * could have registered it), so its password is discarded and ownership passes to the
     * verified Google identity.
     *
     * @throws UnverifiedGoogleEmailException
     */
    public function resolveUser(SocialiteUser $profile): User
    {
        return DB::transaction(function () use ($profile): User {
            $identity = AuthIdentity::where('provider', self::PROVIDER)
                ->where('provider_user_id', (string) $profile->getId())
                ->first();

            if ($identity !== null) {
                return $identity->user;
            }

            if (! $this->emailIsVerified($profile)) {
                throw new UnverifiedGoogleEmailException;
            }

            $email = Str::lower(trim((string) $profile->getEmail()));
            $user = User::where('email', $email)->lockForUpdate()->first();

            if ($user === null) {
                $user = new User(['name' => $profile->getName() ?: $email, 'email' => $email]);
                $user->forceFill(['email_verified_at' => now(), 'google_photo_url' => $profile->getAvatar()])->save();
            } elseif (! $user->hasVerifiedEmail()) {
                $user->forceFill(['password' => null, 'remember_token' => Str::random(60), 'email_verified_at' => now()])->save();
            }

            if (blank($user->profile_photo_path) && blank($user->google_photo_url) && filled($profile->getAvatar())) {
                $user->forceFill(['google_photo_url' => $profile->getAvatar()])->save();
            }

            $user->identities()->create([
                'provider' => self::PROVIDER,
                'provider_user_id' => (string) $profile->getId(),
            ]);

            return $user;
        });
    }

    private function emailIsVerified(SocialiteUser $profile): bool
    {
        $raw = $profile->user ?? [];

        return filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && filled($profile->getEmail());
    }
}
