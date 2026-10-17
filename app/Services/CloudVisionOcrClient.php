<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CloudVisionOcrClient
{
    public function isConfigured(): bool
    {
        $path = config('documents.ocr.credentials');

        return config('documents.ocr.enabled') && is_string($path) && is_file($path);
    }

    /** @param list<int> $pages
     * @return array<int, array{width: int, height: int, words: list<array{text: string, polygon: list<array{float, float}>, confidence: float|null}>}>
     */
    public function annotate(string $pdf, array $pages): array
    {
        $token = $this->accessToken();
        $response = Http::withToken($token)->acceptJson()->timeout((int) config('documents.ocr.timeout'))
            ->post('https://vision.googleapis.com/v1/files:annotate', [
                'requests' => [[
                    'inputConfig' => ['mimeType' => 'application/pdf', 'content' => base64_encode($pdf)],
                    'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
                    'pages' => $pages,
                ]],
            ]);

        if (! $response->successful()) {
            $this->throwProviderError((int) $response->status(), (string) data_get($response->json(), 'error.message', ''));
        }

        $result = [];
        $responses = $response->json('responses', []);
        foreach ($responses as $responseIndex => $fileResponse) {
            if (isset($fileResponse['error'])) {
                $this->throwProviderError(
                    (int) ($fileResponse['error']['code'] ?? 0),
                    (string) ($fileResponse['error']['message'] ?? ''),
                );
            }
            foreach ($fileResponse['responses'] ?? [$fileResponse] as $pageIndex => $pageResponse) {
                if (isset($pageResponse['error'])) {
                    $this->throwProviderError(
                        (int) ($pageResponse['error']['code'] ?? 0),
                        (string) ($pageResponse['error']['message'] ?? ''),
                    );
                }
                $responsePages = data_get($pageResponse, 'fullTextAnnotation.pages', []);
                foreach ($responsePages as $page) {
                    $pageNumber = (int) data_get($pageResponse, 'context.pageNumber', 0)
                        ?: $pages[$pageIndex] ?? $pages[$responseIndex] ?? 0;
                    if ($pageNumber < 1) {
                        continue;
                    }
                    $width = max(1, (int) ($page['width'] ?? 1));
                    $height = max(1, (int) ($page['height'] ?? 1));
                    $words = [];
                    foreach ($page['blocks'] ?? [] as $block) {
                        foreach ($block['paragraphs'] ?? [] as $paragraph) {
                            foreach ($paragraph['words'] ?? [] as $word) {
                                $text = implode('', array_column($word['symbols'] ?? [], 'text'));
                                $box = $word['boundingBox'] ?? [];
                                $normalized = ! empty($box['normalizedVertices']);
                                $vertices = $box['normalizedVertices'] ?? $box['vertices'] ?? [];
                                $polygon = [];
                                foreach ($vertices as $vertex) {
                                    $x = (float) ($vertex['x'] ?? 0);
                                    $y = (float) ($vertex['y'] ?? 0);
                                    $polygon[] = [max(0.0, min(1.0, $normalized ? $x : $x / $width)), max(0.0, min(1.0, $normalized ? $y : $y / $height))];
                                }
                                if ($text !== '' && count($polygon) >= 4) {
                                    $words[] = ['text' => $text, 'polygon' => $polygon, 'confidence' => isset($word['confidence']) ? (float) $word['confidence'] : null];
                                }
                            }
                        }
                    }
                    $result[$pageNumber] = ['width' => $width, 'height' => $height, 'words' => $words];
                }
            }
        }

        return $result;
    }

    private function accessToken(): string
    {
        $path = (string) config('documents.ocr.credentials');
        try {
            $credentials = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $email = $credentials['client_email'];
            $key = $credentials['private_key'];
            $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        } catch (\Throwable) {
            throw new ApiException(503, 'OCR_PROVIDER_NOT_CONFIGURED', 'Layanan OCR belum dikonfigurasi.');
        }

        return Cache::remember('document-ocr-token:'.hash('sha256', $email), now()->addSeconds(3500), function () use ($email, $key, $tokenUri): string {
            $base64 = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $claims = $base64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'
                .$base64(json_encode([
                    'iss' => $email,
                    'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                    'aud' => $tokenUri,
                    'iat' => time(),
                    'exp' => time() + 3600,
                ], JSON_THROW_ON_ERROR));
            $privateKey = openssl_pkey_get_private($key);
            if (! $privateKey || ! openssl_sign($claims, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                throw new ApiException(503, 'OCR_PROVIDER_NOT_CONFIGURED', 'Layanan OCR belum dikonfigurasi.');
            }
            $assertion = $claims.'.'.$base64($signature);
            $response = Http::asForm()->timeout((int) config('documents.ocr.timeout'))->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new ApiException(503, 'OCR_SERVICE_UNAVAILABLE', 'Layanan OCR sedang tidak tersedia.');
            }

            return $response->json('access_token');
        });
    }

    private function throwProviderError(int $status, string $message): never
    {
        if ($status === 429 || str_contains(strtolower($message), 'quota')) {
            throw new ApiException(429, 'OCR_QUOTA_EXHAUSTED', 'Kuota layanan OCR telah habis. Pemrosesan teks sementara tidak tersedia.');
        }

        throw new ApiException(503, 'OCR_SERVICE_UNAVAILABLE', 'Layanan OCR sedang tidak tersedia. Silakan coba lagi nanti.');
    }
}
