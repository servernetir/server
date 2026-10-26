<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\Cloud\VpsTcApi;
use App\Services\Cloud\VpsTcCatalog;
use App\Services\Cloud\VpsTcPricing;
use App\Services\Cloud\VpsTcSales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\NavasanRates;
use Tests\TestCase;

/** Actual offer selection, admin routes and checkout must share the same monthly contract. */
class VpsTcSalesTest extends TestCase
{
    use RefreshDatabase;

    private array $forms;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        NavasanRates::store();
        config(['services.vpstc' => ['base_url' => '', 'username' => '', 'password' => '']]);
        foreach (['cloud_vpstc_api_origin' => 'https://portal.vps.tc', 'vpstc_currency' => 'USD',
            'cloud_margin_pct_vpstc' => '20', 'vpstc_supplier_tax_pct' => '20',
            'pricing_fx_fee_pct_vpstc' => '5', 'vpstc_fx_rate_toman' => ''] as $key => $value) {
            Setting::put($key, $value);
        }
        Setting::putSecret('cloud_vpstc_username', 'fixture-reseller');
        Setting::putSecret('cloud_vpstc_password', 'fixture-secret');
        $this->forms = [['id' => '44', 'type' => 'select', 'required' => true, 'price' => 0,
            'setup' => 0, 'recurring_price' => 0, 'value' => [], 'items' => [
                ['id' => '123', 'title' => 'Ubuntu 24.04', 'price' => 0, 'setup' => 0, 'selected' => false],
            ]]];
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'web');
    }

    private function manifest(?string $account = null): void
    {
        Setting::put('vpstc_catalog_manifest', json_encode([
            'account_fingerprint' => $account ?? app(VpsTcApi::class)->accountFingerprint(),
            'products' => [[
                'product_id' => '12', 'category_id' => '7', 'kind' => 'vps', 'cycle' => 'm',
                'renewal_mode' => 'manual_invoice_deduplicated', 'parent_cancel_stops_billing' => true,
                'forms_sha256' => VpsTcCatalog::formFingerprint($this->forms),
                'country' => 'TR', 'city' => 'Istanbul', 'location_ref' => 'istanbul',
                'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 30, 'disk_type' => 'nvme',
                'cpu_kind' => 'shared', 'arch' => 'x86', 'traffic_gb' => 1000,
                'network_terms' => ['charge_mode' => 'hard_cap', 'extra_charges' => false],
                'availability' => ['path' => 'in_stock', 'mode' => 'boolean'],
                'payment_method' => '120', 'custom' => [],
                'images' => ['12|ubuntu-24.04' => ['key' => 'ubuntu-24.04', 'family' => 'ubuntu',
                    'version' => '24.04', 'label' => 'Ubuntu 24.04', 'min_ram_mb' => 512,
                    'min_disk_gb' => 10, 'custom' => ['44' => ['123' => 1]]]],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function snapshot(): void
    {
        $snapshot = app(CloudPricingSnapshot::class);
        $snapshot->publish($snapshot->rates(), [], ['vpstc' => app(VpsTcPricing::class)->fingerprint()]);
        $this->assertTrue($snapshot->current('vpstc'));
    }

    private function plan(array $overrides = []): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'tr-istanbul'], [
            'country' => 'TR', 'city' => 'Istanbul', 'is_active' => true,
        ]);
        return CloudPlan::create(array_replace([
            'provider' => 'vpstc', 'provider_ref' => '12', 'provider_location' => 'istanbul',
            'location_code' => 'tr-istanbul', 'public_name' => 'CV-2-2', 'slug' => 'fixture-monthly-vps',
            'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 30, 'disk_type' => 'nvme',
            'traffic_gb' => 1000, 'network_terms' => ['charge_mode' => 'hard_cap', 'extra_charges' => false],
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 176,
            'price_eur_cents' => 220, 'price_irt' => 440000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $overrides));
    }

    public function test_configured_and_priced_offers_remain_closed_until_explicit_sales_activation(): void
    {
        $this->manifest();
        $this->snapshot();
        $plan = $this->plan();
        $this->assertFalse(app(VpsTcSales::class)->enabled());
        $this->assertSame('off', $plan->blockedReason());
        $this->assertFalse(CloudPlan::sellable()->whereKey($plan->id)->exists());
        $this->assertCount(0, CloudPlan::offers());
        $this->assertCount(0, CloudPlan::shelf());
        $this->assertNull(CloudPlan::bestForSlug($plan->slug));
        $this->assertFalse($plan->supportsHourly());
        Http::assertNothingSent();
    }

    public function test_sales_flag_cannot_bypass_missing_or_wrong_account_manifest(): void
    {
        Setting::put('vpstc_sales_enabled', '1');
        $plan = $this->plan();
        $this->snapshot();
        $this->assertFalse(app(VpsTcSales::class)->enabled());
        $this->assertFalse(CloudPlan::sellable()->whereKey($plan->id)->exists());
        $this->manifest('different-reseller-account');
        $this->snapshot();
        $this->assertFalse(app(VpsTcSales::class)->enabled());
        $this->assertFalse(CloudPlan::sellable()->whereKey($plan->id)->exists());
        Http::assertNothingSent();
    }

    public function test_admin_activation_rejects_missing_account_review_and_stale_price_proof(): void
    {
        $this->admin();
        $this->plan();
        $this->snapshot();
        $this->post('/admin/cloud/vpstc/sales', ['enabled' => 1])->assertSessionHasErrors('sales');
        $this->assertNotSame('1', Setting::get('vpstc_sales_enabled'));
        $this->manifest('different-reseller-account');
        $this->snapshot();
        $this->post('/admin/cloud/vpstc/sales', ['enabled' => 1])->assertSessionHasErrors('sales');
        $this->manifest();
        $this->snapshot();
        Setting::put('vpstc_supplier_tax_pct', '21');
        $this->assertFalse(app(CloudPricingSnapshot::class)->current('vpstc'));
        $this->post('/admin/cloud/vpstc/sales', ['enabled' => 1])->assertSessionHasErrors('sales');
        $this->assertNotSame('1', Setting::get('vpstc_sales_enabled'));
        Http::assertNothingSent();
    }

    public function test_valid_admin_activation_exposes_monthly_offer_and_turning_off_hides_it_again(): void
    {
        $this->admin();
        $this->manifest();
        $plan = $this->plan(['cost_hour_eur_micro' => 999999]);
        $this->snapshot();
        $this->post('/admin/cloud/vpstc/sales', ['enabled' => 1])
            ->assertRedirect('/admin/cloud/vpstc')->assertSessionHasNoErrors();
        $this->assertSame('1', Setting::get('vpstc_sales_enabled'));
        $this->assertTrue(CloudPlan::sellable()->whereKey($plan->id)->exists());
        $this->assertCount(1, CloudPlan::offers());
        $this->assertSame($plan->id, CloudPlan::bestForSlug($plan->slug)?->id);
        // A stray hourly price must never override the monthly procurement contract.
        $this->assertFalse($plan->supportsHourly());
        $this->assertFalse(CloudPlan::hourlyCapable()->whereKey($plan->id)->exists());
        $this->assertCount(0, CloudPlan::offers(hourly: true));
        $this->assertNull(CloudPlan::bestForSlug($plan->slug, hourly: true));
        $this->post('/admin/cloud/vpstc/sales', ['enabled' => 0])->assertSessionHasNoErrors();
        $this->assertSame('0', Setting::get('vpstc_sales_enabled'));
        $this->assertFalse(CloudPlan::sellable()->whereKey($plan->id)->exists());
        Http::assertNothingSent();
    }

    public function test_price_input_change_hides_previously_enabled_offer_until_fresh_publication(): void
    {
        $this->manifest();
        $plan = $this->plan();
        $this->snapshot();
        Setting::put('vpstc_sales_enabled', '1');
        $this->assertTrue(CloudPlan::sellable()->whereKey($plan->id)->exists());
        Setting::put('pricing_fx_fee_pct_vpstc', '6');
        $this->assertSame('price', $plan->blockedReason());
        $this->assertFalse(CloudPlan::sellable()->whereKey($plan->id)->exists());
        $this->assertNull(CloudPlan::bestForSlug($plan->slug));
        Http::assertNothingSent();
    }

    public function test_guest_and_nonadministrators_cannot_publish_catalog_or_toggle_sales(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (['/admin/cloud/vpstc/catalog', '/admin/cloud/vpstc/sales'] as $path) {
            $this->post($path, ['enabled' => 1])->assertRedirect();
        }
        foreach (['support', 'author'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web');
            foreach (['/admin/cloud/vpstc/catalog', '/admin/cloud/vpstc/sales'] as $path) {
                $this->post($path, ['enabled' => 1])->assertForbidden();
            }
        }
        foreach (['admin.cloud.vpstc.catalog', 'admin.cloud.vpstc.sales'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            foreach (['web', 'auth:web', 'admin'] as $required) {
                $this->assertContains($required, $middleware);
            }
            $this->assertNotEmpty(array_filter($middleware, fn ($value) => str_starts_with($value, 'throttle:')));
        }
        $this->assertNull(Setting::get('vpstc_sales_enabled'));
        $this->assertDatabaseCount('cloud_plans', 0);
        Http::assertNothingSent();
    }

    public function test_sync_without_resource_review_preserves_existing_catalog_and_sends_no_supplier_request(): void
    {
        $this->admin();
        $plan = $this->plan();
        $this->snapshot();
        $before = $plan->fresh()->getAttributes();
        $this->post('/admin/cloud/vpstc/catalog')->assertSessionHasErrors('catalog');
        $this->assertSame($before, $plan->fresh()->getAttributes());
        $this->assertDatabaseCount('cloud_plans', 1);
        $this->assertDatabaseCount('cloud_images', 0);
        $this->assertDatabaseCount('cloud_provider_operations', 0);
        $this->assertNull(Setting::get('vpstc_sales_enabled'));
        Http::assertNothingSent();
    }

    public function test_reviewed_catalog_sync_publishes_prices_without_enabling_sales_or_buying_a_server(): void
    {
        $this->admin();
        $this->manifest();
        $snapshot = app(CloudPricingSnapshot::class);
        $snapshot->publish($snapshot->rates());
        Http::fake([
            'https://portal.vps.tc/api/login' => Http::response(['token' => 'fixture-token']),
            'https://portal.vps.tc/api/balance' => Http::response(['details' => [
                'currency' => 'USD', 'acc_credit' => '100.00', 'acc_balance' => '0']]),
            'https://portal.vps.tc/api/category/7/product' => Http::response(['products' => [[
                'id' => '12', 'category_id' => '7', 'paytype' => 'Regular',
                'periods' => [['value' => 'm', 'price' => '2.79', 'setup' => '1.25']],
            ]]]),
            'https://portal.vps.tc/api/order/12' => Http::response(['product' => ['id' => '12', 'category_id' => '7',
                'paytype' => 'Regular', 'billingtype' => 'PrePay', 'recurring' => 'm',
                'price' => '2.79', 'recurring_price' => '2.79', 'setup' => '1.25', 'in_stock' => true, 'config' => [
                'product' => [['id' => 'cycle', 'items' => [['value' => 'm', 'price' => '2.79', 'setup' => '1.25']]]],
                'forms' => $this->forms]]]),
            'https://portal.vps.tc/api/payment' => Http::response(['payments' => ['120' => 'Credit Balance']]),
            'https://portal.vps.tc/api/payment/fees' => Http::response(['payments' => [
                ['id' => 120, 'fixed_fee' => '0', 'percent_fee' => '0']]]),
        ]);
        $this->post('/admin/cloud/vpstc/catalog')->assertRedirect('/admin/cloud/vpstc')->assertSessionHasNoErrors();
        $plan = CloudPlan::where('provider', 'vpstc')->sole();
        $this->assertSame(176, $plan->cost_eur_cents);
        $this->assertGreaterThan(0, $plan->price_irt);
        $this->assertTrue($snapshot->current('vpstc'));
        $this->assertNull(Setting::get('vpstc_sales_enabled'));
        $this->assertFalse(CloudPlan::sellable()->whereKey($plan->id)->exists());
        $this->assertSame('12|ubuntu-24.04', CloudImage::compatibleRefFor($plan, 'ubuntu-24.04'));
        $this->assertDatabaseCount('cloud_provider_operations', 0);
        $this->assertDatabaseCount('cloud_instances', 0);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET'
            && $request->url() !== 'https://portal.vps.tc/api/login');
        $this->post('/admin/cloud/vpstc/sales', ['enabled' => 1])->assertSessionHasNoErrors();
        $this->assertTrue(CloudPlan::sellable()->whereKey($plan->id)->exists());
    }

    public function test_checkout_never_applies_global_discounts_or_sells_unsupported_cycles(): void
    {
        config(['billing.cycles.monthly.discount_pct' => 80]);
        $plan = $this->plan(['price_irt' => 320000]);
        $this->assertSame(320000, CloudStoreController::priceForCycle($plan, 'monthly'));
        foreach (['hourly', 'quarterly', 'semiannually', 'annually', 'biennially', 'invalid'] as $cycle) {
            $this->assertSame(0, CloudStoreController::priceForCycle($plan, $cycle), $cycle);
        }
        Http::assertNothingSent();
    }

    public function test_image_translation_uses_the_ordered_product_even_when_another_product_has_the_same_os_key(): void
    {
        $plan = $this->plan();
        $this->manifest();
        $this->snapshot();
        Setting::put('vpstc_sales_enabled', '1');
        $fields = ['provider' => 'vpstc', 'key' => 'ubuntu-24.04', 'kind' => 'os',
            'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'min_ram_mb' => 512, 'min_disk_gb' => 10, 'is_active' => true];
        CloudImage::create($fields + ['provider_ref' => '13|ubuntu-24.04']);
        $correct = CloudImage::create($fields + ['provider_ref' => '12|ubuntu-24.04']);
        $this->assertSame('12|ubuntu-24.04', CloudImage::compatibleRefFor($plan, 'ubuntu-24.04'));
        $this->assertSame(['ubuntu-24.04'], CloudStoreController::imageKeysFor($plan));
        $correct->update(['is_active' => false]);
        $this->assertNull(CloudImage::compatibleRefFor($plan, 'ubuntu-24.04'));
        $this->assertSame([], CloudStoreController::imageKeysFor($plan));
        $correct->update(['is_active' => true, 'min_ram_mb' => 4096]);
        $this->assertNull(CloudImage::compatibleRefFor($plan, 'ubuntu-24.04'));
        $this->assertSame([], CloudStoreController::imageKeysFor($plan));
        Http::assertNothingSent();
    }

    public function test_global_unlimited_label_cannot_erase_the_actual_hard_monthly_traffic_limit(): void
    {
        Setting::put('cloud_traffic_unlimited', '1');
        $plan = $this->plan(['traffic_gb' => 500]);
        $this->assertSame('500 GB/month, hard limit', $plan->trafficLabel('en'));
        $this->assertSame('500 GB/ay, kesin sınır', $plan->trafficLabel('tr'));
        $this->assertSame(fa_num(500).' گیگابایت ماهانه با سقف قطعی', $plan->trafficLabel('fa'));
        Http::assertNothingSent();
    }
}
