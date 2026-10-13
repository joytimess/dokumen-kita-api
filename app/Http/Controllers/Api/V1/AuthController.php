<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->safe()->only(['name', 'email', 'password']), $request);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): UserResource
    {
        $user = $this->auth->login(
            $request->safe()->only(['email', 'password']),
            $request->boolean('remember'),
            $request,
        );

        return new UserResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);

        return response()->json(null, 204);
    }
}
