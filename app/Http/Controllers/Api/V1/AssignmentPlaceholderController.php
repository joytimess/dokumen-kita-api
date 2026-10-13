<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\UpdatePlaceholdersRequest;
use App\Http\Resources\SignerAssignmentResource;
use App\Models\SignerAssignment;
use App\Services\SigningSetupService;

class AssignmentPlaceholderController extends Controller
{
    public function __construct(private readonly SigningSetupService $signing) {}

    public function update(UpdatePlaceholdersRequest $request, SignerAssignment $assignment): SignerAssignmentResource
    {
        return new SignerAssignmentResource($this->signing->updatePlaceholders($request->user(), $assignment, $request->validated('placeholders')));
    }
}
