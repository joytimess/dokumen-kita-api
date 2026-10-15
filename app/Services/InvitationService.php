<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\EmailNotification;
use App\Models\InvitationLink;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvitationService
{
    public function __construct(private readonly VersionWorkflow $workflow, private readonly AuditRecorder $audit) {}

    /**
     * Called inside the publish/resend transaction, after locking the assignment.
     * Only the encrypted email payload retains the recoverable invitation URL.
     */
    public function issue(SignerAssignment $assignment): void
    {
        $token = bin2hex(random_bytes(32));
        $invitation = $assignment->invitationLinks()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(max(1, (int) config('documents.invitations.expires_days'))),
        ]);

        EmailNotification::create([
            'version_guid' => $assignment->version_guid,
            'assignment_guid' => $assignment->guid,
            'recipient_email' => $assignment->recipient_email,
            'notification_type' => 'invitation_ready',
            'deduplication_key' => 'invitation:'.$invitation->guid,
            'status' => 'queued',
            'payload' => [
                'invitation_guid' => $invitation->guid,
                'invitation_url' => rtrim(config('app.frontend_url'), '/').'/invitations/claim?token='.$token,
            ],
        ]);
    }

    public function claim(User $actor, string $token): SignerAssignment
    {
        if (! $actor->hasVerifiedEmail()) {
            throw new ApiException(403, 'EMAIL_NOT_VERIFIED', 'Verifikasi email sebelum menerima undangan.');
        }

        $candidate = InvitationLink::where('token_hash', hash('sha256', $token))->first();
        if ($candidate === null) {
            throw $this->invalidInvitation();
        }

        return DB::transaction(function () use ($actor, $candidate, $token): SignerAssignment {
            $version = $this->workflow->lock($candidate->assignment->version);
            $assignment = $version->assignments()->where('guid', $candidate->assignment_guid)->lockForUpdate()->firstOrFail();
            $invitation = InvitationLink::whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();

            if (! hash_equals($invitation->token_hash, hash('sha256', $token))
                || $invitation->revoked_at !== null
                || $invitation->expires_at->lessThanOrEqualTo(now())
                || Str::lower(trim($actor->email)) !== $assignment->normalized_recipient_email) {
                throw $this->invalidInvitation();
            }

            $this->workflow->assertCurrent($version);
            if ($version->status !== VersionStatus::InProgress || $assignment->status !== 'ready') {
                throw ApiException::conflict('INVITATION_NOT_ACTIVE', 'Undangan ini sudah tidak aktif.');
            }
            if ($assignment->user_guid !== null && $assignment->user_guid !== $actor->guid) {
                throw ApiException::conflict('INVITATION_ALREADY_CLAIMED', 'Undangan sudah terikat dengan akun lain.');
            }

            if ($assignment->user_guid === null) {
                $assignment->update(['user_guid' => $actor->guid, 'claimed_at' => now()]);
                $this->audit->record('invitation.claimed', $version->document, $version, $actor, ['assignment_guid' => $assignment->guid]);
            }

            return $assignment->setRelation('version', $version)->load('placeholders');
        });
    }

    public function resend(User $actor, SignerAssignment $assignment): SignerAssignment
    {
        return DB::transaction(function () use ($actor, $assignment): SignerAssignment {
            $version = $this->workflow->lock($assignment->version);
            $this->workflow->assertCurrent($version);
            $locked = $version->assignments()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();

            if ($version->status !== VersionStatus::InProgress || $locked->status !== 'ready') {
                throw ApiException::conflict('INVITATION_NOT_ACTIVE', 'Undangan ini sudah tidak aktif.');
            }

            $this->revoke($locked);

            $this->issue($locked);
            $this->audit->record('invitation.resent', $version->document, $version, $actor, ['assignment_guid' => $locked->guid]);

            return $locked->setRelation('version', $version);
        });
    }

    private function invalidInvitation(): ApiException
    {
        return new ApiException(422, 'INVITATION_INVALID', 'Undangan tidak valid, kedaluwarsa, atau tidak sesuai dengan email akun.');
    }

    /** Call inside the business transaction after locking the assignment. */
    public function revoke(SignerAssignment $assignment): void
    {
        $assignment->invitationLinks()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        EmailNotification::where('assignment_guid', $assignment->guid)
            ->whereIn('notification_type', ['invitation_ready', 'invitation_waiting'])
            ->whereIn('status', ['queued', 'failed'])
            ->update(['status' => 'cancelled', 'payload' => null]);
    }
}
