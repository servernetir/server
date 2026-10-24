<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HourlyBillingHoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        // checkout آزمون vendor مشترک دارد؛ فرمانِ تازه را صریح ثبت می‌کنیم تا
        // نتیجه به classmap قدیمیِ آن vendor وابسته نباشد.
        $this->app[\Illuminate\Contracts\Console\Kernel::class]
            ->registerCommand($this->app->make(\App\Console\Commands\CloudHourlyBillingHold::class));
    }

    /** @return array{Service,Customer} */
    private function delivered(string $provider = 'arvan'): array
    {
        $customer = Customer::create([
            'email' => 'hold'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
        CreditEntry::create([
            'customer_id' => $customer->id, 'currency_code' => 'IRT', 'amount' => 100000,
            'balance_after' => 100000, 'reason' => 'topup', 'source_type' => Customer::class,
            'source_id' => $customer->id, 'note' => 'test',
        ]);
        CloudLocation::create(['code' => 'ir-test', 'country' => 'IR', 'city' => 'Test', 'is_active' => true]);
        $plan = CloudPlan::create([
            'provider' => $provider, 'provider_ref' => 'p1', 'provider_location' => 'ir-test',
            'location_code' => 'ir-test', 'public_name' => 'CV-1-1', 'slug' => 'cv-1-1-ir-test',
            'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20, 'disk_type' => 'ssd',
            'traffic_gb' => 1000, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 100, 'price_eur_cents' => 200, 'price_irt' => 720000,
            'is_active' => true, 'in_stock' => true,
        ]);
        $service = Service::create([
            'customer_id' => $customer->id, 'name' => 'Hold test', 'currency_code' => 'IRT',
            'price' => 0, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => 1000, 'hourly_rate_eur' => 1,
            'status' => 'active', 'provision_status' => 'done', 'cloud_plan_id' => $plan->id,
            'activated_at' => now()->subDay(), 'last_metered_at' => now()->subHours(3),
        ]);
        CloudInstance::create([
            'service_id' => $service->id, 'provider' => $provider, 'provider_ref' => 'srv-'.$service->id,
            'location_code' => 'ir-test', 'image_key' => 'ubuntu-24.04', 'hostname' => 'sn-svc-'.$service->id,
            'ipv4' => '192.0.2.10', 'status' => 'running', 'ready_notified_at' => now()->subDay(),
        ]);

        return [$service, $customer];
    }

    public function test_hold_is_provider_guarded_and_meter_does_not_debit(): void
    {
        [$service, $customer] = $this->delivered('arvan');

        $this->artisan("cloud:hourly-billing-hold {$service->id} --provider=hetzner --reason=incident")
            ->assertFailed();
        $this->assertFalse($service->fresh()->hourlyBillingHeld());

        $this->artisan("cloud:hourly-billing-hold {$service->id} --provider=arvan --reason=incident")
            ->assertSuccessful();
        $this->assertTrue($service->fresh()->hourlyBillingHeld());

        $before = $customer->creditBalance('IRT');
        $this->travel(2)->hours();
        $this->artisan('cloud:meter')->assertSuccessful();

        $this->assertSame($before, $customer->creditBalance('IRT'));
        $this->assertSame(0, CreditEntry::where('source_type', Service::class)
            ->where('source_id', $service->id)->where('reason', 'cloud_hourly')->count());
        $this->assertTrue($service->fresh()->last_metered_at->gt(now()->subMinute()));
    }

    public function test_release_restarts_from_release_time_without_catchup(): void
    {
        [$service, $customer] = $this->delivered('arvan');
        $this->artisan("cloud:hourly-billing-hold {$service->id} --provider=arvan --reason=incident")
            ->assertSuccessful();

        $this->travel(5)->hours();
        $this->artisan("cloud:hourly-billing-hold {$service->id} --provider=arvan --release")
            ->assertSuccessful();
        $this->assertFalse($service->fresh()->hourlyBillingHeld());

        $before = $customer->creditBalance('IRT');
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame($before, $customer->creditBalance('IRT'), 'لحظهٔ رفع نباید پنج ساعت catch-up کند.');

        $this->travel(1)->hours();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame($before - 1000, $customer->creditBalance('IRT'));
    }
}
