<?php

namespace App\Services;

use App\Enums\PreviewStatus;
use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Models\EmailNotification;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RevisionService
{
    public function __construct(
        private readonly VersionWorkflow $workflow,
        private readonly InvitationService $invitations,
        private readonly AuditRecorder $audit,
        private readonly RealtimeNotificationService $realtime,
    ) {}

    /** @return array{previous: DocumentVersion, draft: DocumentVersion} */
    public function request(User $actor, SignerAssignment $assignment, string $reason): array
    {
        return DB::transaction(function () use ($actor, $assignment, $reason): array {
            $version = $this->workflow->lock($assignment->version);
            $assignments = $version->assignments()->orderBy('sequence_number')->lockForUpdate()->get();
            $requester = $assignments->firstWhere('guid', $assignment->guid);
            if ($requester === null) {
                abort(404);
            }

            $this->workflow->assertSigner($actor, $requester);
            $this->workflow->assertReady($version, $requester);
            if ($requester->signature()->lockForUpdate()->exists() || $requester->response()->lockForUpdate()->exists()) {
                throw ApiException::conflict('REVISION_ALREADY_RESOLVED', 'Permintaan pada giliran ini sudah diproses.');
            }

            $requester->response()->create(['response_type' => 'revision_requested', 'reason' => $reason]);
            foreach ($assignments as $current) {
                $this->invitations->revoke($current);
            }
            $requester->update(['status' => 'revision_requested']);
            $version->update(['status' => VersionStatus::RevisionRequested]);

            $draft = $version->document->versions()->create([
                'version_number' => $version->version_number + 1,
                'editor_data_json' => $version->editor_data_json,
                'letterhead' => $version->letterhead,
                'footer' => $version->footer,
                'editor_revision' => 1,
                'conversion_status' => $version->conversion_status === 'ready' ? 'ready' : 'none',
                'conversion_warnings' => $version->conversion_warnings,
                'conversion_page_count' => $version->conversion_page_count,
                'source_type' => $version->source_type,
                'source_path' => $version->source_path,
                'status' => VersionStatus::Draft,
                'preview_status' => PreviewStatus::None,
            ]);
            foreach ($assignments as $current) {
                $copy = $draft->assignments()->create([
                    'recipient_email' => $current->recipient_email,
                    'normalized_recipient_email' => $current->normalized_recipient_email,
                    'sequence_number' => $current->sequence_number,
                    'status' => 'waiting',
                ]);
                foreach ($current->placeholders()->get() as $placeholder) {
                    $copy->placeholders()->create([
                        'page_number' => $placeholder->page_number,
                        'x' => $placeholder->x,
                        'y' => $placeholder->y,
                        'width' => $placeholder->width,
                        'height' => $placeholder->height,
                        'needs_review' => true,
                    ]);
                }
            }
            $version->document->update(['status' => VersionStatus::Draft]);

            EmailNotification::firstOrCreate(['deduplication_key' => 'revision:'.$version->guid], [
                'version_guid' => $version->guid,
                'recipient_email' => $version->document->owner->email,
                'notification_type' => 'revision_requested',
                'status' => 'queued',
                'payload' => ['reason' => $reason, 'requester' => $actor->name.' <'.$actor->email.'>'],
            ]);
            $this->audit->record('version.revision_requested', $version->document, $version, $actor, [
                'assignment_guid' => $requester->guid,
                'next_version_guid' => $draft->guid,
                'reason' => $reason,
            ]);
            $this->realtime->notifyOwner($version, 'version.revision_requested', $requester);

            return ['previous' => $version, 'draft' => $draft];
        }, attempts: 3);
    }
}
