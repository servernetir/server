<?php

use App\Http\Controllers\Api\CloudEngineController;
use App\Http\Middleware\CloudEnginePrivacy;
use App\Http\Middleware\CustomerApiToken;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::prefix('api/v1')->withoutMiddleware([
    StartSession::class, ShareErrorsFromSession::class, AddQueuedCookiesToResponse::class, PreventRequestForgery::class,
])->middleware(CloudEnginePrivacy::class)->group(function () {
    $controller = CloudEngineController::class;
    $auth = CustomerApiToken::class;
    Route::middleware([$auth.':servers:read', 'throttle:60,1'])->group(function () use ($controller) {
        Route::get('servers', [$controller, 'index']);
        Route::get('servers/{server}', [$controller, 'show'])->whereUuid('server');
        Route::get('servers/{server}/metrics', [$controller, 'metrics'])->whereUuid('server');
        Route::get('servers/{server}/traffic', [$controller, 'traffic'])->whereUuid('server');
        Route::get('servers/{server}/resize-options', [$controller, 'resizeOptions'])->whereUuid('server');
        Route::get('servers/{server}/operations', [$controller, 'history'])->whereUuid('server');
        Route::get('operations/{operation}', [$controller, 'operation'])->whereNumber('operation');
    });
    Route::post('servers', [$controller, 'store'])->middleware([$auth.':servers:write', 'throttle:10,1']);
    Route::middleware([$auth.':servers:manage', 'throttle:20,1'])->group(function () use ($controller) {
        Route::patch('servers/{server}/metadata', [$controller, 'metadata'])->whereUuid('server');
        Route::delete('servers/{server}', [$controller, 'destroy'])->whereUuid('server');
        Route::post('servers/{server}/power', [$controller, 'power'])->whereUuid('server');
        Route::post('servers/{server}/rebuild', [$controller, 'rebuild'])->whereUuid('server');
        Route::post('servers/{server}/reset-password', [$controller, 'resetPassword'])->whereUuid('server');
        Route::post('servers/{server}/hostname', [$controller, 'hostname'])->whereUuid('server');
        Route::post('servers/{server}/resize', [$controller, 'resize'])->whereUuid('server');
        Route::post('servers/{server}/console', [$controller, 'console'])->whereUuid('server');
    });
});
