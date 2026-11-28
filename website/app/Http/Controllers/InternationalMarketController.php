<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ConsoleHost;
use App\Support\InternationalMarket;
use Illuminate\View\View;

final class InternationalMarketController extends Controller
{
    public function show(string $marketLocale, string $marketPage): View
    {
        abort_unless(InternationalMarket::isPublished($marketLocale)
            && isset(InternationalMarket::PAGES[$marketPage]), 404);

        $content = InternationalMarket::content($marketLocale);
        $definition = InternationalMarket::PAGES[$marketPage];
        $canonical = InternationalMarket::url($marketLocale, $marketPage);

        return view('pages.international-market', [
            'marketLocale' => $marketLocale,
            'marketPage' => $marketPage,
            'marketLanguage' => InternationalMarket::LANGUAGES[$marketLocale],
            'marketContent' => $content,
            'marketCanonical' => $canonical,
            'marketAlternates' => InternationalMarket::alternateUrls($marketPage),
            'marketCatalogUrl' => ConsoleHost::siteUrl(route($definition['catalog'], $definition['params'], false)),
            'marketLoginUrl' => ConsoleHost::panelUrl(route('en.login', [], false)),
        ]);
    }
}
