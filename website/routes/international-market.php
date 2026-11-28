<?php

use App\Http\Controllers\InternationalMarketController;
use App\Support\InternationalMarket;
use Illuminate\Support\Facades\Route;

// This is intentionally not the shared transactional $site closure. Until a
// language passes full UI, console and authorization tests, expose only the
// explicit informational pages. The controller checks publication at request
// time, including with cached routes and independently of Accept-Language.
foreach (InternationalMarket::PILOT_LOCALES as $marketLocale) {
    Route::prefix($marketLocale)->name('international.'.$marketLocale.'.')->group(function () use ($marketLocale): void {
        foreach (InternationalMarket::PAGES as $marketPage => $definition) {
            Route::get($definition['path'], [InternationalMarketController::class, 'show'])
                ->defaults('marketLocale', $marketLocale)
                ->defaults('marketPage', $marketPage)
                ->name($marketPage);
        }
    });
}
