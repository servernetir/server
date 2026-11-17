<?php

namespace Tests\Feature;

use App\Models\{CloudImage, CloudInstance, CloudLocation, CloudPlan, CreditEntry, Customer, Invoice, Service, Setting};
use App\Services\Cloud\{CloudPricingSnapshot, GpuCapacityAlternatives, GpuCapacityOrders, SaladClient};
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

class GpuCapacityAlternativesTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private CloudPlan $sourcePlan;
    private CloudPlan $target;
    private Service $source;
    private int $stock = 2;
    private bool $providerFailure = false;
    private $onCapacity = null;

    protected function setUp(): void
    {
        parent::setUp();
        \Tests\Support\NavasanRates::store(['USD' => 90000, 'EUR' => 100000, 'USDT' => 90000]);
        app(CloudPricingSnapshot::class)->publish([90000, 100000, 90000]);
        Setting::putSecret('salad_api_key', 'fake-test-key');
        Setting::put('salad_org', 'fake-test-org'); Setting::put('salad_project', 'fake-test-project');
        Setting::put('salad_priority', 'high');
        Setting::put('pricing_rate_override', '100000'); Setting::put('pricing_usd_rate_override', '90000');
        CloudLocation::updateOrCreate(['code' => 'global-gpu'], ['country' => 'XX', 'is_active' => true]);
        $this->sourcePlan = CloudPlan::create(['provider' => 'salad', 'provider_ref' => 'fake-source-class',
            'provider_location' => 'global', 'location_code' => 'global-gpu', 'slug' => 'fake-source',
            'public_name' => 'CV-8-30', 'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 150,
            'disk_type' => 'ssd', 'arch' => 'x86', 'gpu_model' => 'GTX 1650 (4 GB)', 'gpu_vram_mb' => 4096,
            'gpu_count' => 1, 'is_interruptible' => true, 'cost_eur_cents' => 10000,
            'price_eur_cents' => 20000, 'price_irt' => 20000000, 'cost_hour_eur_micro' => 15000,
            'is_active' => true, 'in_stock' => true]);
        $this->target = $this->sourcePlan->replicate();
        $this->target->fill(['provider_ref' => 'fake-target-class', 'slug' => 'fake-target',
            'gpu_model' => 'GTX 1660 (6 GB)', 'gpu_vram_mb' => 6144, 'price_irt' => 30000000]);
        $this->target->save();
        CloudImage::create(['provider' => 'salad', 'key' => 'gpu-jupyter',
            'provider_ref' => SaladClient::APPS['gpu-jupyter']['ref'], 'kind' => 'app',
            'family' => 'gpu-jupyter', 'label' => 'Jupyter', 'arch' => 'x86', 'is_active' => true]);
        $this->customer = Customer::create(['email' => 'alternative@example.test', 'phone' => '09121234567',
            'password' => bcrypt('fake-test-password'), 'status' => 'active', 'locale' => 'fa']);
        $this->source = app(GpuCapacityOrders::class)->register($this->customer, $this->sourcePlan,
            ['image' => 'gpu-jupyter', 'billing_mode' => 'hourly', 'cycle' => 'monthly',
                'gpu_request_key' => str_repeat('s', 40)], 'mygpu', 'Unallocated request',
            ['ok' => true, 'available' => false, 'checked_at' => now()->toIso8601String()]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($this->providerFailure) { return Http::response([], 503); }
            if (str_contains($request->url(), '/availability/')) {
                if ($this->onCapacity) { ($this->onCapacity)(); }
                $stock = $request['gpu_classes'] === ['fake-source-class'] ? 0 : $this->stock;
                return Http::response(['available_gpu_high' => $stock, 'available_gpu_medium' => $stock,
                    'available_gpu_low' => 0, 'on_call_gpu' => 0]);
            }
            if (str_ends_with($request->url(), '/gpu-classes')) {
                return Http::response(['items' => array_map(fn ($id) => ['id' => $id,
                    'prices' => [['priority' => 'high', 'price' => '0.01']]],
                    ['fake-source-class', 'fake-target-class'])]);
            }
            return Http::response([], 500);
        });
    }

    private function rate(): int
    {
        return app(GpuCapacityAlternatives::class)->preview($this->source, $this->target->id)['rate'];
    }

    private function replace(array $overrides = [])
    {
        return $this->actingAs($this->customer, 'customer')->post(route('account.cloud.gpu-alternative-replace', $this->source),
            array_merge(['plan_id' => $this->target->id, 'quoted_rate_irt' => $this->rate(), 'confirm' => '1'], $overrides));
    }

    private function assertNoPurchase(): void
    {
        $this->assertDatabaseCount('cloud_instances', 0);
        $this->assertSame(0, CreditEntry::where('amount', '<', 0)->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/containers') && $r->method() === 'POST');
    }

    public function test_guest_capacity_reports_exact_current_resources_without_private_details_or_mutation(): void
    {
        $this->get(route('gpu.capacity', ['plan' => $this->target->slug]))->assertOk()
            ->assertJson(['ok' => true, 'available' => true, 'count' => 2])
            ->assertDontSee('fake-target-class')->assertDontSee('fake-test-org')->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/availability/') && $r['cpu'] === 8
            && $r['memory'] === 30720 && $r['storage_amount'] === 161061273600);
        $this->assertDatabaseCount('services', 1); $this->assertDatabaseCount('invoices', 0); $this->assertNoPurchase();
    }

    public function test_alternative_catalog_and_three_locales_are_read_only_and_preserve_resources(): void
    {
        $smaller = $this->target->replicate();
        $smaller->fill(['slug' => 'too-small', 'provider_ref' => 'too-small', 'ram_mb' => 8192]); $smaller->save();
        $disabled = $this->target->replicate();
        $disabled->fill(['slug' => 'disabled', 'provider_ref' => 'disabled', 'admin_disabled' => true]); $disabled->save();
        $this->assertSame([$this->target->id], app(GpuCapacityAlternatives::class)->candidates($this->source)->pluck('id')->all());
        foreach (['', 'en.', 'tr.'] as $prefix) {
            $this->actingAs($this->customer, 'customer')->get(route($prefix.'account.cloud.gpu-alternatives', $this->source))
                ->assertOk()->assertSee('GTX 1660')->assertSee('data-gpu-live-capacity', false)
                ->assertDontSee('gpu-alternatives.title')->assertDontSee('fake-target-class');
        }
        Http::assertNothingSent(); $this->assertNoPurchase();
    }

    public function test_confirmation_preview_shows_current_rate_capacity_and_shortfall_without_changing_the_request(): void
    {
        $rate = $this->rate();
        foreach (['', 'en.', 'tr.'] as $prefix) {
            $this->actingAs($this->customer, 'customer')->get(route($prefix.'account.cloud.gpu-alternative-preview', [$this->source, $this->target]))
                ->assertOk()->assertSee('name="quoted_rate_irt" value="'.$rate.'"', false)
                ->assertSee('name="confirm"', false)->assertDontSee('gpu-alternatives.consent');
        }
        $this->assertSame(GpuCapacityOrders::WAIT, $this->source->fresh()->provision_status);
        $this->assertDatabaseCount('services', 1); $this->assertDatabaseCount('invoices', 0); $this->assertNoPurchase();
    }

    public function test_replacement_is_explicit_idempotent_and_continues_automatically_after_exact_topup(): void
    {
        $balance = $this->source->hourly_rate_irt * 24;
        app(Wallet::class)->credit($this->customer->id, 'IRT', $balance, 'topup', $this->customer);
        $rate = $this->rate();
        $response = $this->replace(); $response->assertRedirect()->assertSessionHasNoErrors();
        $new = Service::where('id', '!=', $this->source->id)->sole();
        $response->assertRedirect(route('account.cloud.show', $new));
        $this->assertSame('cancelled', $this->source->refresh()->status);
        $this->assertSame($new->id, data_get($this->source->provision_meta, 'gpu_capacity_order.replacement_service_id'));
        $this->assertSame($this->source->id, data_get($new->provision_meta, 'gpu_capacity_order.replaces_service_id'));
        $this->assertSame($rate, $new->hourly_rate_irt);
        $this->assertSame($this->target->id, $new->cloud_plan_id);
        $this->assertSame('gpu-jupyter', $new->cloud_image_key);
        $this->actingAs($this->customer, 'customer')->post(route('account.cloud.gpu-alternative-replace', $this->source),
            ['plan_id' => $this->target->id, 'quoted_rate_irt' => $rate, 'confirm' => '1'])->assertRedirect(route('account.cloud.show', $new));
        $this->assertDatabaseCount('services', 2);
        $this->post(route('account.topup.start'), ['gpu_service_id' => $new->id])->assertRedirect();
        $invoice = Invoice::sole();
        $this->assertSame($rate * 24 - $balance, $invoice->total);
        app(Wallet::class)->credit($this->customer->id, 'IRT', $invoice->total, 'topup', $invoice);
        $this->assertSame('skip', app(GpuCapacityOrders::class)->advance($this->source->fresh()));
        $this->assertSame('queued', app(GpuCapacityOrders::class)->advance($new));
        $this->assertSame('pending', $new->fresh()->provision_status);
        $this->assertNoPurchase();
    }

    public function test_missing_explicit_consent_or_tampered_rate_cannot_replace_or_charge(): void
    {
        $this->replace(['confirm' => '0'])->assertSessionHasErrors('confirm');
        $this->replace(['quoted_rate_irt' => 1])->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('services', 1); $this->assertNoPurchase();
    }

    public function test_provider_failure_never_reports_capacity_or_replaces_the_request(): void
    {
        $rate = $this->rate();
        $this->providerFailure = true;
        Cache::flush();
        $this->get(route('gpu.capacity', ['plan' => $this->target->slug]))->assertOk()
            ->assertJson(['ok' => false, 'available' => null, 'count' => null]);
        $this->actingAs($this->customer, 'customer')->post(route('account.cloud.gpu-alternative-replace', $this->source),
            ['plan_id' => $this->target->id, 'quoted_rate_irt' => $rate, 'confirm' => '1'])->assertSessionHasErrors('plan');
        $this->assertSame('awaiting_provision', $this->source->fresh()->status);
        $this->assertDatabaseCount('services', 1); $this->assertNoPurchase();
    }

    public function test_lost_capacity_after_preview_keeps_the_original_request(): void
    {
        $rate = $this->rate(); $this->stock = 0;
        $this->actingAs($this->customer, 'customer')->post(route('account.cloud.gpu-alternative-replace', $this->source),
            ['plan_id' => $this->target->id, 'quoted_rate_irt' => $rate, 'confirm' => '1'])->assertSessionHasErrors('plan');
        $this->assertSame('awaiting_provision', $this->source->fresh()->status);
        $this->assertDatabaseCount('services', 1); $this->assertNoPurchase();
    }

    public function test_worker_claim_during_capacity_probe_wins_over_replacement(): void
    {
        $rate = $this->rate();
        $this->onCapacity = fn () => $this->source->update(['provision_status' => 'running']);
        $this->actingAs($this->customer, 'customer')->post(route('account.cloud.gpu-alternative-replace', $this->source),
            ['plan_id' => $this->target->id, 'quoted_rate_irt' => $rate, 'confirm' => '1'])->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('services', 1); $this->assertSame('running', $this->source->fresh()->provision_status);
        $this->assertNoPurchase();
    }

    public function test_changed_target_during_probe_is_rejected_under_the_service_lock(): void
    {
        $rate = $this->rate();
        $this->onCapacity = fn () => $this->target->update(['price_irt' => 50000000]);
        $this->actingAs($this->customer, 'customer')->post(route('account.cloud.gpu-alternative-replace', $this->source),
            ['plan_id' => $this->target->id, 'quoted_rate_irt' => $rate, 'confirm' => '1'])->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('services', 1); $this->assertNoPurchase();
    }

    public function test_another_customers_request_is_not_visible_or_mutable(): void
    {
        $this->source->update(['customer_id' => $this->customer->id + 100]);
        $this->actingAs($this->customer, 'customer')->get(route('account.cloud.gpu-alternatives', $this->source))->assertNotFound();
        $this->get(route('account.cloud.gpu-alternative-preview', [$this->source, $this->target]))->assertNotFound();
        $this->post(route('account.cloud.gpu-alternative-replace', $this->source),
            ['plan_id' => $this->target->id, 'quoted_rate_irt' => 1000, 'confirm' => '1'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_allocation_marker_instance_or_invoice_prevents_replacement(): void
    {
        $this->source->forceFill(['provision_meta->gpu_capacity_order->allocation_started_at' => now()->toIso8601String()])->save();
        $this->assertFalse(GpuCapacityOrders::replaceable($this->source->fresh()));
        $this->source->forceFill(['provision_meta->gpu_capacity_order->allocation_started_at' => null])->save();
        $instance = CloudInstance::create(['service_id' => $this->source->id, 'provider' => 'salad',
            'provider_ref' => 'sn-svc-'.$this->source->id, 'status' => 'building']);
        $this->assertFalse(GpuCapacityOrders::replaceable($this->source->fresh()));
        $instance->delete();
        Invoice::create(['customer_id' => $this->customer->id, 'service_id' => $this->source->id,
            'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 100, 'tax' => 0, 'total' => 100, 'status' => 'unpaid']);
        $this->assertFalse(GpuCapacityOrders::replaceable($this->source->fresh()));
    }

    public function test_new_customer_approval_gets_a_new_funding_window_without_rewriting_old_request_time(): void
    {
        $originalTime = data_get($this->source->provision_meta, 'gpu_capacity_order.requested_at');
        $this->travel(2)->days();
        \Tests\Support\NavasanRates::store(['USD' => 90000, 'EUR' => 100000, 'USDT' => 90000]);
        app(CloudPricingSnapshot::class)->publish([90000, 100000, 90000]);
        $this->replace()->assertSessionHasNoErrors();
        $new = Service::where('id', '!=', $this->source->id)->sole();
        $this->assertSame($originalTime, data_get($this->source->fresh()->provision_meta, 'gpu_capacity_order.requested_at'));
        $this->assertTrue(GpuCapacityOrders::requestDeadline($new)->gt(now()));
        $this->assertDatabaseCount('invoices', 0); $this->assertNoPurchase();
    }
}
