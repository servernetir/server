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

        $privatePaths = array_values(array_unique($configuredPaths));

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

        $sitemap = trim((string) ($policy['sitemap'] ?? ''));
        if (filter_var($sitemap, FILTER_VALIDATE_URL) === false || parse_url($sitemap, PHP_URL_SCHEME) !== 'https') {
            throw new \InvalidArgumentException('Robots policy requires an absolute HTTPS sitemap URL.');
        }
        $groups[] = 'Sitemap: '.$sitemap;

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
