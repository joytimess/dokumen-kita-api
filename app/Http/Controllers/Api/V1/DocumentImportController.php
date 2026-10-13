<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\ImportDocumentRequest;
use App\Http\Resources\DocumentVersionResource;
use App\Models\Document;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DocumentImportController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function store(ImportDocumentRequest $request, Document $document): JsonResponse
    {
        Gate::authorize('manage', $document);

        $version = $this->documents->importSource($request->user(), $document, $request->file('file'));

        return (new DocumentVersionResource($version))->response()->setStatusCode(201);
    }
}
