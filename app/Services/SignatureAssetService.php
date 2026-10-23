<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Signature;
use App\Models\SignatureAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SignatureAssetService
{
    public function __construct(private readonly SignatureImageClassificationService $classification) {}

    public function delete(SignatureAsset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            $locked = SignatureAsset::where('guid', $asset->guid)->lockForUpdate()->firstOrFail();
            if (Signature::where('asset_guid', $locked->guid)->exists()) {
                throw ApiException::conflict('SIGNATURE_ASSET_IN_USE', 'Aset tanda tangan sudah digunakan dan tidak dapat dihapus.');
            }

            $locked->delete();
            $disk = Storage::disk(config('documents.disk'));
            if ($disk->exists($locked->file_path) && ! $disk->delete($locked->file_path)) {
                throw new RuntimeException('Unable to delete signature asset.');
            }
        }, attempts: 3);
    }

    public function store(User $actor, UploadedFile $file, string $sourceType): SignatureAsset
    {
        $maxBytes = (int) config('documents.signature_assets.max_kilobytes') * 1024;
        $bytes = $file->isValid() ? file_get_contents($file->getRealPath(), false, null, 0, $maxBytes + 1) : false;
        if ($bytes === false || strlen($bytes) > $maxBytes || ! in_array($sourceType, ['drawn', 'upload'], true)) {
            throw new ApiException(422, 'SIGNATURE_IMAGE_INVALID', 'Gambar tanda tangan tidak valid.', ['file' => ['Berkas gambar tidak valid atau terlalu besar.']]);
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
            || $info[0] < 16 || $info[1] < 16
            || $info[0] > config('documents.signature_assets.max_width')
            || $info[1] > config('documents.signature_assets.max_height')
            || config('documents.signature_assets.max_pixels') < $info[0] * $info[1]) {
            throw new ApiException(422, 'SIGNATURE_IMAGE_INVALID', 'Gambar tanda tangan tidak valid.', ['file' => ['Format atau dimensi gambar tidak didukung.']]);
        }

        if (! function_exists('imagecreatefromstring')) {
            throw new ApiException(503, 'SIGNATURE_IMAGE_PROCESSING_UNAVAILABLE', 'Layanan pemrosesan gambar tanda tangan tidak tersedia.');
        }

        // Some decoders return partial images on warnings; do not accept those as valid files.
        $decodeWarning = false;
        set_error_handler(function () use (&$decodeWarning): bool {
            $decodeWarning = true;

            return true;
        });
        try {
            $image = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
        if ($image === false || $decodeWarning) {
            if ($image !== false) {
                imagedestroy($image);
            }
            throw new ApiException(422, 'SIGNATURE_IMAGE_INVALID', 'Gambar tanda tangan tidak dapat dibaca.', ['file' => ['Berkas gambar rusak atau tidak didukung.']]);
        }

        try {
            imagepalettetotruecolor($image);
            $this->assertHasInk($image, $info[0], $info[1]);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            try {
                if (! imagepng($image)) {
                    throw new RuntimeException('Unable to normalize signature image.');
                }
                $normalized = ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($image);
        }

        // source_type is client-controlled; drawn bitmaps need the same verification as uploads.
        $this->classification->assertSignature($normalized);

        $disk = Storage::disk(config('documents.disk'));
        $path = 'signature-assets/'.$actor->guid.'/'.Str::random(40).'.png';
        if (! $disk->put($path, $normalized, ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to store signature asset.');
        }

        try {
            return SignatureAsset::create([
                'user_guid' => $actor->guid,
                'file_path' => $path,
                'file_hash' => hash('sha256', $normalized),
                'source_type' => $sourceType,
                'mime_type' => 'image/png',
                'size_bytes' => strlen($normalized),
                'width' => $info[0],
                'height' => $info[1],
            ]);
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }

    public function verify(SignatureAsset $asset): void
    {
        $disk = Storage::disk(config('documents.disk'));
        if (! $disk->exists($asset->file_path)) {
            throw ApiException::conflict('SIGNATURE_ASSET_UNAVAILABLE', 'Berkas tanda tangan tidak tersedia.');
        }
        $stream = $disk->readStream($asset->file_path);
        if (! is_resource($stream)) {
            throw ApiException::conflict('SIGNATURE_ASSET_UNAVAILABLE', 'Berkas tanda tangan tidak tersedia.');
        }
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            $hash = hash_final($context);
        } finally {
            fclose($stream);
        }
        if (! hash_equals($asset->file_hash, $hash)) {
            throw ApiException::conflict('SIGNATURE_ASSET_CHANGED', 'Berkas tanda tangan berubah. Unggah aset baru.');
        }
    }

    /**
     * Composite pixels onto white: transparent pixels and white canvases are blank.
     * Uniform opaque fills are rejected by the contrast check.
     */
    private function assertHasInk(\GdImage $image, int $width, int $height): void
    {
        $darkPixels = 0;
        $minimum = 255;
        $maximum = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $opacity = 1 - (($pixel >> 24) & 127) / 127;
                $luminance = (0.299 * (($pixel >> 16) & 255)
                    + 0.587 * (($pixel >> 8) & 255) + 0.114 * ($pixel & 255)) * $opacity + 255 * (1 - $opacity);
                $minimum = min($minimum, $luminance);
                $maximum = max($maximum, $luminance);
                if ($luminance < 245) {
                    $darkPixels++;
                }
            }
        }
        if ($darkPixels < config('documents.signature_assets.min_ink_pixels') || $maximum - $minimum < 15) {
            throw new ApiException(422, 'SIGNATURE_IMAGE_EMPTY', 'Gambar tanda tangan kosong atau tidak memiliki coretan yang terlihat.', ['file' => ['Gambar harus memiliki coretan yang terlihat.']]);
        }
    }
}
