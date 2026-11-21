<?php

use App\Http\Controllers\Admin\EmailMarketingController;
use Illuminate\Support\Facades\Route;

// Loaded only inside the existing auth:web + admin group. No campaign/sending routes.
Route::get('/email-marketing', [EmailMarketingController::class, 'index'])->name('admin.email-marketing.index');
Route::get('/email-marketing/sample', [EmailMarketingController::class, 'sample'])->name('admin.email-marketing.sample');
Route::post('/email-marketing/stage', [EmailMarketingController::class, 'stage'])
    ->middleware('throttle:3,1')->name('admin.email-marketing.stage');
Route::post('/email-marketing/{preference}/suppress', [EmailMarketingController::class, 'suppress'])
    ->whereNumber('preference')->middleware('throttle:10,1')->name('admin.email-marketing.suppress');
