<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\SignatureAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SignatureAssetService
{
    public function store(User $actor, UploadedFile $file, string $sourceType): SignatureAsset
    {
        $bytes = file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
            || $info[0] > config('documents.signature_assets.max_width')
            || $info[1] > config('documents.signature_assets.max_height')) {
            throw new ApiException(422, 'SIGNATURE_IMAGE_INVALID', 'Gambar tanda tangan tidak valid.');
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new ApiException(422, 'SIGNATURE_IMAGE_INVALID', 'Gambar tanda tangan tidak dapat dibaca.');
        }

        try {
            imagepalettetotruecolor($image);
            $this->assertHasInk($image, $info[0], $info[1]);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            try {
                imagepng($image);
                $normalized = ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($image);
        }

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
            throw new ApiException(422, 'SIGNATURE_IMAGE_EMPTY', 'Gambar tanda tangan kosong atau tidak memiliki coretan yang terlihat.');
        }
    }
}
