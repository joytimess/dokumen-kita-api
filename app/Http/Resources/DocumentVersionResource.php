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
            'is_current' => $this->isCurrent(),
            'version_number' => $this->version_number,
            'status' => $this->status,
            'signing_mode' => $this->signing_mode,
            'signing_progress' => $this->when($isOwner && $this->relationLoaded('assignments'), fn (): array => [
                'signed_count' => $this->assignments->where('status', 'signed')->count(),
                'signer_count' => $this->assignments->count(),
            ]),
            'preview_status' => $this->preview_status,
            'preview_source' => $this->when($isOwner, $this->preview_source),
            'preview_error_code' => $this->when($isOwner, $this->preview_error_code),
            'preview_error_message' => $this->when($isOwner, $this->preview_error_message),
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
            'letterhead' => $this->when($isOwner, $this->decoration('letterhead')),
            'footer' => $this->when($isOwner, $this->decoration('footer')),
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function isCurrent(): bool
    {
        if ($this->document->relationLoaded('latestVersion')) {
            $latest = $this->document->latestVersion;

            return $latest === null || $latest->version_number <= $this->version_number;
        }

        return ! $this->document->versions()->where('version_number', '>', $this->version_number)->exists();
    }

    /** @return array<string, mixed>|null */
    private function decoration(string $kind): ?array
    {
        $data = $this->{$kind};
        if ($data === null) {
            return null;
        }

        return [
            'image_url' => route('versions.decorations.image', ['version' => $this->guid, 'kind' => $kind]),
            'pages' => $data['pages'],
        ];
    }
}
