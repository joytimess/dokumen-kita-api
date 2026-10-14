<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PasswordResetOtp;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class PasswordResetService
{
    public const OTP_LIFETIME = 300;

    public const TOKEN_LIFETIME = 300;

    public const RESEND_DELAY = 60;

    public function sendOtp(string $email): void
    {
        DB::transaction(function () use ($email): void {
            $user = User::where('email', $email)->lockForUpdate()->first();

            if ($user === null || blank($user->password)) {
                return;
            }

            $challenge = DB::table('password_reset_challenges')->where('user_guid', $user->guid)->lockForUpdate()->first();

            if ($challenge !== null && Carbon::parse($challenge->last_sent_at)->addSeconds(self::RESEND_DELAY)->isFuture()) {
                throw new TooManyRequestsHttpException((int) ceil(now()->diffInSeconds(Carbon::parse($challenge->last_sent_at)->addSeconds(self::RESEND_DELAY))));
            }

            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $challengeGuid = (string) Str::uuid();

            DB::table('password_reset_challenges')->updateOrInsert(['user_guid' => $user->guid], [
                'guid' => $challengeGuid,
                'otp_hash' => Hash::make($otp),
                'otp_expires_at' => now()->addSeconds(self::OTP_LIFETIME),
                'failed_attempts' => 0,
                'last_sent_at' => now(),
                'reset_token_hash' => null,
                'reset_token_expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $user->notify(new PasswordResetOtp($challengeGuid, $otp));
        });
    }

    public function verifyOtp(string $email, string $otp): string
    {
        $token = DB::transaction(function () use ($email, $otp): ?string {
            $user = User::where('email', $email)->lockForUpdate()->first();

            if ($user === null || blank($user->password)) {
                return null;
            }

            $query = DB::table('password_reset_challenges')->where('user_guid', $user->guid);
            $challenge = (clone $query)->lockForUpdate()->first();

            if ($challenge === null || $challenge->otp_hash === null || $challenge->failed_attempts >= 5 || ! Carbon::parse($challenge->otp_expires_at)->isFuture()) {
                return null;
            }

            if (! Hash::check($otp, $challenge->otp_hash)) {
                $query->increment('failed_attempts', 1, ['updated_at' => now()]);

                return null;
            }

            $token = bin2hex(random_bytes(32));
            $query->update([
                'otp_hash' => null,
                'reset_token_hash' => hash('sha256', $token),
                'reset_token_expires_at' => now()->addSeconds(self::TOKEN_LIFETIME),
                'updated_at' => now(),
            ]);

            return $token;
        });

        if ($token === null) {
            throw ValidationException::withMessages(['otp' => ['Kode OTP tidak valid atau sudah kedaluwarsa.']]);
        }

        return $token;
    }

    public function reset(string $email, string $token, string $password, Request $request): void
    {
        $user = DB::transaction(function () use ($email, $token, $password): ?User {
            $user = User::where('email', $email)->lockForUpdate()->first();

            if ($user === null || blank($user->password)) {
                return null;
            }

            $challenge = DB::table('password_reset_challenges')->where('user_guid', $user->guid)->lockForUpdate()->first();

            if ($challenge === null || $challenge->reset_token_hash === null || $challenge->reset_token_expires_at === null || ! Carbon::parse($challenge->reset_token_expires_at)->isFuture() || ! hash_equals($challenge->reset_token_hash, hash('sha256', $token))) {
                return null;
            }

            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->delete();
            DB::table('password_reset_challenges')->where('user_guid', $user->guid)->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            DB::afterCommit(fn () => event(new PasswordReset($user)));

            return $user;
        });

        if ($user === null) {
            throw ValidationException::withMessages(['reset_token' => ['Token reset tidak valid atau sudah kedaluwarsa.']]);
        }

        if (Auth::guard('web')->id() === $user->id) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }
    }
}
