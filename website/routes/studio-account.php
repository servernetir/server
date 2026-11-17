<?php

use App\Http\Controllers\Ai\StudioController;
use Illuminate\Support\Facades\Route;

Route::get('/studio/authorize', [StudioController::class, 'authorizePage'])->middleware('throttle:20,1')->name('studio.authorize');
Route::post('/studio/authorize', [StudioController::class, 'authorizeSubmit'])->middleware('throttle:20,1')->name('studio.approve');

Route::get('/studio', function () {
    $service = app(\App\Services\Studio\StudioAuthorization::class);
    abort_unless($service->configured(), 404);
    $callback = (string) config('studio.redirect_uri');
    $origin = parse_url($callback, PHP_URL_SCHEME).'://'.parse_url($callback, PHP_URL_HOST);
    $port = parse_url($callback, PHP_URL_PORT);
    if ($port) $origin .= ':'.$port;
    return redirect()->away($origin.'/auth/start?'.http_build_query(['lang' => app()->getLocale()]));
})->name('studio.launch');
