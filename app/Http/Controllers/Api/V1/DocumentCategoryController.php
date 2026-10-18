<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\AssignDocumentCategoryRequest;
use App\Http\Requests\Document\BulkAssignDocumentCategoryRequest;
use App\Http\Requests\Document\ListDocumentCategoriesRequest;
use App\Http\Requests\Document\ListDocumentsRequest;
use App\Http\Requests\Document\UpsertDocumentCategoryRequest;
use App\Http\Resources\DocumentCategoryResource;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Services\DocumentCategoryService;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentCategoryController extends Controller
{
    public function __construct(private readonly DocumentCategoryService $categories) {}

    public function index(ListDocumentCategoriesRequest $request): AnonymousResourceCollection
    {
        $categories = DocumentCategory::where('owner_guid', $request->user()->guid)
            ->withCount('documents');

        $search = $request->validated('q');
        if ($search !== null && $search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $categories->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
        }

        $paginated = $request->has('per_page');
        $sortBy = $request->validated('sort_by', $paginated ? 'created_at' : 'name');
        $direction = $request->validated('sort_direction', $sortBy === 'created_at' ? 'desc' : 'asc');
        $categories->orderBy($sortBy === 'name' ? 'normalized_name' : $sortBy, $direction)->orderBy('id', $direction);

        return DocumentCategoryResource::collection($paginated
            ? $categories->paginate((int) $request->validated('per_page'))->withQueryString()
            : $categories->get());
    }

    public function show(Request $request, string $category): DocumentCategoryResource
    {
        return new DocumentCategoryResource($this->categories->findForOwner($request->user(), $category)->loadCount('documents'));
    }

    public function store(UpsertDocumentCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($request->user(), $request->validated());

        return (new DocumentCategoryResource($category->loadCount('documents')))->response()->setStatusCode(201);
    }

    public function update(UpsertDocumentCategoryRequest $request, string $category): DocumentCategoryResource
    {
        return new DocumentCategoryResource($this->categories->update($request->user(), $category, $request->validated())->loadCount('documents'));
    }

    public function destroy(Request $request, string $category): JsonResponse
    {
        $count = $this->categories->delete($request->user(), $category);

        if ($count > 0) {
            return response()->json([
                'error' => ['code' => 'CATEGORY_IN_USE', 'message' => 'Kategori masih digunakan oleh dokumen.', 'errors' => (object) []],
                'meta' => ['request_id' => $request->attributes->get('request_id'), 'usage_count' => $count],
            ], 409);
        }

        return response()->json(null, 204);
    }

    public function documents(ListDocumentsRequest $request, string $category, DocumentService $documents): AnonymousResourceCollection
    {
        $this->categories->findForOwner($request->user(), $category);
        $page = $documents->paginateForOwner(
            $request->user(), $request->validated('status'), (int) $request->validated('per_page', 15),
            $request->validated('q'), $category,
            false, $request->validated('visibility', 'active'),
        );

        return DocumentResource::collection($page)->additional(['meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }

    public function assign(AssignDocumentCategoryRequest $request, Document $document): DocumentResource
    {
        $document = $this->categories->assign($request->user(), [$document->guid], $request->validated('category_guid'))->firstOrFail();

        return new DocumentResource($document->load('latestVersion.document', 'category'));
    }

    public function bulkAssign(BulkAssignDocumentCategoryRequest $request): JsonResponse
    {
        $documents = $this->categories->assign(
            $request->user(), $request->validated('document_guids'), $request->validated('category_guid'),
        );

        return response()->json([
            'data' => ['updated_count' => $documents->count(), 'category_guid' => $request->validated('category_guid')],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
