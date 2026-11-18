<?php

use Illuminate\Support\Facades\Route;
use TriggerEngage\Server\Http\Controllers\McpController;

Route::post('/', [McpController::class, 'handle'])->name('engage.mcp');
Route::match(['get', 'delete'], '/', [McpController::class, 'unsupported']);
