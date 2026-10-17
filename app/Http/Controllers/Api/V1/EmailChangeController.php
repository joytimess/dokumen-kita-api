<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailChangeController extends Controller
{
    public function verify(Request $request, string $requestGuid, ProfileService $profile): RedirectResponse
    {
        $verified = $profile->completeEmailChange(
            $requestGuid,
            (string) $request->query('userGuid'),
            (string) $request->query('email'),
            (string) $request->query('signature'),
        );

        $status = $verified ? 'verified' : 'invalid';

        return redirect()->away(rtrim(config('app.frontend_url'), '/').'/email/changed?status='.$status);
    }
}
