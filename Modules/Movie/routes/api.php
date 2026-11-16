<?php

use Illuminate\Support\Facades\Route;
use Modules\Movie\Http\Controllers\MovieController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('movies', MovieController::class)->names('movie');
});
