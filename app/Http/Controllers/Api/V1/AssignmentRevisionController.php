<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreRevisionRequest;
use App\Http\Resources\RevisionResultResource;
use App\Models\SignerAssignment;
use App\Services\RevisionService;

class AssignmentRevisionController extends Controller
{
    public function store(StoreRevisionRequest $request, SignerAssignment $assignment, RevisionService $revisions): RevisionResultResource
    {
        return new RevisionResultResource($revisions->request($request->user(), $assignment, trim($request->validated('reason'))));
    }
}
