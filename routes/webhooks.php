<?php

use Illuminate\Support\Facades\Route;
use TriggerEngage\Server\Http\Controllers\Api\V1\DeliveryWebhookController;

Route::post('/termii/{channel}', [DeliveryWebhookController::class, 'termii']);
Route::post('/onesignal/{channel}', [DeliveryWebhookController::class, 'onesignal']);
