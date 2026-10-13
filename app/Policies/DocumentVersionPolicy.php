<?php

namespace App\Policies;

use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DocumentVersionPolicy
{
    /**
     * The owner sees every version; a recipient only sees versions they are assigned to (decision B08).
     */
    public function view(User $user, DocumentVersion $version): Response
    {
        if ($this->owns($user, $version) || $this->isAssigned($user, $version)) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function update(User $user, DocumentVersion $version): Response
    {
        if ($this->owns($user, $version)) {
            return Response::allow();
        }

        return $this->isAssigned($user, $version) ? Response::deny() : Response::denyAsNotFound();
    }

    private function owns(User $user, DocumentVersion $version): bool
    {
        return $version->document->owner_guid === $user->guid;
    }

    private function isAssigned(User $user, DocumentVersion $version): bool
    {
        return $version->assignments()->where('user_guid', $user->guid)->exists();
    }
}
