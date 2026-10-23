<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every exception on /api/* as the documented `{error, meta}` envelope.
 */
class ApiExceptionRenderer
{
    public function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(fn (ValidationException $e, Request $request) => $this->respond($request, 422, 'VALIDATION_FAILED', 'Data yang dikirim tidak valid.', $e->errors()));
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $this->respond($request, 401, 'UNAUTHENTICATED', 'Sesi tidak ditemukan atau sudah berakhir.'));
        $exceptions->render(fn (ApiException $e, Request $request) => $this->respond($request, $e->status, $e->errorCode, $e->getMessage(), $e->errors));
        $exceptions->render(fn (AuthorizationException $e, Request $request) => $e->status() === 404
            ? $this->respond($request, 404, 'NOT_FOUND', 'Data tidak ditemukan.')
            : $this->respond($request, 403, 'FORBIDDEN', 'Anda tidak berhak melakukan tindakan ini.'));
        $exceptions->render(fn (ModelNotFoundException|NotFoundHttpException $e, Request $request) => $this->respond($request, 404, 'NOT_FOUND', 'Data tidak ditemukan.'));
        $exceptions->render(fn (TokenMismatchException $e, Request $request) => $this->respond($request, 419, 'CSRF_TOKEN_MISMATCH', 'Token CSRF tidak valid atau kedaluwarsa.'));
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => $this->respond(
            $request,
            $e->getStatusCode(),
            match ($e->getStatusCode()) {
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                409 => 'CONFLICT',
                429 => 'TOO_MANY_REQUESTS',
                default => 'HTTP_ERROR',
            },
            match ($e->getStatusCode()) {
                403 => 'Anda tidak berhak melakukan tindakan ini.',
                404 => 'Data tidak ditemukan.',
                default => $e->getMessage() !== '' ? $e->getMessage() : 'Permintaan tidak dapat diproses.',
            },
        )?->withHeaders($e->getHeaders()));
        $exceptions->render(function (Throwable $e, Request $request) {
            if (config('app.debug')) {
                return null;
            }

            return $this->respond($request, 500, 'INTERNAL_ERROR', 'Terjadi kesalahan pada server.');
        });
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    private function respond(Request $request, int $status, string $code, string $message, array $errors = []): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'error' => ['code' => $code, 'message' => $message, 'errors' => (object) $errors],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], $status);
    }
}
