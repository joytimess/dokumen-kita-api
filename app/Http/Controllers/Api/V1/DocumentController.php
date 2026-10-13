<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\ListDocumentsRequest;
use App\Http\Requests\Document\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(ListDocumentsRequest $request): AnonymousResourceCollection
    {
        $page = $this->documents->paginateForOwner(
            $request->user(),
            $request->validated('status'),
            (int) $request->validated('per_page', 15),
        );

        return DocumentResource::collection($page)
            ->additional(['meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $document = $this->documents->createDraft(
            $request->user(),
            $request->validated('title'),
            $request->validated('editor_data'),
        );

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document->load('latestVersion.document'));
    }
}
