<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\ClaimInvitationRequest;
use App\Http\Resources\SignerAssignmentResource;
use App\Models\SignerAssignment;
use App\Services\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function claim(ClaimInvitationRequest $request): SignerAssignmentResource
    {
        return new SignerAssignmentResource($this->invitations->claim($request->user(), $request->validated('token')));
    }

    public function resend(Request $request, SignerAssignment $assignment): SignerAssignmentResource
    {
        Gate::authorize('manage', $assignment);

        return new SignerAssignmentResource($this->invitations->resend($request->user(), $assignment));
    }
}
