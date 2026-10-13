<?php

namespace App\Http\Resources;

use App\Models\SignaturePlaceholder;
use Illuminate\Http\Request;

/** @mixin SignaturePlaceholder */
class SignaturePlaceholderResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'guid' => $this->guid,
            'page_number' => $this->page_number,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'needs_review' => $this->needs_review,
        ];
    }
}
