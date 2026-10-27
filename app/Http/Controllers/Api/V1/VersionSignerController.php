<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\UpdateSignersRequest;
use App\Http\Resources\DocumentVersionResource;
use App\Models\DocumentVersion;
use App\Services\SigningSetupService;

class VersionSignerController extends Controller
{
    public function __construct(private readonly SigningSetupService $signing) {}

    public function update(UpdateSignersRequest $request, DocumentVersion $version): DocumentVersionResource
    {
        return new DocumentVersionResource($this->signing->updateSigners(
            $request->user(), $version, $request->validated('signers'), $request->validated('signing_mode'),
        ));
    }
}
