<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentOcrPage;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Config as PdfParserConfig;
use Smalot\PdfParser\Parser as PdfParser;
use Smalot\PdfParser\XObject\PdfImage;
use Throwable;

class DocumentOcrService
{
    public function __construct(
        private readonly CloudVisionOcrClient $vision,
        private readonly StoredPreviewSnapshot $preview,
    ) {}

    public function start(DocumentVersion $version, string $expectedHash): array
    {
        $pdf = $this->pdf($version, $expectedHash, false);
        $existing = $this->pages($version, $expectedHash);
        if ($existing->count() === $pdf['pages']) {
            return $this->response($version, $expectedHash, '');
        }
        if (! $this->vision->isConfigured()) {
            return $this->unavailable($version, $expectedHash, $pdf['pages']);
        }

        $pdf = $this->pdf($version, $expectedHash);
        $analysis = $this->scannedPages($pdf['bytes'], $pdf['pages']);
        $ocrPages = $analysis['pages'];
        $limitExceeded = count($ocrPages) > (int) config('documents.ocr.max_pages')
            || (bool) $ocrPages && strlen($pdf['bytes']) > (int) config('documents.ocr.max_bytes');

        return DB::transaction(function () use ($version, $expectedHash, $pdf, $ocrPages, $analysis, $limitExceeded): array {
            $current = DocumentVersion::where('guid', $version->guid)->lockForUpdate()->firstOrFail();
            if (! $current->content_hash || ! hash_equals($current->content_hash, $expectedHash)) {
                throw ApiException::conflict('PDF_VERSION_CHANGED', 'Versi PDF berubah. Muat ulang sebelum mencari.');
            }
            if ($this->pages($current, $expectedHash)->count() === $pdf['pages']) {
                return $this->response($current, $expectedHash, '');
            }

            DocumentOcrPage::where('version_guid', $current->guid)->delete();
            $now = now();
            $rows = [];
            foreach (range(1, $pdf['pages']) as $number) {
                $needsOcr = in_array($number, $ocrPages, true);
                $rows[] = [
                    'version_guid' => $current->guid,
                    'pdf_hash' => $expectedHash,
                    'page_number' => $number,
                    'source' => $needsOcr ? 'ocr' : 'native',
                    'status' => $needsOcr ? ($limitExceeded ? 'failed' : 'queued') : 'completed',
                    'text' => $needsOcr ? null : '',
                    'words' => '[]',
                    'page_rotation' => $analysis['rotations'][$number] ?? null,
                    'error_code' => $needsOcr && $limitExceeded ? 'OCR_LIMIT_EXCEEDED' : null,
                    'retryable' => false,
                    'processed_at' => ! $needsOcr || $limitExceeded ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DocumentOcrPage::insert($rows);

            return $this->response($current, $expectedHash, '');
        });
    }

    public function status(DocumentVersion $version, string $expectedHash, string $query = ''): array
    {
        $this->assertVersionHash($version, $expectedHash);
        $pages = $this->pages($version, $expectedHash);
        if ($pages->isEmpty()) {
            return $this->unavailable($version, $expectedHash, $version->preview_page_count ?? 0);
        }

        return $this->response($version, $expectedHash, $query, $pages);
    }

    /** @param list<int> $pageNumbers */
    public function retry(DocumentVersion $version, string $expectedHash, array $pageNumbers): array
    {
        $this->pdf($version, $expectedHash, false);
        if (! $this->vision->isConfigured()) {
            throw ApiException::conflict('OCR_PROVIDER_NOT_CONFIGURED', 'Layanan OCR belum dikonfigurasi.');
        }

        $pages = $this->pages($version, $expectedHash);
        $retryable = $pages->where('status', 'failed')->where('retryable')->pluck('page_number')->all();
        if (array_diff($pageNumbers, $retryable) !== []) {
            throw ApiException::conflict('OCR_PAGES_NOT_RETRYABLE', 'Hanya halaman OCR gagal yang dapat dicoba ulang.');
        }
        DocumentOcrPage::where('version_guid', $version->guid)->where('pdf_hash', $expectedHash)
            ->whereIn('page_number', $pageNumbers)->update([
                'status' => 'queued', 'error_code' => null, 'retryable' => false, 'processed_at' => null, 'updated_at' => now(),
            ]);

        return $this->response($version, $expectedHash, '');
    }

    public function process(string $versionGuid, string $pdfHash): void
    {
        $version = DocumentVersion::where('guid', $versionGuid)->first();
        if (! $version || ! $version->content_hash || ! hash_equals($version->content_hash, $pdfHash)) {
            DocumentOcrPage::where('version_guid', $versionGuid)->where('pdf_hash', $pdfHash)->delete();

            return;
        }

        $queued = DocumentOcrPage::where('version_guid', $versionGuid)->where('pdf_hash', $pdfHash)
            ->whereIn('status', ['queued', 'processing'])->orderBy('page_number')->pluck('page_number')->all();
        if ($queued === []) {
            return;
        }
        if (! $this->vision->isConfigured()) {
            $this->failPages($versionGuid, $pdfHash, $queued, 'OCR_PROVIDER_NOT_CONFIGURED', false);

            return;
        }

        try {
            $pdf = $this->pdf($version, $pdfHash);
        } catch (ApiException) {
            $this->failPages($versionGuid, $pdfHash, $queued, 'OCR_PDF_UNAVAILABLE', false);

            return;
        }

        foreach (array_chunk($queued, 5) as $batchIndex => $batch) {
            DocumentOcrPage::where('version_guid', $versionGuid)->where('pdf_hash', $pdfHash)->whereIn('page_number', $batch)
                ->whereIn('status', ['queued', 'processing'])->update(['status' => 'processing', 'updated_at' => now()]);
            try {
                $results = $this->vision->annotate($pdf['bytes'], $batch);
            } catch (ApiException $exception) {
                $retryable = in_array($exception->errorCode, ['OCR_SERVICE_UNAVAILABLE', 'OCR_QUOTA_EXHAUSTED'], true);
                $remaining = array_slice($queued, $batchIndex * 5);
                $this->failPages($versionGuid, $pdfHash, $remaining, $exception->errorCode, $retryable);
                break;
            }
            foreach ($batch as $number) {
                $pageResult = $results[$number] ?? ['width' => null, 'height' => null, 'words' => []];
                $words = $pageResult['words'];
                DocumentOcrPage::where('version_guid', $versionGuid)->where('pdf_hash', $pdfHash)->where('page_number', $number)
                    ->update([
                        'status' => 'completed',
                        'text' => implode(' ', array_column($words, 'text')),
                        'words' => json_encode($words, JSON_THROW_ON_ERROR),
                        'page_width' => $pageResult['width'],
                        'page_height' => $pageResult['height'],
                        'error_code' => null,
                        'retryable' => false,
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /** @return array{path: string, bytes: string, pages: int} */
    private function pdf(DocumentVersion $version, string $expectedHash, bool $readBytes = true): array
    {
        $this->assertVersionHash($version, $expectedHash);
        $disk = Storage::disk(config('documents.disk'));
        if ($version->status === VersionStatus::Completed) {
            $path = $version->final_pdf_path;
            if (! $path || ! $version->final_pdf_hash) {
                throw ApiException::conflict('PDF_NOT_READY', 'PDF final belum tersedia.');
            }
            $this->preview->verifyFile($path, $version->final_pdf_hash, 'PDF');
        } else {
            $this->preview->verify($version);
            $path = $version->preview_pdf_path;
        }
        $bytes = $readBytes ? $disk->get($path) : '';

        return ['path' => $path, 'bytes' => $bytes, 'pages' => (int) $version->preview_page_count];
    }

    private function assertVersionHash(DocumentVersion $version, string $expectedHash): void
    {
        if (! $version->content_hash || ! hash_equals($version->content_hash, $expectedHash)) {
            throw ApiException::conflict('PDF_VERSION_CHANGED', 'Versi PDF berubah. Muat ulang sebelum mencari.');
        }
    }

    /** @return array{pages: list<int>, rotations: array<int, int>} */
    private function scannedPages(string $bytes, int $pageCount): array
    {
        try {
            $config = new PdfParserConfig;
            $config->setDecodeMemoryLimit(32 * 1024 * 1024);
            $pages = (new PdfParser([], $config))->parseContent($bytes)->getPages();
            $scanned = [];
            $rotations = [];
            foreach (range(1, $pageCount) as $number) {
                $page = $pages[$number - 1] ?? null;
                if ($page) {
                    $rotations[$number] = (int) (string) $page->get('Rotate');
                }
                $hasImage = $page && collect($page->getXObjects())->contains(fn ($object) => $object instanceof PdfImage);
                if (! $page || trim($page->getText()) === '' || $hasImage) {
                    $scanned[] = $number;
                }
            }

            return ['pages' => $scanned, 'rotations' => $rotations];
        } catch (Throwable) {
            return ['pages' => range(1, max(1, $pageCount)), 'rotations' => []];
        }
    }

    private function pages(DocumentVersion $version, string $hash)
    {
        return DocumentOcrPage::where('version_guid', $version->guid)->where('pdf_hash', $hash)->orderBy('page_number')->get();
    }

    private function response(DocumentVersion $version, string $hash, string $query, $pages = null): array
    {
        $pages ??= $this->pages($version, $hash);
        $total = (int) $version->preview_page_count;
        $completed = $pages->where('status', 'completed')->count();
        $failed = $pages->where('status', 'failed')->count();
        $ocrPages = $pages->where('source', 'ocr');
        $recognizedOcrPages = $ocrPages->where('status', 'completed')->filter(fn (DocumentOcrPage $page) => trim((string) $page->text) !== '')->count();
        if ($pages->isEmpty()) {
            $status = 'unavailable';
        } elseif ($failed === $total) {
            $status = 'failed';
        } elseif ($failed > 0 && $completed + $failed >= $total) {
            $status = 'partial';
        } elseif ($completed === $total) {
            $status = 'completed';
        } elseif ($pages->contains('status', 'processing')) {
            $status = 'processing';
        } else {
            $status = 'queued';
        }

        $matches = $query === '' ? [] : $this->matches($version, $hash, $pages, $query);

        return [
            'document_guid' => $version->document_guid,
            'version_guid' => $version->guid,
            'pdf_hash' => $hash,
            'coordinate_space' => 'normalized_top_left_page_view',
            'processing_status' => $status,
            'page_counts' => ['total' => $total, 'completed' => $completed, 'failed' => $failed],
            'ocr_page_counts' => ['total' => $ocrPages->count(), 'recognized' => $recognizedOcrPages],
            'failed_pages' => $pages->where('status', 'failed')->map(fn (DocumentOcrPage $page) => [
                'page_number' => $page->page_number, 'error_code' => $page->error_code, 'retryable' => $page->retryable,
            ])->values(),
            'matches' => $matches,
        ];
    }

    private function unavailable(DocumentVersion $version, string $hash, int $pageCount): array
    {
        return [
            'document_guid' => $version->document_guid,
            'version_guid' => $version->guid,
            'pdf_hash' => $hash,
            'coordinate_space' => 'normalized_top_left_page_view',
            'processing_status' => 'unavailable',
            'page_counts' => ['total' => $pageCount, 'completed' => 0, 'failed' => 0],
            'ocr_page_counts' => ['total' => 0, 'recognized' => 0],
            'failed_pages' => [],
            'matches' => [],
            'error_code' => 'OCR_PROVIDER_NOT_CONFIGURED',
        ];
    }

    private function matches(DocumentVersion $version, string $hash, $pages, string $query): array
    {
        $matches = [];
        foreach ($pages->where('status', 'completed')->where('source', 'ocr') as $page) {
            $words = $page->words ?? [];
            $text = implode(' ', array_column($words, 'text'));
            $offset = 0;
            while (($position = mb_stripos($text, $query, $offset, 'UTF-8')) !== false && count($matches) < 500) {
                $end = $position + mb_strlen($query, 'UTF-8');
                $cursor = 0;
                $polygons = [];
                foreach ($words as $word) {
                    $wordEnd = $cursor + mb_strlen($word['text'], 'UTF-8');
                    if ($wordEnd > $position && $cursor < $end) {
                        $polygons[] = $word['polygon'];
                    }
                    $cursor = $wordEnd + 1;
                }
                $matches[] = [
                    'match_id' => hash('sha256', implode(':', [$version->guid, $hash, $page->page_number, $position, mb_strtolower($query, 'UTF-8')])),
                    'page_number' => (int) $page->page_number,
                    'snippet' => (mb_strlen($text, 'UTF-8') > 96 ? '…'.mb_substr($text, max(0, $position - 40), 96, 'UTF-8').'…' : $text),
                    'matched_text' => mb_substr($text, $position, mb_strlen($query, 'UTF-8'), 'UTF-8'),
                    'polygons' => $polygons,
                    'page_dimensions' => ['width' => $page->page_width, 'height' => $page->page_height],
                    'page_rotation' => $page->page_rotation,
                ];
                $offset = $position + max(1, mb_strlen($query, 'UTF-8'));
            }
        }

        return $matches;
    }

    /** @param list<int> $pages */
    private function failPages(string $versionGuid, string $hash, array $pages, string $code, bool $retryable): void
    {
        DocumentOcrPage::where('version_guid', $versionGuid)->where('pdf_hash', $hash)->whereIn('page_number', $pages)
            ->whereIn('status', ['queued', 'processing'])->update([
                'status' => 'failed', 'error_code' => $code, 'retryable' => $retryable, 'processed_at' => now(), 'updated_at' => now(),
            ]);
    }
}
