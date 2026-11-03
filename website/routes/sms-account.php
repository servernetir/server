<?php

use Illuminate\Support\Facades\Route;

// Included inside the existing customer-authenticated, locale-aware account group.
Route::middleware(\App\Http\Middleware\IdentityPrivacy::class)->group(function () {
    $controller = \App\Http\Controllers\Account\SmsController::class;
    Route::get('/sms', [$controller, 'index'])->name('sms');
    Route::post('/sms/orders', [$controller, 'order'])->middleware('throttle:6,1')->name('sms.order');
    Route::post('/sms/upgrade', [$controller, 'upgrade'])->middleware('throttle:6,1')->name('sms.upgrade');
});
