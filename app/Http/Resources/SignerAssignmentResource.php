<?php

namespace App\Http\Resources;

use App\Models\SignerAssignment;
use Illuminate\Http\Request;

/** @mixin SignerAssignment */
class SignerAssignmentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isOwner = $request->user()?->guid === $this->version->document->owner_guid;
        $canViewPlaceholders = $isOwner || $this->status !== 'waiting';

        return [
            'guid' => $this->guid,
            'version_guid' => $this->version_guid,
            'document_guid' => $this->version->document_guid,
            'document_title' => $this->version->document->title,
            'version_number' => $this->version->version_number,
            'recipient_email' => $this->recipient_email,
            'sequence_number' => $this->sequence_number,
            'status' => $this->status,
            'claimed_at' => $this->claimed_at,
            'placeholders' => $this->when($canViewPlaceholders, fn () => SignaturePlaceholderResource::collection($this->whenLoaded('placeholders'))),
            'read_confirmation' => $this->when($canViewPlaceholders, fn () => new ReadConfirmationResource($this->whenLoaded('readConfirmation'))),
            'signature' => $this->when($canViewPlaceholders, fn () => new SignatureResource($this->whenLoaded('signature'))),
        ];
    }
}
