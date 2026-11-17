<?php

use App\Http\Controllers\Ai\StudioController;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    \App\Http\Middleware\PageCache::class,
])->middleware('throttle:60,1')->group(function () {
    Route::post('/api/studio/token', [StudioController::class, 'token']);
    Route::get('/v1/studio/account', [StudioController::class, 'account']);
    Route::post('/v1/studio/revoke', [StudioController::class, 'revoke']);
});
