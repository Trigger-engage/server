<?php

use App\Http\Controllers\Web\AutomationController;
use App\Http\Controllers\Web\ChannelController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\EventDefinitionController;
use App\Http\Controllers\Web\RunController;
use App\Http\Controllers\Web\TemplateController;
use App\Http\Controllers\Web\UnsubscribeController;
use App\Http\Middleware\AuthenticateWorkspace;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/app');
});

Route::get('/unsubscribe/{message}', [UnsubscribeController::class, 'show'])
    ->middleware('signed')->name('unsubscribe.show');
Route::post('/unsubscribe/{message}', [UnsubscribeController::class, 'destroy'])
    ->middleware('signed')->name('unsubscribe.destroy');

Route::prefix('app')->middleware(AuthenticateWorkspace::class)->name('engage.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/events', [EventDefinitionController::class, 'store'])->name('events.store');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
    Route::post('/automations', [AutomationController::class, 'store'])->name('automations.store');
    Route::get('/automations/{automation}', [AutomationController::class, 'edit'])->name('automations.edit');
    Route::put('/automations/{automation}/publish', [AutomationController::class, 'publish'])->name('automations.publish');
    Route::post('/automations/{automation}/pause', [AutomationController::class, 'pause'])->name('automations.pause');
    Route::get('/runs/{run}', [RunController::class, 'show'])->name('runs.show');
});
