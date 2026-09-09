<?php

namespace App\Support;

final class SitemapUrlPolicy
{
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
