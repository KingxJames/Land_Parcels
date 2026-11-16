<?php

use Illuminate\Support\Facades\Route;
use Modules\Movie\Http\Controllers\MovieController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('movies', MovieController::class)->names('movie');
});
