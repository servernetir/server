<?php

namespace App\Http\Controllers;

use App\Models\CloudLocation;
use App\Services\Cloud\CountryLandingSeo;
use App\Services\Cloud\PublicVpsCatalog;
use Illuminate\View\View;

class CountryVpsController extends Controller
{
    public function show(string $iso): View
    {
        abort_unless(isset(CloudLocation::COUNTRIES[$iso]) && $iso !== 'XX', 404);

        $rows = app(PublicVpsCatalog::class)->rows($iso);
        $url = \App\Services\Cloud\CloudCountry::url($iso);
        $country = CloudLocation::COUNTRIES[$iso][app()->getLocale()] ?? CloudLocation::COUNTRIES[$iso]['en'];
        $items = [];
        $rate = cloud_eur_rate();
        foreach ($rows as $row) {
            $price = app()->getLocale() === 'fa' ? schema_price_irr($row['monthly'])
                : ($rate > 0 ? round($row['monthly'] / $rate, 2) : null);
            if ($price === null || $price <= 0) { continue; }
            $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'item' => [
                '@type' => 'Product', 'name' => $row['name'].' — '.$country.' — '.$row['city'],
                'offers' => ['@type' => 'Offer', 'price' => $price,
                    'priceCurrency' => app()->getLocale() === 'fa' ? 'IRR' : 'EUR',
                    'url' => $url, 'availability' => 'https://schema.org/InStock'],
            ]];
        }

        return view('pages.vps-country', [
            'iso' => $iso,
            'country' => $country,
            'flag' => CloudLocation::COUNTRIES[$iso]['flag'],
            'seo' => app(CountryLandingSeo::class)->content($iso),
            'vpsRows' => $rows,
            'ld' => [
                'list' => ['itemListElement' => $items],
                'crumbs' => ['itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => lroute('home')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => __('vps-catalog.catalog'), 'item' => lroute('cloud.index')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $country, 'item' => $url],
                ]],
            ],
        ]);
    }
}
