<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\GeneratePreviewRequest;
use App\Http\Resources\DocumentVersionResource;
use App\Models\DocumentVersion;
use App\Services\DocumentPipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class VersionPreviewController extends Controller
{
    public function store(GeneratePreviewRequest $request, DocumentVersion $version, DocumentPipelineService $pipeline): JsonResponse
    {
        Gate::authorize('update', $version);

        return (new DocumentVersionResource($pipeline->requestPreview($request->user(), $version, $request->validated('source'))))
            ->response()->setStatusCode(202);
    }
}
