<?php

namespace Tests\Feature;

use App\Services\Cloud\LandingPageStyles;
use Illuminate\Http\Request;
use Tests\TestCase;

class LandingPageStylesTest extends TestCase
{
    public function test_both_cities_and_three_locales_use_complete_inline_styles_without_javascript(): void
    {
        foreach (['', '/en', '/tr'] as $locale) {
            foreach (['ae-dubai', 'ph-manila'] as $city) {
                app()->instance('request', Request::create($locale.'/cloud/'.$city));
                $css = app(LandingPageStyles::class)->inline('assets/css/site.min.css');
                $this->assertIsString($css);
                $this->assertStringNotContainsString('data:font/woff2;base64,', $css);
                $this->assertStringContainsString('/assets/font/woff2/IRANSans-location-20261011-400.woff2', $css);
                $this->assertStringContainsString('/assets/font/woff2/IRANSans-location-20261011-700.woff2', $css);
                $this->assertStringContainsString('#menu-products .mega-inner', $css);
                $this->assertStringContainsString('unicode-range:', $css);
                $this->assertStringContainsString('/assets/font/woff2/IRANSans-web.woff2', $css);
                foreach (['#header', '.container', '.drawer', 'footer{', '@media', '[data-theme="light"]'] as $rule) {
                    $this->assertStringContainsString($rule, $css);
                }
            }
        }
    }

    public function test_unrelated_routes_keep_the_original_stylesheet(): void
    {
        foreach (['/', '/cloud/de-frankfurt', '/cloud/ph-manila-extra', '/account/cloud-store', '/vps/hourly', '/gpu'] as $path) {
            app()->instance('request', Request::create($path));
            $this->assertNull(app(LandingPageStyles::class)->inline('assets/css/site.min.css'));
        }
    }

    public function test_stale_source_and_corrupt_subset_use_the_original_stylesheet(): void
    {
        app()->instance('request', Request::create('/cloud/ae-dubai'));
        $this->assertNull(app(LandingPageStyles::class)->inline('assets/css/site.css'));
        $file = resource_path('css/cloud-location-lcp.json');
        $original = file_get_contents($file);
        try {
            $meta = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
            $meta['sources']['public/assets/js/site.js'] = [str_repeat('0', 64)];
            file_put_contents($file, json_encode($meta));
            $this->assertNull(app(LandingPageStyles::class)->inline('assets/css/site.min.css'));
            $meta = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
            $meta['css_sha256'] = str_repeat('0', 64);
            file_put_contents($file, json_encode($meta));
            $this->assertNull(app(LandingPageStyles::class)->inline('assets/css/site.min.css'));
            $meta = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
            $meta['sources']['public/assets/font/woff2/IRANSans-location-20261011-400.woff2'] = [str_repeat('0', 64)];
            file_put_contents($file, json_encode($meta));
            $this->assertNull(app(LandingPageStyles::class)->inline('assets/css/site.min.css'));
            $meta = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
            $meta['sources']['public/assets/css/server-menu.css'] = [str_repeat('0', 64)];
            file_put_contents($file, json_encode($meta));
            $this->assertNull(app(LandingPageStyles::class)->inline('assets/css/site.min.css'));
        } finally {
            file_put_contents($file, $original);
        }
    }
}
