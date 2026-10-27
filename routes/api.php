<?php

use App\Http\Controllers\Api\V1\AiChatController;
use App\Http\Controllers\Api\V1\AssignmentPlaceholderController;
use App\Http\Controllers\Api\V1\AssignmentRejectionController;
use App\Http\Controllers\Api\V1\AssignmentRevisionController;
use App\Http\Controllers\Api\V1\AssignmentSignatureController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DocumentActivityController;
use App\Http\Controllers\Api\V1\DocumentCategoryController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentImportController;
use App\Http\Controllers\Api\V1\DocumentOcrController;
use App\Http\Controllers\Api\V1\DocumentPdfController;
use App\Http\Controllers\Api\V1\DocumentVersionController;
use App\Http\Controllers\Api\V1\EmailChangeController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OwnerSigningQrController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\QrSigningController;
use App\Http\Controllers\Api\V1\ReadConfirmationController;
use App\Http\Controllers\Api\V1\SignatureAssetController;
use App\Http\Controllers\Api\V1\SigningHistoryController;
use App\Http\Controllers\Api\V1\UserNotificationController;
use App\Http\Controllers\Api\V1\VersionConversionController;
use App\Http\Controllers\Api\V1\VersionDecorationController;
use App\Http\Controllers\Api\V1\VersionFinalizationController;
use App\Http\Controllers\Api\V1\VersionPreviewController;
use App\Http\Controllers\Api\V1\VersionPublishController;
use App\Http\Controllers\Api\V1\VersionSignerController;
use App\Http\Controllers\Api\V1\VersionSourceController;
use App\Http\Middleware\BlockBrowserNavigation;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::get('/', function () {
    abort(404);
})->middleware(BlockBrowserNavigation::class);

