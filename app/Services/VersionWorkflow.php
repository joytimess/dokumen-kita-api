<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\Document;
use App\Models\DocumentVersion;

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
        if ($version->document->versions()->where('version_number', '>', $version->version_number)->exists()) {
            throw ApiException::conflict('VERSION_CONFLICT', 'Versi ini bukan versi dokumen terkini.');
        }
    }
}
