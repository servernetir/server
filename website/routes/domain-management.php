<?php

// Included inside the existing locale/account/customer/active/IP middleware group.
use App\Http\Controllers\Account\DomainController;
use Illuminate\Support\Facades\Route;

Route::post('/domains/{domain}/dnssec', [DomainController::class, 'dnssec'])
    ->whereNumber('domain')->middleware('throttle:10,1')->name('domain.dnssec');
Route::post('/domains/{domain}/contacts', [DomainController::class, 'contacts'])
    ->whereNumber('domain')->middleware('throttle:10,1')->name('domain.contacts');
