<?php

use Illuminate\Support\Facades\Route;

Route::prefix('admin/sms')->middleware(['auth:web', 'admin'])->group(function () {
    $controller = \App\Http\Controllers\Admin\SmsController::class;
    Route::get('/', [$controller, 'index'])->name('admin.sms');
    Route::post('/', [$controller, 'save'])->middleware('throttle:6,1')->name('admin.sms.save');
    Route::post('/preflight', [$controller, 'preflight'])->middleware('throttle:3,1')->name('admin.sms.preflight');
});
