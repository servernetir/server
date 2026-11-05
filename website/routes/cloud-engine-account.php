<?php

use App\Http\Controllers\Account\EngineAccountController;
use Illuminate\Support\Facades\Route;

// Required inside the existing localized account group: customer authentication,
// active-account/IP controls, session and CSRF middleware are inherited intact.
Route::get('cloud/{service}/engine/status', [EngineAccountController::class, 'status'])->name('cloud.engine.status')->middleware('throttle:30,1');
Route::post('cloud/{service}/engine/power', [EngineAccountController::class, 'power'])->name('cloud.engine.power')->middleware('throttle:12,1');
Route::post('cloud/{service}/engine/rebuild', [EngineAccountController::class, 'rebuild'])->name('cloud.engine.rebuild')->middleware('throttle:6,1');
Route::post('cloud/{service}/engine/password', [EngineAccountController::class, 'resetPassword'])->name('cloud.engine.password')->middleware('throttle:6,1');
Route::post('cloud/{service}/engine/hostname', [EngineAccountController::class, 'hostname'])->name('cloud.engine.hostname')->middleware('throttle:6,1');
Route::post('cloud/{service}/engine/console', [EngineAccountController::class, 'console'])->name('cloud.engine.console')->middleware('throttle:10,1');
