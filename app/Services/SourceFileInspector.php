<?php

namespace App\Services;

use App\Enums\SourceType;
use App\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Decides the real type of an uploaded source by its bytes, never by the client-supplied MIME.
 *
 * PDF handling is intentionally limited to detection: editing/adjusting PDF sources is still undecided.
 */
class SourceFileInspector
{
    private const MAX_ZIP_ENTRIES = 2000;

    private const MAX_ZIP_UNCOMPRESSED_BYTES = 209_715_200;

    /**
     * @throws ValidationException
     * @throws ApiException
     */
    public function inspect(UploadedFile $file): SourceType
    {
        $type = $this->detect($file);
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension !== $type->value) {
            throw ValidationException::withMessages(['file' => ['Ekstensi berkas tidak sesuai dengan isinya.']]);
        }

        return $type;
    }

    private function detect(UploadedFile $file): SourceType
    {
        // Windows upload temp directories may be readable without being resolvable by realpath().
        $path = $file->getPathname();
        if (! $file->isValid() || $path === '' || ! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['file' => ['Berkas unggahan tidak tersedia atau tidak dapat dibaca.']]);
        }

        $head = @file_get_contents($path, false, null, 0, 1024);
        if ($head === false) {
            throw ValidationException::withMessages(['file' => ['Berkas unggahan tidak tersedia atau tidak dapat dibaca.']]);
        }

        if (str_contains($head, '%PDF-')) {
            $this->assertPdfNotEncrypted($path);

            return SourceType::Pdf;
        }

        if (str_starts_with($head, "PK\x03\x04") && $this->isDocx($path)) {
            return SourceType::Docx;
        }

        throw ValidationException::withMessages(['file' => ['Hanya berkas PDF dan DOCX yang valid yang diterima.']]);
    }

    /**
     * Best-effort check on the trailer; unsupported PDF features must fail with a clear error.
     */
    private function assertPdfNotEncrypted(string $path): void
    {
        $size = filesize($path) ?: 0;
        $tail = (string) file_get_contents($path, false, null, max(0, $size - 4096));

        if (str_contains($tail, '/Encrypt')) {
            throw new ApiException(422, 'PDF_ENCRYPTED', 'PDF terenkripsi atau dilindungi password tidak didukung.');
        }
    }

    private function isDocx(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                return false;
            }

            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
            }

            return $total <= self::MAX_ZIP_UNCOMPRESSED_BYTES
                && $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName('word/document.xml') !== false;
        } finally {
            $zip->close();
        }
    }
}
