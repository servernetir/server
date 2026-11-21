<?php

use App\Http\Controllers\EmailUnsubscribeController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

$tokenPattern = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[0-9a-f]{64}';

Route::get('/email-marketing/unsubscribe/{token}', [EmailUnsubscribeController::class, 'show'])
    ->where('token', $tokenPattern)->middleware('throttle:30,1')->name('email-marketing.unsubscribe');
Route::post('/email-marketing/unsubscribe/{token}', [EmailUnsubscribeController::class, 'store'])
    ->where('token', $tokenPattern)->middleware('throttle:30,1')
    ->withoutMiddleware([PreventRequestForgery::class])->name('email-marketing.unsubscribe.submit');
