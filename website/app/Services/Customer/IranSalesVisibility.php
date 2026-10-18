<?php

namespace App\Services\Customer;

use Illuminate\Support\Facades\Auth;

/** Per-request purchase navigation; shared catalogue/menu caches stay unfiltered. */
class IranSalesVisibility
{
    public static function restricted(): bool
    {
        try {
            $customer = Auth::guard('customer')->user();
            $key = '_iran_purchase_restricted_'.($customer?->getAuthIdentifier() ?? 'guest');
            $attributes = request()->attributes;
            if (! $attributes->has($key)) {
                $attributes->set($key, IranSalesGate::blocksIranFor($customer));
            }

            return (bool) $attributes->get($key);
        } catch (\Throwable) {
            return true;
        }
    }

    public static function allowsCountry(?string $country): bool
    {
        return ! IranSalesGate::isIranTarget($country) || ! self::restricted();
    }

    public static function allowsUrl(?string $url): bool
    {
        $parts = parse_url(html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($parts === false) {
            return true;
        }
        $path = strtolower(rawurldecode((string) ($parts['path'] ?? '')));
        $path = preg_replace('~^/(?:en|tr)(?=/|$)~', '', $path);
        $iran = preg_match('~^/(?:vps|dedicated|hosting)/iran(?:/|$)~', $path)
            || preg_match('~^/cloud/ir(?:-|/|$)~', $path);
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (['location', 'loc', 'country'] as $key) {
            if (is_scalar($query[$key] ?? null) && IranSalesGate::isIranTarget((string) $query[$key])) {
                $iran = true;
            }
        }

        return ! $iran || ! self::restricted();
    }

    public static function allowsItem(array $item, string $scope = ''): bool
    {
        foreach (['iso', 'country'] as $key) {
            if (isset($item[$key]) && ! self::allowsCountry((string) $item[$key])) {
                return false;
            }
        }
        $route = $item['route'] ?? $item['link'] ?? null;
        if (is_array($route)) {
            $name = $route[0] ?? '';
            $parameters = $route[1] ?? [];
            if ($name === 'catalog' && is_array($parameters)
                && in_array($parameters['category'] ?? '', ['vps', 'dedicated', 'cloud', 'hosting'], true)
                && strtolower((string) ($parameters['slug'] ?? '')) === 'iran' && self::restricted()) {
                return false;
            }
            if ($name === 'cloud.location') {
                $location = is_array($parameters) ? ($parameters['location'] ?? $parameters[0] ?? '') : $parameters;
                if (! self::allowsCountry((string) $location)) {
                    return false;
                }
            }
        }
        if (strtolower((string) ($item['slug'] ?? '')) === 'iran'
            && in_array($scope, ['vps', 'dedicated', 'cloud', 'hosting'], true) && self::restricted()) {
            return false;
        }
        foreach (['url', 'href'] as $key) {
            if (isset($item[$key]) && ! self::allowsUrl((string) $item[$key])) {
                return false;
            }
        }

        return true;
    }

    public static function menu(array $mega): array
    {
        foreach ($mega as $tab => &$section) {
            foreach ($section['groups'] ?? [] as $index => $group) {
                $section['groups'][$index]['items'] = array_values(array_filter(
                    $group['items'] ?? [], fn ($item) => self::allowsItem($item, (string) $tab)
                ));
            }
            $section['groups'] = array_values(array_filter($section['groups'] ?? [], fn ($group) => ! empty($group['items'])));
        }
        unset($section);

        return $mega;
    }
}
