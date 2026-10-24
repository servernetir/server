<?php

namespace Tests\Feature;

use App\Models\PhysicalServer;
use App\Models\ServerPart;
use App\Models\User;
use App\Services\Shop\HardwareCatalogImport;
use App\Services\Shop\HardwarePresentation;
use App\Services\Cloud\CloudPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class HardwareCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function part(string $slug, array $extra = []): ServerPart
    {
        return ServerPart::create($extra + ['slug' => $slug, 'category' => 'cpu', 'brand' => 'Intel', 'active' => true,
            'condition' => 'catalog', 'price_contact' => true, 'in_stock' => false, 'compat_gens' => [],
            'name' => ['fa' => $slug, 'en' => $slug, 'tr' => $slug], 'summary' => ['fa' => 'Model '.$slug],
            'gallery' => ['/assets/hardware/xeon-e5-2630-v2-reference.svg']]);
    }

    public function test_catalog_pages_have_real_crawlable_pagination_and_canonical(): void
    {
        Cache::flush();
        for ($i = 1; $i <= 49; $i++) $this->part('part-'.$i, ['sort' => $i]);
        $first = $this->get('/parts/cpu')->assertOk()->getContent();
        $second = $this->get('/parts/cpu?page=2')->assertOk()->getContent();
        $this->assertStringContainsString('/parts/cpu?page=2', $first);
        $this->assertStringContainsString('href="'.url('/parts/cpu?page=2').'"', $second);
        $this->assertStringContainsString('part-49', $second);
        $this->assertStringNotContainsString('/parts/cpu/part-1"', $second);
        $this->get('/parts/cpu?page=3')->assertNotFound();
        $this->get('/parts/cpu?q[]=bad&page[]=bad')->assertOk();
    }

    public function test_unknown_stock_has_no_product_offer_and_keeps_breadcrumb_and_images(): void
    {
        $this->part('catalog-only');
        foreach (['', 'en/', 'tr/'] as $locale) {
            Cache::flush();
            $html = $this->get('/'.$locale.'parts/cpu/catalog-only')->assertOk()->getContent();
            $this->assertStringNotContainsString('"@type":"Product"', $html);
            $this->assertStringNotContainsString('"@type":"Offer"', $html);
            $this->assertStringContainsString('BreadcrumbList', $html);
            $this->assertStringContainsString('name="twitter:image"', $html);
            $this->assertSame(1, preg_match_all('/<h1\b/', $html));
        }
    }

    public function test_offer_uses_displayed_currency_and_toman_to_rial_conversion(): void
    {
        $part = $this->part('priced', ['condition' => 'used', 'price_contact' => false, 'price_irt' => 123456, 'price_eur' => 3250, 'in_stock' => true]);
        $this->mock(CloudPricing::class, fn ($mock) => $mock->shouldReceive('eurToToman')->andReturn(100000));
        app()->setLocale('fa');
        $this->assertSame('IRR', HardwarePresentation::offer($part)['priceCurrency']);
        $this->assertSame('32500000', HardwarePresentation::offer($part)['price']);
        app()->setLocale('en');
        $this->assertSame('32.5', HardwarePresentation::offer($part)['price']);
        $part->price_contact = true;
        $this->assertNull(HardwarePresentation::offer($part));
    }

    public function test_hardware_sitemap_contains_each_locale_and_absolute_images_only_once(): void
    {
        $this->part('sitemap-part');
        $xml = $this->get('/hardware-sitemap.xml')->assertOk()->getContent();
        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);
        foreach (['', 'en/', 'tr/'] as $locale) $this->assertStringContainsString(url('/'.$locale.'parts/cpu/sitemap-part'), $xml);
        $this->assertStringContainsString('<image:loc>'.url('/assets/hardware/xeon-e5-2630-v2-reference.svg'), $xml);
        $this->assertStringNotContainsString('parts/compare', $xml);
        $urls = array_map('strval', $parsed->xpath('//*[local-name()="url"]/*[local-name()="loc"]'));
        $this->assertSame(count($urls), count(array_unique($urls)));
    }

    public function test_import_preflight_is_atomic_and_rollback_preserves_existing_price_changes(): void
    {
        $part = $this->part('existing', ['gallery' => [], 'price_irt' => 100]);
        $folder = sys_get_temp_dir().'/hardware-'.bin2hex(random_bytes(6));
        mkdir($folder);
        $manifest = $folder.'/manifest.json'; $receipt = $folder.'/receipt.json';
        $records = [
            ['slug' => 'existing', 'kind' => 'part', 'existing_id' => $part->id, 'before' => ['gallery' => []], 'patch' => ['gallery' => ['/assets/hardware/xeon-e5-2630-v2-reference.svg']]],
            ['slug' => 'new-entry', 'kind' => 'part', 'data' => ['category' => 'cpu', 'brand' => 'Intel', 'condition' => 'catalog', 'active' => true, 'price_contact' => true, 'in_stock' => false, 'name' => ['fa' => 'New', 'en' => 'New', 'tr' => 'New'], 'body' => ['fa' => 'Body', 'en' => 'Body', 'tr' => 'Body']]],
        ];
        file_put_contents($manifest, json_encode($records));
        $import = app(HardwareCatalogImport::class);
        $this->assertSame(1, $import->apply($manifest, $receipt)['insert']);
        $part->gallery = ['/operator-edit.webp']; $part->saveQuietly();
        try { $import->apply($manifest, $receipt, true); $this->fail('Drift must abort'); } catch (RuntimeException $e) { $this->assertStringContainsString('Concurrent', $e->getMessage()); }
        $this->assertFalse(ServerPart::where('slug', 'new-entry')->exists());
        $part->gallery = []; $part->saveQuietly();
        $import->apply($manifest, $receipt, true);
        try { $import->apply($manifest, $receipt, true); $this->fail('Receipt must stop retries'); } catch (RuntimeException $e) { $this->assertStringContainsString('receipt', $e->getMessage()); }
        $part->refresh()->price_irt = 250; $part->saveQuietly();
        $this->assertSame(2, $import->rollback($receipt)['rolled_back']);
        $this->assertSame(250, $part->fresh()->price_irt);
        $this->assertSame([], $part->fresh()->gallery);
        $this->assertFalse(ServerPart::where('slug', 'new-entry')->exists());
        foreach (glob($folder.'/*') as $file) unlink($file); rmdir($folder);
    }

    public function test_admin_edit_preserves_catalog_condition_unknown_compatibility_and_subtype(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'hardware-admin@example.test', 'password' => bcrypt('test'), 'role' => 'admin']);
        $part = $this->part('editable-cooling', ['category' => 'other', 'attrs' => ['kind' => 'cooling']]);
        $this->actingAs($admin, 'web')->get('/admin/parts/'.$part->id.'/edit')
            ->assertOk()->assertSee('value="catalog" selected', false);
        $this->post('/admin/parts/'.$part->id, ['slug' => $part->slug, 'category' => 'other', 'brand' => 'HPE',
            'condition' => 'catalog', 'name_fa' => 'فن', 'name_en' => 'Fan', 'name_tr' => 'Fan',
            'price_contact' => '1', 'active' => '1', 'sort' => 1])->assertSessionHasNoErrors()->assertRedirect('/admin/parts');
        $this->assertSame('catalog', $part->fresh()->condition);
        $this->assertSame([], $part->fresh()->compat_gens);
        $this->assertSame('cooling', $part->fresh()->attrs['kind']);
        $this->assertFalse($part->fresh()->in_stock);
        $this->get('/admin/parts')->assertOk()->assertSee('استعلام موجودی');
    }

    public function test_admin_can_edit_a_catalog_server_without_marking_it_new(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'hardware-server-admin@example.test', 'password' => bcrypt('test'), 'role' => 'admin']);
        $server = PhysicalServer::create(['slug' => 'catalog-server', 'brand' => 'hp', 'condition' => 'catalog',
            'active' => true, 'price_contact' => true, 'name' => ['fa' => 'Platform', 'en' => 'Platform', 'tr' => 'Platform'],
            'tag' => ['fa' => '', 'en' => '', 'tr' => ''], 'hero_d' => ['fa' => '', 'en' => '', 'tr' => ''],
            'description' => ['fa' => '', 'en' => '', 'tr' => '']]);
        $this->actingAs($admin, 'web')->get('/admin/server-shop/'.$server->id.'/edit')
            ->assertOk()->assertSee('value="catalog" selected', false);
        $this->post('/admin/server-shop/'.$server->id, ['slug' => $server->slug, 'brand' => 'hp', 'condition' => 'catalog',
            'name_fa' => 'Platform', 'name_en' => 'Platform', 'name_tr' => 'Platform', 'price_contact' => '1', 'active' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect('/admin/server-shop');
        $this->assertSame('catalog', $server->fresh()->condition);
        $this->get('/admin/server-shop')->assertOk()->assertSee('کاتالوگ؛ با استعلام');
    }
}
