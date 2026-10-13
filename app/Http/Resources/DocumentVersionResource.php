<?php

namespace App\Http\Resources;

use App\Models\DocumentVersion;
use Illuminate\Http\Request;

/**
 * @mixin DocumentVersion
 */
class DocumentVersionResource extends ApiResource
{
    /**
     * Editor content and source details are only exposed to the document owner.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isOwner = $request->user()?->guid === $this->document->owner_guid;

        return [
            'guid' => $this->guid,
            'document_guid' => $this->document_guid,
            'version_number' => $this->version_number,
            'status' => $this->status,
            'preview_status' => $this->preview_status,
            'preview_page_count' => $this->when($isOwner, $this->preview_page_count),
            'assignments' => $this->whenLoaded('assignments', function () use ($isOwner, $request) {
                $assignments = $isOwner ? $this->assignments : $this->assignments->where('user_guid', $request->user()?->guid)->values();
                foreach ($assignments as $assignment) {
                    $assignment->setRelation('version', $this->resource);
                }

                return SignerAssignmentResource::collection($assignments);
            }),
            'source_type' => $this->when($isOwner, $this->source_type),
            'has_source' => $this->when($isOwner, $this->source_path !== null),
            'editor_data' => $this->when($isOwner, $this->editor_data_json),
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
