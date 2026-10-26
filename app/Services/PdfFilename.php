<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Models\Signature;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class PdfFilename
{
    public function disposition(DocumentVersion $version, string $disposition = 'inline', bool $final = false): string
    {
        $date = $version->created_at;
        if ($final) {
            $date = Signature::query()
                ->whereHas('assignment', fn ($assignments) => $assignments->where('version_guid', $version->guid))
                ->latest('signed_at')->first()?->signed_at;
            if ($date === null) {
                throw ApiException::conflict('SIGNING_COMPLETION_NOT_FOUND', 'Waktu penyelesaian tanda tangan tidak tersedia.');
            }
        }

        // Match the signing history display timezone, independently of the server timezone.
        $suffix = ($final ? '_Signed_' : '_').$date->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d').'.pdf';
        $title = preg_replace('/[\p{C}<>:"\/\\\\|?*.]+/u', '_', $version->document->title ?? '');
        $title = preg_replace('/[\s_]+/u', '_', $title);
        $limit = 180 - strlen($suffix);
        $title = trim(mb_strcut($title, 0, $limit, 'UTF-8'), '_') ?: 'Dokumen';
        $fallback = preg_replace('/[^A-Za-z0-9_-]+/', '_', Str::ascii($title));
        $fallback = trim(substr($fallback, 0, $limit), '_') ?: 'Dokumen';

        return HeaderUtils::makeDisposition($disposition, $title.$suffix, $fallback.$suffix);
    }
}
