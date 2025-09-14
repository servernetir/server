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

Route::get('/login', [LoginController::class, 'index'])->name('login');
Route::post('/login', [LoginController::class, 'auth'])->name('auth');
Route::post('/register', [LoginController::class, 'register'])->name('register');

Route::middleware('check.login')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', [HomeController::class, 'index'])->name('home');
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