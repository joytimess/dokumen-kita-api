<?php

namespace App\Policies;

use App\Models\SignatureAsset;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SignatureAssetPolicy
{
    public function view(User $user, SignatureAsset $asset): Response
    {
        return $asset->user_guid === $user->guid ? Response::allow() : Response::denyAsNotFound();
    }
}
