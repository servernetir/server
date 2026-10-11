<?php

namespace App\Services\Cloud;

/** Use the audited full-page CSS subset only while its inputs still match. */
class LandingPageStyles
{
    public function inline(string $stylesheet): ?string
    {
        if (! preg_match('~^(?:en/|tr/)?cloud/(?:ae-dubai|ph-manila)$~', request()->path())) {
            return null;
        }
        $manifest = @file_get_contents(resource_path('css/cloud-location-lcp.json'));
        $css = @file_get_contents(resource_path('css/cloud-location-lcp.css'));
        $meta = $manifest ? json_decode($manifest, true) : null;
        if (! is_array($meta) || ! is_string($css) || str_contains(strtolower($css), '</style')
            || ! hash_equals((string) ($meta['css_sha256'] ?? ''), hash('sha256', $css))) {
            return null;
        }
        $dependencies = [
            'public/'.$stylesheet,
            'public/assets/css/server-menu.css',
            'public/assets/js/site.js',
            'public/assets/font/woff2/IRANSans-web.woff2',
            'public/assets/font/woff2/IRANSans-Bold-web.woff2',
            'public/assets/font/woff2/IRANSans-location-20261011-400.woff2',
            'public/assets/font/woff2/IRANSans-location-20261011-700.woff2',
            'resources/views/layouts/site.blade.php',
            'resources/views/pages/cloud-location.blade.php',
            'resources/views/partials/header.blade.php',
            'resources/views/partials/footer.blade.php',
            'resources/views/partials/chat.blade.php',
            'resources/views/partials/server-menu.blade.php',
        ];
        foreach ($dependencies as $rel) {
            $path = str_starts_with($rel, 'public/')
                ? public_asset_path(substr($rel, 7)) : base_path($rel);
            $source = $path ? @file_get_contents($path) : false;
            $accepted = $meta['sources'][$rel] ?? [];
            if ($source === false || ! is_array($accepted)
                || ! in_array(hash('sha256', str_ends_with($rel, '.woff2') ? $source : str_replace("\r\n", "\n", $source)), $accepted, true)) {
                return null;
            }
        }
        return $css;
    }
}
