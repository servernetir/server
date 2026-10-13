<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Server;
use App\Models\Setting;
use App\Services\ObjectStorage\HetznerObjectPriceFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorageSalesSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_object_storage_cannot_be_sold_until_customer_key_isolation_is_verified(): void
    {
        $product = new Product([
            'group' => 'object-storage',
            'plan' => 'sn_object_storage_1tb_fsn1',
            'is_active' => true,
        ]);

        $this->assertNotNull($product->storageConfigurationError());
        $this->assertSame([], $product->availableCountries());
        $this->assertSame(['monthly'], $product->orderCycles());
    }

    public function test_public_storage_pages_are_available_but_object_storage_has_no_buy_link(): void
    {
        $this->get('/cloud/storage-box')->assertOk();
        $object = $this->get('/cloud/object-storage')->assertOk();

        $object->assertSee(__('storage.preparing'));
        $object->assertDontSee('class="sn-storage-buy"', false);
    }

    public function test_object_catalog_sync_can_only_create_inactive_products(): void
    {
        $server = Server::create([
            'name' => 'HZ-OBJECT-CATALOG',
            'type' => 'hetzner_object',
            'status' => 'maintenance',
        ]);
        Setting::put('pricing_rate_override', '300000');
        Setting::put('pricing_fx_fee_pct_hetzner', '17');
        Setting::put('cloud_margin_pct', '5');

        $this->mock(HetznerObjectPriceFeed::class, function ($mock): void {
            $mock->shouldReceive('fetch')->once()->andReturn([
                'currency' => 'EUR',
                'tax_included' => false,
                'base_monthly_eur' => '4.99',
                'base_hourly_eur' => '0.0081',
                'storage_tb_hour_eur' => '0.0067',
                'egress_tb_eur' => '1.00',
                'source_url' => HetznerObjectPriceFeed::SOURCE_URL,
                'source_urls' => [],
                'fetched_at' => now()->utc()->toIso8601String(),
            ]);
        });

        $this->artisan('hetzner:object-catalog', [
            '--sync' => true,
            '--server' => (string) $server->id,
        ])->assertSuccessful();

        $this->assertSame(21, Product::where('group', 'object-storage')->count());
        $this->assertSame(0, Product::where('group', 'object-storage')->where('is_active', true)->count());
        $this->assertSame(21, Product::where('group', 'object-storage')->where('server_id', $server->id)->count());
    }
}
