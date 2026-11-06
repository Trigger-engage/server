<?php

use Illuminate\Support\Facades\Route;
use TriggerEngage\Server\Http\Controllers\Api\V1\ClientController;

// Called straight from apps with a publishable key; see AuthenticateClient.
Route::post('/events', [ClientController::class, 'events']);
Route::post('/identify', [ClientController::class, 'identify']);
Route::post('/push-tokens', [ClientController::class, 'registerPushToken']);
Route::delete('/push-tokens', [ClientController::class, 'removePushToken']);
// Browsers preflight every call; the middleware answers with CORS headers.
Route::options('/{path}', fn () => response()->noContent())->where('path', '.*');
