<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreSignatureRequest;
use App\Http\Resources\SignatureResource;
use App\Models\SignerAssignment;
use App\Services\SignatureService;

class AssignmentSignatureController extends Controller
{
    public function __construct(private readonly SignatureService $signatures) {}

    public function store(StoreSignatureRequest $request, SignerAssignment $assignment): SignatureResource
    {
        return new SignatureResource($this->signatures->sign(
            $request->user(), $assignment, $request->validated('asset_guid'),
            $request->validated('version_content_hash'), $request->validated('idempotency_key'),
        ));
    }
}
