<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentVersionResource;
use App\Models\DocumentVersion;
use App\Services\PublishService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VersionPublishController extends Controller
{
    public function __construct(private readonly PublishService $publishing) {}

    public function store(Request $request, DocumentVersion $version): DocumentVersionResource
    {
        Gate::authorize('update', $version);

        return new DocumentVersionResource($this->publishing->publish($request->user(), $version));
    }
}
