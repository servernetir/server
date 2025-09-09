<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\VpsController;
use App\Http\Controllers\HicpuController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\DedicatedController;
use App\Http\Controllers\VpnController;
use App\Http\Controllers\SoftController;
use App\Http\Controllers\ProfileController;

// میهمان (کاربر لاگین‌نشده)
// Route::middleware('guest')->group(function () {
    Route::get('/auth/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/auth/login', [LoginController::class, 'auth'])->name('auth');
// });

// Route::get('/auth/{provider}/redirect', [LoginController::class, 'redirectToProvider'])->name('social.redirect');
// Route::get('/auth/{provider}/callback', [LoginController::class, 'handleProviderCallback'])->name('social.callback');

Route::middleware('check.login')->group(function () {
    Route::post('/auth/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', [HomeController::class, 'home'])->name('home');
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile');

    Route::prefix('order')->group(function () {
        Route::get('/vps', [VpsController::class, 'index'])->name('vps');
        Route::get('/hicpu', [HicpuController::class, 'index'])->name('hicpu');
        Route::get('/dedicated', [DedicatedController::class, 'index'])->name('dedicated');
        Route::get('/domain', [DomainController::class, 'index'])->name('domain');
        Route::get('/vpn', [VpnController::class, 'index'])->name('vpn');
        Route::get('/soft', [SoftController::class, 'index'])->name('soft');
    });
});
