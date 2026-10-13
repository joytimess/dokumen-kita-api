<?php

namespace App\Services;

use App\Enums\PreviewStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;

class StoredPreviewSnapshot
{
    /**
     * The renderer persists a unique private object, its SHA-256 and page count.
     * Published versions reuse this immutable object rather than rendering again.
     */
    public function verify(DocumentVersion $version): void
    {
        if ($version->preview_status !== PreviewStatus::Ready
            || ! $version->preview_pdf_path
            || ! $version->content_hash
            || $version->preview_page_count < 1) {
            throw ApiException::conflict('PREVIEW_NOT_READY', 'Preview terbaru belum siap.');
        }

        $this->verifyFile($version->preview_pdf_path, $version->content_hash);
    }

    public function verifyFile(string $path, string $expectedHash, string $errorPrefix = 'PREVIEW'): void
    {
        $disk = Storage::disk(config('documents.disk'));
        if (! $disk->exists($path)) {
            throw ApiException::conflict($errorPrefix.'_NOT_READY', 'Berkas PDF tidak tersedia.');
        }

        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw ApiException::conflict($errorPrefix.'_NOT_READY', 'Berkas PDF tidak tersedia.');
        }

        try {
            $header = fread($stream, 5);
            $context = hash_init('sha256');
            hash_update($context, $header);
            hash_update_stream($context, $stream);
            $hash = hash_final($context);
        } finally {
            fclose($stream);
        }

        if ($header !== '%PDF-' || ! hash_equals($expectedHash, $hash)) {
            throw ApiException::conflict($errorPrefix.'_MISMATCH', 'Berkas PDF tidak sesuai dengan hash yang tersimpan.');
        }
    }
}
