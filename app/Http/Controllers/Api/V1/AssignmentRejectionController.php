<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreRejectionRequest;
use App\Models\SignerAssignment;
use App\Services\AssignmentRejectionService;
use Illuminate\Http\JsonResponse;

class AssignmentRejectionController extends Controller
{
    public function store(StoreRejectionRequest $request, SignerAssignment $assignment, AssignmentRejectionService $rejections): JsonResponse
    {
        $assignment = $rejections->reject($request->user(), $assignment, trim($request->validated('reason')));

        return response()->json(['data' => [
            'assignment_guid' => $assignment->guid,
            'assignment_status' => $assignment->status,
            'version_guid' => $assignment->version_guid,
            'version_status' => $assignment->version->status,
            'reason' => $assignment->response()->firstOrFail()->reason,
        ], 'meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }
}
