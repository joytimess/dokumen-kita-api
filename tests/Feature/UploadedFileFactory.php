<?php

namespace Tests\Feature;

use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;

/**
 * Builds uploaded-file fixtures from raw bytes.
 */
class UploadedFileFactory
{
    public static function content(string $name, string $bytes): File
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }
}
