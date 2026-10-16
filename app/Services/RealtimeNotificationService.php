<?php

namespace App\Services;

use App\Events\DocumentStatusChanged;
use App\Models\DocumentVersion;
use App\Models\RealtimeDelivery;
use App\Models\SignerAssignment;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RealtimeNotificationService
{
    public function notifyOwner(DocumentVersion $version, string $eventType, ?SignerAssignment $assignment = null): void
    {
        $this->notify($version->document->owner, $version, $eventType, $assignment);
    }

    public function notifyAssignment(DocumentVersion $version, SignerAssignment $assignment, string $eventType): void
    {
        if ($assignment->user_guid !== null) {
            $this->notify($assignment->user, $version, $eventType, $assignment);
        }
    }

    public function deliver(string $deliveryGuid): void
    {
        $candidate = RealtimeDelivery::where('guid', $deliveryGuid)->first();
        if ($candidate === null) {
            return;
        }

        DB::transaction(function () use ($candidate): void {
            $delivery = RealtimeDelivery::whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
            if ($delivery->status !== 'queued' || $delivery->attempt_count >= 3
                || $delivery->next_attempt_at?->isFuture()) {
                return;
            }

            $notification = $delivery->notification()->firstOrFail();
            $attempt = $delivery->attempt_count + 1;

            try {
                $payload = $notification->payload_json ?? [];
                $payload['notification_guid'] = $notification->guid;
                event(new DocumentStatusChanged($notification->user_guid, $payload));
                $delivery->update(['status' => 'delivered', 'attempt_count' => $attempt, 'next_attempt_at' => null, 'delivered_at' => now()]);
            } catch (Throwable $exception) {
                $delivery->update([
                    'status' => $attempt >= 3 ? 'failed' : 'queued',
                    'attempt_count' => $attempt,
                    'next_attempt_at' => $attempt >= 3 ? null : ($attempt === 1 ? now()->addMinute() : now()->addMinutes(5)),
                ]);
                Log::warning('Realtime notification delivery failed.', [
                    'notification_guid' => $notification->guid,
                    'attempt' => $attempt,
                    'error' => class_basename($exception),
                ]);
            }
        });
    }

    private function notify(User $recipient, DocumentVersion $version, string $eventType, ?SignerAssignment $assignment): void
    {
        $assignmentGuid = $assignment?->guid;
        $deduplicationKey = $eventType.':'.$version->guid.':'.($assignmentGuid ?? 'version');
        $notification = UserNotification::firstOrCreate([
            'user_guid' => $recipient->guid,
            'deduplication_key' => $deduplicationKey,
        ], [
            'document_guid' => $version->document_guid,
            'version_guid' => $version->guid,
            'assignment_guid' => $assignmentGuid,
            'event_type' => $eventType,
            'payload_json' => [
                'event_type' => $eventType,
                'document_guid' => $version->document_guid,
                'version_guid' => $version->guid,
                'version_status' => $version->status->value,
                'assignment_guid' => $assignmentGuid,
                'assignment_status' => $assignment?->status,
            ],
        ]);

        RealtimeDelivery::firstOrCreate(['notification_guid' => $notification->guid]);
    }
}
