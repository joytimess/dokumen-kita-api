<?php

namespace App\Http\Resources;

use App\Models\UserNotification;
use Illuminate\Http\Request;

/** @mixin UserNotification */
class UserNotificationResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = $this->payload_json ?? [];
        $documentTitle = $this->document?->title ?? 'dokumen ini';

        return [
            'guid' => $this->guid,
            'event_type' => $this->event_type,
            'title' => $payload['title'] ?? 'Pembaruan dokumen',
            'message' => $payload['message'] ?? 'Ada pembaruan pada dokumen '.$documentTitle.'.',
            'document_guid' => $this->document_guid,
            'version_guid' => $this->version_guid,
            'assignment_guid' => $this->assignment_guid,
            'created_at' => $this->created_at,
            'read_at' => $this->read_at,
        ];
    }
}
