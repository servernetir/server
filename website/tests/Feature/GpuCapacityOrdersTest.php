<?php

namespace Tests\Feature;

use App\Models\{CloudImage, CloudInstance, CloudLocation, CloudPlan, CreditEntry, Customer, Invoice, Service, Setting};
use App\Services\Cloud\{GpuCapacityOrders, HourlyStartCredit, SaladClient};
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

class GpuCapacityOrdersTest extends TestCase
{
    use RefreshDatabase;
    private Customer $customer;
    private CloudPlan $plan;
    private int $stock = 0;
    private bool $ready = false;
    private string $state = 'deploying';
    private bool $failStock = false;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::putSecret('salad_api_key', 'test-only');
        Setting::put('salad_org', 'test-org'); Setting::put('salad_project', 'test-project');
        Setting::put('salad_priority', 'high');
        Setting::put('pricing_rate_override', '100000'); Setting::put('pricing_usd_rate_override', '90000');
        CloudLocation::updateOrCreate(['code' => 'global-gpu'], ['country' => 'XX', 'is_active' => true]);
        $this->plan = CloudPlan::create(['provider' => 'salad', 'provider_ref' => 'gpu-class-test', 'provider_location' => 'global',
            'location_code' => 'global-gpu', 'slug' => 'gpu-capacity-test', 'public_name' => 'GTX 1650 (4 GB)',
            'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 150, 'arch' => 'x86', 'disk_type' => 'ssd',
            'gpu_model' => 'GTX 1650', 'gpu_vram_mb' => 4096, 'gpu_count' => 1, 'is_interruptible' => true,
            'cost_eur_cents' => 1000, 'price_eur_cents' => 2000, 'price_irt' => 2000000,
            'cost_hour_eur_micro' => 15000, 'is_active' => true, 'in_stock' => true]);
        CloudImage::create(['provider' => 'salad', 'key' => 'gpu-jupyter', 'provider_ref' => SaladClient::APPS['gpu-jupyter']['ref'],
            'kind' => 'app', 'family' => 'gpu-jupyter', 'label' => 'Jupyter PyTorch', 'arch' => 'x86', 'is_active' => true]);
        $this->customer = Customer::create(['email' => 'gpu-test@example.com', 'phone' => '09121112233',
            'password' => 'test-only-password', 'status' => 'active', 'locale' => 'fa']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/availability/')) {
                return Http::response(['available_gpu_high' => $this->stock, 'available_gpu_low' => 12,
                    'available_gpu_medium' => 12, 'available_gpu_batch' => 12, 'on_call_gpu' => 99], $this->failStock ? 503 : 200);
            }
            if (str_ends_with($request->url(), '/gpu-classes')) {
                return Http::response(['items' => [['id' => 'gpu-class-test', 'is_high_demand' => false,
                    'prices' => [['priority' => 'high', 'price' => '0.01']]]]], 200);
            }
            if (str_ends_with($request->url(), '/instances')) {
                return Http::response(['instances' => $this->ready ? [['state' => 'running', 'started' => true,
                    'ready' => true, 'ssh_ip' => '203.0.113.10']] : []], 200);
            }
            if (str_ends_with($request->url(), '/stop')) {
                $this->state = 'stopped'; $this->ready = false;
                return Http::response([], 202);
            }
            return Http::response(['current_state' => ['status' => $this->state,
                'instance_status_counts' => ['allocating_count' => $this->state === 'deploying' ? 1 : 0]],
                'networking' => ['dns' => 'test-capacity.salad.cloud']], 200);
        });
    }

    private function register(string $key = ''): Service
    {
        return app(GpuCapacityOrders::class)->register($this->customer, $this->plan,
            ['image' => 'gpu-jupyter', 'billing_mode' => 'hourly', 'cycle' => 'monthly',
                'gpu_request_key' => $key ?: str_repeat('x', 40)], 'test-gpu', 'Test request',
            ['ok' => true, 'available' => $this->stock > 0, 'checked_at' => now()->toIso8601String()]);
    }

    private function fund(): void
    {
        app(Wallet::class)->credit($this->customer->id, 'IRT', $this->plan->hourlyIrt() * 40, 'topup', $this->customer);
    }

    public function test_capacity_uses_exact_resources_and_only_selected_priority(): void
    {
        $r = app(SaladClient::class)->capacityFor($this->plan, true);
        $this->assertTrue($r['ok']); $this->assertFalse($r['available']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/availability/') && $r->method() === 'POST'
            && $r['cpu'] === 8 && $r['memory'] === 30720 && $r['storage_amount'] === 161061273600
            && $r['gpu_classes'] === ['gpu-class-test']);
        $this->failStock = true;
        $this->assertNull(app(SaladClient::class)->capacityFor($this->plan, true)['available']);
    }

    public function test_zero_capacity_creates_no_invoice_debit_instance_or_provider_purchase(): void
    {
        $s = $this->register(); $orders = app(GpuCapacityOrders::class);
        $this->travel(3)->days();
        $this->assertSame('waiting', $orders->advance($s));
        $this->assertSame(GpuCapacityOrders::WAIT, $s->fresh()->provision_status);
        $this->assertDatabaseCount('invoices', 0); $this->assertDatabaseCount('cloud_instances', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/containers') && $r->method() === 'POST');
        $this->assertSame(0, app(HourlyStartCredit::class)->existingRate($this->customer->id));
    }

    public function test_replaying_even_an_allocated_request_never_creates_a_second_intent(): void
    {
        $s = $this->register();
        $s->update(['provision_status' => 'done', 'status' => 'active']);
        $this->assertSame($s->id, $this->register()->id);
        $this->assertDatabaseCount('services', 1);
        $this->assertNull(app(GpuCapacityOrders::class)->replay($this->customer->id + 1, str_repeat('x', 40)));
    }

    public function test_capacity_return_queues_allocation_without_charging_and_rechecks_before_create(): void
    {
        $this->fund(); $s = $this->register(); $this->stock = 1;
        $before = app(Wallet::class)->balanceOf($this->customer->id);
        $this->assertSame('queued', app(GpuCapacityOrders::class)->advance($s));
        $this->assertSame('pending', $s->fresh()->provision_status);
        $this->assertSame($before, app(Wallet::class)->balanceOf($this->customer->id));
        $this->stock = 0;
        $this->assertFalse(app(GpuCapacityOrders::class)->allowAllocation($s->fresh(), $this->plan));
        $this->assertSame(GpuCapacityOrders::WAIT, $s->fresh()->provision_status);
        $this->assertDatabaseCount('cloud_instances', 0);
    }

    public function test_increased_price_requires_explicit_confirmation_and_stale_confirmation_is_rejected(): void
    {
        $this->fund(); $s = $this->register(); $approved = $s->hourly_rate_irt; $this->stock = 1;
        $this->plan->update(['price_irt' => $this->plan->price_irt * 2]);
        $orders = app(GpuCapacityOrders::class);
        $this->assertSame('waiting', $orders->advance($s));
        $this->assertSame('price_changed', data_get($s->fresh()->provision_meta, 'gpu_capacity_order.state'));
        $this->assertSame($approved, $s->fresh()->hourly_rate_irt);
        $this->actingAs($this->customer, 'customer')->post(route('account.cloud.gpu-price', $s),
            ['quoted_price_irt' => $approved])->assertSessionHasErrors('price');
        $rate = $orders->currentRate($s->fresh());
        $this->actingAs($this->customer, 'customer')->post(route('account.cloud.gpu-price', $s),
            ['quoted_price_irt' => $rate])->assertRedirect();
        $this->assertSame($rate, $s->fresh()->hourly_rate_irt);
        $this->assertSame(0, CreditEntry::where('amount', '<', 0)->count());
    }

    public function test_first_hour_charged_once_only_after_verified_readiness(): void
    {
        $this->fund(); $s = $this->register();
        $meta = $s->provision_meta; $meta['gpu_capacity_order']['allocation_started_at'] = now()->toIso8601String();
        $s->update(['provision_status' => 'done', 'status' => 'active', 'provision_meta' => $meta]);
        $i = CloudInstance::create(['service_id' => $s->id, 'provider' => 'salad', 'provider_ref' => 'sn-svc-'.$s->id,
            'image_key' => 'gpu-jupyter', 'hostname' => 'test-capacity.salad.cloud', 'status' => 'running']);
        $i->setPassword('test-only-notebook-token'); $i->save();
        $this->assertFalse($i->isDelivered()); $this->assertFalse($i->readyForNotice());
        $this->assertSame('waiting', app(GpuCapacityOrders::class)->advance($s));
        $this->assertSame(0, CreditEntry::where('amount', '<', 0)->count());
        $this->ready = true; $this->state = 'running';
        $this->assertSame('ready', app(GpuCapacityOrders::class)->advance($s));
        $this->assertSame('skip', app(GpuCapacityOrders::class)->advance($s));
        $this->assertSame(1, CreditEntry::where('amount', '<', 0)->count());
        $this->assertSame(-$s->hourly_rate_irt, (int) CreditEntry::where('reason', 'cloud_hourly')->sum('amount'));
        $this->assertTrue($i->fresh()->isDelivered());
    }

    public function test_timeout_after_allocation_stops_without_debit_and_free_wait_has_no_delivery_deadline(): void
    {
        $s = $this->register(); $meta = $s->provision_meta;
        $meta['gpu_capacity_order']['allocation_started_at'] = now()->subMinutes(121)->toIso8601String();
        $s->update(['provision_status' => 'done', 'status' => 'active', 'provision_meta' => $meta]);
        CloudInstance::create(['service_id' => $s->id, 'provider' => 'salad', 'provider_ref' => 'sn-svc-'.$s->id,
            'image_key' => 'gpu-jupyter', 'status' => 'building']);
        $this->assertSame('stopped', app(GpuCapacityOrders::class)->advance($s));
        $this->assertSame('cancelled', $s->fresh()->status);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertFalse(data_get($s->fresh()->provision_meta, 'gpu_capacity_order.stop_pending'));
    }

    public function test_group_allocation_is_visible_even_before_any_instance_exists(): void
    {
        $r = app(SaladClient::class)->serverStatus('sn-svc-test');
        $this->assertSame(['phase' => 'allocating', 'pull_pct' => null], $r['build']);
    }

    public function test_waiting_panel_and_readonly_status_do_not_create_or_charge(): void
    {
        $s = $this->register();
        $this->actingAs($this->customer, 'customer')->get(route('account.cloud.show', $s))
            ->assertOk()->assertSee('data-gpu-capacity-wait', false)->assertSee('هیچ هزینه‌ای', false);
        $this->actingAs($this->customer, 'customer')->get(route('account.cloud.status', $s))
            ->assertOk()->assertJson(['waiting_capacity' => true, 'ready' => false]);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseCount('cloud_instances', 0);
    }

    public function test_checkout_registers_unpaid_intent_and_replays_without_api_or_invoice(): void
    {
        $body = ['location' => 'global-gpu', 'plan' => $this->plan->slug, 'image' => 'gpu-jupyter',
            'cycle' => 'monthly', 'billing_mode' => 'hourly', 'label' => 'test-checkout',
            'gpu_request_key' => str_repeat('z', 40), 'quoted_hourly_irt' => $this->plan->hourlyIrt()];
        $this->actingAs($this->customer, 'customer')->post(route('account.cloud.store.place'), $body)->assertRedirect();
        $this->assertDatabaseCount('services', 1);
        $sentBefore = Http::recorded()->count();
        $this->actingAs($this->customer, 'customer')->post(route('account.cloud.store.place'), $body)->assertRedirect();
        $this->assertSame($sentBefore, Http::recorded()->count());
        $this->assertDatabaseCount('services', 1); $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_scheduler_finds_nested_json_intents_and_waits_without_payment(): void
    {
        $s = $this->register();
        $this->assertSame([$s->id], app(GpuCapacityOrders::class)->candidates()->pluck('id')->all());
        $this->artisan('cloud:advance-gpu-capacity')->assertExitCode(0);
        $this->assertSame(GpuCapacityOrders::WAIT, $s->fresh()->provision_status);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseCount('cloud_instances', 0);
    }

    public function test_two_waiting_requests_cannot_start_against_credit_for_only_one(): void
    {
        app(Wallet::class)->credit($this->customer->id, 'IRT', $this->plan->hourlyIrt() * 30, 'topup', $this->customer);
        $a = $this->register(str_repeat('a', 40)); $b = $this->register(str_repeat('b', 40));
        $this->assertNotSame($a->id, $b->id);
        $this->stock = 1; $orders = app(GpuCapacityOrders::class);
        $this->assertSame('queued', $orders->advance($a));
        $this->assertSame('waiting', $orders->advance($b));
        $this->assertSame('insufficient_credit', data_get($b->fresh()->provision_meta, 'gpu_capacity_order.state'));
        $this->assertSame(0, CreditEntry::where('amount', '<', 0)->count());
    }

    public function test_monthly_request_gets_an_unpaid_invoice_only_after_capacity_returns(): void
    {
        $orders = app(GpuCapacityOrders::class);
        $s = $orders->register($this->customer, $this->plan, ['image' => 'gpu-jupyter', 'cycle' => 'monthly',
            'billing_mode' => 'cycle', 'gpu_request_key' => str_repeat('m', 40)], 'cycle-gpu', 'Cycle request',
            ['ok' => true, 'available' => false, 'checked_at' => now()->toIso8601String()]);
        $this->assertSame('waiting', $orders->advance($s)); $this->assertDatabaseCount('invoices', 0);
        $this->stock = 1; Cache::flush();
        $this->assertSame('invoice', $orders->advance($s));
        $this->assertSame('pending', $s->fresh()->status);
        $this->assertDatabaseCount('invoices', 1); $this->assertDatabaseCount('cloud_instances', 0);
        $this->assertSame(0, (int) Invoice::where('service_id', $s->id)->value('paid'));
    }

    public function test_cancelled_waiting_request_never_resumes_or_charges(): void
    {
        $s = $this->register();
        $this->actingAs($this->customer, 'customer')->post(route('account.services.cancel', $s))->assertRedirect();
        $this->stock = 1;
        $this->assertSame('skip', app(GpuCapacityOrders::class)->advance($s));
        $this->assertDatabaseCount('cloud_instances', 0); $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_allocation_timeout_without_a_local_instance_closes_without_payment(): void
    {
        $s = $this->register(); $meta = $s->provision_meta;
        $meta['gpu_capacity_order']['allocation_started_at'] = now()->subMinutes(121)->toIso8601String();
        $s->update(['provision_status' => 'failed', 'provision_meta' => $meta]);
        $this->assertSame('stopped', app(GpuCapacityOrders::class)->advance($s));
        $this->assertSame('cancelled', $s->fresh()->status);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_a_running_jupyter_without_access_token_is_not_charged(): void
    {
        $this->fund(); $s = $this->register(); $meta = $s->provision_meta;
        $meta['gpu_capacity_order']['allocation_started_at'] = now()->toIso8601String();
        $s->update(['provision_status' => 'done', 'status' => 'active', 'provision_meta' => $meta]);
        CloudInstance::create(['service_id' => $s->id, 'provider' => 'salad', 'provider_ref' => 'sn-svc-'.$s->id,
            'image_key' => 'gpu-jupyter', 'hostname' => 'test-capacity.salad.cloud', 'status' => 'running']);
        $this->ready = true; $this->state = 'running';
        $this->assertSame('waiting', app(GpuCapacityOrders::class)->advance($s));
        $this->assertSame(0, CreditEntry::where('amount', '<', 0)->count());
    }

    public function test_price_confirmation_cannot_modify_another_customers_request(): void
    {
        $s = $this->register(); $s->update(['customer_id' => $this->customer->id + 1]);
        $this->actingAs($this->customer, 'customer')->post(route('account.cloud.gpu-price', $s),
            ['quoted_price_irt' => 10000])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_cycle_invoice_panel_and_three_language_waiting_views_render(): void
    {
        $s = $this->register();
        foreach (['fa' => '', 'en' => 'en.', 'tr' => 'tr.'] as $locale => $prefix) {
            $response = $this->actingAs($this->customer, 'customer')->get(route($prefix.'account.cloud.show', $s));
            $response->assertOk()->assertSee('data-gpu-capacity-wait', false)->assertDontSee('ui.gpu_', false);
            if (getenv('GPU_VIEW_EXPORT')) { file_put_contents(getenv('GPU_VIEW_EXPORT').'/waiting-'.$locale.'.html', $response->getContent()); }
        }
        $s->update(['billing_mode' => 'cycle']); $this->stock = 1;
        $this->assertSame('invoice', app(GpuCapacityOrders::class)->advance($s));
        $this->actingAs($this->customer, 'customer')->get(route('account.cloud.show', $s))
            ->assertOk()->assertSee('data-gpu-payment-wait', false);
    }

    public function test_gpu_download_phase_and_measured_percentage_are_rendered(): void
    {
        $s = $this->register(); $s->update(['provision_status' => 'done', 'status' => 'active']);
        $i = CloudInstance::create(['service_id' => $s->id, 'provider' => 'salad', 'provider_ref' => 'sn-svc-'.$s->id,
            'image_key' => 'gpu-jupyter', 'status' => 'building',
            'meta' => ['gpu_build' => ['phase' => 'downloading', 'pull_pct' => 24]]]);
        $this->assertSame('downloading', $i->stage()); $this->assertSame(3, $i->stageIndex());
        $response = $this->actingAs($this->customer, 'customer')->get(route('account.cloud.show', $s));
        $response->assertOk()->assertSee('data-stage="3"', false)->assertSee('24', false);
        if (getenv('GPU_VIEW_EXPORT')) {
            file_put_contents(getenv('GPU_VIEW_EXPORT').'/download.html', $response->getContent());
            $store = $this->get(route('account.cloud.store', ['location'=>'global-gpu','billing'=>'hourly']));
            $store->assertOk(); file_put_contents(getenv('GPU_VIEW_EXPORT').'/store.html', $store->getContent());
        }
    }

    public function test_paused_application_remains_unpaid_even_when_capacity_exists(): void
    {
        $this->fund(); $s=$this->register(); $this->stock=1;
        Setting::put('salad_jupyter_sales_paused', '1');
        $orders=app(GpuCapacityOrders::class);
        $this->assertSame('waiting', $orders->advance($s));
        $this->assertSame('unavailable', data_get($s->fresh()->provision_meta, 'gpu_capacity_order.state'));
        $this->assertFalse($orders->allowAllocation($s->fresh(), $this->plan));
        $this->assertSame(0, CreditEntry::where('amount', '<', 0)->count());
    }
}
