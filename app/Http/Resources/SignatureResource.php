<?php

namespace App\Http\Resources;

use App\Models\Signature;
use Illuminate\Http\Request;

/** @mixin Signature */
class SignatureResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'guid' => $this->guid, 'assignment_guid' => $this->assignment_guid, 'asset_guid' => $this->asset_guid,
            'version_content_hash' => $this->version_content_hash, 'signed_at' => $this->signed_at,
        ];
    }
}
