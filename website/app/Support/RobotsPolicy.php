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

        /*
        | تله‌های خزش — الگوهای wildcardِ query که بودجه را می‌سوزانند.
        |
        | `private_paths` عمداً فقط مسیرِ خالص می‌پذیرد (بالاتر: `parse_url`
        | باید خودِ رشته را برگرداند)، پس الگوی `?`‌دار این‌جا جای جدا دارد.
        |
        | Search Console، ۱۶ سپتامبر ۲۰۲۶: ۲۸۹ «Alternate page with proper
        | canonical» (فیلترهای `?gen=&sort=&max=` فروشگاهِ قطعات و
        | `?attachment_id=` وردپرسِ قدیمی) و ۴۰۵ «Excluded by noindex»
        | (تگ‌های بلاگ) — همه هر روز دوباره خزیده می‌شدند، در حالی که ۶۴۴
        | مقاله هنوز «Discovered – currently not indexed» بودند.
        |
        | 🔴 هر الگو اجباری `?` یا `*` دارد. الگوی بی‌wildcard مسیرِ خصوصی است و
        |    جایش `private_paths` است؛ و الگوی سراسری (`/*`، `/*?`) رد می‌شود
        |    چون `?page=2` و `?cat=` صفحاتِ ایندکس‌شدنی‌اند — بستنشان همان
        |    ۶۵۴ صفحهٔ «Discovered» را برمی‌گرداند. تستِ «هیچ الگویی هیچ URLِ
        |    نقشهٔ سایت را نبندد» جهتِ مخالف را قفل می‌کند.
        */
        $crawlTraps = [];
        foreach ((array) ($policy['crawl_traps'] ?? []) as $pattern) {
            if (! is_string($pattern)
                || ! preg_match('~^/[A-Za-z0-9/_\-.*?=&%]+$~', $pattern)
                || str_starts_with($pattern, '//')
                || ! preg_match('~[*?]~', $pattern)
                || in_array($pattern, ['/*', '/*?', '/?', '/*&'], true)) {
                throw new \InvalidArgumentException('Robots crawl traps must be narrow wildcard patterns.');
            }
            $crawlTraps[] = $pattern;
        }
        $crawlTraps = array_values(array_unique($crawlTraps));

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
                $groups[] = self::group(trim($agent), (bool) ($group['allow'] ?? false), [...$privatePaths, ...$crawlTraps]);
            }
        }

        $groups[] = self::group('*', true, [...$privatePaths, ...$crawlTraps]);

        $sitemap = trim((string) ($policy['sitemap'] ?? ''));
        if (filter_var($sitemap, FILTER_VALIDATE_URL) === false || parse_url($sitemap, PHP_URL_SCHEME) !== 'https') {
            throw new \InvalidArgumentException('Robots policy requires an absolute HTTPS sitemap URL.');
        }
        $groups[] = 'Sitemap: '.$sitemap;

        return implode("\n\n", $groups)."\n";
    }

    /**
     * آیا یک خطِ `Disallow` این آدرس را می‌بندد؟ — با معنای گوگل.
     *
     * `*` هر دنباله (حتی خالی)، `$` لنگرِ انتها، و بقیه تطبیقِ **پیشوندی** از
     * ابتدای مسیر+query. عمومی است تا تست و release gate با **همان** تعریف
     * بسنجند؛ دو پیاده‌سازیِ جدا یعنی روزی یکی بگوید «باز» و دیگری «بسته».
     */
    public static function blocks(string $pattern, string $pathAndQuery): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $body = $anchored ? substr($pattern, 0, -1) : $pattern;

        $regex = '~^'.str_replace('\*', '.*', preg_quote($body, '~')).($anchored ? '$' : '').'~';

        return preg_match($regex, $pathAndQuery) === 1;
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
