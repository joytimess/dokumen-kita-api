<?php

namespace Tests\Feature;

use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;

/**
 * Builds uploaded-file fixtures from raw bytes.
 */
class UploadedFileFactory
{
    public static function withoutRealPath(UploadedFile $file): UploadedFile
    {
        // Keep the fixture alive so its temporary file remains available.
        return new class($file) extends UploadedFile
        {
            public function __construct(private readonly UploadedFile $fixture)
            {
                parent::__construct($fixture->getPathname(), $fixture->getClientOriginalName(), $fixture->getClientMimeType(), $fixture->getError(), true);
            }

            public function getRealPath(): string|false
            {
                return false;
            }
        };
    }

    public static function content(string $name, string $bytes): File
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }
}
