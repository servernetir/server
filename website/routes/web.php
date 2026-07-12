<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DomainCheckController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\ToolController;
use Illuminate\Support\Facades\Route;

/*
| ساختار دوزبانه: فارسی در ریشه (پیش‌فرض) و انگلیسی با پیشوند /en
| صفحات جدید را فقط داخل $site اضافه کنید تا خودکار در هر دو زبان ساخته شوند.
*/

$site = function (): void {
    Route::get('/', [SiteController::class, 'home'])->name('home');
    Route::get('/hosting/{slug}', [CatalogController::class, 'hosting'])->name('hosting')->where('slug', '[a-z-]+');
    Route::get('/contact', [SiteController::class, 'contact'])->name('contact');
    Route::get('/knowledge', [SiteController::class, 'knowledge'])->name('knowledge');

    Route::get('/tools/{slug}', [ToolController::class, 'show'])->name('tools')->where('slug', '[a-z-]+');
    Route::post('/api/audit', [ToolController::class, 'audit'])->name('api.audit');
    Route::post('/api/whois', [ToolController::class, 'whois'])->name('api.whois');
    Route::post('/api/ip', [ToolController::class, 'ip'])->name('api.ip');
    Route::get('/{category}/{slug}', [CatalogController::class, 'show'])->name('catalog')
        ->whereIn('category', ['vps', 'dedicated', 'cloud', 'domain', 'services'])->where('slug', '[a-z0-9-]+');
    Route::post('/api/chat', ChatController::class)->name('chat');
    Route::post('/api/domain-check', DomainCheckController::class)->name('domain.check');
};

Route::middleware('locale:fa')->group($site);
Route::prefix('en')->name('en.')->middleware('locale:en')->group($site);
Route::prefix('tr')->name('tr.')->middleware('locale:tr')->group($site);
