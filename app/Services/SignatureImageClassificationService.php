<?php

namespace App\Services;

use App\Contracts\SignatureImageClassifierInterface;
use App\Enums\SignatureImageClassification;
use App\Exceptions\ApiException;
use Throwable;

class SignatureImageClassificationService
{
    public function __construct(private readonly SignatureImageClassifierInterface $classifier) {}

    public function assertSignature(#[\SensitiveParameter] string $png): void
    {
        try {
            $result = $this->classifier->classify($png);
        } catch (Throwable) {
            // Do not retain provider exceptions: they may contain credentials or image data.
            throw new ApiException(503, 'SIGNATURE_CLASSIFIER_UNAVAILABLE', 'Layanan verifikasi gambar tanda tangan tidak tersedia. Silakan coba lagi nanti.');
        }

        if ($result === SignatureImageClassification::NonSignature) {
            throw new ApiException(422, 'SIGNATURE_IMAGE_NOT_SIGNATURE', 'Gambar yang diunggah bukan tanda tangan.', [
                'file' => ['Silakan unggah gambar tanda tangan asli, bukan foto, ilustrasi, atau gambar lainnya.'],
            ]);
        }

        if ($result === SignatureImageClassification::Uncertain) {
            throw new ApiException(422, 'SIGNATURE_IMAGE_UNCERTAIN', 'Gambar belum dapat diverifikasi sebagai tanda tangan.', [
                'file' => ['Silakan unggah gambar tanda tangan yang lebih jelas.'],
            ]);
        }
    }
}
