<?php

use App\Http\Controllers\Account\MadarController;
use Illuminate\Support\Facades\Route;

// Included inside the existing localized, active-customer account group.
Route::get('/madar', [MadarController::class, 'index'])->name('madar');
Route::post('/madar', [MadarController::class, 'apply'])->name('madar.apply')->middleware('throttle:5,1');
Route::prefix('/madar/{participation}')->whereUuid('participation')->group(function () {
    Route::get('/', [MadarController::class, 'show'])->name('madar.show');
    Route::get('/attachments/{message}', [MadarController::class, 'attachment'])->name('madar.attachment')->whereNumber('message');
    foreach (['message', 'accept', 'submit', 'cancel', 'redeem'] as $action) {
        Route::post('/'.$action, [MadarController::class, $action])->name('madar.'.$action)->middleware('throttle:15,1');
    }
});
