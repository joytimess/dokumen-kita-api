<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\DocumentVersion;
use App\Services\StoredPreviewSnapshot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentPdfController extends Controller
{
    public function __construct(private readonly StoredPreviewSnapshot $preview) {}

    public function show(DocumentVersion $version): StreamedResponse
    {
        Gate::authorize('viewPdf', $version);
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

        return $disk->response($path, $version->guid.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Document-Content-Hash' => $version->content_hash,
        ]);
    }
}
