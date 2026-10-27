<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

class ImageDimensions implements ValidationRule
{
    public function __construct(
        private readonly int $minWidth,
        private readonly int $minHeight,
        private readonly int $maxWidth,
        private readonly int $maxHeight,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Windows FastCGI uploads can be readable even when realpath() returns false.
        $path = $value instanceof UploadedFile ? $value->getPathname() : '';
        $dimensions = $path !== '' && $value->isValid() && is_file($path) && is_readable($path)
            ? @getimagesize($path) : false;

        if ($dimensions === false || $dimensions[0] < $this->minWidth || $dimensions[1] < $this->minHeight
            || $dimensions[0] > $this->maxWidth || $dimensions[1] > $this->maxHeight) {
            $fail('validation.dimensions')->translate();
        }
    }
}
