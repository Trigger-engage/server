<?php

use Illuminate\Support\Facades\Route;
use TriggerEngage\Server\Http\Controllers\Api\V1\BatchController;
use TriggerEngage\Server\Http\Controllers\Api\V1\EventController;
use TriggerEngage\Server\Http\Controllers\Api\V1\PersonController;
use TriggerEngage\Server\Http\Controllers\Api\V1\SegmentMembershipController;
use TriggerEngage\Server\Http\Controllers\Api\V1\SubscriptionController;

Route::post('/events', [EventController::class, 'store']);
Route::get('/people', [PersonController::class, 'index']);
Route::get('/people/{externalId}', [PersonController::class, 'show']);
Route::put('/people/{externalId}', [PersonController::class, 'update']);
Route::patch('/people/{externalId}/properties', [PersonController::class, 'update']);
Route::delete('/people/{externalId}/properties/{property}', [PersonController::class, 'destroyProperty']);
Route::delete('/people/{externalId}', [PersonController::class, 'destroy']);
Route::get('/people/{externalId}/subscriptions', [SubscriptionController::class, 'show']);
Route::put('/people/{externalId}/subscriptions', [SubscriptionController::class, 'update']);
Route::put('/segments/{segment}/people/{externalId}', [SegmentMembershipController::class, 'store']);
Route::delete('/segments/{segment}/people/{externalId}', [SegmentMembershipController::class, 'destroy']);
Route::post('/batch', [BatchController::class, 'store']);
