<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\ReadConfirmation;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReadConfirmationService
{
    public function __construct(
        private readonly VersionWorkflow $workflow,
        private readonly StoredPreviewSnapshot $preview,
        private readonly AuditRecorder $audit,
    ) {}

    public function confirm(User $actor, SignerAssignment $assignment, string $contentHash): ReadConfirmation
    {
        return DB::transaction(function () use ($actor, $assignment, $contentHash): ReadConfirmation {
            $version = $this->workflow->lock($assignment->version);
            $locked = $version->assignments()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();
            $this->workflow->assertSigner($actor, $locked);
            $this->workflow->assertCurrent($version);

            if (! $version->content_hash || ! hash_equals($version->content_hash, $contentHash)) {
                throw ApiException::conflict('CONTENT_HASH_MISMATCH', 'Snapshot dokumen berubah. Muat ulang dokumen.');
            }

            $existing = $locked->readConfirmation()->lockForUpdate()->first();
            if ($existing !== null && hash_equals($existing->version_content_hash, $contentHash)
                && in_array($version->status, [VersionStatus::InProgress, VersionStatus::Finalizing, VersionStatus::Completed], true)) {
                return $existing;
            }

            $this->workflow->assertReady($version, $locked);
            if ($existing !== null) {
                throw ApiException::conflict('READ_CONFIRMATION_MISMATCH', 'Konfirmasi baca tidak cocok dengan snapshot ini.');
            }
            $this->preview->verify($version);
            $confirmation = $locked->readConfirmation()->create([
                'version_content_hash' => $contentHash,
                'confirmed_at' => now(),
            ]);
            $this->audit->record('assignment.read_confirmed', $version->document, $version, $actor, [
                'assignment_guid' => $locked->guid, 'content_hash' => $contentHash,
            ]);

            return $confirmation;
        });
    }
}
