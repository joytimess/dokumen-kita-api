<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Target of the signed link in the verification email; works without a session.
     */
    public function verify(string $userGuid, string $hash): RedirectResponse
    {
        $user = User::where('guid', $userGuid)->firstOrFail();

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->away(rtrim(config('app.frontend_url'), '/').'/email/verified');
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'data' => ['status' => 'sent'],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 202);
    }
}
