<?php

use Illuminate\Support\Facades\Route;

Route::get('/cloud/sms', [\App\Http\Controllers\SmsStorefrontController::class, 'home'])->name('sms.home');
