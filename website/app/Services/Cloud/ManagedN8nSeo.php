<?php

namespace App\Services\Cloud;

class ManagedN8nSeo
{
    public function present(array $cards, bool $enabled, array $countries): array
    {
        $copy = (require resource_path('content/n8n-seo.php'))[app()->getLocale()];
        $url = lroute('catalog', ['category' => 'cloud', 'slug' => 'n8n']);
        $rates = [];
        foreach ($countries as $country) {
            $card = collect($cards)->where('country', $country)->sortBy('cycles.monthly')->first();
            if ($card) { $rates[] = $card; }
        }
        $service = ['@'.'id' => $url.'#service', 'name' => $copy['heading'],
            'description' => $copy['intro'], 'url' => $url, 'serviceType' => 'n8n hosting',
            'provider' => ['@'.'id' => rtrim(config('app.url'), '/').'/#organization'],
            'areaServed' => array_map(fn ($code) => ['@'.'type' => 'Country', 'name' => __('n8n.'.$code)], $countries)];
        if ($enabled) {
            $eur = cloud_eur_rate();
            $offers = [];
            foreach ($rates as $card) {
                $amount = (int) $card['cycles']['monthly'];
                if ($amount <= 0 || (app()->getLocale() !== 'fa' && $eur <= 0)) { continue; }
                $currency = app()->getLocale() === 'fa' ? 'IRR' : 'EUR';
                $price = $currency === 'IRR' ? schema_price_irr($amount) : number_format($amount / $eur, 2, '.', '');
                $offers[] = ['@'.'type' => 'Offer', 'name' => $card['name'].' · '.__('n8n.'.$card['country']),
                    'url' => $url.'?'.http_build_query(['country' => $card['country'], 'location' => $card['location'], 'plan' => $card['slug']]).'#n8n-details',
                    'priceCurrency' => $currency, 'price' => $price,
                    'priceSpecification' => ['@'.'type' => 'UnitPriceSpecification', 'price' => $price,
                        'priceCurrency' => $currency, 'unitCode' => 'MON', 'valueAddedTaxIncluded' => false]];
            }
            if ($offers !== []) { $service['offers'] = $offers; }
        }
        $faq = ['mainEntity' => array_map(fn ($item) => ['@'.'type' => 'Question', 'name' => $item['q'],
            'acceptedAnswer' => ['@'.'type' => 'Answer', 'text' => $item['a']]], $copy['faqs'])];
        $crumbs = ['itemListElement' => [
            ['@'.'type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => lroute('home')],
            ['@'.'type' => 'ListItem', 'position' => 2, 'name' => 'n8n', 'item' => $url],
        ]];
        return ['copy' => $copy, 'rates' => $rates, 'service' => $service, 'faq' => $faq, 'crumbs' => $crumbs];
    }
}
