<?php

namespace App\Http\Resources;

use App\Models\SignatureAsset;
use Illuminate\Http\Request;

/** @mixin SignatureAsset */
class SignatureAssetResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'guid' => $this->guid, 'source_type' => $this->source_type, 'mime_type' => $this->mime_type,
            'width' => $this->width, 'height' => $this->height, 'size_bytes' => $this->size_bytes,
            'image_url' => route('signature-assets.image', ['asset' => $this->guid]), 'created_at' => $this->created_at,
        ];
    }
}
