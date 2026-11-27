<?php

use App\Http\Controllers\Api\CloudApiController;
use App\Http\Middleware\CustomerCloudApiThrottle;
use App\Http\Middleware\CustomerApiToken;
use Illuminate\Support\Facades\Route;

Route::middleware([CustomerApiToken::class.':cloud:read', CustomerCloudApiThrottle::class.':read'])->group(function () {
    Route::get('/cloud', [CloudApiController::class, 'index']);
    Route::get('/cloud/{service}', [CloudApiController::class, 'show'])->whereNumber('service');
    Route::get('/cloud/{service}/status', [CloudApiController::class, 'status'])->whereNumber('service');
    Route::get('/cloud/{service}/metrics', [CloudApiController::class, 'metrics'])->whereNumber('service');
    Route::get('/operations/{operation}', [CloudApiController::class, 'operation'])->whereUuid('operation');
});
Route::post('/cloud/{service}/actions/{action}', [CloudApiController::class, 'power'])
    ->whereNumber('service')->whereIn('action', ['start', 'stop', 'reboot'])
    ->middleware([CustomerApiToken::class.':cloud:power', CustomerCloudApiThrottle::class.':power']);
