<?php

use App\Http\Controllers\HermesMiniAppMonitorController;
use App\Http\Middleware\HermesMiniAppJson;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

// Serve only the three existing public font files; do not create a webroot bale/hermes directory.
Route::get('/bale/hermes/assets/fonts/{file}', [HermesMiniAppMonitorController::class, 'font'])
    ->where('file', '[A-Za-z0-9_.-]+')->name('bale.hermes.monitor.font');

// Signed owner authentication is mandatory; cookie authentication is never used for these APIs.
// CSRF omission is scoped to this group, after the installed owner gate validates each request.
Route::prefix('/bale/hermes')->middleware([HermesMiniAppJson::class, 'throttle:60,1,hermes-monitor:'])
    ->withoutMiddleware([PreventRequestForgery::class])->group(function (): void {
        Route::post('/client-errors', [HermesMiniAppMonitorController::class, 'clientError'])
            ->middleware('throttle:20,1,hermes-monitor-errors:')->name('bale.hermes.monitor.client-errors');
        Route::get('/errors', [HermesMiniAppMonitorController::class, 'errors'])->name('bale.hermes.monitor.errors');
        Route::get('/automation', [HermesMiniAppMonitorController::class, 'automation'])->name('bale.hermes.monitor.automation');
    });
