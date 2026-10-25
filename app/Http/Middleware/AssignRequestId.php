<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AssignRequestId
{
    /**
     * Attach a server-generated trace id to the request and echo it on the response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        $startedAt = microtime(true);
        $name = config('session.cookie');
        $cookie = $request->cookies->all()[$name] ?? null;

        if ($request->is('api/v1/auth/google/*')) {
            // Capture presence before EncryptCookies can replace an invalid cookie with null.
            $request->attributes->set('oauth_session_cookie_received', is_string($cookie) && $cookie !== '');
            $request->attributes->set('oauth_session_cookie_header_count', preg_match_all(
                '/(?:^|;\s*)'.preg_quote($name, '/').'=/', $request->headers->get('cookie', '')
            ));
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        if (config('services.google.session_diagnostics') && $request->hasSession()) {
            // This runs outside StartSession and EncryptCookies, after saving and encrypting.
            try {
                $session = $request->session();
                $incomingId = $request->cookies->all()[$name] ?? null;
                $state = $session->get('state');
                $responseCookies = array_values(array_filter($response->headers->getCookies(), fn ($item) => $item->getName() === $name));
                Log::info('Google OAuth session response.', [
                    'request_id' => $requestId,
                    'request_started_at_ms' => (int) ($startedAt * 1000),
                    'request_finished_at_ms' => (int) (microtime(true) * 1000),
                    'request_method' => $request->method(),
                    'request_route' => $request->route()?->uri(),
                    'response_status' => $response->getStatusCode(),
                    'incoming_cookie_fingerprint' => is_string($cookie) ? hash('sha256', $cookie) : null,
                    'response_cookie_fingerprints' => array_map(fn ($item) => hash('sha256', $item->getValue()), $responseCookies),
                    'incoming_session_id_fingerprint' => is_string($incomingId) && $session->isValidId($incomingId) ? hash('sha256', 'oauth-session:'.$incomingId) : null,
                    'session_id_fingerprint' => hash('sha256', 'oauth-session:'.$session->getId()),
                    'session_id_rotated' => is_string($incomingId) && $session->isValidId($incomingId) && ! hash_equals($incomingId, $session->getId()),
                    'app_key_fingerprint' => substr(hash_hmac('sha256', 'google-oauth-diagnostics', app('encrypter')->getKey()), 0, 16),
                    'session_state_present' => $session->has('state'),
                    'session_state_fingerprint' => is_string($state) && $state !== '' ? hash('sha256', 'oauth-state:'.$state) : null,
                ] + ($request->is('api/v1/auth/google/*') ? $this->persistedSessionContext($request) : []));
            } catch (Throwable $e) {
                // Diagnostics must not change authentication responses or expose database errors.
                Log::warning('Google OAuth session diagnostics unavailable.', [
                    'request_id' => $requestId, 'exception_type' => $e::class,
                ]);
            }
        }

        return $response;
    }

    /** @return array<string, bool|string|null> */
    private function persistedSessionContext(Request $request): array
    {
        if (config('session.driver') !== 'database' || config('session.serialization') !== 'json') {
            return ['session_persistence_checked' => false];
        }

        $connection = DB::connection(config('session.connection'));
        $table = config('session.table', 'sessions');
        $row = $connection->table($table)->useWritePdo()->where('id', $request->session()->getId())->first(['payload']);
        $payload = $row ? base64_decode($row->payload) : '';
        if ($payload !== '' && config('session.encrypt')) {
            $payload = app('encrypter')->decrypt($payload);
        }
        $data = json_decode($payload, true);
        $state = is_array($data) ? ($data['state'] ?? null) : null;

        return [
            'session_persistence_checked' => true,
            'session_storage_fingerprint' => hash('sha256', json_encode([
                $connection->getConfig('driver'), $connection->getConfig('host'), $connection->getConfig('port'),
                $connection->getDatabaseName(), $table,
            ])),
            'session_database_row_present' => $row !== null,
            'session_database_payload_readable' => is_array($data),
            'session_database_state_present' => is_string($state) && $state !== '',
            'session_database_state_matches' => is_string($state) && $state !== '' && hash_equals((string) $request->session()->get('state'), $state),
        ];
    }
}
