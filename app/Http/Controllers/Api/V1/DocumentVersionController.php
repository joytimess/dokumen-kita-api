<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\UpdateVersionRequest;
use App\Http\Resources\DocumentVersionResource;
use App\Models\DocumentVersion;
use App\Services\DocumentService;
use Illuminate\Support\Facades\Gate;

class DocumentVersionController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function update(UpdateVersionRequest $request, DocumentVersion $version): DocumentVersionResource
    {
        Gate::authorize('update', $version);

        return new DocumentVersionResource($this->documents->updateDraft($request->user(), $version, $request->validated()));
    }
}
