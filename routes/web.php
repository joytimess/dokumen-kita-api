<?php

use App\Http\Controllers\Api\V1\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('api/v1/auth/google')->middleware('throttle:auth-register')->group(function (): void {
    Route::get('redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('callback', [GoogleAuthController::class, 'callback']);
});
