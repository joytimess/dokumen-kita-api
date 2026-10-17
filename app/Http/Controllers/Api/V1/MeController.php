<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\StartEmailChangeRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UploadProfilePhotoRequest;
use App\Http\Resources\UserResource;
use App\Services\ProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load('identities'));
    }

    public function updateProfile(UpdateProfileRequest $request, ProfileService $profile): UserResource
    {
        return new UserResource($profile->updateName($request->user(), $request->validated('name')));
    }

    public function storePhoto(UploadProfilePhotoRequest $request, ProfileService $profile): UserResource
    {
        return new UserResource($profile->storePhoto($request->user(), $request->file('photo')));
    }

    public function deletePhoto(Request $request, ProfileService $profile): UserResource
    {
        return new UserResource($profile->deletePhoto($request->user()));
    }

    public function photo(Request $request)
    {
        $path = $request->user()->profile_photo_path;
        abort_unless($path !== null && Storage::disk(config('documents.disk'))->exists($path), 404);
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => abort(404),
        };

        return Storage::disk(config('documents.disk'))->response($path, 'profile-photo', [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function requestEmailChange(StartEmailChangeRequest $request, ProfileService $profile)
    {
        $profile->requestEmailChange($request->user(), $request->validated('email'));

        return response()->json([
            'data' => ['status' => 'pending', 'expires_in' => 3600, 'resend_after' => 60],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 202);
    }

    public function changePassword(ChangePasswordRequest $request, ProfileService $profile): UserResource
    {
        $user = $profile->changePassword(
            $request->user(), $request->validated('password'),
            $request->hasSession() ? $request->session()->getId() : '',
        );

        return new UserResource($user);
    }
}
