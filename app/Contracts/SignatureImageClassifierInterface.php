<?php

namespace App\Contracts;

use App\Enums\SignatureImageClassification;

interface SignatureImageClassifierInterface
{
    /** Classify normalized PNG bytes in memory; never log the image or raw provider response. */
    public function classify(#[\SensitiveParameter] string $png): SignatureImageClassification;
}
