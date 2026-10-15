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
            'document_title' => $this->document->title,
            'is_current' => ! $this->document->versions()->where('version_number', '>', $this->version_number)->exists(),
            'version_number' => $this->version_number,
            'status' => $this->status,
            'preview_status' => $this->preview_status,
            'preview_source' => $this->when($isOwner, $this->preview_source),
            'preview_error_code' => $this->when($isOwner, $this->preview_error_code),
            'finalization_error_code' => $this->when($isOwner, $this->finalization_error_code),
            'content_hash' => $this->when($request->user()?->can('viewPdf', $this->resource), $this->content_hash),
            'preview_page_count' => $this->when($isOwner, $this->preview_page_count),
            'conversion_status' => $this->when($isOwner, $this->conversion_status),
            'conversion_generation' => $this->when($isOwner, $this->conversion_generation),
            'conversion_error_code' => $this->when($isOwner, $this->conversion_error_code),
            'conversion_warnings' => $this->when($isOwner, $this->conversion_warnings),
            'conversion_page_count' => $this->when($isOwner, $this->conversion_page_count),
            'editor_revision' => $this->when($isOwner, $this->editor_revision),
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
