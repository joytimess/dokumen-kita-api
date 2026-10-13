<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

        Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:auth-register');
        Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:auth-register');

        Route::get('email/verify/{userGuid}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
        Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware(['auth:sanctum', 'throttle:6,1']);
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [MeController::class, 'show']);
    });
});
