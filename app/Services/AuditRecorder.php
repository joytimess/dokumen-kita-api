<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;

class AuditRecorder
{
    /**
     * Records a business action; call inside the same transaction as the state change.
     *
     * @param  array<string, mixed>  $metadata  never include raw tokens, document content, or signature images
     */
    public function record(string $action, Document $document, ?DocumentVersion $version, ?User $actor, array $metadata = []): AuditEvent
    {
        return AuditEvent::create([
            'document_guid' => $document->guid,
            'version_guid' => $version?->guid,
            'actor_guid' => $actor?->guid,
            'action' => $action,
            'metadata_json' => $metadata ?: null,
        ]);
    }
}
