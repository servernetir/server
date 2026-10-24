<?php

use App\Http\Controllers\HermesMiniAppController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/bale/hermes', [HermesMiniAppController::class, 'index']);
Route::prefix('bale/hermes')->middleware([\App\Http\Middleware\HermesMiniAppAudit::class,'throttle:90,1'])->group(function () {
    Route::get('/session', [HermesMiniAppController::class, 'session']);
    Route::get('/overview', [HermesMiniAppController::class, 'overview'])->middleware('throttle:12,1');
    Route::get('/state', [HermesMiniAppController::class, 'state']);
    // These exact APIs authenticate signed Bale data / encrypted owner bearer.
    // They never authorize from cookies, so third-party iframe cookies and CSRF
    // sessions are not part of their authentication contract. No wildcard bypass.
    Route::post('/chat', [HermesMiniAppController::class, 'chat'])
        ->withoutMiddleware(PreventRequestForgery::class)->middleware('throttle:8,1');
    Route::post('/memory', [HermesMiniAppController::class, 'memory'])
        ->withoutMiddleware(PreventRequestForgery::class)->middleware('throttle:30,1');
    Route::post('/history/clear', [HermesMiniAppController::class, 'clear'])
        ->withoutMiddleware(PreventRequestForgery::class)->middleware('throttle:5,1');
});
