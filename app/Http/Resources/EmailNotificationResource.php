<?php

namespace App\Http\Resources;

use App\Models\EmailNotification;
use Illuminate\Http\Request;

/** @mixin EmailNotification */
class EmailNotificationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'guid' => $this->guid,
            'version_guid' => $this->version_guid,
            'assignment_guid' => $this->assignment_guid,
            'recipient_email' => $this->recipient_email,
            'notification_type' => $this->notification_type,
            'status' => $this->status,
            'attempt_count' => $this->attempt_count,
            'scheduled_at' => $this->scheduled_at,
            'sent_at' => $this->sent_at,
            'last_error_code' => $this->last_error_code,
            'created_at' => $this->created_at,
        ];
    }
}
