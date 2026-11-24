<?php

use App\Http\Controllers\Account\HostingDomainQuoteController;
use Illuminate\Support\Facades\Route;

// Included inside the existing localized, active-customer account group.
Route::post('/order-domain-quote', HostingDomainQuoteController::class)
    ->name('order.domain-quote')->middleware('throttle:10,1');
