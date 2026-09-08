<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArvanPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_validates_exact_mapping_with_gets_only_and_never_prints_token(): void
    {
        Setting::putSecret('arvan_api_token', 'Apikey never-print-this');
        Setting::put('arvan_security_group', 'sg-selected');
        $plan = CloudPlan::create([
            'provider' => 'arvan', 'provider_ref' => 'g1-1-1', 'provider_location' => 'ir-thr-c2',
            'location_code' => 'ir-tehran', 'public_name' => 'A1', 'slug' => 'a1', 'vcpu' => 1,
            'ram_mb' => 1024, 'disk_gb' => 25, 'disk_type' => 'ssd', 'traffic_gb' => 1000,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 1, 'price_eur_cents' => 2,
            'price_irt' => 1000, 'is_active' => true, 'in_stock' => true,
        ]);
        CloudImage::create(['provider' => 'arvan', 'provider_ref' => 'old-image', 'key' => 'ubuntu-24.04', 'label' => 'Ubuntu 24.04', 'family' => 'ubuntu', 'arch' => 'x86', 'is_active' => true]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_ends_with($url, '/regions')) {
                return Http::response(['data' => [['code' => 'ir-thr-c2', 'country' => 'IR', 'create' => true, 'visible' => true]]]);
            }
            if (str_contains($url, '/sizes')) {
                return Http::response(['data' => [['id' => 'g1-1-1']]]);
            }
            if (str_contains($url, '/images')) {
                return Http::response(['data' => [['images' => [['id' => 'regional-image', 'name' => 'Ubuntu 24.04']]]]]);
            }
            if (str_contains($url, '/securities')) {
                return Http::response(['data' => [['id' => 'sg-selected', 'name' => 'default', 'real_name' => 'arDefault', 'default' => true]]]);
            }
            if (str_contains($url, '/networks')) {
                return Http::response(['data' => [['id' => 'public-net', 'name' => 'public', 'enable_gateway' => true]]]);
            }

            return Http::response(['data' => []], 404);
        });

        $this->artisan('cloud:arvan-preflight', ['--plan' => $plan->id])
            ->expectsOutputToContain('firewall resolved: arDefault')
            ->doesntExpectOutputToContain('never-print-this')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }
}
