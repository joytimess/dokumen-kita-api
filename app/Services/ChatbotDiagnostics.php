<?php

namespace App\Services;

use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class ChatbotDiagnostics
{
    public static function failure(string $stage, string|Throwable $error, float $started, ?int $status = null): void
    {
        if ($error instanceof Throwable) {
            $previous = $error->getPrevious();
            $error = match (true) {
                $error instanceof JsonException => 'invalid_json',
                $error instanceof ConnectionException && ($previous instanceof ConnectTimeoutException || $previous instanceof NetworkTimeoutException || $previous instanceof ResponseTimeoutException) => 'timeout',
                $error instanceof ConnectionException => 'connection_failed',
                default => 'unexpected_error',
            };
        }
        if ($error === 'http_error') {
            $error = match (true) {
                $status === 429 => 'rate_limit',
                $status >= 500 => 'http_server_error',
                $status >= 400 => 'http_client_error',
                default => 'http_redirect',
            };
        }

        // Only controlled labels and numeric metadata; never attach exceptions, bodies or headers.
        Log::warning('ai_chat.groq_failed', [
            'stage' => $stage,
            'http_status' => $status,
            'error_type' => $error,
            'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
        ]);
    }
}
