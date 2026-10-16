<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Models\EmailNotification;
use App\Models\InvitationLink;
use App\Notifications\DocumentWorkflowNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DocumentEmailService
{
    public function __construct(private readonly VersionWorkflow $workflow) {}

    public function deliver(string $guid): void
    {
        $candidate = EmailNotification::where('guid', $guid)->first();
        if ($candidate === null) {
            return;
        }

        /** Serialize delivery with invitation rotation using the workflow lock order. */
        DB::transaction(function () use ($candidate): void {
            $version = $this->workflow->lock($candidate->version);
            $assignment = $candidate->assignment_guid === null ? null
                : $version->assignments()->where('guid', $candidate->assignment_guid)->lockForUpdate()->first();
            $email = EmailNotification::whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
            if ($email->status !== 'queued' || $email->attempt_count >= 3 || $email->scheduled_at?->isFuture()) {
                return;
            }

            $url = null;
            if (in_array($email->notification_type, ['invitation_ready', 'invitation_waiting'], true)) {
                $invitation = InvitationLink::where('guid', $email->payload['invitation_guid'] ?? '')
                    ->where('assignment_guid', $email->assignment_guid)->first();
                $expected = $email->notification_type === 'invitation_ready' ? 'ready' : 'waiting';
                if ($assignment === null || $version->status !== VersionStatus::InProgress
                    || $assignment->status !== $expected || $invitation === null
                    || $invitation->revoked_at !== null || $invitation->expires_at->lessThanOrEqualTo(now())
                    || $version->document->versions()->where('version_number', '>', $version->version_number)->exists()) {
                    $email->update(['status' => 'cancelled', 'payload' => null]);

                    return;
                }
                $url = $email->payload['invitation_url'] ?? null;
                if (! is_string($url) || $url === '') {
                    $email->update(['status' => 'failed', 'last_error_code' => 'INVITATION_PAYLOAD_MISSING']);

                    return;
                }
            } elseif (! (($email->notification_type === 'version_finalizing' && $version->status === VersionStatus::Finalizing)
                || ($email->notification_type === 'version_completed' && $version->status === VersionStatus::Completed)
                || ($email->notification_type === 'revision_requested' && $version->status === VersionStatus::RevisionRequested)
                || ($email->notification_type === 'version_rejected' && $version->status === VersionStatus::Rejected))) {
                $email->update(['status' => 'cancelled', 'payload' => null]);

                return;
            }

            $email->attempt_count++;
            try {
                Notification::route('mail', $email->recipient_email)->notify(new DocumentWorkflowNotification(
                    $email->notification_type, $version->document->title, $version->version_number, $url,
                    in_array($email->notification_type, ['revision_requested', 'version_rejected'], true) ? ($email->payload['reason'] ?? null) : null,
                    in_array($email->notification_type, ['revision_requested', 'version_rejected'], true) ? ($email->payload['requester'] ?? null) : null,
                ));
                $email->fill(['status' => 'sent', 'sent_at' => now(), 'payload' => null, 'last_error_code' => null]);
            } catch (Throwable) {
                $email->fill([
                    'status' => $email->attempt_count >= 3 ? 'failed' : 'queued',
                    'scheduled_at' => now()->addSeconds($email->attempt_count === 1 ? 60 : 300),
                    'last_error_code' => 'MAIL_DELIVERY_FAILED',
                ]);
            }
            $email->save();
        });
    }
}
