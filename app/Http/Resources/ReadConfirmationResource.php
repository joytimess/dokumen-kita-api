<?php

namespace App\Http\Resources;

use App\Models\ReadConfirmation;
use Illuminate\Http\Request;

/** @mixin ReadConfirmation */
class ReadConfirmationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'guid' => $this->guid, 'assignment_guid' => $this->assignment_guid,
            'version_content_hash' => $this->version_content_hash, 'confirmed_at' => $this->confirmed_at,
        ];
    }
}
