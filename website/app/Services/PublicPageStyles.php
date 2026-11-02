<?php

namespace App\Services;

/** Complete current styles for a bounded set of public pages, never a generated subset. */
class PublicPageStyles
{
    private const REQUEST_CACHE = 'servernet.public-page-styles';

    private const PATHS = [
        '', 'gpu', 'vps/hourly', 'vps/philippines', 'vps/uae',
        'hosting/linux', 'blog/php-version-upgrade-guide',
    ];

    private const FONTS = [
        200 => ['IRANSans-UltraLight-web', 'swap'],
        300 => ['IRANSans-Light-web', 'swap'],
        400 => ['IRANSans-web', 'swap'],
        500 => ['IRANSans-Medium-web', 'optional'],
        700 => ['IRANSans-Bold-web', 'optional'],
    ];

    public function inline(string $stylesheet): ?array
    {
        $styles = $this->bundle($stylesheet);

        return $styles === null ? null : array_intersect_key($styles, array_flip(['site', 'server-menu', 'site-select']));
    }

    /** Keep page and widget CSS in their original cascade positions. */
    public function auxiliary(string $asset): ?string
    {
        $key = ['assets/css/vps-catalog.css' => 'vps-catalog', 'assets/css/chat-morph.css' => 'chat-morph'][$asset] ?? null;
        if ($key === null) {
            return null;
        }
        $source = public_asset_path('assets/css/site.css');
        $min = public_asset_path('assets/css/site.min.css');
        $stylesheet = $source && $min && @filemtime($min) >= @filemtime($source)
            ? 'assets/css/site.min.css' : 'assets/css/site.css';

        return $this->bundle($stylesheet)[$key] ?? null;
    }

    private function bundle(string $stylesheet): ?array
    {
        $path = preg_replace('~^(?:en|tr)(?:/|$)~', '', trim(request()->path(), '/'));
        if (! in_array($path, self::PATHS, true)
            || ! in_array($stylesheet, ['assets/css/site.css', 'assets/css/site.min.css'], true)) {
            return null;
        }

        // Request-local only: a later request reads the current served files again.
        $cache = request()->attributes->get(self::REQUEST_CACHE, []);
        if (array_key_exists($stylesheet, $cache)) {
            return $cache[$stylesheet];
        }
        $assets = [
            'site' => $stylesheet,
            'server-menu' => 'assets/css/server-menu.css',
            'site-select' => 'assets/css/site-select.css',
            'chat-morph' => 'assets/css/chat-morph.css',
        ];
        if (in_array($path, ['vps/hourly', 'vps/philippines', 'vps/uae'], true)) {
            $assets['vps-catalog'] = 'assets/css/vps-catalog.css';
        }
        $styles = [];
        foreach ($assets as $name => $asset) {
            $css = $this->readStylesheet($asset);
            $css = $css === null ? null : $this->prepare($css);
            if ($css === null) {
                $styles = null;
                break;
            }
            $styles[$name] = $css;
        }
        if ($styles !== null) {
            $site = $this->embedFonts($styles['site']);
            $styles = $site === null ? null : array_replace($styles, ['site' => $site]);
        }
        // These are the exact runtime styles, so there is no generated artifact
        // whose hash could drift from a template or a newly added CSS selector.
        $cache[$stylesheet] = $styles;
        request()->attributes->set(self::REQUEST_CACHE, $cache);

        return $styles;
    }

    protected function readStylesheet(string $asset): ?string
    {
        $path = public_asset_path($asset);
        $css = $path ? @file_get_contents($path) : false;

        return is_string($css) && trim($css) !== '' ? $css : null;
    }

    protected function readFont(string $asset): ?string
    {
        $path = public_asset_path($asset);
        $bytes = $path ? @file_get_contents($path) : false;

        return is_string($bytes) ? $bytes : null;
    }

    /** Embed the unchanged served font bytes before text can paint in a fallback font. */
    private function embedFonts(string $css): ?string
    {
        $seen = [];
        $unsafe = false;
        $prepared = preg_replace_callback('~@font-face\s*\{[^}]*\}~i', function (array $match) use (&$seen, &$unsafe): string {
            $face = $match[0];
            $canonical = preg_replace('~\s+~', '', str_replace('"', "'", $face));
            foreach (self::FONTS as $weight => [$name, $display]) {
                $woff2 = '/assets/font/woff2/'.$name.'.woff2';
                $expected = "@font-face{font-family:'IRANSans';src:url('".$woff2."')format('woff2'),url('/assets/font/woff/".$name.".woff')format('woff');font-weight:".$weight.';font-style:normal;font-display:'.$display.'}';
                if ($canonical !== $expected) {
                    continue;
                }
                if (isset($seen[$weight])) {
                    $unsafe = true;

                    return $face;
                }
                $seen[$weight] = true;
                if (! in_array($weight, [400, 700], true)) {
                    return $face;
                }
                $bytes = $this->readFont(ltrim($woff2, '/'));
                if (! is_string($bytes) || strlen($bytes) < 48 || strlen($bytes) > 131072
                    || substr($bytes, 0, 4) !== 'wOF2'
                    || (unpack('Nlength', substr($bytes, 8, 4))['length'] ?? 0) !== strlen($bytes)) {
                    $unsafe = true;

                    return $face;
                }
                $face = str_replace($woff2, 'data:font/woff2;base64,'.base64_encode($bytes), $face);

                return preg_replace('~\bfont-display\s*:\s*'.$display.'\b~', 'font-display:block', $face);
            }
            // A new face/descriptor must be reviewed before changing its load behavior.
            $unsafe = true;

            return $face;
        }, $css, -1, $count);

        return ! $unsafe && $count === count(self::FONTS) && count($seen) === count(self::FONTS) && is_string($prepared) ? $prepared : null;
    }

    private function prepare(string $css): ?string
    {
        $css = str_replace("\r\n", "\n", $css);
        if (trim($css) === '' || str_contains(strtolower($css), '</style') || str_contains($css, "\0")
            || preg_match('~@import\b~i', $css)) {
            return null;
        }
        $fonts = [];
        foreach (self::FONTS as [$font]) {
            foreach (['woff2', 'woff'] as $format) {
                $fonts['../font/'.$format.'/'.$font.'.'.$format] = '/assets/font/'.$format.'/'.$font.'.'.$format;
            }
        }
        $unsafe = false;
        $urls = preg_match_all('~\burl\s*\(~i', $css);
        $count = 0;
        $prepared = preg_replace_callback('~\burl\s*\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'()]+))\s*\)~i', function (array $match) use ($fonts, &$unsafe): string {
            $url = $match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
            if (str_contains($url, '\\') || preg_match('~[\x00-\x1f]~', $url)) {
                $unsafe = true;
            } elseif (isset($fonts[$url])) {
                return str_replace($url, $fonts[$url], $match[0]);
            } elseif (! preg_match('~^(?:/|https?://|data:)~i', $url)) {
                // A new relative URL needs an explicit, reviewed rebase rule.
                $unsafe = true;
            }

            return $match[0];
        }, $css, -1, $count);

        return ! $unsafe && $urls === $count && is_string($prepared) ? $prepared : null;
    }
}
