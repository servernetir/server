<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Cloud\HetznerClient;
use App\Services\ObjectStorage\HetznerObjectPriceFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorageSalesSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_monthly_tariff_includes_live_server_and_ipv4_costs(): void
    {
        Setting::putSecret('hetzner_api_token', 'provider-token');
        Setting::put('cloud_ipv4_eur_cents', '50');
        Http::fake([
            'api.hetzner.cloud/v1/server_types*' => Http::response([
                'server_types' => [[
                    'name' => 'cax11',
                    'prices' => [[
                        'location' => 'fsn1',
                        'price_monthly' => ['net' => '5.9900000000'],
                    ]],
                ]],
            ]),
        ]);

        $quote = app(HetznerClient::class)->serverMonthlyNetPrice('cax11', 'fsn1');

        $this->assertTrue($quote['ok']);
        $this->assertSame(599, $quote['server_net_eur_cents']);
        $this->assertSame(50, $quote['ipv4_net_eur_cents']);
        $this->assertSame(649, $quote['net_eur_cents']);
    }

    public function test_object_storage_cannot_be_sold_without_the_dedicated_gateway(): void
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

    public function test_object_catalog_activates_only_when_the_signed_gateway_health_is_ready(): void
    {
        $server = Server::create([
            'name' => 'HZ-OBJECT-CATALOG',
            'type' => 'hetzner_object_gateway',
            'status' => 'active',
            'hostname' => 'https://object-api.example.test',
            'api_token' => str_repeat('a', 64),
        ]);
        Setting::put('pricing_rate_override', '300000');
        Setting::put('pricing_fx_fee_pct_hetzner', '17');
        Setting::put('cloud_margin_pct', '5');
        $this->mock(HetznerClient::class, function ($mock): void {
            $mock->shouldReceive('serverMonthlyNetPrice')->once()->with('cax11', 'fsn1')->andReturn([
                'ok' => true, 'net_eur_cents' => 649,
            ]);
        });

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
        Http::fake(['object-api.example.test/*' => Http::response([
            'ok' => true, 'ready' => true, 'regions' => ['fsn1' => true, 'nbg1' => true, 'hel1' => true],
        ])]);

        $this->artisan('hetzner:object-catalog', [
            '--sync' => true,
            '--server' => (string) $server->id,
        ])->assertSuccessful();

        $this->assertSame(21, Product::where('group', 'object-storage')->count());
        $this->assertSame(21, Product::where('group', 'object-storage')->where('is_active', true)->count());
        $this->assertSame(21, Product::where('group', 'object-storage')->where('server_id', $server->id)->count());
        $oneTb = Product::where('slug', 'object-storage-1tb-fsn1')->firstOrFail();
        $this->assertSame(4620000, (int) $oneTb->price);
        $this->assertSame(1540, (int) $oneTb->price_eur);
        $book = json_decode((string) Setting::get('hetzner_object_pricebook'), true);
        $this->assertSame('6.49', $book['gateway_monthly_net_eur']);
    }

    public function test_catalog_stays_closed_when_any_gateway_region_is_not_ready(): void
    {
        $server = Server::create([
            'name' => 'Object Gateway', 'type' => 'hetzner_object_gateway', 'status' => 'active',
            'hostname' => 'https://object-api.example.test', 'api_token' => str_repeat('b', 64),
        ]);
        Setting::put('pricing_rate_override', '300000');
        Setting::put('pricing_fx_fee_pct_hetzner', '17');
        Setting::put('cloud_margin_pct', '5');
        $this->mock(HetznerClient::class, function ($mock): void {
            $mock->shouldReceive('serverMonthlyNetPrice')->once()->with('cax11', 'fsn1')->andReturn([
                'ok' => true, 'net_eur_cents' => 649,
            ]);
        });
        $this->mock(HetznerObjectPriceFeed::class, function ($mock): void {
            $mock->shouldReceive('fetch')->once()->andReturn([
                'currency' => 'EUR', 'tax_included' => false,
                'base_monthly_eur' => '4.99', 'base_hourly_eur' => '0.0081',
                'storage_tb_hour_eur' => '0.0067', 'egress_tb_eur' => '1.00',
                'source_url' => HetznerObjectPriceFeed::SOURCE_URL, 'source_urls' => [],
                'fetched_at' => now()->utc()->toIso8601String(),
            ]);
        });
        Http::fake(['object-api.example.test/*' => Http::response([
            'ok' => true, 'ready' => false, 'regions' => ['fsn1' => true, 'nbg1' => false, 'hel1' => true],
        ])]);

        $this->artisan('hetzner:object-catalog', ['--sync' => true, '--server' => (string) $server->id])
            ->assertSuccessful();

        $this->assertSame(0, Product::where('group', 'object-storage')->where('is_active', true)->count());
    }
}