Route::prefix('v1')->middleware(BlockBrowserNavigation::class)->group(function (): void {
    Route::middleware('throttle:30,1')->prefix('signing-qr')->group(function (): void {
        Route::post('resolve', [QrSigningController::class, 'resolve']);
        Route::post('claim', [QrSigningController::class, 'claim'])->middleware('auth:sanctum');
    });
    Route::get('/', function () {
        abort(404);
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

        Route::prefix('google')
            ->middleware(['web', 'throttle:auth-register'])
            ->withoutMiddleware([EnsureFrontendRequestsAreStateful::class, BlockBrowserNavigation::class])
            ->group(function (): void {
                Route::get('redirect', [GoogleAuthController::class, 'redirect']);
                Route::get('callback', [GoogleAuthController::class, 'callback']);
            });

        Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-forgot');
        Route::post('password/verify-otp', [PasswordResetController::class, 'verifyOtp'])->middleware('throttle:password-verify');
        Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

        Route::get('email/verify/{userGuid}/{hash}', [EmailVerificationController::class, 'verify'])
            ->withoutMiddleware(BlockBrowserNavigation::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
        Route::get('email/change/{requestGuid}', [EmailChangeController::class, 'verify'])
            ->withoutMiddleware(BlockBrowserNavigation::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name('auth.email-change.verify');
        Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware(['auth:sanctum', 'throttle:6,1']);
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('ai/chat', AiChatController::class)->middleware('throttle:ai-chat');

        Route::get('me', [MeController::class, 'show']);
        Route::patch('me/profile', [MeController::class, 'updateProfile']);
        Route::get('me/photo', [MeController::class, 'photo'])->withoutMiddleware(BlockBrowserNavigation::class)->name('me.photo');
        Route::post('me/photo', [MeController::class, 'storePhoto'])->middleware('throttle:uploads');
        Route::delete('me/photo', [MeController::class, 'deletePhoto']);
        Route::post('me/email-change', [MeController::class, 'requestEmailChange'])->middleware('throttle:6,1');
        Route::post('me/password', [MeController::class, 'changePassword']);
        Route::get('me/history', [SigningHistoryController::class, 'index'])->name('me.history.index');
        Route::get('me/history/{event}', [SigningHistoryController::class, 'show'])->name('me.history.show');

        Route::get('notifications', [UserNotificationController::class, 'index']);
        Route::patch('notifications/{notification}/read', [UserNotificationController::class, 'read']);

        Route::prefix('documents')->group(function (): void {
            Route::get('/', [DocumentController::class, 'index']);
            Route::post('/', [DocumentController::class, 'store']);
            Route::patch('categories', [DocumentCategoryController::class, 'bulkAssign']);
            Route::patch('{document}/visibility', [DocumentController::class, 'updateVisibility']);
            Route::patch('{document}/category', [DocumentCategoryController::class, 'assign']);
            Route::get('{document}', [DocumentController::class, 'show']);
            Route::get('{document}/audit', [DocumentActivityController::class, 'audit']);
            Route::get('{document}/versions', [DocumentActivityController::class, 'versions']);
            Route::get('{document}/emails', [DocumentActivityController::class, 'emails']);
            Route::post('{document}/imports', [DocumentImportController::class, 'store'])->middleware('throttle:uploads');
        });

        Route::prefix('categories')->group(function (): void {
            Route::get('/', [DocumentCategoryController::class, 'index']);
            Route::post('/', [DocumentCategoryController::class, 'store']);
            Route::get('{category}', [DocumentCategoryController::class, 'show']);
            Route::put('{category}', [DocumentCategoryController::class, 'update']);
            Route::delete('{category}', [DocumentCategoryController::class, 'destroy']);
            Route::get('{category}/documents', [DocumentCategoryController::class, 'documents']);
        });

        Route::prefix('versions')->group(function (): void {
            Route::get('{version}/signing-qr', [OwnerSigningQrController::class, 'show'])->withoutMiddleware(BlockBrowserNavigation::class)->name('versions.signing-qr.image');
            Route::get('{version}', [DocumentVersionController::class, 'show']);
            Route::get('{version}/ocr', [DocumentOcrController::class, 'show']);
            Route::post('{version}/ocr', [DocumentOcrController::class, 'store'])->middleware('throttle:10,1');
            Route::post('{version}/ocr/retry', [DocumentOcrController::class, 'retry'])->middleware('throttle:10,1');
            Route::post('{version}/preview', [VersionPreviewController::class, 'store'])->middleware('throttle:10,1');
            Route::post('{version}/convert-import', [VersionConversionController::class, 'store'])->middleware('throttle:10,1');
            Route::get('{version}/source', [VersionSourceController::class, 'show'])->withoutMiddleware(BlockBrowserNavigation::class);
            Route::post('{version}/finalize', [VersionFinalizationController::class, 'store'])->middleware('throttle:10,1');
            Route::patch('{version}', [DocumentVersionController::class, 'update']);
            Route::post('{version}/decorations/{kind}', [VersionDecorationController::class, 'update'])->whereIn('kind', ['letterhead', 'footer'])->middleware('throttle:uploads');
            Route::patch('{version}/decorations/{kind}', [VersionDecorationController::class, 'update'])->whereIn('kind', ['letterhead', 'footer']);
            Route::delete('{version}/decorations/{kind}', [VersionDecorationController::class, 'destroy'])->whereIn('kind', ['letterhead', 'footer']);
            Route::get('{version}/decorations/{kind}/image', [VersionDecorationController::class, 'image'])->withoutMiddleware(BlockBrowserNavigation::class)->whereIn('kind', ['letterhead', 'footer'])->name('versions.decorations.image');
            Route::put('{version}/signers', [VersionSignerController::class, 'update']);
            Route::post('{version}/publish', [VersionPublishController::class, 'store'])->middleware('throttle:20,1');
            Route::get('{version}/pdf', [DocumentPdfController::class, 'show'])->withoutMiddleware(BlockBrowserNavigation::class);
        });

        Route::prefix('assignments')->group(function (): void {
            Route::put('{assignment}/placeholders', [AssignmentPlaceholderController::class, 'update']);
            Route::post('{assignment}/resend-invitation', [InvitationController::class, 'resend'])->middleware('throttle:invitation-resend');
            Route::post('{assignment}/read-confirmation', [ReadConfirmationController::class, 'store'])->middleware('throttle:30,1');
            Route::post('{assignment}/signatures', [AssignmentSignatureController::class, 'store'])->middleware('throttle:30,1');
            Route::post('{assignment}/revision-request', [AssignmentRevisionController::class, 'store'])->middleware('throttle:10,1');
            Route::post('{assignment}/reject', [AssignmentRejectionController::class, 'store'])->middleware('throttle:10,1');
        });

        Route::prefix('signature-assets')->group(function (): void {
            Route::get('/', [SignatureAssetController::class, 'index']);
            Route::post('/', [SignatureAssetController::class, 'store'])->middleware(['throttle:uploads', 'throttle:signature-classification']);
            Route::delete('{asset}', [SignatureAssetController::class, 'destroy']);
            Route::get('{asset}/image', [SignatureAssetController::class, 'image'])->withoutMiddleware(BlockBrowserNavigation::class)->name('signature-assets.image');
        });

        Route::post('invitations/claim', [InvitationController::class, 'claim'])->middleware('throttle:invitations');
    });
});
