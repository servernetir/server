<?php

namespace App\Support;

final class SitemapUrlPolicy
{
    public static function hasCanonicalOrigin(string $url, string $canonicalBase): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || filter_var($canonicalBase, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $urlScheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $canonicalScheme = strtolower((string) parse_url($canonicalBase, PHP_URL_SCHEME));
        if ($urlScheme !== 'https' || $canonicalScheme !== 'https') {
            return false;
        }

        $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $canonicalHost = strtolower((string) parse_url($canonicalBase, PHP_URL_HOST));
        $urlPort = parse_url($url, PHP_URL_PORT) ?: 443;
        $canonicalPort = parse_url($canonicalBase, PHP_URL_PORT) ?: 443;

        return $urlHost !== ''
            && hash_equals($canonicalHost, $urlHost)
            && $urlPort === $canonicalPort;
    }

    /** Only canonical category landing pages may carry a sitemap query. */
    public static function allowsQuery(string $path, ?string $query): bool
    {
        if ($query === null || $query === '') {
            return true;
        }

        $blog = preg_match('~^/(?:en/|tr/)?blog$~', $path) === 1;
        $aiModels = preg_match('~^/(?:en/|tr/)?ai/models$~', $path) === 1;
        if (! $blog && ! $aiModels) {
            return false;
        }

        $key = $blog ? 'cat' : 'type';
        parse_str($query, $parameters);
        if (array_keys($parameters) !== [$key] || ! is_string($parameters[$key])) {
            return false;
        }

        $category = $parameters[$key];
        $canonicalQuery = http_build_query([$key => $category], '', '&', PHP_QUERY_RFC3986);

        return hash_equals($canonicalQuery, $query)
            && ($blog
                ? array_key_exists($category, (array) config('blog.categories', []))
                : in_array($category, \App\Models\AiModel::CATEGORIES, true));
    }
}
