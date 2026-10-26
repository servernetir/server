<?php

use App\Http\Controllers\Admin\GrowthController;
use App\Http\Controllers\Admin\GrowthKnowledgeController;
use App\Http\Controllers\HermesKnowledgeController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/ai-growth')->middleware(['auth:web', 'admin'])->group(function () {
    Route::get('/', [GrowthController::class, 'index'])->name('admin.growth');
    Route::post('/content', [GrowthController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/content/{content}', [GrowthController::class, 'show']);
    Route::post('/content/{content}/edit', [GrowthController::class, 'edit']);
    Route::post('/content/{content}/transition', [GrowthController::class, 'transition']);
    Route::post('/content/{content}/quote', [GrowthController::class, 'quote'])->middleware('throttle:10,1');
    Route::post('/content/{content}/generate', [GrowthController::class, 'generate'])->middleware('throttle:10,1');
    Route::post('/content/{content}/upload', [GrowthController::class, 'upload']);
    Route::post('/content/{content}/archive', [GrowthController::class, 'archive'])->middleware('throttle:6,1');
    Route::post('/content/{content}/published', [GrowthController::class, 'published']);
    Route::get('/content/{content}/export', [GrowthController::class, 'export']);
    Route::get('/assets/{asset}', [GrowthController::class, 'asset']);
    Route::post('/settings', [GrowthController::class, 'settings']);
    Route::post('/supervisor', [GrowthController::class, 'supervisor']);
    Route::post('/google/connect', [GrowthController::class, 'googleConnect']);
    Route::get('/google/callback', [GrowthController::class, 'googleCallback'])->name('admin.growth.google.callback');
    Route::post('/google/disconnect', [GrowthController::class, 'googleDisconnect']);
    Route::post('/knowledge', [GrowthKnowledgeController::class, 'store']);
    Route::post('/knowledge/{document}/review', [GrowthKnowledgeController::class, 'review']);
    Route::post('/knowledge/{document}/delete', [GrowthKnowledgeController::class, 'delete']);
    Route::get('/knowledge/export', [GrowthKnowledgeController::class, 'export']);
    Route::post('/knowledge/import/preview', [GrowthKnowledgeController::class, 'preview'])->middleware('throttle:6,1');
    Route::post('/knowledge/import/commit', [GrowthKnowledgeController::class, 'import']);
});
Route::post('/api/internal/growth/knowledge/{action}', [HermesKnowledgeController::class, 'handle'])
    ->whereIn('action', ['search', 'solution', 'suggest', 'candidate', 'feedback', 'products', 'changes'])
    ->middleware('throttle:30,1')->withoutMiddleware([PreventRequestForgery::class]);
