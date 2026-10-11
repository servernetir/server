<?php

namespace Tests\Feature;

use App\Services\Cloud\CountryLandingSeo;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

class CountryLandingSeoTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_country_marketing_renders_localized_guidance_and_schema_without_changing_other_products(): void
    {
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            app()->setLocale($locale);
            $html = $this->get($prefix.'/vps/germany')->assertOk()->getContent();
            $seo = app(CountryLandingSeo::class)->content('DE');
            $this->assertStringContainsString(e($seo['title']), $html);
            $this->assertStringContainsString(e($seo['network_title']), $html);
            $this->assertStringContainsString(e($seo['faq'][0]['q']), $html);
            preg_match_all('~<script type="application/ld\\+json">(.*?)</script>~s', $html, $scripts);
            $faq = null;
            foreach ($scripts[1] as $json) {
                $schema = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                if (($schema['@type'] ?? '') === 'FAQPage') $faq = $schema;
            }
            $this->assertSame($seo['faq_ld']['mainEntity'], $faq['mainEntity']);
            $this->assertStringNotContainsString('infra-panel', $html);
        }
        $control = $this->get('/hosting/linux')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="country-selection"', $control);
        $this->assertStringContainsString('infra-panel', $control);
    }

    public function test_all_locales_have_complete_profiles_and_no_fabricated_network_numbers(): void
    {
        $keys = null;
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $copy = Lang::get('country-seo');
            $this->assertIsArray($copy);
            $this->assertCount(42, $copy['profiles']);
            $keys ??= array_keys($copy);
            $this->assertSame($keys, array_keys($copy));
            foreach (array_keys($copy['profiles']) as $iso) {
                $seo = app(CountryLandingSeo::class)->content($iso, 'Test City');
                $this->assertStringContainsString('Test City', $seo['title']);
                $this->assertStringNotContainsString(':country', implode(' ', array_filter($seo, 'is_string')));
                $this->assertDoesNotMatchRegularExpression('/\d+\s*(ms|میلی|milisaniye)/u', $seo['network']);
                $this->assertSame($seo['faq'][0]['a'], $seo['faq_ld']['mainEntity'][0]['acceptedAnswer']['text']);
                $this->assertCount(5, $seo['checks']);
            }
        }
    }

    public function test_context_distinguishes_priority_markets_without_guaranteed_latency(): void
    {
        app()->setLocale('en');
        $s = app(CountryLandingSeo::class);
        $this->assertStringContainsString('Istanbul', $s->content('AE')['profile']);
        $this->assertStringContainsString('regulatory', $s->content('SA')['profile']);
        $this->assertStringContainsString('database', $s->content('PH')['profile']);
        $this->assertStringContainsString('Hanoi', $s->content('VN')['profile']);
        $this->assertStringNotContainsString('95 ms', $s->content('DE')['intro']);
    }

    public function test_editorial_partial_escapes_untrusted_location_names(): void
    {
        app()->setLocale('en');
        $seo = app(CountryLandingSeo::class)->content('<script>bad</script>');
        $html = view('partials.country-selection-guide', ['countrySeo' => $seo])->render();
        $this->assertStringNotContainsString('<script>bad</script>', $html);
        $this->assertStringContainsString('&lt;SCRIPT&gt;', $html);
        $this->assertStringContainsString('network', strtolower($html));
    }
}
