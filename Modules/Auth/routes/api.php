<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\GoogleController;

// Not signed in yet
Route::get('auth/google', [GoogleController::class, 'redirect']);
Route::get('auth/google/callback', [GoogleController::class, 'callback']);

// Signed in
Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [GoogleController::class, 'me']);
    Route::post('logout', [GoogleController::class, 'logout']);
});