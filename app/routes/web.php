<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\VpsController;
use App\Http\Controllers\HicpuController;
use App\Http\Controllers\HostController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\DedicatedController;
use App\Http\Controllers\VpnController;
use App\Http\Controllers\CpController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\FinancesController;
use App\Http\Controllers\ReferralSystemController;
use App\Http\Controllers\LimitsController;
use App\Http\Controllers\SocialController;

Route::get('/login', [LoginController::class, 'index'])->name('login');
Route::post('/register/start', [LoginController::class, 'register'])->name('register.start');
Route::post('/register/verify', [LoginController::class, 'verifyCode'])->name('register.verify');
Route::post('/register/resend', [LoginController::class, 'resendCode'])->name('register.resend');
Route::post('/register/cancel', [LoginController::class, 'cancelVerification'])->name('register.cancel');

Route::post('/login', [LoginController::class, 'login'])->name('auth');
Route::get('auth/{provider}', [SocialController::class, 'redirect']);
Route::get('auth/{provider}/callback', [SocialController::class, 'callback']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware('check.login')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', [HomeController::class, 'index'])->name('home');

    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'index'])->name('profile');
        Route::delete('/sessions/{id}', [ProfileController::class, 'destroy'])->name('profile.sessions.destroy');
        Route::post('/sessions/logout-others', [ProfileController::class, 'logoutOthers'])->name('profile.sessions.logoutOthers');
    });

    Route::prefix('order')->group(function () {
        Route::get('/vps', [VpsController::class, 'index'])->name('vps');
        Route::get('/vps-submit', [VpsController::class, 'submit'])->name('vps.submit');

        Route::get('/hi-cpu', [HicpuController::class, 'index'])->name('hi-cpu');

        Route::get('/dedicated', [DedicatedController::class, 'index'])->name('dedicated');

        Route::get('/host', [HostController::class, 'index'])->name('host');

        Route::get('/domain', [DomainController::class, 'index'])->name('domain');
        Route::post('/domain/check', [DomainController::class, 'check'])->name('domain.check');
        Route::post('/domain/order', [DomainController::class, 'order'])->name('domain.order');

        Route::get('/vpn', [VpnController::class, 'index'])->name('vpn');
        Route::get('/vpn-submit', [VpnController::class, 'submit'])->name('vpn.submit');

        Route::get('/license', [CpController::class, 'index'])->name('license');
        Route::get('/license-submit', [CpController::class, 'submit'])->name('license.submit');
    });

    Route::get('/finances', [FinancesController::class, 'index'])->name('finances');
    Route::get('/referral-system', [ReferralSystemController::class, 'index'])->name('referral-system');
    Route::get('/limits', [LimitsController::class, 'index'])->name('limits');
});