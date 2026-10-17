<?php

namespace App\Http\Resources;

use App\Models\AuditEvent;
use Illuminate\Http\Request;

/** @mixin AuditEvent */
class SigningHistoryResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = $this->metadata_json ?? [];
        $snapshot = $metadata['actor_snapshot'] ?? null;
        $canAccess = $this->document !== null && $request->user() !== null
            && $request->user()->can('view', $this->document);

        return [
            'guid' => $this->guid,
            'action' => $this->action,
            'occurred_at' => $this->created_at,
            'document' => [
                'guid' => $canAccess ? $this->document->guid : null,
                'title' => $this->document->title,
                'status' => $this->document->status->value,
                'owner' => [
                    'guid' => $this->document->owner->guid,
                    'name' => $this->document->owner->name,
                    'email' => $this->document->owner->email,
                ],
            ],
            'version_guid' => $canAccess ? $this->version_guid : null,
            'actor_snapshot_available' => is_array($snapshot)
                && filled($snapshot['name'] ?? null) && filled($snapshot['email'] ?? null),
            'actor_snapshot' => [
                'name' => $snapshot['name'] ?? null,
                'email' => $snapshot['email'] ?? null,
            ],
            'can_access_document' => $canAccess,
            'revision_reason' => $this->when($request->routeIs('me.history.show') && $this->action === 'version.revision_requested', $metadata['reason'] ?? null),
        ];
    }
}
