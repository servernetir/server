<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HourlyStartCreditTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private CloudPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
        Setting::put('pricing_rate_override', '120000');

        $this->customer = Customer::create([
            'email' => 'hourly-start@example.test', 'phone' => '09121112222',
            'password' => 'test-password', 'status' => 'active', 'locale' => 'fa',
        ]);
        CloudLocation::create(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);
        CloudImage::create([
            'provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'min_disk_gb' => 5, 'is_active' => true,
        ]);
        $this->plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'hourly-start', 'provider_location' => 'fsn1',
            'location_code' => 'de-frankfurt', 'public_name' => 'Hourly 64,600',
            'slug' => 'cv-2c-4g-40d-de-frankfurt', 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 20480,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 379,
            'price_eur_cents' => 570, 'price_irt' => 46_512_000,
            'is_active' => true, 'in_stock' => true, 'is_interruptible' => true,
        ]);
        $this->topup(1_936_600);
        $this->assertSame(64_600, $this->plan->hourlyIrt());
    }

    private function topup(int $amount): void
    {
        CreditEntry::create([
            'customer_id' => $this->customer->id, 'currency_code' => 'IRT', 'amount' => $amount,
            'balance_after' => $amount, 'reason' => 'topup', 'source_type' => Customer::class,
            'source_id' => $this->customer->id, 'note' => 'test credit',
        ]);
    }

    private function order()
    {
        return $this->actingAs($this->customer, 'customer')->post('/account/cloud-store', [
            'location' => 'de-frankfurt', 'plan' => $this->plan->slug,
            'image' => 'ubuntu-24.04', 'cycle' => 'monthly', 'billing_mode' => 'hourly',
        ]);
    }

    private function existing(string $instanceStatus, bool $interruptible = true): Service
    {
        $plan = $this->plan;
        if (! $interruptible) {
            $plan = $this->plan->replicate();
            $plan->slug = 'old-regular-hourly';
            $plan->provider_ref = 'old-regular-hourly';
            $plan->is_interruptible = false;
            $plan->save();
        }

        $service = Service::create([
            'customer_id' => $this->customer->id, 'name' => 'Existing hourly server',
            'currency_code' => 'IRT', 'price' => 45_648_000, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => 63_400, 'hourly_rate_eur' => 1,
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
            'last_metered_at' => now()->subHours(2), 'activated_at' => now()->subDay(),
        ]);
        CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'hetzner', 'provider_ref' => 'audit-'.$service->id,
            'location_code' => 'de-frankfurt', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'audit-'.$service->id, 'ipv4' => '192.0.2.1',
            'status' => $instanceStatus, 'ready_notified_at' => now()->subDay(),
        ]);

        return $service;
    }

    public function test_stopped_interruptible_gpu_does_not_raise_the_new_servers_minimum(): void
    {
        $this->existing('off');
        $html = $this->actingAs($this->customer, 'customer')
            ->get('/account/cloud-store?billing_mode=hourly')->assertOk()->getContent();
        $this->assertStringContainsString(cloud_price(1_550_400), $html);
        $this->assertStringNotContainsString(cloud_price(3_072_000), $html);

        $this->order()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, Service::where('customer_id', $this->customer->id)->count());
        $this->assertSame(1_872_000, $this->customer->creditBalance('IRT'));
    }

    public function test_running_gpu_is_included_in_both_preview_and_purchase_gate(): void
    {
        $this->existing('running');
        $html = $this->actingAs($this->customer, 'customer')
            ->get('/account/cloud-store?billing_mode=hourly')->assertOk()->getContent();
        $this->assertStringContainsString(cloud_price(3_072_000), $html);
        $this->assertStringContainsString(cloud_price(1_521_600), $html);

        $message = $this->order()->assertSessionHasErrors('billing_mode')
            ->getSession()->get('errors')->first('billing_mode');
        $this->assertStringContainsString(cloud_price(3_072_000), $message);
        $this->assertSame(1, Service::where('customer_id', $this->customer->id)->count());
        $this->assertSame(1_936_600, $this->customer->creditBalance('IRT'));
    }

    public function test_non_interruptible_server_still_counts_while_off(): void
    {
        $this->existing('off', false);
        $this->order()->assertSessionHasErrors('billing_mode');
        $this->assertSame(1, Service::where('customer_id', $this->customer->id)->count());
    }

    public function test_powering_on_stopped_gpu_requires_credit_for_both_servers(): void
    {
        $old = $this->existing('off');
        $this->order()->assertSessionHasNoErrors();

        $response = $this->actingAs($this->customer, 'customer')
            ->post(route('account.cloud.power', $old), ['action' => 'on'])
            ->assertSessionHasErrors();
        $this->assertStringContainsString(cloud_price(3_072_000),
            $response->getSession()->get('errors')->first());
        $this->assertSame('off', $old->cloudInstance()->firstOrFail()->status);
        Http::assertNothingSent();
    }
}
