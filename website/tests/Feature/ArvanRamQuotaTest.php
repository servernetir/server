<?php

namespace Tests\Feature;

use App\Models\{CloudImage, CloudInstance, CloudLocation, CloudPlan, CreditEntry, Customer, Invoice, Service, Setting};
use App\Services\Cloud\{CloudFraudGuard, CloudManager, CloudProvider, CloudProvisioner};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use Mockery;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class ArvanRamQuotaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalogPricing;

    private const ERROR = 'You have reached RAM size limit';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
        $this->seedCatalogPricing();
        Setting::put('arvan_hourly_sales_enabled', '1');
        CloudLocation::create(['code' => 'ir-tehran', 'country' => 'IR', 'city' => 'Tehran', 'is_active' => true]);
    }

    private function plan(array $overrides = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider' => 'arvan', 'provider_ref' => 'eco-1-1-0', 'provider_location' => 'ir-thr-ba1',
            'location_code' => 'ir-tehran', 'public_name' => 'CV-1-1', 'slug' => 'cv-1c-1g-25d-ir-tehran',
            'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 25, 'disk_type' => 'ssd', 'arch' => 'x86',
            'cpu_kind' => 'shared', 'cost_eur_cents' => 379, 'cost_hour_eur_micro' => 10000,
            'price_eur_cents' => 1000, 'price_irt' => 1000000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $overrides));
    }

    private function quarantine(CloudPlan $plan, string $message = self::ERROR): void
    {
        (new \ReflectionMethod(CloudProvisioner::class, 'quarantineProvider'))
            ->invoke(app(CloudProvisioner::class), $plan, $message);
    }

    public function test_exact_provider_response_closes_only_its_account_and_preserves_manual_notes(): void
    {
        $small = $this->plan();
        $large = $this->plan(['provider_ref' => 'large', 'ram_mb' => 65536, 'slug' => 'large']);
        $manual = $this->plan(['provider_ref' => 'manual', 'admin_disabled' => true, 'admin_note' => 'manual decision']);
        $peer = $this->plan(['provider' => 'hetzner', 'provider_ref' => 'peer', 'slug' => 'peer']);

        $this->quarantine($small);

        $this->assertTrue(CloudPlan::providerIsDisabled('arvan'));
        $this->assertFalse(CloudPlan::providerIsDisabled('hetzner'));
        foreach ([$small, $large] as $plan) {
            $this->assertTrue($plan->fresh()->admin_disabled);
            $this->assertTrue($plan->fresh()->is_active);
            $this->assertStringStartsWith(CloudProvisioner::QUARANTINE_PREFIX, $plan->fresh()->admin_note);
        }
        $this->assertSame('manual decision', $manual->fresh()->admin_note);
        $this->assertFalse($peer->fresh()->admin_disabled);
        $this->assertSame([$peer->id], CloudPlan::sellable()->pluck('id')->all());
    }

    public function test_new_catalogue_rows_and_previously_loaded_checkout_cannot_bypass_quota_block(): void
    {
        $plan = $this->plan();
        $this->assertTrue(CloudPlan::offers()->has($plan->slug));
        $this->quarantine($plan);
        $new = $this->plan(['provider_ref' => 'newly-synced', 'slug' => 'newly-synced']);
        $this->assertFalse(CloudPlan::offers()->has($new->slug));
        $this->assertNull(CloudPlan::bestForSlug($plan->slug, true));

        $customer = Customer::create(['email' => 'quota@example.test', 'password' => 'test-only', 'status' => 'active', 'locale' => 'fa']);
        Setting::put('iran_sales_open_to_unverified', '1');
        $before = [Service::count(), Invoice::count(), CreditEntry::count(), CloudInstance::count()];
        $this->actingAs($customer, 'customer')->post(route('account.cloud.store.place'), [
            'location' => $plan->location_code, 'plan' => $plan->slug,
            'image' => 'ubuntu-24.04', 'cycle' => 'monthly', 'billing_mode' => 'hourly',
        ])->assertRedirect()->assertSessionHasErrors();
        $this->assertSame($before, [Service::count(), Invoice::count(), CreditEntry::count(), CloudInstance::count()]);
        Http::assertNothingSent();
    }

    public function test_real_delivery_failure_blocks_subsequent_retry_without_another_create(): void
    {
        $plan = $this->plan();
        CloudImage::create(['provider' => 'arvan', 'provider_ref' => 'ubuntu-test', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'label' => 'Ubuntu', 'arch' => 'x86', 'is_active' => true]);
        $customer = Customer::create(['email' => 'delivery@example.test', 'password' => 'test-only', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'cloud_plan_id' => $plan->id,
            'cloud_image_key' => 'ubuntu-24.04', 'name' => 'Test VPS', 'price' => 1000000,
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'awaiting_provision', 'provision_status' => 'pending']);
        $this->mock(CloudFraudGuard::class)->shouldReceive('check')->andReturn(['hold' => false, 'reason' => '']);
        $driver = Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('isConfigured')->andReturnTrue();
        $driver->shouldReceive('capabilities')->andReturn([]);
        $driver->shouldReceive('createServer')->once()->andReturn(['ok' => false, 'message' => self::ERROR,
            'raw' => ['status' => 400, 'detail' => '{"message":"'.self::ERROR.'","errors":[]}']]);
        $this->partialMock(CloudManager::class, fn ($m) => $m->shouldReceive('forPlan')->andReturn($driver));

        $this->assertFalse(app(CloudProvisioner::class)->provision($service));
        $this->assertSame('failed', $service->fresh()->provision_status);
        $this->assertTrue(CloudPlan::providerIsDisabled('arvan'));
        $this->assertFalse(app(CloudProvisioner::class)->provision($service->fresh()));
        $this->assertSame(1, CloudInstance::where('service_id', $service->id)->count());
    }

    public function test_transient_inventory_failure_does_not_close_sales(): void
    {
        $plan = $this->plan();
        $this->quarantine($plan, 'timeout while connecting');
        $this->assertFalse(CloudPlan::providerIsDisabled('arvan'));
        $this->assertFalse($plan->fresh()->admin_disabled);
    }

    public function test_repeated_ram_error_is_idempotent_and_reopening_requires_both_existing_controls(): void
    {
        $plan = $this->plan();
        $this->quarantine($plan);
        $settings = Setting::get('cloud_disabled_providers');
        $note = $plan->fresh()->admin_note;
        $this->quarantine($plan->fresh());
        $this->assertSame($settings, Setting::get('cloud_disabled_providers'));
        $this->assertSame($note, $plan->fresh()->admin_note);
        CloudPlan::setProviderDisabled('arvan', false);
        $this->assertFalse(CloudPlan::offers()->has($plan->slug));
        $plan->update(['admin_disabled' => false, 'admin_note' => null]);
        $this->assertTrue(CloudPlan::offers()->has($plan->slug));
    }
}
