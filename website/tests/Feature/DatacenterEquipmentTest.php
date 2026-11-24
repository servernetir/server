<?php

namespace Tests\Feature;

use App\Models\ServerPart;
use App\Models\PhysicalServer;
use App\Services\Shop\HardwareCatalogImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DatacenterEquipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_reviewed_model_is_importable_and_has_a_localized_canonical_product_page(): void
    {
        $manifest = database_path('catalog/datacenter-equipment-20261124.json');
        $records = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $folder = sys_get_temp_dir().'/datacenter-'.bin2hex(random_bytes(8));
        mkdir($folder, 0700);
        $receipt = $folder.'/receipt.json';
        try {
            $import = app(HardwareCatalogImport::class);
            $this->assertCount(74, $records);
            $this->assertSame(count($records), $import->apply($manifest, $receipt)['insert']);
            $this->assertSame(count($records), $import->apply($manifest, $receipt, true)['insert']);
            $this->assertSame(63, ServerPart::count());
            $this->assertSame(11, PhysicalServer::count());
            foreach (ServerPart::EQUIPMENT_CATEGORIES as $category => $meta) {
                $this->assertGreaterThanOrEqual(5, ServerPart::where('category', $category)->count());
            }
            foreach ($records as $record) {
                if ($record['kind'] === 'server') {
                    $server = PhysicalServer::where('slug', $record['slug'])->sole();
                    $this->assertTrue($server->price_contact);
                    $this->assertNull($server->price_eur);
                    foreach (['fa'=>'', 'en'=>'/en', 'tr'=>'/tr'] as $locale => $prefix) {
                        $path = $prefix.'/servers/'.$record['slug'];
                        $html = $this->get($path)->assertOk()->getContent();
                        $this->assertStringContainsString(e($record['data']['name'][$locale]), $html);
                        $this->assertStringContainsString(e($record['reference']), $html);
                        $this->assertStringContainsString('rel="canonical" href="'.url($path).'"', $html);
                        $this->assertStringNotContainsString('"@type":"Offer"', $html);
                        $this->assertGreaterThan(300, mb_strlen($server->body[$locale]));
                    }
                    continue;
                }
                $part = ServerPart::where('slug', $record['slug'])->sole();
                $this->assertSame('catalog', $part->condition);
                $this->assertTrue($part->price_contact);
                $this->assertFalse($part->in_stock);
                $this->assertSame([], $part->compat_gens);
                $this->assertNull($part->price_eur);
                $this->assertNotEmpty($part->specs);
                $this->assertEmpty(array_diff(array_keys($part->attrs), array_keys(ServerPart::ATTR_LABELS)));
                foreach (['fa'=>'', 'en'=>'/en', 'tr'=>'/tr'] as $locale => $prefix) {
                    $path = $prefix.'/parts/'.$part->category.'/'.$part->slug;
                    $html = $this->get($path)->assertOk()->getContent();
                    $this->assertStringContainsString(e($record['data']['name'][$locale]), $html);
                    $this->assertStringContainsString(e($record['reference']), $html);
                    $this->assertStringContainsString(e($record['presentation']['limitation'][$locale]), $html);
                    $this->assertStringContainsString('rel="canonical" href="'.url($path).'"', $html);
                    $this->assertStringNotContainsString('"@type":"Offer"', $html);
                    $this->assertStringNotContainsString('equipment.benefit', $html);
                    $this->assertGreaterThan(300, mb_strlen($part->body[$locale]));
                }
            }
            $this->assertSame(0, ServerPart::forGeneration('gen9')->count());
            $xml = $this->get('/hardware-sitemap.xml')->assertOk()->getContent();
            foreach (['','/en','/tr'] as $prefix) {
                foreach (array_keys(ServerPart::EQUIPMENT_CATEGORIES) as $category) {
                    $this->get($prefix.'/parts/'.$category)->assertOk()->assertSee($prefix.'/parts/planner', false);
                    $this->assertStringContainsString('<loc>'.url($prefix.'/parts/'.$category).'</loc>', $xml);
                }
                foreach ($records as $r) {
                    $path = $r['kind'] === 'server' ? '/servers/'.$r['slug'] : '/parts/'.$r['data']['category'].'/'.$r['slug'];
                    $this->assertStringContainsString('<loc>'.url($prefix.$path).'</loc>', $xml);
                }
            }
            $this->get('/parts/compare?parts=apc-ar3100,apc-ar3157')->assertOk()->assertDontSee('class="sp-best"', false);
            $this->assertSame(count($records), $import->rollback($receipt)['rolled_back']);
            $this->assertSame(0, ServerPart::count());
            $this->assertSame(0, PhysicalServer::count());
        } finally {
            if (is_file($receipt)) unlink($receipt);
            rmdir($folder);
        }
    }

    public function test_planner_is_linked_rendered_and_discoverable_without_javascript(): void
    {
        Cache::flush();
        foreach (['', '/en', '/tr'] as $prefix) {
            $path = $prefix.'/parts/planner';
            $html = $this->get($path)->assertOk()->getContent();
            if ($fixtureDir = getenv('DATACENTER_BROWSER_FIXTURES')) {
                $locale = $prefix === '' ? 'fa' : substr($prefix, 1);
                file_put_contents($fixtureDir.'/'.$locale.'.html', $html);
                file_put_contents($fixtureDir.'/'.$locale.'-hub.html', $this->get($prefix.'/parts')->assertOk()->getContent());
            }
            foreach (['raid','rack','ups','vm','cost'] as $tool) {
                $this->assertStringContainsString('data-calculator="'.$tool.'"', $html);
            }
            $this->assertStringContainsString('datacenter-planner.js', $html);
            $this->assertStringNotContainsString('equipment.planner_title', $html);
            $this->assertStringContainsString('rel="canonical" href="'.url($path).'"', $html);
            $this->get($prefix.'/parts')->assertOk()->assertSee($path, false);
        }
        foreach (['/sitemap.xml','/hardware-sitemap.xml'] as $path) {
            $xml = $this->get($path)->assertOk()->getContent();
            foreach (['','/en','/tr'] as $prefix) $this->assertStringContainsString('<loc>'.url($prefix.'/parts/planner').'</loc>', $xml);
        }
        $this->get('/llms.txt')->assertOk()->assertSee('/en/parts/planner', false);
        $this->get('/parts/nonexistent')->assertNotFound();
    }
}
