<?php

use App\Http\Controllers\Admin\DomainSupplierController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/domain-suppliers')->middleware(['auth:web', 'admin'])->group(function () {
    Route::post('/operations/{supplierOperation}/refresh-quote', [DomainSupplierController::class, 'refreshQuote'])->whereNumber('supplierOperation')->middleware('throttle:10,1');
    Route::post('/operations/{supplierOperation}/approve', [DomainSupplierController::class, 'approve'])->whereNumber('supplierOperation')->middleware('throttle:10,1');
    Route::get('/', [DomainSupplierController::class, 'index'])->name('admin.domain-suppliers');
    Route::post('/', [DomainSupplierController::class, 'store'])->middleware('throttle:20,1');
    Route::post('/{supplierAccount}', [DomainSupplierController::class, 'update'])->whereNumber('supplierAccount')->middleware('throttle:20,1');
    Route::post('/{supplierAccount}/contacts', [DomainSupplierController::class, 'contact'])->whereNumber('supplierAccount')->middleware('throttle:10,1');
    Route::post('/{supplierAccount}/probe', [DomainSupplierController::class, 'probe'])->whereNumber('supplierAccount')->middleware('throttle:6,1');
    Route::post('/{supplierAccount}/sales', [DomainSupplierController::class, 'sales'])->whereNumber('supplierAccount')->middleware('throttle:20,1');
});
