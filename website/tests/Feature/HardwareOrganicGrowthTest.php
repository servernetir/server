<?php

namespace Tests\Feature;

use App\Models\ServerPart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HardwareOrganicGrowthTest extends TestCase
{
    use RefreshDatabase;

    private function part(string $slug, array $fields = []): void
    {
        ServerPart::create($fields + [
            'slug' => $slug, 'category' => 'ram', 'brand' => 'HPE',
            'active' => true, 'condition' => 'catalog', 'price_contact' => true,
            'in_stock' => false, 'compat_gens' => ['gen9'],
            'name' => ['fa' => $slug, 'en' => $slug, 'tr' => $slug],
        ]);
    }

    public function test_curated_landing_pages_are_localized_discoverable_and_do_not_claim_unverified_stock(): void
    {
        Cache::flush();
        $this->part('gen9-ecc-demo');
        $this->part('gen10-ecc-demo', ['compat_gens' => ['gen10']]);
        $this->part('unknown-compat-demo', ['compat_gens' => null]);
        $this->part('inactive-demo', ['active' => false]);

        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $path = $prefix.'/parts/catalog/hp-gen9-ram';
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString(config('hardware_topics.hp-gen9-ram.title.'.$locale), $html);
            $this->assertStringContainsString('rel="canonical" href="'.url($path).'"', $html);
            $this->assertStringNotContainsString('name="robots" content="noindex', $html);
            $this->assertStringContainsString($prefix.'/parts/ram/gen9-ecc-demo', $html);
            $this->assertStringNotContainsString('/parts/ram/gen10-ecc-demo', $html);
            $this->assertStringNotContainsString('/parts/ram/unknown-compat-demo', $html);
            $this->assertStringNotContainsString('/parts/ram/inactive-demo', $html);
            $this->assertStringNotContainsString('"@type":"Offer"', $html);
            $this->assertStringNotContainsString('shop_seo.', $html);
            $this->assertSame(1, preg_match_all('/<h1\b/', $html));
            $this->get($prefix.'/parts')->assertOk()->assertSee($path, false);
            $this->get($prefix.'/parts/ram')->assertOk()->assertSee($path, false);
        }

        foreach (['/sitemap.xml', '/hardware-sitemap.xml'] as $sitemap) {
            $xml = $this->get($sitemap)->assertOk()->getContent();
            foreach (['', '/en', '/tr'] as $prefix) {
                $this->assertStringContainsString('<loc>'.url($prefix.'/parts/catalog/hp-gen9-ram').'</loc>', $xml);
            }
            $this->assertStringNotContainsString('/parts/compare', $xml);
        }
        $this->get('/llms.txt')->assertOk()->assertSee('/en/parts/catalog/hp-gen9-ram', false);
        $this->get('/parts/catalog/arbitrary-filter')->assertNotFound();
        $this->get('/parts/ram?gen=gen9')->assertOk()->assertSee('noindex,follow', false);
    }

    public function test_cooling_landing_page_is_scoped_to_its_actual_subtype(): void
    {
        $this->part('fan-demo', ['category' => 'other', 'attrs' => ['kind' => 'cooling']]);
        $this->part('rail-demo', ['category' => 'other', 'attrs' => ['kind' => 'rail']]);
        $this->get('/parts/catalog/server-fans')->assertOk()
            ->assertSee('/parts/other/fan-demo', false)
            ->assertDontSee('/parts/other/rail-demo', false);
    }

    public function test_every_topic_has_translations_and_an_existing_category(): void
    {
        foreach (config('hardware_topics') as $slug => $data) {
            $this->assertArrayHasKey($data['category'], ServerPart::CATEGORIES);
            foreach (['title', 'intro', 'advice'] as $field) {
                foreach (['fa', 'en', 'tr'] as $locale) {
                    $this->assertNotEmpty($data[$field][$locale], "$slug $field $locale");
                }
            }
        }
    }

    public function test_competitor_gap_batch_is_quote_only_and_imports_with_real_specs(): void
    {
        $manifest = database_path('catalog/hardware-growth-20261123.json');
        $folder = sys_get_temp_dir().'/hardware-growth-'.bin2hex(random_bytes(8));
        mkdir($folder, 0700);
        $receipt = $folder.'/receipt.json';
        try {
            $import = app(\App\Services\Shop\HardwareCatalogImport::class);
            $this->assertSame(5, $import->apply($manifest, $receipt)['insert']);
            $this->assertSame(5, $import->apply($manifest, $receipt, true)['insert']);
            foreach (json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR) as $record) {
                $path = $record['kind'] === 'server'
                    ? '/servers/'.$record['slug'] : '/parts/gpu/'.$record['slug'];
                foreach (['', '/en', '/tr'] as $prefix) {
                    $html = $this->get($prefix.$path)->assertOk()->getContent();
                    $this->assertStringContainsString($record['reference'], $html);
                    $this->assertStringNotContainsString('"@type":"Offer"', $html);
                }
            }
            $this->assertSame(5, $import->rollback($receipt)['rolled_back']);
        } finally {
            if (is_file($receipt)) unlink($receipt);
            rmdir($folder);
        }
    }
}
