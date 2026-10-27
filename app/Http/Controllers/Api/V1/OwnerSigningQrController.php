<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentVersion;
use App\Services\QrSvgService;
use App\Services\QrTokenService;
use App\Services\VersionWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OwnerSigningQrController extends Controller
{
    public function __construct(
        private readonly QrTokenService $tokens,
        private readonly VersionWorkflow $workflow,
        private readonly QrSvgService $svg,
    ) {}

    public function show(Request $request, DocumentVersion $version): JsonResponse|Response
    {
        if (! $request->wantsJson()) {
            return $this->image($request, $version);
        }

        $metadata = $this->metadata($version);

        return response()->json([
            'data' => $metadata,
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    public function image(Request $request, DocumentVersion $version): Response
    {
        $metadata = $this->metadata($version);
        $request->validate(['download' => ['sometimes', 'boolean']]);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($this->svg->render($metadata['signing_url']), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => $disposition.'; filename="signing-qr-'.$version->guid.'.svg"',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** @return array{status: string, signing_url: string, svg_url: string} */
    private function metadata(DocumentVersion $version): array
    {
        Gate::authorize('update', $version);

        return DB::transaction(function () use ($version): array {
            $locked = $this->workflow->lock($version);
            $qr = $this->tokens->ensure($locked);

            return [
                'status' => $this->tokens->state($locked, $qr),
                'signing_url' => rtrim(config('app.frontend_url'), '/').'/sign/qr/'.$this->tokens->token($qr),
                'svg_url' => route('versions.signing-qr.image', ['version' => $locked->guid]),
            ];
        });
    }
}
