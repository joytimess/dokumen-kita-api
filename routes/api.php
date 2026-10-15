<?php

use App\Http\Controllers\Api\V1\AssignmentPlaceholderController;
use App\Http\Controllers\Api\V1\AssignmentSignatureController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentImportController;
use App\Http\Controllers\Api\V1\DocumentPdfController;
use App\Http\Controllers\Api\V1\DocumentVersionController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ReadConfirmationController;
use App\Http\Controllers\Api\V1\SignatureAssetController;
use App\Http\Controllers\Api\V1\VersionFinalizationController;
use App\Http\Controllers\Api\V1\VersionPreviewController;
use App\Http\Controllers\Api\V1\VersionPublishController;
use App\Http\Controllers\Api\V1\VersionSignerController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

        Route::prefix('google')
            ->middleware(['web', 'throttle:auth-register'])
            ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
            ->group(function (): void {
                Route::get('redirect', [GoogleAuthController::class, 'redirect']);
                Route::get('callback', [GoogleAuthController::class, 'callback']);
            });

        Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-forgot');
        Route::post('password/verify-otp', [PasswordResetController::class, 'verifyOtp'])->middleware('throttle:password-verify');
        Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

        Route::get('email/verify/{userGuid}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
        Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware(['auth:sanctum', 'throttle:6,1']);
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [MeController::class, 'show']);

        Route::prefix('documents')->group(function (): void {
            Route::get('/', [DocumentController::class, 'index']);
            Route::post('/', [DocumentController::class, 'store']);
            Route::get('{document}', [DocumentController::class, 'show']);
            Route::post('{document}/imports', [DocumentImportController::class, 'store'])->middleware('throttle:uploads');
        });

        Route::prefix('versions')->group(function (): void {
            Route::get('{version}', [DocumentVersionController::class, 'show']);
            Route::post('{version}/preview', [VersionPreviewController::class, 'store'])->middleware('throttle:10,1');
            Route::post('{version}/finalize', [VersionFinalizationController::class, 'store'])->middleware('throttle:10,1');
            Route::patch('{version}', [DocumentVersionController::class, 'update']);
            Route::put('{version}/signers', [VersionSignerController::class, 'update']);
            Route::post('{version}/publish', [VersionPublishController::class, 'store'])->middleware('throttle:20,1');
            Route::get('{version}/pdf', [DocumentPdfController::class, 'show']);
        });

        Route::prefix('assignments')->group(function (): void {
            Route::put('{assignment}/placeholders', [AssignmentPlaceholderController::class, 'update']);
            Route::post('{assignment}/resend-invitation', [InvitationController::class, 'resend'])->middleware('throttle:invitation-resend');
            Route::post('{assignment}/read-confirmation', [ReadConfirmationController::class, 'store'])->middleware('throttle:30,1');
            Route::post('{assignment}/signatures', [AssignmentSignatureController::class, 'store'])->middleware('throttle:30,1');
        });

        Route::prefix('signature-assets')->group(function (): void {
            Route::get('/', [SignatureAssetController::class, 'index']);
            Route::post('/', [SignatureAssetController::class, 'store'])->middleware('throttle:uploads');
            Route::get('{asset}/image', [SignatureAssetController::class, 'image'])->name('signature-assets.image');
        });

        Route::post('invitations/claim', [InvitationController::class, 'claim'])->middleware('throttle:invitations');
    });
});
