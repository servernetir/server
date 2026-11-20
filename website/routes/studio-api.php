<?php

use App\Http\Controllers\Ai\StudioController;
use App\Http\Controllers\Ai\StudioInferenceController;
use App\Http\Controllers\Ai\StudioFreeController;
use App\Http\Controllers\Ai\StudioMcpController;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    \App\Http\Middleware\PageCache::class,
])->middleware(\App\Http\Middleware\StudioRateLimit::class)->group(function () {
    Route::post('/api/studio/token', [StudioController::class, 'token']);
    Route::get('/v1/studio/account', [StudioController::class, 'account']);
    Route::post('/v1/studio/media/quote', [StudioInferenceController::class, 'quote']);
    Route::get('/v1/studio/media/{job}', [StudioInferenceController::class, 'result'])->where('job', '[a-f0-9]{32}');
    Route::post('/v1/studio/free/chat', [StudioFreeController::class, 'chat']);
    Route::match(['GET','POST','DELETE'], '/v1/studio/mcp', StudioMcpController::class);
    Route::post('/v1/studio/revoke', [StudioController::class, 'revoke']);
});
