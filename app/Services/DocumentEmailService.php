<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Models\EmailNotification;
use App\Models\InvitationLink;
use App\Models\User;
use App\Notifications\DocumentWorkflowNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class DocumentEmailService
{
    public function __construct(
        private readonly VersionWorkflow $workflow,
        private readonly StoredPreviewSnapshot $snapshot,
    ) {}

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
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $token = $query['token'] ?? null;
                if (! is_string($token) || ! hash_equals($invitation->token_hash, hash('sha256', $token))) {
                    $email->update(['status' => 'failed', 'last_error_code' => 'INVITATION_PAYLOAD_MISSING']);

                    return;
                }

                $registered = User::where('email', Str::lower(trim($email->recipient_email)))
                    ->lockForUpdate()
                    ->first(['guid']) !== null;
                $destination = $registered ? '/login' : '/register';
                $url = rtrim(config('app.frontend_url'), '/').$destination.'?'.http_build_query([
                    'invitation_token' => $token,
                ]);
            } elseif (! (($email->notification_type === 'version_finalizing' && $version->status === VersionStatus::Finalizing)
                || ($email->notification_type === 'version_completed' && $version->status === VersionStatus::Completed)
                || ($email->notification_type === 'revision_requested' && $version->status === VersionStatus::RevisionRequested)
                || ($email->notification_type === 'version_rejected' && $version->status === VersionStatus::Rejected)
                || ($email->notification_type === 'assignment_signed' && $assignment?->signature()
                    ->where('guid', $email->payload['signature_guid'] ?? '')->exists()))) {
                $email->update(['status' => 'cancelled', 'payload' => null]);

                return;
            }

            $email->attempt_count++;
            try {
                if ($email->notification_type === 'version_completed') {
                    $this->snapshot->verifyFile($version->final_pdf_path ?? '', $version->final_pdf_hash ?? '', 'PDF');
                }
                if ($url === null) {
                    $url = rtrim(config('app.frontend_url'), '/').'/documents/'.$version->document_guid;
                    if (in_array($email->notification_type, ['version_completed', 'assignment_signed'], true)) {
                        $url .= '/versions/'.$version->guid.'/view';
                    }
                }
                Notification::route('mail', $email->recipient_email)->notify(new DocumentWorkflowNotification(
                    $email->notification_type, $version->document->title, $version->version_number, $url,
                    in_array($email->notification_type, ['revision_requested', 'version_rejected'], true) ? ($email->payload['reason'] ?? null) : null,
                    $email->payload['requester'] ?? null,
                    $email->payload['completed_at'] ?? null,
                    $email->payload['signed_count'] ?? null,
                    $email->payload['signer_count'] ?? null,
                    $version->signing_mode,
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
