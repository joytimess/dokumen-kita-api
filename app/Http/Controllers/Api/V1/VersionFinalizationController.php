<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentVersionResource;
use App\Models\DocumentVersion;
use App\Services\DocumentPipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VersionFinalizationController extends Controller
{
    public function store(Request $request, DocumentVersion $version, DocumentPipelineService $pipeline): JsonResponse
    {
        Gate::authorize('update', $version);

        return (new DocumentVersionResource($pipeline->retryFinalization($request->user(), $version)))
            ->response()->setStatusCode(202);
    }
}
