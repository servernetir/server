<?php

namespace App\Services\Cloud;

use App\Models\CloudLocation;
use Illuminate\Support\Facades\Lang;

/** Editorial presentation only: never computes a price, stock or network promise. */
class CountryLandingSeo
{
    public function content(string $iso, string $city = ''): array
    {
        $locale = app()->getLocale();
        $copy = Lang::get('country-seo');
        $iso = strtoupper($iso);
        $country = CloudLocation::COUNTRIES[$iso][$locale]
            ?? CloudLocation::COUNTRIES[$iso]['en'] ?? $iso;
        $place = $country.($city !== '' && $city !== $country ? ' — '.$city : '');
        $replace = fn (string $s): string => strtr($s, [':country' => $country, ':place' => $place]);
        $faqs = array_map(fn (array $row): array => array_map($replace, $row), $copy['faq']);

        return [
            'title' => $replace($copy['title']),
            'description' => $replace($copy['description']),
            'h1' => $replace($copy['h1']),
            'intro' => $replace($copy['intro']),
            'profile' => $replace($copy['profiles'][$iso] ?? $copy['profile_default']),
            'network_title' => $copy['network_title'],
            'network' => $copy['network'],
            'check_title' => $replace($copy['check_title']),
            'country_link' => $replace($copy['country_link']),
            'checks' => $copy['checks'],
            'faq' => $faqs,
            'faq_ld' => ['mainEntity' => array_map(fn (array $row): array => [
                '@type' => 'Question', 'name' => $row['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $row['a']],
            ], $faqs)],
        ];
    }
}
