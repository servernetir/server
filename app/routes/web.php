<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\VpsController;
use App\Http\Controllers\HicpuController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\DedicatedController;
use App\Http\Controllers\VpnController;
use App\Http\Controllers\CpController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServicesController;
use App\Http\Controllers\FinancesController;
use App\Http\Controllers\ReferralSystemController;
use App\Http\Controllers\LimitsController;

Route::get('/login', [LoginController::class, 'index'])->name('login');
Route::post('/login', [LoginController::class, 'auth'])->name('auth');
Route::post('/register', [LoginController::class, 'register'])->name('register');

Route::middleware('check.login')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', [HomeController::class, 'index'])->name('home');

    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'index'])->name('profile');
        Route::delete('/sessions/{id}', [ProfileController::class, 'destroy'])->name('profile.sessions.destroy');
        Route::post('/sessions/logout-others', [ProfileController::class, 'logoutOthers'])->name('profile.sessions.logoutOthers');
    });

    Route::prefix('order')->group(function () {
        Route::get('/vps', [VpsController::class, 'index'])->name('order.vps');
        Route::get('/hi-cpu', [HicpuController::class, 'index'])->name('order.hi-cpu');
        Route::get('/dedicated', [DedicatedController::class, 'index'])->name('order.dedicated');
        Route::get('/domain', [DomainController::class, 'index'])->name('order.domain');
        Route::get('/vpn', [VpnController::class, 'index'])->name('order.vpn');
        Route::get('/Cp-manager', [CpController::class, 'index'])->name('order.cp-manager');
    });

    Route::get('/services', [ServicesController::class, 'index'])->name('services');
    Route::get('/finances', [FinancesController::class, 'index'])->name('finances');
    Route::get('/referral-system', [ReferralSystemController::class, 'index'])->name('referral-system');
    Route::get('/limits', [LimitsController::class, 'index'])->name('limits');
});