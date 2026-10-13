<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreReadConfirmationRequest;
use App\Http\Resources\ReadConfirmationResource;
use App\Models\SignerAssignment;
use App\Services\ReadConfirmationService;

class ReadConfirmationController extends Controller
{
    public function __construct(private readonly ReadConfirmationService $confirmations) {}

    public function store(StoreReadConfirmationRequest $request, SignerAssignment $assignment): ReadConfirmationResource
    {
        return new ReadConfirmationResource($this->confirmations->confirm($request->user(), $assignment, $request->validated('version_content_hash')));
    }
}
