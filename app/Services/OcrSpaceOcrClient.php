<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OcrSpaceOcrClient
{
    private const ENDPOINT = 'https://api.ocr.space/parse/image';

    public function isConfigured(): bool
    {
        return (bool) config('documents.ocr.enabled')
            && trim((string) config('documents.ocr.api_key')) !== ''
            && (int) config('documents.ocr.timeout') > 0;
    }

    /** @return list<string|null> OCR text in source-page order; null marks a page-level provider failure. */
    public function extractText(string $pdf, int $pageCount): array
    {
        $key = trim((string) config('documents.ocr.api_key'));
        if (! $this->isConfigured()) {
            throw new ApiException(503, 'OCR_PROVIDER_NOT_CONFIGURED', 'OCR belum dikonfigurasi dengan benar pada server.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('documents.ocr.timeout'))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['apikey' => $key])
                ->attach('file', $pdf, 'source.pdf', ['Content-Type' => 'application/pdf'])
                ->post(self::ENDPOINT, [
                    'OCREngine' => '2',
                    'language' => 'auto',
                    'isOverlayRequired' => 'false',
                ]);
        } catch (ConnectionException) {
            throw new ApiException(503, 'OCR_SERVICE_UNAVAILABLE', 'Layanan OCR sedang tidak tersedia.');
        }

        if (in_array($response->status(), [401, 413, 429], true)) {
            $this->throwProviderError($response, []);
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new ApiException(503, 'OCR_SERVICE_UNAVAILABLE', 'Layanan OCR sedang tidak tersedia.');
        }
        if (! $response->successful()) {
            $this->throwProviderError($response, $data);
        }

        $exitCode = filter_var($data['OCRExitCode'] ?? null, FILTER_VALIDATE_INT);
        if (! in_array($exitCode, [1, 2, 3], true)
            || (($data['IsErroredOnProcessing'] ?? false) === true && ! in_array($exitCode, [2, 3], true))) {
            $this->throwProviderError($response, $data);
        }

        $results = $data['ParsedResults'] ?? null;
        if (! is_array($results) || ! array_is_list($results) || count($results) !== $pageCount) {
            throw new ApiException(503, 'OCR_SERVICE_UNAVAILABLE', 'Layanan OCR sedang tidak tersedia.');
        }

        $texts = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                throw new ApiException(503, 'OCR_SERVICE_UNAVAILABLE', 'Layanan OCR sedang tidak tersedia.');
            }
            $pageExitCode = filter_var($result['FileParseExitCode'] ?? null, FILTER_VALIDATE_INT);
            if (in_array($pageExitCode, [0, -10, -20, -30, -99], true)) {
                $texts[] = null;

                continue;
            }
            if ($pageExitCode !== 1 || ! is_string($result['ParsedText'] ?? null)) {
                $this->throwProviderError($response, is_array($result) ? $result : $data);
            }

            $texts[] = $result['ParsedText'];
        }

        return $texts;
    }

    private function throwProviderError(Response $response, array $data): never
    {
        $details = implode(' ', array_filter(array_merge(
            is_string($data['ErrorMessage'] ?? null) ? [$data['ErrorMessage']] : (is_array($data['ErrorMessage'] ?? null) ? $data['ErrorMessage'] : []),
            is_string($data['ErrorDetails'] ?? null) ? [$data['ErrorDetails']] : (is_array($data['ErrorDetails'] ?? null) ? $data['ErrorDetails'] : []),
        ), 'is_string'));

        if ($response->status() === 429 || preg_match('/quota|rate[\s-]?limit|too many requests|requests?.{0,30}(?:limit|quota|exceed)|(?:limit|exceed).{0,30}requests?/i', $details)) {
            throw new ApiException(503, 'OCR_QUOTA_EXHAUSTED', 'Kuota OCR sementara tidak tersedia.');
        }
        if ($response->status() === 413 || preg_match('/(?:file|pdf|page|size).{0,30}limit|too many pages|maximum.{0,20}pages/i', $details)) {
            throw new ApiException(422, 'OCR_LIMIT_EXCEEDED', 'PDF melewati batas OCR gratis: maksimal 1 MB dan 3 halaman.');
        }
        if ($response->status() === 401 || preg_match('/invalid.{0,20}(?:api[\s_-]?key|key)|api[\s_-]?key.{0,20}(?:invalid|missing|unauthori[sz]ed)|unauthori[sz]ed|authentication/i', $details)) {
            throw new ApiException(503, 'OCR_PROVIDER_NOT_CONFIGURED', 'OCR belum dikonfigurasi dengan benar pada server.');
        }

        throw new ApiException(503, 'OCR_SERVICE_UNAVAILABLE', 'Layanan OCR sedang tidak tersedia.');
    }
}
