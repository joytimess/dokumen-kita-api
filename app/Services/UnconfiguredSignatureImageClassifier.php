<?php

namespace App\Services;

use App\Contracts\SignatureImageClassifierInterface;
use App\Enums\SignatureImageClassification;
use RuntimeException;

class UnconfiguredSignatureImageClassifier implements SignatureImageClassifierInterface
{
    public function classify(#[\SensitiveParameter] string $png): SignatureImageClassification
    {
        throw new RuntimeException('Signature image classifier is not configured.');
    }
}
