<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignerAssignment;
use App\Models\User;

class VersionWorkflow
{
    /**
     * Call inside a transaction. Lock document, version, then assignments in this order.
     */
    public function lock(DocumentVersion $version): DocumentVersion
    {
        $document = Document::where('guid', $version->document_guid)->lockForUpdate()->firstOrFail();
        $locked = DocumentVersion::whereKey($version->getKey())->lockForUpdate()->firstOrFail();

        return $locked->setRelation('document', $document);
    }

    public function assertDraft(DocumentVersion $version): void
    {
        if ($version->status !== VersionStatus::Draft) {
            throw ApiException::conflict('VERSION_NOT_EDITABLE', 'Versi ini sudah tidak dapat diubah. Buat revisi untuk mengubah dokumen.');
        }
    }

    public function assertCurrent(DocumentVersion $version): void
    {
        if ($version->document->versions()->where('version_number', '>', $version->version_number)->lockForUpdate()->first(['guid']) !== null) {
            throw ApiException::conflict('VERSION_CONFLICT', 'Versi ini bukan versi dokumen terkini.');
        }
    }

    public function assertSigner(User $actor, SignerAssignment $assignment): void
    {
        if ($assignment->user_guid !== $actor->guid) {
            throw new ApiException(403, 'FORBIDDEN', 'Assignment tidak terikat dengan akun Anda.');
        }
        if (! $actor->hasVerifiedEmail()) {
            throw new ApiException(403, 'EMAIL_NOT_VERIFIED', 'Verifikasi email sebelum menandatangani.');
        }
    }

    public function assertReady(DocumentVersion $version, SignerAssignment $assignment): void
    {
        $this->assertCurrent($version);
        if ($version->status !== VersionStatus::InProgress) {
            throw ApiException::conflict('VERSION_NOT_SIGNABLE', 'Versi ini sudah tidak dapat ditandatangani.');
        }
        if ($assignment->status !== 'ready') {
            throw ApiException::conflict('ASSIGNMENT_NOT_READY', 'Assignment belum siap atau sudah selesai.');
        }
    }
}
