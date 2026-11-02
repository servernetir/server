<?php

use App\Http\Controllers\Api\UrmiaAuthController;
use App\Http\Middleware\IdentityPrivacy;
use Illuminate\Support\Facades\Route;

Route::prefix('api/internal/urmia-auth/v1/otp')->withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
])->middleware([IdentityPrivacy::class, 'throttle:60,1'])->group(function () {
    Route::post('start', [UrmiaAuthController::class, 'start']);
    Route::post('verify', [UrmiaAuthController::class, 'verify']);
});
