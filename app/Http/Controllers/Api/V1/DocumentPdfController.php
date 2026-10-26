<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\DocumentVersion;
use App\Services\PdfFilename;
use App\Services\StoredPreviewSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentPdfController extends Controller
{
    public function __construct(private readonly StoredPreviewSnapshot $preview) {}

    public function show(Request $request, DocumentVersion $version, PdfFilename $filename): StreamedResponse
    {
        Gate::authorize('viewPdf', $version);
        $request->validate(['download' => ['sometimes', 'boolean']]);
        $disk = Storage::disk(config('documents.disk'));
        if ($version->status === VersionStatus::Completed) {
            $path = $version->final_pdf_path;
            if (! $path || ! $version->final_pdf_hash || ! $disk->exists($path)) {
                throw ApiException::conflict('PDF_NOT_READY', 'PDF final belum tersedia.');
            }
            $this->preview->verifyFile($path, $version->final_pdf_hash, 'PDF');
        } else {
            $this->preview->verify($version);
            $path = $version->preview_pdf_path;
        }

        return $disk->response($path, null, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $filename->disposition(
                $version, $request->boolean('download') ? 'attachment' : 'inline', $version->status === VersionStatus::Completed,
            ),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Document-Content-Hash' => $version->content_hash,
        ]);
    }
}
