<?php

use Illuminate\Support\Facades\Route;

// Serve Vue SPA for all non-API routes
Route::get('/{any}', function () {
    return response()->file(public_path('index.html'));
})->where('any', '^(?!api/).*$');
