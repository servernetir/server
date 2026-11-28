<?php

namespace App\Support;

use App\Http\Middleware\ConsoleHost;

/**
 * Public discovery pilots, distinct from the fully localized checkout locales.
 * Never expand AppServiceProvider::LOCALES until all its consumers are ready.
 */
final class InternationalMarket
{
    public const LANGUAGES = [
        'fa' => ['label' => 'فارسی', 'dir' => 'rtl', 'og' => 'fa_IR'],
        'en' => ['label' => 'English', 'dir' => 'ltr', 'og' => 'en_US'],
        'tr' => ['label' => 'Türkçe', 'dir' => 'ltr', 'og' => 'tr_TR'],
        'ru' => ['label' => 'Русский', 'dir' => 'ltr', 'og' => 'ru_RU'],
        'az' => ['label' => 'Azərbaycanca', 'dir' => 'ltr', 'og' => 'az_AZ'],
        'fr' => ['label' => 'Français', 'dir' => 'ltr', 'og' => 'fr_FR'],
        'ar' => ['label' => 'العربية', 'dir' => 'rtl', 'og' => 'ar'],
    ];

    public const PILOT_LOCALES = ['ru', 'az'];

    /** Only these public GET pages exist in the pilot. Handoffs stay on en. */
    public const PAGES = [
        'home' => ['path' => '/', 'catalog' => 'en.home', 'params' => []],
        'vps' => ['path' => '/cloud', 'catalog' => 'en.cloud.index', 'params' => []],
        'hourly' => ['path' => '/vps/hourly', 'catalog' => 'en.vps.hourly', 'params' => []],
        'n8n' => ['path' => '/cloud/n8n', 'catalog' => 'en.cloud.location', 'params' => ['location' => 'n8n']],
        'gpu' => ['path' => '/gpu', 'catalog' => 'en.gpu', 'params' => []],
        'ai' => ['path' => '/ai', 'catalog' => 'en.ai.home', 'params' => []],
    ];

    /** @return list<string> */
    public static function publishedLocales(): array
    {
        $configured = config('international-market.enabled_locales', '');
        $requested = is_string($configured) ? explode(',', $configured) : (array) $configured;
        $requested = array_map(static fn ($code) => is_string($code) ? trim($code) : '', $requested);

        // An unrecognized or not-yet-translated language never becomes public.
        return array_values(array_intersect(self::PILOT_LOCALES, $requested));
    }

    public static function isPublished(string $locale): bool
    {
        return in_array($locale, self::publishedLocales(), true);
    }

    /** Public sitemap/link tooling knows the pilot prefixes without enabling checkout. */
    public static function localeFromPath(string $path): string
    {
        $prefix = explode('/', trim((string) parse_url($path, PHP_URL_PATH), '/'))[0];

        return in_array($prefix, ['en', 'tr', ...self::PILOT_LOCALES], true) ? $prefix : 'fa';
    }

    public static function path(string $locale, string $page): string
    {
        return '/'.$locale.rtrim(self::PAGES[$page]['path'], '/');
    }

    public static function url(string $locale, string $page): string
    {
        return ConsoleHost::siteUrl(self::path($locale, $page));
    }

    /** @return array<string, string> Exact, reciprocal equivalents only. */
    public static function alternateUrls(string $page): array
    {
        $urls = [];
        foreach (self::publishedLocales() as $locale) {
            $urls[$locale] = self::url($locale, $page);
        }

        return $urls;
    }

    /** @return array<string, string> Navigation, not hreflang. */
    public static function switcher(): array
    {
        return self::alternateUrls('home');
    }

    public static function isPublishedPath(string $path): bool
    {
        foreach (self::publishedLocales() as $locale) {
            foreach (array_keys(self::PAGES) as $page) {
                if (trim(self::path($locale, $page), '/') === trim($path, '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<array{loc: string, lastmod: null}> */
    public static function sitemapEntries(): array
    {
        $entries = [];
        foreach (self::publishedLocales() as $locale) {
            foreach (array_keys(self::PAGES) as $page) {
                $entries[] = ['loc' => self::url($locale, $page), 'lastmod' => null];
            }
        }

        return $entries;
    }

    public static function content(string $locale): array
    {
        // No fallback to English masquerading as another language. The ready
        // list is closed and coverage is checked in CI.
        if (! in_array($locale, self::PILOT_LOCALES, true)) {
            throw new \InvalidArgumentException('Unknown public market locale');
        }

        return require lang_path($locale.'/international-market.php');
    }
}
