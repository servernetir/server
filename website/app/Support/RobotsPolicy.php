<?php

namespace App\Support;

final class RobotsPolicy
{
    /** @param array<string, mixed> $policy */
    public static function render(array $policy): string
    {
        $configuredPaths = (array) ($policy['private_paths'] ?? []);
        if ($configuredPaths === []) {
            throw new \InvalidArgumentException('Robots policy requires private paths.');
        }

        foreach ($configuredPaths as $path) {
            if (! is_string($path)
                || $path === ''
                || ! str_starts_with($path, '/')
                || str_starts_with($path, '//')
                || parse_url($path, PHP_URL_PATH) !== $path) {
                throw new \InvalidArgumentException('Robots private paths must be absolute paths.');
            }
        }

        $crawlExclusions = (array) ($policy['crawl_exclusions'] ?? []);
        foreach ($crawlExclusions as $pattern) {
            // Separate public query-pattern rules from literal private paths.
            // Never allow injected directives or another host's URL.
            if (! is_string($pattern) || ! preg_match('~\A/(?!/)[^\x00-\x20\x7f]+\z~D', $pattern)
                || (! str_contains($pattern, '?') && ! str_contains($pattern, '&'))) {
                throw new \InvalidArgumentException('Robots crawl exclusions must be single-line query patterns.');
            }
        }
        $privatePaths = array_values(array_unique([...$configuredPaths, ...$crawlExclusions]));

        $groups = [];
        foreach (['search_discovery', 'model_training'] as $kind) {
            $group = (array) ($policy[$kind] ?? []);
            $agents = array_values(array_filter(
                (array) ($group['agents'] ?? []),
                fn ($agent): bool => is_string($agent) && trim($agent) !== ''
            ));
            if ($agents === []) {
                throw new \InvalidArgumentException('Robots policy requires agents for '.$kind.'.');
            }

            foreach ($agents as $agent) {
                $groups[] = self::group(trim($agent), (bool) ($group['allow'] ?? false), $privatePaths);
            }
        }

        $groups[] = self::group('*', true, $privatePaths);

        $sitemaps = [$policy['sitemap'] ?? '', ...((array) ($policy['additional_sitemaps'] ?? []))];
        foreach ($sitemaps as $sitemap) {
            if (! is_string($sitemap) || filter_var($sitemap, FILTER_VALIDATE_URL) === false || parse_url($sitemap, PHP_URL_SCHEME) !== 'https') {
                throw new \InvalidArgumentException('Robots policy requires absolute HTTPS sitemap URLs.');
            }
        }
        $groups[] = implode("\n", array_map(fn ($url) => 'Sitemap: '.$url, array_unique($sitemaps)));

        return implode("\n\n", $groups)."\n";
    }

    /** @param list<string> $privatePaths */
    private static function group(string $agent, bool $allowed, array $privatePaths): string
    {
        $lines = ['User-agent: '.$agent];

        if (! $allowed) {
            $lines[] = 'Disallow: /';

            return implode("\n", $lines);
        }

        $lines[] = 'Allow: /';
        foreach ($privatePaths as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        return implode("\n", $lines);
    }
}
