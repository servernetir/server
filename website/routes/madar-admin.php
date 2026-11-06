<?php

use App\Http\Controllers\Admin\MadarController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/madar')->middleware(['auth:web', 'admin'])->name('admin.madar')->group(function () {
    Route::get('/', [MadarController::class, 'index']);
    Route::post('/settings', [MadarController::class, 'settings'])->name('.settings')->middleware('throttle:15,1');
    Route::prefix('/{participation}')->whereUuid('participation')->group(function () {
        Route::get('/', [MadarController::class, 'show'])->name('.show');
        Route::get('/attachments/{message}', [MadarController::class, 'attachment'])->name('.attachment')->whereNumber('message');
        foreach (['message', 'offer', 'review', 'reject', 'extend'] as $action) {
            Route::post('/'.$action, [MadarController::class, $action])->name('.'.$action)->middleware('throttle:20,1');
        }
    });
});
