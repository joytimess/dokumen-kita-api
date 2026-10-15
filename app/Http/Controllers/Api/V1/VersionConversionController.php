<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\RetryConversionRequest;
use App\Http\Resources\DocumentVersionResource;
use App\Models\DocumentVersion;
use App\Services\DocumentConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class VersionConversionController extends Controller
{
    public function store(RetryConversionRequest $request, DocumentVersion $version, DocumentConversionService $conversion): JsonResponse
    {
        Gate::authorize('update', $version);

        return (new DocumentVersionResource($conversion->retry(
            $request->user(), $version, $request->boolean('replace_editor_data'), $request->validated('editor_revision'),
        )))
            ->response()->setStatusCode(202);
    }
}
