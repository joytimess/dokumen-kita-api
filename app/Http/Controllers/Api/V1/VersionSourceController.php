<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\DocumentVersion;
use App\Services\PdfFilename;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VersionSourceController extends Controller
{
    public function show(DocumentVersion $version, PdfFilename $filename): StreamedResponse
    {
        Gate::authorize('update', $version);
        if ($version->source_path === null || $version->source_type === null) {
            throw ApiException::conflict('SOURCE_NOT_READY', 'Berkas sumber belum tersedia.');
        }
        $disk = Storage::disk(config('documents.disk'));
        if (! $disk->exists($version->source_path)) {
            throw ApiException::conflict('SOURCE_NOT_READY', 'Berkas sumber tidak tersedia.');
        }

        $type = $version->source_type->value;

        return $disk->response($version->source_path, $version->guid.'.'.$type, [
            'Content-Type' => $type === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => $type === 'pdf' ? $filename->disposition($version) : 'attachment; filename="'.$version->guid.'.'.$type.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
