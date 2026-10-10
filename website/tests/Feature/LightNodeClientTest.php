<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Models\CloudPlan;
use App\Models\CloudProviderOperation;
use App\Models\Setting;
use App\Services\Cloud\CloudCatalogSync;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\LightNodeClient;
use App\Services\Cloud\LightNodePublicPricing;
use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LightNodeClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Existing lifecycle cases use explicit synthetic rates, never a live
        // production quote. API-only default behavior is covered separately.
        config(['app.key' => '12345678901234567890123456789012', 'lightnode.allow_fixture_rates' => true]);
    }

    private function client(): LightNodeClient { return app(LightNodeClient::class); }

    private function package(): array
    {
        return ['cpu' => 2, 'memory' => 4, 'systemDiskSize' => 50, 'dataDiskSize' => 0,
            'freeFlow' => 2000, 'bandwidth' => 100, 'publicIpChargeMode' => 'PayByTraffic',
            'regionCode' => 'ph-test', 'zoneCode' => 'ph-test-a', 'packageCode' => 'sample-package'];
    }

    private function image(): array
    {
        return ['imageResourceUUID' => 'img-sample', 'imageType' => 'System', 'imageSize' => 50,
            'osDistroVersion' => 'Ubuntu', 'osVersionDetail' => 'Ubuntu 24.04', 'regionCode' => 'ph-test'];
    }

    private function spec(): array
    {
        return ['name' => 'sn-svc-test', 'plan_ref' => 'ph-test|ph-test-a|sample-package',
            'location_ref' => 'ph-test|ph-test-a', 'image_ref' => 'ph-test|img-sample',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 50, 'term' => 'hour'];
    }

    private function fake(array $overrides = []): void
    {
        Setting::putSecret('lightnode_api_token', 'test-token');
        Setting::put('lightnode_manifest', json_encode(['packages' => ['ph-test|ph-test-a|sample-package' => [
            'hour_usd' => '0.022', 'month_usd' => '14.7', 'verified_at' => time(),
            'traffic_policy_verified' => true, 'spec' => $this->client()->packageSpec($this->package()),
        ]]]));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake($overrides + [
            '*/region/list*' => Http::response(['regions' => [['regionCode' => 'ph-test', 'regionName' => 'Manila', 'zones' => [['zoneCode' => 'ph-test-a']]]]]),
            '*/instance/list*' => Http::response(['instances' => [], 'rowCount' => 0, 'success' => true]),
            '*/package/list*' => Http::response(['packages' => [$this->package()]]),
            '*/image/list*' => Http::response(['images' => [$this->image()], 'rowCount' => 1, 'success' => true]),
            '*/instance/create' => Http::response(['asyncTaskInfo' => ['asyncTaskUUID' => 'task-sample', 'ecsResourceUUID' => 'ecs-sample']], 202),
            '*/asynctask/getResult*' => Http::response(['asyncTaskInfo' => ['processResult' => 'SUCCESS', 'taskStatus' => 'FINISHED']], 202),
            '*/instance/detail*' => Http::response(['instance' => ['ecsResourceUUID' => 'ecs-sample', 'ecsStatus' => 'STARTED',
                'ecsPendingStatus' => 'NONE', 'publicIpAddress' => '203.0.113.10', 'regionCode' => 'ph-test', 'zoneCode' => 'ph-test-a',
                'cpu' => 2, 'memory' => 4, 'systemDiskSize' => 50, 'dataDiskSize' => 0,
                'imageResourceUUID' => 'img-sample', 'internetChargeType' => 'PAY_BY_TRAFFIC', 'freeFlow' => 2000,
                'limitFlow' => 'TRUE', 'usedFlow' => 0.09]]),
        ]);
    }

    private function manifest(array $changes = []): void
    {
        Setting::put('pricing_rate_override', '100000');
        $this->mock(ExchangeRate::class, fn ($m) => $m->shouldReceive('toToman')->with('USD')->andReturn(90000));
        Setting::put('lightnode_manifest', json_encode([
            'regions' => ['ph-test' => ['country' => 'PH', 'city' => 'Manila', 'location_code' => 'ph-manila']],
            'packages' => ['ph-test|ph-test-a|sample-package' => $changes + [
                'hour_usd' => '0.022', 'month_usd' => '14.7', 'verified_at' => time(),
                'traffic_policy_verified' => true, 'delivery_verified' => true, 'spec' => $this->client()->packageSpec($this->package()),
            ]],
        ]));
    }

    public function test_registered_after_existing_drivers_and_token_not_returned_to_customer(): void
    {
        $manager = app(CloudManager::class);
        $this->assertSame('lightnode', array_key_last(CloudManager::DRIVERS));
        $this->assertInstanceOf(LightNodeClient::class, $manager->driver('lightnode'));
        $this->assertFalse($this->client()->isConfigured());
        $this->assertFalse($this->client()->testConnection()['ok']);
        Http::assertNothingSent();
    }

    public function test_official_header_and_no_other_provider_auth(): void
    {
        $this->fake();
        $this->assertTrue($this->client()->testConnection()['ok']);
        Http::assertSent(fn ($r) => $r->header('x-open-token') === ['test-token'] && ! $r->hasHeader('Authorization'));
    }

    public function test_generated_password_uses_the_common_live_reinstall_format(): void
    {
        $this->fake(); $this->manifest();
        $this->assertTrue($this->client()->createServer($this->spec())['ok']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/instance/create')
            && preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*@)[A-Za-z0-9@]{20}$/', $r['packageConfig']['password'])
            && ! preg_match('/(.)\1\1/', $r['packageConfig']['password']));
    }

    public function test_api_only_default_does_not_sell_saved_manual_quotes(): void
    {
        $this->fake(); $this->manifest();
        config(['lightnode.sales_enabled' => true, 'lightnode.allow_fixture_rates' => false]);
        $catalog = $this->client()->fetchCatalog();
        $this->assertFalse($catalog['ok']);
        $this->assertSame([], $catalog['plans']);
        $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        $this->assertSame(0, CloudProviderOperation::count());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_fixture_prices_cannot_enable_production_sales(): void
    {
        $this->fake(); $this->manifest();
        config(['lightnode.sales_enabled' => true, 'lightnode.allow_fixture_rates' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertContains('lightnode', CloudPlan::disabledProviders());
        $this->assertFalse($this->client()->fetchCatalog()['ok']);
        $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    private function publicPricing(array $overrides = [], ?callable $quote = null): array
    {
        $package = $this->package(); $package['publicIpChargeMode'] = 'PayByBandwidth';
        $package['bandwidth'] = 5; unset($package['freeFlow']);
        $receipt = ['ecsResourceUUID' => 'ecs-sample', 'ecsStatus' => 'STARTED', 'ecsPendingStatus' => 'NONE',
            'publicIpAddress' => '203.0.113.10', 'regionCode' => 'ph-test', 'zoneCode' => 'ph-test-a',
            'cpu' => 2, 'memory' => 4, 'systemDiskSize' => 50, 'dataDiskSize' => 0,
            'imageResourceUUID' => 'img-sample', 'internetChargeType' => 'PAY_BY_BAND_WIDTH', 'bandwidth' => 5, 'instanceSeries' => 's2'];
        $this->fake($overrides + ['*/package/list*' => Http::response(['packages' => [$package]]),
            '*/instance/detail*' => Http::response(['instance' => $receipt])]);
        $this->manifest();
        $manifest = json_decode(Setting::get('lightnode_manifest'), true);
        $manifest['bindings'] = ['ph-test|ph-test-a|sample-package' => ['series' => 's2', 'cpu_kind' => 'shared', 'verified_at' => time(),
            'delivery_verified' => true, 'spec' => $this->client()->packageSpec($package)]];
        Setting::put('lightnode_manifest', json_encode($manifest));
        config(['lightnode.public_pricing_enabled' => true, 'lightnode.allow_fixture_rates' => false]);
        $this->mock(LightNodePublicPricing::class, function ($mock) use ($quote) {
            $mock->shouldReceive('quote')->andReturnUsing($quote ?? fn ($p, $series, $os = 'linux') =>
                ['ok' => true, 'source' => 'public_calculator', 'currency' => 'USD', 'series' => $series,
                    'hour_usd' => '0.037506000000', 'month_usd' => '25.204032000000', 'verified_at' => time()]);
        });
        return $package;
    }

    public function test_public_prices_use_existing_margin_and_hourly_fee_in_production(): void
    {
        $this->publicPricing(); $this->app->detectEnvironment(fn () => 'production');
        Setting::put('cloud_margin_pct', '17'); Setting::put('pricing_fx_fee_pct_lightnode', '3');
        $this->assertTrue(app(CloudCatalogSync::class)->sync('lightnode')['ok']);
        $plan = CloudPlan::where('provider', 'lightnode')->firstOrFail();
        $cost = (int) ceil(.037506 * .9 * 1.03 * 1000000);
        $this->assertSame($cost, $plan->cost_hour_eur_micro);
        $this->assertSame((int) ceil($cost * 1.17), $plan->hourlyCostFloorEurMicro());
        $this->assertFalse($plan->in_stock);
        $this->assertStringContainsString('5 Mbps', $plan->trafficLabel('en'));
        // Saved manual quote was .022; the live quote is authoritative.
        $this->assertNotSame(19800, $cost);
    }

    public function test_public_prices_require_explicit_current_exact_series_binding(): void
    {
        $this->publicPricing();
        $original = json_decode(Setting::get('lightnode_manifest'), true);
        foreach ([[], ['series' => 'guess'], ['cpu_kind' => null], ['cpu_kind' => 'guess'], ['spec' => ['cpu' => 8]], ['verified_at' => time() - 31 * 86400]] as $change) {
            $manifest = $original;
            if ($change === []) { $manifest['bindings'] = []; }
            else { $manifest['bindings']['ph-test|ph-test-a|sample-package'] = $change + $manifest['bindings']['ph-test|ph-test-a|sample-package']; }
            Setting::put('lightnode_manifest', json_encode($manifest));
            $this->assertSame([], $this->client()->fetchCatalog()['plans']);
            $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/instance/create'));
    }

    public function test_different_windows_price_or_unavailable_public_quote_cannot_buy(): void
    {
        foreach (['price', 'unavailable'] as $failure) {
            $this->publicPricing([], fn ($p, $series, $os = 'linux') => $failure === 'unavailable'
                ? ['ok' => false] : ['ok' => true, 'source' => 'public_calculator', 'currency' => 'USD', 'series' => $series,
                    'hour_usd' => $os === 'windows' ? '.040' : '.037', 'month_usd' => '25', 'verified_at' => time()]);
            $this->assertSame([], $this->client()->fetchCatalog()['plans']);
            $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/instance/create'));
    }

    public function test_fresh_quote_increase_or_changed_network_contract_never_buys(): void
    {
        $this->publicPricing();
        $this->assertFalse($this->client()->createServer($this->spec())['ok']); // No priced local plan.
        app(CloudCatalogSync::class)->sync('lightnode');
        $plan = CloudPlan::where('provider', 'lightnode')->firstOrFail();
        $original = $plan->cost_hour_eur_micro;
        $plan->update(['cost_hour_eur_micro' => $original - 1]);
        $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        $plan->update(['cost_hour_eur_micro' => $original, 'network_terms' => ['charge_mode' => 'PayByTraffic', 'bandwidth_mbps' => 5]]);
        $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        $this->assertSame(0, CloudProviderOperation::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/instance/create'));
    }

    public function test_disabling_public_price_source_hides_existing_production_plans(): void
    {
        $this->publicPricing(); config(['lightnode.sales_enabled' => true]);
        app(CloudCatalogSync::class)->sync('lightnode');
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertNotContains('lightnode', CloudPlan::disabledProviders());
        config(['lightnode.public_pricing_enabled' => false]);
        $this->assertContains('lightnode', CloudPlan::disabledProviders());
        $this->assertSame(0, CloudPlan::sellable()->where('provider', 'lightnode')->count());
        $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/instance/create'));
    }

    public function test_public_price_purchase_stores_quote_and_requires_receipt_series(): void
    {
        $this->publicPricing(); app(CloudCatalogSync::class)->sync('lightnode');
        $this->assertTrue($this->client()->createServer($this->spec())['ok']);
        $op = CloudProviderOperation::firstOrFail();
        $this->assertSame('s2', $op->payload['_expected_series']);
        $this->assertSame('public_calculator', $op->payload['_supplier_quote']['source']);
        $this->assertSame('running', $this->client()->serverStatus('ecs-sample')['status']);
        Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
        Http::fake(['*/instance/detail*' => Http::response(['instance' => ['ecsResourceUUID' => 'ecs-sample', 'ecsStatus' => 'STARTED',
            'ecsPendingStatus' => 'NONE', 'regionCode' => 'ph-test', 'zoneCode' => 'ph-test-a', 'cpu' => 2, 'memory' => 4,
            'systemDiskSize' => 50, 'dataDiskSize' => 0, 'internetChargeType' => 'PAY_BY_BAND_WIDTH', 'bandwidth' => 5,
            'imageResourceUUID' => 'img-sample', 'instanceSeries' => 'g2']])]);
        $this->assertFalse($this->client()->serverStatus('ecs-sample')['ok']);
    }

    public function test_live_bandwidth_receipt_spelling_is_accepted_but_wrong_contract_is_not_delivered(): void
    {
        $this->publicPricing(); app(CloudCatalogSync::class)->sync('lightnode');
        $this->assertTrue($this->client()->createServer($this->spec())['ok']);
        $this->assertSame('running', $this->client()->serverStatus('ecs-sample')['status']);
        // Sanitized shape of the real fixed-bandwidth acceptance receipt.
        $receipt = ['ecsResourceUUID' => 'ecs-sample', 'ecsStatus' => 'STARTED', 'ecsPendingStatus' => 'NONE',
            'publicIpAddress' => '203.0.113.10', 'regionCode' => 'ph-test', 'zoneCode' => 'ph-test-a',
            'cpu' => 2, 'memory' => 4, 'systemDiskSize' => 50, 'dataDiskSize' => 0,
            'imageResourceUUID' => 'img-sample', 'instanceSeries' => 's2',
            'internetChargeType' => 'PAY_BY_BAND_WIDTH', 'bandwidth' => 5, 'limitFlow' => 'FALSE'];
        foreach ([['internetChargeType' => 'PAY_BY_TRAFFIC'], ['internetChargeType' => 'PAY_BY_BANDWIDTH'],
            ['bandwidth' => 2], ['dataDiskSize' => 100], ['instanceSeries' => 'g2']] as $change) {
            Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
            Http::fake(['*/instance/detail*' => Http::response(['instance' => array_replace($receipt, $change)])]);
            $result = $this->client()->serverStatus('ecs-sample');
            $this->assertFalse($result['ok']); $this->assertNull($result['ipv4']);
        }
        Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
        Http::fake(['*/instance/detail*' => Http::response(['instance' => $receipt])]);
        $this->assertSame('running', $this->client()->serverStatus('ecs-sample')['status']);
    }

    public function test_catalog_uses_reviewed_actual_hourly_rate_and_keeps_sale_closed(): void
    {
        $this->fake(); $this->manifest();
        $r = $this->client()->fetchCatalog();
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['plans']);
        $this->assertSame(19800, $r['plans'][0]['cost_hour_eur_micro']);
        $this->assertSame(1323, $r['plans'][0]['cost_eur_cents']);
        $this->assertFalse($r['plans'][0]['in_stock']);
        $this->assertSame('ph-test|img-sample', $r['images'][0]['provider_ref']);
        $this->assertSame('ubuntu-24.04', $r['images'][0]['key']);
    }

    public function test_old_rates_or_unverified_traffic_are_not_imported(): void
    {
        $this->fake(); $this->manifest(['verified_at' => time() - 8 * 86400]);
        $this->assertSame([], $this->client()->fetchCatalog()['plans']);
        $this->manifest(['traffic_policy_verified' => false]);
        $this->assertSame([], $this->client()->fetchCatalog()['plans']);
        $this->manifest(['spec' => ['cpu' => 8]]);
        $this->assertSame([], $this->client()->fetchCatalog()['plans']);
    }

    public function test_sync_with_no_reviewed_rates_deactivates_old_lightnode_rows_only(): void
    {
        $this->fake(); $this->manifest(['verified_at' => 1]);
        $old = CloudPlan::create(['provider' => 'lightnode', 'provider_ref' => 'old', 'location_code' => 'ph-manila',
            'slug' => 'old', 'public_name' => 'old', 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 50,
            'cost_eur_cents' => 1000, 'price_irt' => 100000, 'is_active' => true, 'in_stock' => true]);
        $this->assertTrue(app(CloudCatalogSync::class)->sync('lightnode')['ok']);
        $this->assertFalse($old->fresh()->is_active);
    }

    public function test_repeated_create_buys_once_and_password_is_encrypted_before_post(): void
    {
        $this->fake();
        $first = $this->client()->createServer($this->spec());
        $second = $this->client()->createServer($this->spec());
        $this->assertTrue($first['ok']);
        $this->assertSame('building', $first['status']);
        $this->assertNull($first['ipv4']);
        $this->assertSame($first['ref'], $second['ref']);
        $this->assertSame($first['root_password'], $second['root_password']);
        $this->assertMatchesRegularExpression('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[!@]).{8,30}$/', $first['root_password']);
        $this->assertStringNotContainsString($first['root_password'], CloudProviderOperation::first()->getRawOriginal('payload'));
        $posts = Http::recorded(fn ($r) => $r->method() === 'POST')->values();
        $this->assertCount(1, $posts);
        $this->assertSame('sample-package', $posts[0][0]['packageConfig']['packageCode']);
        $this->assertSame('img-sample', $posts[0][0]['packageConfig']['imageResourceUUID']);
        $this->assertFalse(isset($posts[0][0]['term']));
    }

    public function test_lost_create_response_is_not_repeated(): void
    {
        $this->fake(['*/instance/create' => Http::response('gateway failed', 502)]);
        $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $this->assertDatabaseCount('cloud_provider_operations', 1);
    }

    public function test_unknown_task_does_not_claim_running_or_delivery_ip(): void
    {
        $this->fake(['*/asynctask/getResult*' => Http::response(['asyncTaskInfo' => ['processResult' => 'PROCESSING']])]);
        $this->client()->createServer($this->spec());
        $status = $this->client()->serverStatus('ecs-sample');
        $this->assertSame('building', $status['status']);
        $this->assertNull($status['ipv4']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/instance/detail'));
    }

    public function test_task_success_and_exact_resource_detail_allow_running(): void
    {
        $this->fake(); $this->client()->createServer($this->spec());
        $r = $this->client()->serverStatus('ecs-sample');
        $this->assertSame('running', $r['status']);
        $this->assertSame('203.0.113.10', $r['ipv4']);
    }

    public function test_wrong_region_or_changed_resources_never_buy(): void
    {
        $this->fake();
        $spec = $this->spec(); $spec['image_ref'] = 'us-test|img-sample';
        $this->assertFalse($this->client()->createServer($spec)['ok']);
        $spec = $this->spec(); $spec['ram_mb'] = 8192;
        $this->assertFalse($this->client()->createServer($spec)['ok']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_existing_names_without_purchase_journal_are_not_adopted_or_purchased(): void
    {
        $this->fake(['*/instance/list*' => Http::response(['instances' => [['ecsResourceUUID' => 'ecs-existing',
            'instanceName' => 'sn-svc-test', 'ecsStatus' => 'STARTED', 'publicIpAddress' => '203.0.113.11']]])]);
        $r = $this->client()->createServer($this->spec());
        $this->assertFalse($r['ok']); $this->assertNull($r['ref']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_async_delete_does_not_stop_cost_until_task_and_full_inventory_confirm(): void
    {
        $this->fake([
            '*/instance/release' => Http::response(['asyncTaskInfo' => ['asyncTaskUUID' => 'release-task', 'ecsResourceUUID' => 'ecs-sample']], 202),
            '*/asynctask/getResult*' => Http::sequence()
                ->push(['asyncTaskInfo' => ['processResult' => 'PROCESSING']], 202)
                ->push(['asyncTaskInfo' => ['processResult' => 'SUCCESS']], 202),
        ]);
        $this->assertFalse($this->client()->deleteServer('ecs-sample')['ok']);
        $this->assertTrue($this->client()->deleteServer('ecs-sample')['ok']);
        $this->assertTrue($this->client()->deleteServer('ecs-sample')['ok']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_delete_success_task_but_live_resource_is_not_release_confirmation(): void
    {
        $this->fake([
            '*/instance/release' => Http::response(['asyncTaskInfo' => ['asyncTaskUUID' => 'release-task']], 202),
            '*/instance/list*' => Http::response(['instances' => [['ecsResourceUUID' => 'ecs-sample', 'instanceName' => 'keep-me']]]),
        ]);
        $this->assertFalse($this->client()->deleteServer('ecs-sample')['ok']);
    }

    public function test_inventory_failure_never_becomes_an_empty_success(): void
    {
        $this->fake(['*/instance/list*' => Http::response(['success' => false], 200)]);
        $this->assertFalse($this->client()->listServers()['ok']);
    }

    public function test_region_specific_images_and_hourly_gate(): void
    {
        foreach (['us-test', 'ph-test'] as $region) {
            CloudImage::create(['provider' => 'lightnode', 'provider_ref' => $region.'|img', 'key' => 'windows-2022',
                'kind' => 'os', 'label' => 'Windows Server 2022', 'family' => 'windows', 'arch' => 'x86', 'min_disk_gb' => 50, 'min_ram_mb' => 2048, 'is_active' => true]);
        }
        $p = new CloudPlan(['provider' => 'lightnode', 'provider_location' => 'ph-test|ph-test-a', 'arch' => 'x86', 'vcpu' => 2, 'disk_gb' => 50, 'ram_mb' => 4096]);
        $this->assertSame('ph-test|img', CloudImage::compatibleRefFor($p, 'windows-2022'));
        $this->assertFalse($p->supportsHourly());
        $p->cost_hour_eur_micro = 19800;
        $this->assertTrue($p->supportsHourly());
        $this->assertContains('lightnode', CloudPlan::disabledProviders());
        $this->assertFalse($this->client()->capabilities()['console']);
        $this->assertFalse($this->client()->capabilities()['ssh_key']);
    }

    public function test_windows_uses_regional_image_and_generated_password_without_term_parameter(): void
    {
        $windows = $this->image();
        $windows['imageResourceUUID'] = 'win-regional';
        $windows['osDistroVersion'] = 'Windows';
        $windows['osVersionDetail'] = 'Windows Server 2022';
        $this->fake(['*/image/list*' => Http::response(['images' => [$windows]])]);
        $spec = $this->spec(); $spec['image_ref'] = 'ph-test|win-regional';
        $this->assertTrue($this->client()->createServer($spec)['ok']);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r['packageConfig']['imageResourceUUID'] === 'win-regional'
            && strlen($r['packageConfig']['password']) === 20 && ! isset($r['term']));
    }

    public function test_complete_inventory_reads_every_page_even_if_row_count_means_page_count(): void
    {
        $first = array_map(fn ($i) => ['ecsResourceUUID' => 'ecs-'.$i, 'instanceName' => 'vm-'.$i], range(1, 50));
        $this->fake(['*/instance/list*' => Http::sequence()
            ->push(['instances' => $first, 'rowCount' => 50])
            ->push(['instances' => [['ecsResourceUUID' => 'ecs-51', 'instanceName' => 'vm-51']], 'rowCount' => 1])]);
        $r = $this->client()->listServers();
        $this->assertTrue($r['ok']); $this->assertCount(51, $r['servers']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'page=2'));
    }

    public function test_repeating_pages_and_malformed_inventory_fail_closed(): void
    {
        $first = array_map(fn ($i) => ['ecsResourceUUID' => 'ecs-'.$i, 'instanceName' => 'vm-'.$i], range(1, 50));
        $this->fake(['*/instance/list*' => Http::response(['instances' => $first])]);
        $this->assertFalse($this->client()->listServers()['ok']);
        $this->fake(['*/instance/list*' => Http::response(['instances' => ['invalid-row']])]);
        $this->assertFalse($this->client()->listServers()['ok']);
    }

    public function test_failed_create_task_never_becomes_delivered(): void
    {
        $this->fake(['*/asynctask/getResult*' => Http::response(['asyncTaskInfo' => ['processResult' => 'FAILURE', 'taskStatus' => 'FINISHED']])]);
        $this->client()->createServer($this->spec());
        $r = $this->client()->serverStatus('ecs-sample');
        $this->assertFalse($r['ok']); $this->assertNull($r['ipv4']);
        $this->assertSame('failed', CloudProviderOperation::first()->state);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/instance/detail'));
    }

    public function test_pending_power_blocks_conflicting_release(): void
    {
        $this->fake([
            '*/instance/stop' => Http::response(['asyncTaskInfo' => ['asyncTaskUUID' => 'stop-task']], 202),
            '*/asynctask/getResult*' => Http::response(['asyncTaskInfo' => ['processResult' => 'PROCESSING']]),
        ]);
        $r = $this->client()->power('ecs-sample', 'off');
        $this->assertTrue($r['ok']); $this->assertTrue($r['pending']);
        $this->assertFalse($this->client()->deleteServer('ecs-sample')['ok']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/instance/release'));
    }

    public function test_network_contract_is_persisted_and_cannot_be_replaced_with_unlimited_label(): void
    {
        $this->fake(); $this->manifest();
        Setting::put('cloud_traffic_unlimited', '1');
        $this->assertTrue(app(CloudCatalogSync::class)->sync('lightnode')['ok']);
        $plan = CloudPlan::where('provider', 'lightnode')->firstOrFail();
        $this->assertSame(100, $plan->network_terms['bandwidth_mbps']);
        $this->assertStringContainsString('-net-', $plan->slug);
        foreach (['fa', 'en', 'tr'] as $locale) {
            $label = $plan->trafficLabel($locale);
            $this->assertStringContainsString('2000', $label);
            $this->assertStringContainsString('100 Mbps', $label);
        }
    }

    public function test_data_disk_is_separate_from_boot_capacity_and_order_total_is_checked(): void
    {
        $package = $this->package(); $package['dataDiskSize'] = 100;
        $this->fake(['*/package/list*' => Http::response(['packages' => [$package]])]);
        $this->manifest(['spec' => $this->client()->packageSpec($package)]);
        $catalog = $this->client()->fetchCatalog();
        $this->assertSame(150, $catalog['plans'][0]['disk_gb']);
        $this->assertSame(50, $catalog['plans'][0]['boot_disk_gb']);
        $this->assertSame(100, $catalog['plans'][0]['data_disk_gb']);
        $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $spec = $this->spec(); $spec['disk_gb'] = 150;
        $this->assertTrue($this->client()->createServer($spec)['ok']);
    }

    public function test_live_api_omits_bandwidth_for_traffic_and_traffic_for_bandwidth_packages(): void
    {
        foreach (['PayByTraffic', 'PayByBandwidth'] as $mode) {
            $package = $this->package(); $package['publicIpChargeMode'] = $mode;
            unset($package[$mode === 'PayByTraffic' ? 'bandwidth' : 'freeFlow']);
            $this->fake(['*/package/list*' => Http::response(['packages' => [$package]])]);
            $this->manifest(['spec' => $this->client()->packageSpec($package)]);
            $catalog = $this->client()->fetchCatalog();
            $this->assertCount(1, $catalog['plans']);
            $this->assertSame($mode, $catalog['plans'][0]['network_terms']['charge_mode']);
            $plan = new CloudPlan($catalog['plans'][0] + ['provider' => 'lightnode']);
            $this->assertDoesNotMatchRegularExpression('/\b0 Mbps\b/', $plan->trafficLabel('en'));
        }
    }

    public function test_unverified_or_wrong_region_firewall_never_buys(): void
    {
        $this->fake(['*/firewall/list*' => Http::response(['firewalls' => [['firewallUUID' => 'fw-test',
            'regionCode' => 'us-test', 'firewallStatus' => 'AVAILABLE']]])]);
        $this->manifest();
        $manifest = json_decode(Setting::get('lightnode_manifest'), true);
        $manifest['regions']['ph-test']['firewall_uuid'] = 'fw-test';
        Setting::put('lightnode_manifest', json_encode($manifest));
        $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    private function assertDeliveryPath(string $family, bool $public = false): void
    {
        Mail::fake();
        config(['lightnode.sales_enabled' => true]);
        $image = $this->image();
        if ($family === 'windows') {
            $image['osDistroVersion'] = 'Windows'; $image['osVersionDetail'] = 'Windows Server 2022';
        }
        if ($public) {
            $this->publicPricing(['*/image/list*' => Http::response(['images' => [$image]])]);
        } else {
            $this->fake(['*/image/list*' => Http::response(['images' => [$image]])]);
            $this->manifest();
        }
        app(CloudCatalogSync::class)->sync('lightnode');
        $plan = CloudPlan::where('provider', 'lightnode')->firstOrFail();
        $customer = Customer::create(['name' => 'Acceptance fixture', 'email' => 'fixture@example.test',
            'password' => 'fixture-only', 'status' => 'active', 'locale' => 'en']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'VPS acceptance',
            'price' => 100000, 'cycle' => 'monthly', 'status' => 'awaiting_provision',
            'provision_status' => 'pending', 'cloud_plan_id' => $plan->id,
            'cloud_image_key' => $family === 'windows' ? 'windows-2022' : 'ubuntu-24.04']);
        $provisioner = app(CloudProvisioner::class);
        $this->assertTrue($provisioner->provision($service));
        $instance = CloudInstance::where('service_id', $service->id)->firstOrFail();
        $this->assertSame('building', $instance->status);
        $this->assertNull($instance->ready_notified_at);
        $this->assertNotEmpty($instance->password());
        $this->assertSame($family === 'windows' ? 'Administrator' : 'root', $service->fresh()->username);
        $provisioner->syncInstances();
        $instance->refresh();
        $this->assertSame('running', $instance->status);
        $this->assertSame('203.0.113.10', $instance->ipv4);
        $this->assertNotNull($instance->ready_notified_at);
        $this->assertSame($family === 'windows', $instance->isWindows());
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $this->assertTrue($provisioner->provision($service->fresh()));
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_linux_provisioning_delivery_path_waits_for_task_then_notifies_once(): void
    {
        $this->assertDeliveryPath('ubuntu');
    }

    public function test_windows_provisioning_delivery_path_uses_administrator_and_rdp_image(): void
    {
        $this->assertDeliveryPath('windows');
    }

    public function test_public_price_linux_delivers_through_real_provisioner_path(): void
    {
        $this->assertDeliveryPath('ubuntu', true);
    }

    public function test_public_price_windows_delivers_through_real_provisioner_path(): void
    {
        $this->assertDeliveryPath('windows', true);
    }

    public function test_async_password_is_saved_atomically_only_after_success(): void
    {
        $this->fake([
            '*/instance/detail*' => Http::response(['instance' => ['ecsResourceUUID' => 'ecs-sample',
                'ecsStatus' => 'STOPPED', 'ecsPendingStatus' => 'NONE']]),
            '*/instance/modifyInstancePassword' => Http::response(['asyncTaskUUID' => 'pw-task'], 202),
            '*/asynctask/getResult*' => Http::sequence()
                ->push(['asyncTaskInfo' => ['processResult' => 'PROCESSING']])
                ->push(['asyncTaskInfo' => ['processResult' => 'PROCESSING']])
                ->push(['asyncTaskInfo' => ['processResult' => 'SUCCESS']]),
        ]);
        $customer = Customer::create(['name' => 'Fixture', 'email' => 'pw@example.test', 'password' => 'fixture-only']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Fixture', 'price' => 1, 'cycle' => 'monthly', 'password' => 'PreviousPassword9!']);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'lightnode',
            'provider_ref' => 'ecs-sample', 'status' => 'off', 'location_code' => 'ph-manila', 'image_key' => 'ubuntu-24.04']);
        $instance->setPassword('PreviousPassword9!'); $instance->save();
        $r = $this->client()->resetPassword('ecs-sample');
        $this->assertTrue($r['ok']); $this->assertTrue($r['pending']); $this->assertNull($r['root_password']);
        $newPassword = CloudProviderOperation::first()->payload['password'];
        $this->assertSame('building', $this->client()->serverStatus('ecs-sample')['status']);
        $this->assertSame('PreviousPassword9!', $instance->fresh()->password());
        $this->assertSame('off', $this->client()->serverStatus('ecs-sample')['status']);
        $this->assertSame($newPassword, $instance->fresh()->password());
        $this->assertSame($newPassword, $service->fresh()->password);
        $this->assertFalse($instance->fresh()->password_seen);
        $this->assertDatabaseCount('cloud_provider_operations', 0);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_reset_password_rejects_running_server_without_mutation(): void
    {
        $this->fake();
        $this->assertFalse($this->client()->resetPassword('ecs-sample')['ok']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_windows_requires_two_cpus_and_four_gb_before_purchase(): void
    {
        foreach ([[1, 4], [2, 2]] as [$cpu, $memory]) {
            $package = $this->package(); $package['cpu'] = $cpu; $package['memory'] = $memory;
            $image = $this->image(); $image['osDistroVersion'] = 'Windows'; $image['osVersionDetail'] = 'Windows Server 2022';
            $this->fake(['*/package/list*' => Http::response(['packages' => [$package]]),
                '*/image/list*' => Http::response(['images' => [$image]])]);
            $this->manifest(['spec' => $this->client()->packageSpec($package)]);
            $spec = $this->spec(); $spec['vcpu'] = $cpu; $spec['ram_mb'] = $memory * 1024;
            $this->assertFalse($this->client()->createServer($spec)['ok']);
            Http::assertNotSent(fn ($r) => $r->method() === 'POST');
            CloudImage::updateOrCreate(['provider' => 'lightnode', 'provider_ref' => 'ph-test|img-sample'],
                ['key' => 'windows-2022', 'family' => 'windows', 'kind' => 'os', 'label' => 'Windows Server 2022', 'is_active' => true]);
            $plan = new CloudPlan(['provider' => 'lightnode', 'provider_location' => 'ph-test|ph-test-a',
                'vcpu' => $cpu, 'ram_mb' => $memory * 1024, 'disk_gb' => 50]);
            $this->assertNull(CloudImage::compatibleRefFor($plan, 'windows-2022'));
        }
    }

    public function test_completed_reinstall_retains_journal_until_windows_credentials_commit(): void
    {
        $image = $this->image(); $image['osDistroVersion'] = 'Windows'; $image['osVersionDetail'] = 'Windows Server 2022';
        $this->fake(['*/image/list*' => Http::response(['images' => [$image]]),
            '*/instance/detail*' => Http::response(['instance' => ['ecsResourceUUID' => 'ecs-sample',
                'regionCode' => 'ph-test', 'ecsStatus' => 'STOPPED', 'ecsPendingStatus' => 'NONE',
                'systemDiskSize' => 50, 'cpu' => 2, 'memory' => 4]]),
            '*/instance/reinstallSystem' => Http::response(['asyncTaskUUID' => 'reinstall-task'], 202)]);
        CloudImage::create(['provider' => 'lightnode', 'provider_ref' => 'ph-test|img-sample',
            'key' => 'windows-2022', 'family' => 'windows', 'kind' => 'os', 'label' => 'Windows Server 2022', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Fixture', 'email' => 'reinstall@example.test', 'password' => 'fixture-only']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Fixture', 'price' => 1,
            'cycle' => 'monthly', 'password' => 'PreviousPassword9!', 'username' => 'root']);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'lightnode',
            'provider_ref' => 'ecs-sample', 'status' => 'off', 'location_code' => 'ph-manila', 'image_key' => 'ubuntu-24.04']);
        $instance->setPassword('PreviousPassword9!'); $instance->save();
        $r = $this->client()->rebuild('ecs-sample', 'ph-test|img-sample');
        $this->assertTrue($r['ok']); $this->assertTrue($r['pending']); $this->assertNull($r['root_password']);
        $this->assertSame('success', CloudProviderOperation::first()->state);
        $newPassword = CloudProviderOperation::first()->payload['password'];
        $this->assertSame('ubuntu-24.04', $instance->fresh()->image_key);
        $this->client()->serverStatus('ecs-sample');
        $this->assertSame('windows-2022', $instance->fresh()->image_key);
        $this->assertSame($newPassword, $instance->fresh()->password());
        $this->assertSame('Administrator', $service->fresh()->username);
        $this->assertSame('windows-2022', $service->fresh()->cloud_image_key);
        $this->assertDatabaseCount('cloud_provider_operations', 0);
    }

    public function test_purchase_reconciliation_only_reads_the_order_region_and_zone(): void
    {
        $this->fake(['*/region/list*' => Http::response(['regions' => [
            ['regionCode' => 'ph-test', 'zones' => [['zoneCode' => 'ph-test-a']]],
            ['regionCode' => 'us-other', 'zones' => [['zoneCode' => 'us-other-a']]],
        ]])]);
        $this->assertTrue($this->client()->createServer($this->spec())['ok']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/instance/list') && $r['regionCode'] === 'us-other');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/instance/list')
            && $r['regionCode'] === 'ph-test' && $r['zoneCode'] === 'ph-test-a');
    }

    public function test_chinese_windows_image_cannot_replace_english_customer_image(): void
    {
        $zh = $this->image(); $zh['osDistroVersion'] = 'Windows'; $zh['osVersionDetail'] = 'Windows Server 2022 (ZH)'; $zh['imageResourceUUID'] = 'img-zh';
        $en = $zh; $en['osVersionDetail'] = 'Windows Server 2022 (EN)'; $en['imageResourceUUID'] = 'img-en';
        $this->fake(['*/image/list*' => Http::response(['images' => [$zh, $en]])]); $this->manifest();
        $images = $this->client()->fetchCatalog()['images'];
        $this->assertCount(1, $images);
        $this->assertSame('ph-test|img-en', $images[0]['provider_ref']);
    }

    public function test_duplicate_package_rows_are_deduplicated_but_conflicting_specs_block_purchase(): void
    {
        $p = $this->package();
        $this->fake(['*/package/list*' => Http::response(['packages' => [$p, $p]])]); $this->manifest();
        $this->assertCount(1, $this->client()->fetchCatalog()['plans']);
        $changed = $p; $changed['memory'] = 8;
        $this->fake(['*/package/list*' => Http::response(['packages' => [$p, $changed]])]); $this->manifest();
        $this->assertFalse($this->client()->fetchCatalog()['ok']);
        $this->assertFalse($this->client()->createServer($this->spec())['ok']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_successful_task_cannot_deliver_wrong_resources_or_uncontrolled_traffic(): void
    {
        foreach (['memory' => 2, 'limitFlow' => 'FALSE', 'imageResourceUUID' => 'other-image'] as $field => $value) {
            CloudProviderOperation::query()->delete();
            $detail = ['ecsResourceUUID' => 'ecs-sample', 'ecsStatus' => 'STARTED', 'ecsPendingStatus' => 'NONE',
                'publicIpAddress' => '203.0.113.10', 'regionCode' => 'ph-test', 'zoneCode' => 'ph-test-a',
                'cpu' => 2, 'memory' => 4, 'systemDiskSize' => 50, 'dataDiskSize' => 0, 'imageResourceUUID' => 'img-sample',
                'internetChargeType' => 'PAY_BY_TRAFFIC', 'freeFlow' => 2000, 'limitFlow' => 'TRUE'];
            $detail[$field] = $value;
            $this->fake(['*/instance/detail*' => Http::response(['instance' => $detail])]);
            $this->assertTrue($this->client()->createServer($this->spec())['ok']);
            $status = $this->client()->serverStatus('ecs-sample');
            $this->assertFalse($status['ok']); $this->assertNull($status['ipv4']);
            $this->assertTrue($this->client()->createServer($this->spec())['ok']);
            $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
            Http::assertSent(fn ($r) => $r->method() === 'POST' && ! isset($r['_expected_spec']));
        }
    }

    public function test_customer_cannot_reveal_lightnode_password_until_delivery_is_confirmed(): void
    {
        $this->fake();
        $customer = Customer::create(['name' => 'Fixture', 'email' => 'reveal@example.test', 'password' => 'fixture-only', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Fixture', 'price' => 1,
            'cycle' => 'monthly', 'status' => 'active', 'provision_status' => 'done']);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'lightnode',
            'provider_ref' => 'ecs-sample', 'status' => 'building', 'image_key' => 'ubuntu-24.04']);
        $instance->setPassword('FixturePassword9!'); $instance->save();
        $this->actingAs($customer, 'customer')->get('/account/cloud/'.$service->id)
            ->assertOk()->assertViewHas('canReveal', false);
        $this->post('/account/cloud/'.$service->id.'/reveal-password')->assertSessionHasErrors();
        $this->assertFalse($instance->fresh()->password_seen);
        $instance->update(['status' => 'running', 'ready_notified_at' => now()]);
        $this->get('/account/cloud/'.$service->id)->assertOk()->assertViewHas('canReveal', true);
        $this->post('/account/cloud/'.$service->id.'/reveal-password')
            ->assertSessionHas('revealed_root_password', 'FixturePassword9!');
        $this->assertTrue($instance->fresh()->password_seen);
    }
}
