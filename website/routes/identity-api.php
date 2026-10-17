<?php

use App\Http\Controllers\Api\IdentityController;
use App\Http\Middleware\CustomerApiToken;
use App\Http\Middleware\IdentityPrivacy;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::prefix('api/v1/identity')->withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    AddQueuedCookiesToResponse::class,
    PreventRequestForgery::class,
])->middleware([IdentityPrivacy::class, 'throttle:60,1'])->group(function () {
    $c = IdentityController::class;
    $auth = CustomerApiToken::class;
    Route::get('services', [$c, 'services'])->middleware($auth.':identity:services:read');
    Route::get('pricing', [$c, 'services'])->middleware($auth.':identity:services:read');
    Route::post('inquiries/{service}', [$c, 'inquire'])->middleware([$auth.':identity:execute', 'throttle:20,1']);
    Route::get('requests/{id}', [$c, 'show'])->middleware($auth.':identity:requests:read');
});

foreach (['' => '', 'en/' => 'en.', 'tr/' => 'tr.'] as $prefix => $name) {
    $locale = $prefix === '' ? 'fa' : rtrim($prefix, '/');
    Route::prefix($prefix.'admin/identity-api')->name($name.'admin.identity.')->middleware(['locale:'.$locale, 'auth:web', 'admin', IdentityPrivacy::class])->group(function () {
        $c = App\Http\Controllers\Admin\IdentityController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::get('export', [$c, 'export'])->name('export');
        Route::post('control', [$c, 'control'])->name('control')->middleware('throttle:20,1');
        Route::post('prices', [$c, 'price'])->name('price')->middleware('throttle:20,1');
        Route::post('customers', [$c, 'customerCap'])->name('customer-cap')->middleware('throttle:20,1');
        Route::post('requests/{id}/refund', [$c, 'refund'])->name('refund')->middleware('throttle:10,1');
    });
}
