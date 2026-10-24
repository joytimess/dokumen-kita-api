<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;

/**
 * @mixin Document
 */
class DocumentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->relationLoaded('latestVersion') && $this->latestVersion !== null) {
            $this->latestVersion->setRelation('document', $this->resource);
        }

        return [
            'guid' => $this->guid,
            'public_reference' => $this->public_reference,
            'title' => $this->title,
            'status' => $this->status,
            'hidden_at' => $this->hidden_at,
            'is_hidden' => $this->hidden_at !== null,
            'owner_guid' => $this->owner_guid,
            'category_guid' => $this->category_guid,
            'category' => new DocumentCategoryResource($this->whenLoaded('category')),
            'current_version' => new DocumentVersionResource($this->whenLoaded('latestVersion')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
