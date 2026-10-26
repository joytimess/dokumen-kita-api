<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (app()->environment('staging')) {
        return response('', 405)
            ->header('Cache-Control', 'no-store');
            }
    return response('', 405)
        ->header('Cache-Control', 'no-store');
});