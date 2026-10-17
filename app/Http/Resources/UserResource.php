<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * @mixin User
 */
class UserResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('identities');
        $google = $this->resource->identities->contains('provider', 'google');
        $hasPassword = filled($this->resource->password);

        return [
            'guid' => $this->guid,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'login_methods' => array_values(array_filter([
                $hasPassword ? 'email_password' : null,
                $google ? 'google' : null,
            ])),
            'capabilities' => [
                'can_change_name' => true,
                'can_change_photo' => true,
                'can_change_email' => $hasPassword && ! $google,
                'can_change_password' => $hasPassword && ! $google,
            ],
            'photo_url' => $this->profile_photo_path !== null
                ? URL::route('me.photo')
                : $this->google_photo_url,
        ];
    }
}
