<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\EmailNotification;
use App\Models\Signature;
use App\Models\SignatureAsset;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SignatureService
{
    public function __construct(
        private readonly VersionWorkflow $workflow,
        private readonly StoredPreviewSnapshot $preview,
        private readonly SignatureAssetService $assets,
        private readonly InvitationService $invitations,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Use locking reads for signatures/confirmations: relationship lookups can
     * establish a MySQL repeatable-read snapshot before the document lock is acquired.
     */
    public function sign(User $actor, SignerAssignment $assignment, string $assetGuid, string $contentHash, string $idempotencyKey): Signature
    {
        return DB::transaction(function () use ($actor, $assignment, $assetGuid, $contentHash, $idempotencyKey): Signature {
            $version = $this->workflow->lock($assignment->version);
            $assignments = $version->assignments()->lockForUpdate()->get();
            $locked = $assignments->firstWhere('guid', $assignment->guid);
            if ($locked === null) {
                abort(404);
            }
            $this->workflow->assertSigner($actor, $locked);
            $this->workflow->assertCurrent($version);

            if (! in_array($version->status, [VersionStatus::InProgress, VersionStatus::Finalizing, VersionStatus::Completed], true)) {
                throw ApiException::conflict('VERSION_NOT_SIGNABLE', 'Versi ini sudah tidak dapat ditandatangani.');
            }

            $existing = $locked->signature()->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->asset_guid !== $assetGuid
                    || $existing->idempotency_key !== $idempotencyKey
                    || ! hash_equals($existing->version_content_hash, $contentHash)) {
                    throw ApiException::conflict('IDEMPOTENCY_CONFLICT', 'Assignment sudah ditandatangani dengan request berbeda.');
                }

                return $existing;
            }

            $this->workflow->assertReady($version, $locked);
            if (! $version->content_hash || ! hash_equals($version->content_hash, $contentHash)) {
                throw ApiException::conflict('CONTENT_HASH_MISMATCH', 'Snapshot dokumen berubah. Muat ulang dokumen.');
            }
            $confirmation = $locked->readConfirmation()->lockForUpdate()->first();
            if ($confirmation === null || ! hash_equals($confirmation->version_content_hash, $contentHash)) {
                throw ApiException::conflict('READ_CONFIRMATION_REQUIRED', 'Konfirmasikan membaca snapshot ini sebelum menandatangani.');
            }

            $asset = SignatureAsset::where('guid', $assetGuid)->where('user_guid', $actor->guid)->lockForUpdate()->firstOrFail();
            $this->assets->verify($asset);
            $this->preview->verify($version);

            $signature = $locked->signature()->create([
                'asset_guid' => $asset->guid,
                'version_content_hash' => $contentHash,
                'idempotency_key' => $idempotencyKey,
                'signed_at' => now(),
            ]);
            $locked->update(['status' => 'signed']);
            $this->invitations->revoke($locked);
            $this->audit->record('assignment.signed', $version->document, $version, $actor, [
                'assignment_guid' => $locked->guid, 'signature_guid' => $signature->guid, 'content_hash' => $contentHash,
            ]);

            $next = $assignments->firstWhere('sequence_number', $locked->sequence_number + 1);
            if ($next !== null) {
                if ($next->status !== 'waiting') {
                    throw ApiException::conflict('SIGNING_ORDER_CONFLICT', 'Urutan penandatangan tidak konsisten.');
                }
                $next->update(['status' => 'ready']);
                $this->invitations->revoke($next);
                $this->invitations->issue($next);
                $this->audit->record('assignment.activated', $version->document, $version, $actor, ['assignment_guid' => $next->guid]);
            } else {
                $version->update(['status' => VersionStatus::Finalizing]);
                $version->document->update(['status' => VersionStatus::Finalizing]);
                EmailNotification::create([
                    'version_guid' => $version->guid,
                    'recipient_email' => $version->document->owner->email,
                    'notification_type' => 'version_finalizing',
                    'deduplication_key' => 'finalizing:'.$version->guid,
                    'status' => 'queued',
                ]);
                $this->audit->record('version.finalizing', $version->document, $version, $actor);
            }

            return $signature;
        }, attempts: 3);
    }
}
