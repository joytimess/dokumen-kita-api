<?php

namespace App\Http\Resources;

use App\Models\AuditEvent;
use Illuminate\Http\Request;

/** @mixin AuditEvent */
class AuditEventResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'guid' => $this->guid,
            'version_guid' => $this->version_guid,
            'actor_guid' => $this->actor_guid,
            'action' => $this->action,
            'metadata' => $this->metadata_json,
            'created_at' => $this->created_at,
        ];
    }
}
