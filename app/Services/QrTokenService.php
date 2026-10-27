<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Models\DocumentVersionQr;
use Illuminate\Support\Facades\Crypt;

class QrTokenService
{
    /** Call inside a transaction after locking the document and version. */
    public function ensure(DocumentVersion $version): DocumentVersionQr
    {
        if ($version->published_at === null) {
            throw ApiException::conflict('QR_NOT_AVAILABLE', 'QR tersedia setelah versi diterbitkan.');
        }

        $existing = $version->signingQr()->first();
        if ($existing !== null) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));

        return $version->signingQr()->create([
            'token_hash' => hash('sha256', $token),
            'encrypted_token' => Crypt::encryptString($token),
        ]);
    }

    public function token(DocumentVersionQr $qr): string
    {
        return Crypt::decryptString($qr->encrypted_token);
    }

    public function state(DocumentVersion $version, DocumentVersionQr $qr): string
    {
        if (in_array($version->status, [VersionStatus::RevisionRequested, VersionStatus::Superseded], true)
            || $version->document->versions()->where('version_number', '>', $version->version_number)->exists()) {
            return 'superseded';
        }
        if ($qr->revoked_at !== null || $version->status === VersionStatus::Rejected) {
            return 'revoked';
        }
        if ($version->published_at === null || in_array($version->status, [VersionStatus::Draft, VersionStatus::Preparing], true)) {
            return 'draft';
        }
        if ($version->status === VersionStatus::Completed) {
            return 'completed';
        }
        if ($version->status === VersionStatus::Finalizing
            || ! $version->assignments()->whereIn('status', ['ready', 'waiting'])->exists()) {
            return 'finalizing';
        }

        return $version->status === VersionStatus::InProgress ? 'active' : 'revoked';
    }

    /** Call inside the existing lifecycle transaction. */
    public function revoke(DocumentVersion $version): void
    {
        $version->signingQr()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
