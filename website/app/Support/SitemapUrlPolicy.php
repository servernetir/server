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

    /**
     * The sitemap has one intentional query landing-page shape: blog category.
     * Pagination is crawlable through links but deliberately absent from XML;
     * search, tags, tracking, sorting and arbitrary filters never belong there.
     */
    public static function allowsQuery(string $path, ?string $query): bool
    {
        if ($query === null || $query === '') {
            return true;
        }

        if (preg_match('~^/(?:en/|tr/)?blog$~', $path) !== 1) {
            return false;
        }

        parse_str($query, $parameters);
        if (array_keys($parameters) !== ['cat'] || ! is_string($parameters['cat'])) {
            return false;
        }

        $category = $parameters['cat'];
        $canonicalQuery = http_build_query(['cat' => $category], '', '&', PHP_QUERY_RFC3986);

        return hash_equals($canonicalQuery, $query)
            && array_key_exists($category, (array) config('blog.categories', []));
    }
}
