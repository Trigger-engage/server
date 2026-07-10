<?php

use App\Http\Controllers\Api\V1\BatchController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Middleware\AuthenticateWorkspace;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(AuthenticateWorkspace::class)->group(function () {
    Route::post('/events', [EventController::class, 'store']);
    Route::put('/people/{externalId}', [PersonController::class, 'update']);
    Route::delete('/people/{externalId}', [PersonController::class, 'destroy']);
    Route::post('/batch', [BatchController::class, 'store']);
});
