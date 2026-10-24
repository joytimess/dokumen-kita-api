<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DocumentPolicy
{
    /**
     * Owner or any recipient of one of the document's versions; everyone else gets a 404.
     */
    public function view(User $user, Document $document): Response
    {
        if ($this->owns($user, $document) || $this->isRecipient($user, $document)) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function manage(User $user, Document $document): Response
    {
        if ($this->owns($user, $document)) {
            return Response::allow();
        }

        return $this->isRecipient($user, $document) ? Response::deny() : Response::denyAsNotFound();
    }

    private function owns(User $user, Document $document): bool
    {
        return $document->owner_guid === $user->guid;
    }

    private function isRecipient(User $user, Document $document): bool
    {
        if ($document->relationLoaded('recipientMemberships')) {
            return $document->recipientMemberships->contains('user_guid', $user->guid);
        }

        return SignerAssignment::query()
            ->where('user_guid', $user->guid)
            ->whereHas('version', fn ($query) => $query->where('document_guid', $document->guid))
            ->exists();
    }
}
