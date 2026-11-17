<?php

use App\Http\Controllers\Ai\StudioController;
use Illuminate\Support\Facades\Route;

Route::get('/studio/authorize', [StudioController::class, 'authorizePage'])->middleware('throttle:20,1')->name('studio.authorize');
Route::post('/studio/authorize', [StudioController::class, 'authorizeSubmit'])->middleware('throttle:20,1')->name('studio.approve');
