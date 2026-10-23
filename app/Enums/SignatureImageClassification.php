<?php

namespace App\Enums;

enum SignatureImageClassification: string
{
    case Signature = 'signature';
    case NonSignature = 'non_signature';
    case Uncertain = 'uncertain';
}
