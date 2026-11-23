<?php

namespace App\Support;

use App\Providers\AppServiceProvider;
use App\Services\Studio\StudioAuthorization;

/** Single public catalogue for the visible home directory and its discovery metadata. */
final class HomeServiceDirectory
{
    public static function entries(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $prefix = AppServiceProvider::LOCALES[$locale] ?? '';
        $definitions = [
            'vps' => ['cloud.index', [], 'server'],
            'gpu' => ['gpu', [], 'cpu'],
            'studio' => ['ai.studio', [], 'sparkles'],
            'n8n' => ['cloud.location', ['n8n'], 'flow'],
            'hosting' => ['hosting', ['linux'], 'globe'],
            'email' => ['email.transactional', [], 'mail'],
        ];

        if (! app(StudioAuthorization::class)->configured()) {
            unset($definitions['studio']);
        }

        $entries = [];
        foreach ($definitions as $key => [$route, $params, $icon]) {
            $entries[$key] = [
                'url' => route($prefix.$route, $params),
                'icon' => $icon,
                'name' => trans('home_discovery.services.'.$key.'.name', [], $locale),
                'description' => trans('home_discovery.services.'.$key.'.description', [], $locale),
                'detail' => trans('home_discovery.services.'.$key.'.detail', [], $locale),
                'cta' => trans('home_discovery.services.'.$key.'.cta', [], $locale),
            ];
        }

        return $entries;
    }

    public static function description(): string
    {
        return __(app(StudioAuthorization::class)->configured()
            ? 'home_discovery.meta_description' : 'home_discovery.meta_description_without_studio');
    }

    public static function schema(array $entries, string $pageUrl): array
    {
        $provider = rtrim((string) config('app.url'), '/').'/#organization';

        return [
            '@type' => 'ItemList',
            '@id' => $pageUrl.'#service-directory',
            'name' => __('home_discovery.heading'),
            'numberOfItems' => count($entries),
            'itemListElement' => array_map(static function ($entry, $index) use ($provider) {
                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'Service',
                        '@id' => $entry['url'].'#service',
                        'name' => $entry['name'],
                        'description' => $entry['description'],
                        'url' => $entry['url'],
                        'provider' => ['@id' => $provider],
                    ],
                ];
            }, array_values($entries), array_keys(array_values($entries))),
        ];
    }
}
