<?php

namespace App\Services;

use App\Models\CloudLocation;
use App\Services\Cloud\CloudCountry;

/** Presentation of the existing, already permission-filtered server menu. */
class ServerMenu
{
    public function build(array $section): array
    {
        $out = ['cloud' => [], 'dedicated' => [], 'links' => [], 'parts' => [],
            'hardware' => false, 'gpu' => null, 'hourly' => null, 'guidance' => []];
        foreach ($section['groups'] ?? [] as $group) {
            $role = $group['server_role'] ?? 'other';
            if ($role === 'hardware') {
                $out['hardware'] = ! empty($group['items']);
                // Model-specific and administrator-added links remain discoverable.
                foreach ($group['items'] as $item) {
                    if (! \App\Services\Customer\IranSalesVisibility::allowsItem($item, 'vps')) {
                        continue;
                    }
                    $out['guidance'][] = $this->link($item);
                }

                continue;
            }
            foreach ($group['items'] ?? [] as $item) {
                if (! \App\Services\Customer\IranSalesVisibility::allowsItem($item, 'vps')) {
                    continue;
                }
                $link = $this->link($item);
                $slug = $item['slug'] ?? ($item['route'][1]['slug'] ?? null);
                $iso = strtoupper($item['iso'] ?? CloudCountry::isoForSlug($slug ?? '') ?? '');
                if ($iso !== '' && $iso !== 'XX' && in_array($role, ['cloud', 'countries', 'dedicated'], true)) {
                    $bucket = $role === 'dedicated' ? 'dedicated' : 'cloud';
                    $country = CloudLocation::COUNTRIES[$iso] ?? [];
                    $out[$bucket][$iso] = $link + ['iso' => $iso,
                        'name' => $country[app()->getLocale()] ?? $country['en'] ?? $iso,
                        'flag' => CloudLocation::flagSvgFor($iso),
                        'search' => implode(' ', array_merge([$iso], array_values($country)))];
                } elseif (($item['route'][0] ?? '') === 'gpu') {
                    $out['gpu'] = $link;
                } elseif (($item['route'][0] ?? '') === 'vps.hourly') {
                    $out['hourly'] = $link;
                } elseif ($role === 'parts') {
                    $out['parts'][] = $link;
                } elseif (! in_array($slug, ['gpu', 'cloud'], true)) {
                    $out['guidance'][] = $link;
                }
            }
        }

        return $out;
    }

    private function link(array $item): array
    {
        $url = $item['href'] ?? (isset($item['route'])
            ? lroute($item['route'][0], $item['route'][1] ?? [])
            : (isset($item['slug']) ? lroute('catalog', ['category' => 'vps', 'slug' => $item['slug']]) : '#'));

        return ['url' => $url, 'label' => lc($item)];
    }
}
