<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\QrSigningRequest;
use App\Services\QrSigningService;
use Illuminate\Http\JsonResponse;

class QrSigningController extends Controller
{
    public function __construct(private readonly QrSigningService $signing) {}

    public function resolve(QrSigningRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->signing->resolve($request->validated('token'))]);
    }

    public function claim(QrSigningRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->signing->claim($request->user(), $request->validated('token'))]);
    }
}
