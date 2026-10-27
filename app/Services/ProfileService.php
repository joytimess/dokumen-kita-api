<?php

namespace App\Services;

use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Notifications\EmailChangeVerification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class ProfileService
{
    public function updateName(User $user, string $name): User
    {
        $user->update(['name' => $name]);

        return $user->refresh()->load('identities');
    }

    public function storePhoto(User $user, UploadedFile $file): User
    {
        $path = $file->getPathname();
        $contents = $file->isValid() && $path !== '' && is_file($path) && is_readable($path)
            ? @file_get_contents($path) : false;
        $image = $contents === false ? false : @getimagesizefromstring($contents);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

        if ($image === false || ! isset($types[$image[2]])) {
            throw ValidationException::withMessages(['photo' => ['Foto harus berupa JPG, PNG, atau WebP yang valid.']]);
        }

        $disk = Storage::disk(config('documents.disk'));
        $path = 'profile-photos/'.$user->guid.'/'.Str::uuid().'.'.$types[$image[2]];

        if (! $disk->put($path, $contents, ['visibility' => 'private'])) {
            throw new \RuntimeException('Foto profil gagal disimpan.');
        }

        try {
            $oldPath = DB::transaction(function () use ($user, $path): ?string {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $oldPath = $locked->profile_photo_path;
                $locked->forceFill(['profile_photo_path' => $path, 'google_photo_url' => null])->save();

                return $oldPath;
            });
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        if ($oldPath !== null) {
            $disk->delete($oldPath);
        }

        return $user->refresh()->load('identities');
    }

    public function deletePhoto(User $user): User
    {
        $oldPath = DB::transaction(function () use ($user): ?string {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $oldPath = $locked->profile_photo_path;
            $locked->forceFill(['profile_photo_path' => null, 'google_photo_url' => null])->save();

            return $oldPath;
        });

        if ($oldPath !== null) {
            Storage::disk(config('documents.disk'))->delete($oldPath);
        }

        return $user->refresh()->load('identities');
    }

    public function requestEmailChange(User $user, string $email): void
    {
        $this->assertEmailPasswordAccount($user);
        $key = 'email-change:'.$user->guid;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }

        DB::transaction(function () use ($user, $email): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertEmailPasswordAccount($locked);

            if ($locked->email === $email) {
                throw ValidationException::withMessages(['email' => ['Email baru harus berbeda dari email aktif.']]);
            }

            if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => ['Email sudah digunakan.']]);
            }

            EmailChangeRequest::where('expires_at', '<=', now())->delete();
            EmailChangeRequest::where('user_guid', $locked->guid)->delete();

            if (EmailChangeRequest::where('new_email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => ['Email sedang diminta oleh akun lain.']]);
            }

            $pending = EmailChangeRequest::create([
                'user_guid' => $locked->guid,
                'new_email' => $email,
                'token_hash' => str_repeat('0', 64),
                'expires_at' => now()->addMinutes(60),
            ]);
            $url = URL::temporarySignedRoute('auth.email-change.verify', $pending->expires_at, [
                'requestGuid' => $pending->guid,
                'userGuid' => $locked->guid,
                'email' => $email,
            ]);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $pending->update(['token_hash' => hash('sha256', $query['signature'] ?? '')]);

            DB::afterCommit(fn () => Notification::route('mail', $email)->notify(new EmailChangeVerification($url)));
        });

        RateLimiter::hit($key, 60);
    }

    public function completeEmailChange(string $requestGuid, string $userGuid, string $email, string $signature): bool
    {
        try {
            return DB::transaction(function () use ($requestGuid, $userGuid, $email, $signature): bool {
                $pending = EmailChangeRequest::where('guid', $requestGuid)->lockForUpdate()->first();

                if ($pending === null || $pending->user_guid !== $userGuid || $pending->new_email !== $email
                    || ! $pending->expires_at->isFuture() || ! hash_equals($pending->token_hash, hash('sha256', $signature))) {
                    return false;
                }

                $user = User::where('guid', $userGuid)->lockForUpdate()->first();

                if ($user === null || $user->hasGoogleIdentity()
                    || User::whereRaw('LOWER(email) = ?', [$email])->where('guid', '!=', $userGuid)->exists()) {
                    return false;
                }

                $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
                $pending->delete();
                DB::afterCommit(fn () => event(new Verified($user)));

                return true;
            });
        } catch (QueryException $exception) {
            if (str_starts_with((string) $exception->getCode(), '23')) {
                return false;
            }

            throw $exception;
        }
    }

    public function changePassword(User $user, string $password, string $sessionId): User
    {
        $this->assertEmailPasswordAccount($user);

        if (config('session.driver') !== 'database') {
            abort(503, 'Invalidasi sesi lain memerlukan session driver database.');
        }

        DB::transaction(function () use ($user, $password, $sessionId): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! filled($locked->password) || $locked->hasGoogleIdentity()) {
                abort(403);
            }

            $locked->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $sessions = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $locked->id);

            if ($sessionId !== '') {
                $sessions->where('id', '!=', $sessionId);
            }

            $sessions->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', $locked->getMorphClass())->where('tokenable_id', $locked->id)->delete();
        });

        return $user->refresh()->load('identities');
    }

    private function assertEmailPasswordAccount(User $user): void
    {
        abort_unless(filled($user->password) && ! $user->hasGoogleIdentity(), 403);
    }
}
