<?php

use App\Http\Controllers\IdentityStorefrontController;
use Illuminate\Support\Facades\Route;

Route::get('/identity-api', [IdentityStorefrontController::class, 'home'])->name('identity.home');
Route::get('/identity-api/docs', [IdentityStorefrontController::class, 'docs'])->name('identity.docs');
Route::get('/identity-api/terms', [IdentityStorefrontController::class, 'terms'])->name('identity.terms');
Route::get('/identity-api/openapi.json', [IdentityStorefrontController::class, 'openapi'])->name('identity.openapi');
