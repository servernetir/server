<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Http\Controllers\Admin\ServiceController;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Reports\BusinessReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArvanSalesFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('pricing_rate_override', '100000');
        Setting::put('cloud_margin_pct', '45');
        CloudLocation::create([
            'code' => 'ir-tabriz', 'country' => 'IR', 'city' => 'Tabriz', 'is_active' => true,
        ]);
    }

    private function plan(array $overrides = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider' => 'arvan', 'provider_ref' => 'eco-small1',
            'provider_location' => 'ir-tbz-sh1', 'location_code' => 'ir-tabriz',
            'public_name' => 'CV-1-1', 'slug' => 'cv-1c-1g-25d-ir-tabriz',
            'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 25, 'disk_type' => 'ssd',
            'traffic_gb' => 0, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 1000, 'cost_hour_eur_micro' => 16_000,
            'price_eur_cents' => 1450, 'price_irt' => 1_450_000,
            'is_active' => true, 'in_stock' => true,
        ], $overrides));
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'arvan-finance-'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    public function test_monthly_and_long_cycle_prices_remain_above_supplier_cost(): void
    {
        $plan = $this->plan();

        $monthly = CloudStoreController::priceForCycle($plan, 'monthly');
        $yearly = CloudStoreController::priceForCycle($plan, 'yearly');

        $this->assertGreaterThan(1_000_000, $monthly);
        $this->assertGreaterThanOrEqual(12_000_000, $yearly);
        $this->assertSame(1_450_000, $monthly);
        $this->assertSame(13_920_000, $yearly); // 12 × sell price × 80%
    }

    public function test_cloud_renewal_is_repriced_from_the_current_plan_before_invoice(): void
    {
        $plan = $this->plan();
        $service = Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'VPS ایران', 'plan' => $plan->public_name,
            'cycle' => 'monthly', 'currency_code' => 'IRT',
            'price' => 500_000, 'status' => 'active',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
            'next_due_at' => now()->addDay(),
        ]);

        $invoice = app(ServiceController::class)->issueInvoice($service);

        $this->assertSame(1_450_000, (int) $service->fresh()->price);
        $this->assertSame(1_450_000, (int) $invoice->total);
        $this->assertSame(1_450_000, (int) $invoice->items()->sum('line_total'));
    }

    public function test_profit_forecast_uses_the_same_current_cloud_renewal_price(): void
    {
        $plan = $this->plan();
        Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'VPS با قیمت قدیمی', 'plan' => $plan->public_name,
            'cycle' => 'monthly', 'currency_code' => 'IRT',
            'price' => 500_000, 'status' => 'active',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
            'next_due_at' => now()->addDay(),
        ]);

        $renewals = app(BusinessReport::class)->forecast(30)['incoming']['renewals'];

        $this->assertSame(1, $renewals['count']);
        $this->assertSame(1_450_000, $renewals['amount']);
        $this->assertSame(1_450_000, (int) $renewals['rows']->first()->forecast_total);
        $this->assertSame(0, $renewals['unpriced']);
    }

    public function test_profit_forecast_does_not_silently_lower_an_existing_contract(): void
    {
        $plan = $this->plan();
        Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'VPS با قرارداد گران‌تر', 'plan' => $plan->public_name,
            'cycle' => 'monthly', 'currency_code' => 'IRT',
            'price' => 1_600_000, 'status' => 'active',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
            'next_due_at' => now()->addDay(),
        ]);

        $renewals = app(BusinessReport::class)->forecast(30)['incoming']['renewals'];

        $this->assertSame(1_600_000, $renewals['amount']);
    }

    public function test_profit_forecast_exposes_cloud_renewal_with_missing_plan(): void
    {
        Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'VPS بدون قیمت فعلی', 'plan' => 'missing',
            'cycle' => 'monthly', 'currency_code' => 'IRT',
            'price' => 500_000, 'status' => 'active',
            'cloud_plan_id' => 999999, 'cloud_image_key' => 'ubuntu-24.04',
            'next_due_at' => now()->addDay(),
        ]);

        $renewals = app(BusinessReport::class)->forecast(30)['incoming']['renewals'];

        $this->assertSame(0, $renewals['count']);
        $this->assertSame(0, $renewals['amount']);
        $this->assertSame(1, $renewals['unpriced']);
    }

    public function test_scheduled_renewal_fails_closed_when_cloud_plan_is_missing(): void
    {
        $service = Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'VPS بدون پلن', 'plan' => 'missing',
            'cycle' => 'monthly', 'currency_code' => 'IRT',
            'price' => 100_000, 'status' => 'active',
            'cloud_plan_id' => 999999, 'cloud_image_key' => 'ubuntu-24.04',
            'next_due_at' => now()->addDay(),
        ]);

        $this->artisan('services:renew-due')->assertFailed();
        $this->assertFalse(Invoice::where('service_id', $service->id)->exists());
    }

    public function test_arvan_hourly_sale_is_off_until_the_48_hour_policy_is_explicitly_accepted(): void
    {
        $plan = $this->plan();

        $this->assertFalse($plan->supportsHourly());
        $this->assertFalse(CloudPlan::query()->hourlyCapable()->whereKey($plan->id)->exists());

        Setting::put('arvan_hourly_sales_enabled', '1');

        $this->assertTrue($plan->fresh()->supportsHourly());
        $this->assertTrue(CloudPlan::query()->hourlyCapable()->whereKey($plan->id)->exists());
    }

    public function test_supplier_costs_never_leave_the_internal_model_json(): void
    {
        $json = $this->plan()->toArray();

        $this->assertArrayNotHasKey('cost_eur_cents', $json);
        $this->assertArrayNotHasKey('cost_hour_eur_micro', $json);
    }

    public function test_live_cloud_instance_cost_is_included_in_profit_forecast(): void
    {
        $plan = $this->plan();
        $service = Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'VPS آروان', 'plan' => $plan->public_name,
            'cycle' => 'monthly', 'currency_code' => 'IRT',
            'price' => 1_450_000, 'status' => 'active',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
            'next_due_at' => now()->addMonth(),
        ]);
        CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'arvan',
            'provider_ref' => 'ir-tbz-sh1:paid-vm', 'location_code' => 'ir-tabriz',
            'image_key' => 'ubuntu-24.04', 'hostname' => 'sn-svc-'.$service->id,
            'status' => 'running',
        ]);

        $month = app(BusinessReport::class)->forecast(30)['outgoing']['cloud'];
        $quarter = app(BusinessReport::class)->forecast(90)['outgoing']['cloud'];

        $this->assertSame(1_000_000, $month['monthly']);
        $this->assertSame(1_000_000, $month['toman']);
        $this->assertSame(3_000_000, $quarter['toman']);
        $this->assertSame(0, $month['missing']);
    }

    public function test_existing_cloud_instance_with_unknown_cost_is_reported_as_missing(): void
    {
        $service = Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'VPS مبهم', 'cycle' => 'monthly', 'currency_code' => 'IRT',
            'price' => 1_000_000, 'status' => 'active', 'cloud_image_key' => 'ubuntu-24.04',
        ]);
        CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'arvan',
            'provider_ref' => 'ir-tbz-sh1:unknown-cost', 'location_code' => 'ir-tabriz',
            'image_key' => 'ubuntu-24.04', 'hostname' => 'sn-svc-'.$service->id,
            'status' => 'running',
        ]);

        $cloud = app(BusinessReport::class)->forecast(30)['outgoing']['cloud'];

        $this->assertSame(1, $cloud['missing']);
        $this->assertSame(0, $cloud['toman']);
    }
}
