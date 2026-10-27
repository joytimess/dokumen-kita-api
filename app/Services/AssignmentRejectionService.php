<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\EmailNotification;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignmentRejectionService
{
    public function __construct(
        private readonly VersionWorkflow $workflow,
        private readonly InvitationService $invitations,
        private readonly AuditRecorder $audit,
        private readonly RealtimeNotificationService $realtime,
        private readonly QrTokenService $qr,
    ) {}

    public function reject(User $actor, SignerAssignment $assignment, string $reason): SignerAssignment
    {
        return DB::transaction(function () use ($actor, $assignment, $reason): SignerAssignment {
            $version = $this->workflow->lock($assignment->version);
            $assignments = $version->assignments()->lockForUpdate()->get();
            $locked = $assignments->firstWhere('guid', $assignment->guid);
            if ($locked === null) {
                abort(404);
            }

            $this->workflow->assertSigner($actor, $locked);
            $this->workflow->assertReady($version, $locked);
            if ($locked->signature()->lockForUpdate()->exists() || $locked->response()->lockForUpdate()->exists()) {
                throw ApiException::conflict('ASSIGNMENT_ALREADY_RESOLVED', 'Assignment ini sudah diproses.');
            }

            $locked->response()->create(['response_type' => 'rejected', 'reason' => $reason]);
            $locked->update(['status' => 'rejected']);
            foreach ($assignments as $current) {
                $this->invitations->revoke($current);
                if ($current->guid !== $locked->guid
                    && ($current->status === 'waiting' || ($version->signing_mode === 'parallel' && $current->status === 'ready'))) {
                    $current->update(['status' => 'cancelled']);
                }
            }
            $version->update(['status' => VersionStatus::Rejected]);
            $this->qr->revoke($version);
            $version->document->update(['status' => VersionStatus::Rejected]);

            EmailNotification::firstOrCreate(['deduplication_key' => 'rejected:'.$version->guid], [
                'version_guid' => $version->guid,
                'recipient_email' => $version->document->owner->email,
                'notification_type' => 'version_rejected',
                'status' => 'queued',
                'payload' => ['reason' => $reason, 'requester' => $actor->name.' <'.$actor->email.'>'],
            ]);
            $this->audit->record('version.rejected', $version->document, $version, $actor, [
                'assignment_guid' => $locked->guid,
                'reason' => $reason,
            ]);
            $this->realtime->notifyOwner($version, 'version.rejected', $locked);

            return $locked->setRelation('version', $version);
        }, attempts: 3);
    }
}
