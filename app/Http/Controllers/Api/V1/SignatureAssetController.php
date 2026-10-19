<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreSignatureAssetRequest;
use App\Http\Resources\SignatureAssetResource;
use App\Models\SignatureAsset;
use App\Services\SignatureAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignatureAssetController extends Controller
{
    public function __construct(private readonly SignatureAssetService $assets) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return SignatureAssetResource::collection(
            SignatureAsset::where('user_guid', $request->user()->guid)
                ->orderByDesc('id')
                ->paginate((int) ($validated['per_page'] ?? 15))
                ->withQueryString()
        )->additional(['meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }

    public function store(StoreSignatureAssetRequest $request): JsonResponse
    {
        return (new SignatureAssetResource($this->assets->store(
            $request->user(), $request->file('file'), $request->validated('source_type')
        )))->response()->setStatusCode(201);
    }

    public function image(SignatureAsset $asset): StreamedResponse
    {
        Gate::authorize('view', $asset);
        $this->assets->verify($asset);

        return Storage::disk(config('documents.disk'))->response($asset->file_path, $asset->guid.'.png', [
            'Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(SignatureAsset $asset): Response
    {
        Gate::authorize('delete', $asset);
        $this->assets->delete($asset);

        return response()->noContent();
    }
}
