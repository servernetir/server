<?php

use App\Http\Controllers\Account\EmailPreferencesController;
use Illuminate\Support\Facades\Route;

Route::get('/email-preferences', [EmailPreferencesController::class, 'show'])->name('email-preferences');
Route::post('/email-preferences', [EmailPreferencesController::class, 'update'])
    ->middleware('throttle:10,1')->name('email-preferences.update');
