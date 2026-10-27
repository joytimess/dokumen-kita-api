<?php

namespace App\Services;

use App\Models\DocumentVersionQr;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QrSigningService
{
    public function __construct(
        private readonly QrTokenService $tokens,
        private readonly VersionWorkflow $workflow,
        private readonly AuditRecorder $audit,
        private readonly RealtimeNotificationService $realtime,
    ) {}

    /** @return array{status: string} */
    public function resolve(string $token): array
    {
        $qr = DocumentVersionQr::where('token_hash', hash('sha256', $token))->first();

        return ['status' => $qr === null ? 'invalid' : $this->tokens->state($qr->version, $qr)];
    }

    /** @return array{status: string, document_guid?: string, version_guid?: string, assignment_guid?: string} */
    public function claim(User $actor, string $token): array
    {
        if (! $actor->hasVerifiedEmail()) {
            return ['status' => 'email_not_verified'];
        }

        $hash = hash('sha256', $token);
        $candidate = DocumentVersionQr::where('token_hash', $hash)->first();
        if ($candidate === null) {
            return ['status' => 'invalid'];
        }

        return DB::transaction(function () use ($actor, $candidate, $hash): array {
            $version = $this->workflow->lock($candidate->version);
            $assignments = $version->assignments()->lockForUpdate()->get();
            $qr = DocumentVersionQr::whereKey($candidate->getKey())->lockForUpdate()->first();
            if ($qr === null || ! hash_equals($qr->token_hash, $hash)) {
                return ['status' => 'invalid'];
            }

            $state = $this->tokens->state($version, $qr);
            if ($state !== 'active') {
                return ['status' => $state];
            }

            $assignment = $assignments->firstWhere('normalized_recipient_email', Str::lower(trim($actor->email)));
            if ($assignment === null || ($assignment->user_guid !== null && $assignment->user_guid !== $actor->guid)) {
                return ['status' => 'not_assigned'];
            }
            if ($assignment->status === 'signed') {
                return ['status' => 'already_signed'];
            }
            if ($assignment->status === 'waiting') {
                return ['status' => 'waiting'];
            }
            if ($assignment->status !== 'ready') {
                return ['status' => 'revoked'];
            }

            $this->workflow->assertReady($version, $assignment);
            if ($assignment->user_guid === null) {
                $assignment->update(['user_guid' => $actor->guid, 'claimed_at' => now()]);
                $this->audit->record('invitation.claimed', $version->document, $version, $actor, [
                    'assignment_guid' => $assignment->guid,
                    'access_channel' => 'qr',
                ]);
                $this->realtime->notifyAssignment($version, $assignment, 'assignment.ready');
            }

            return [
                'status' => 'active',
                'document_guid' => $version->document_guid,
                'version_guid' => $version->guid,
                'assignment_guid' => $assignment->guid,
            ];
        }, attempts: 3);
    }
}
