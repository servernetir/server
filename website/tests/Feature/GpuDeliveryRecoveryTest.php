<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudDeliveryWatch;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Cloud\GpuDeliveryRecovery;
use App\Services\Cloud\SaladClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GpuDeliveryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Setting::putSecret('salad_api_key', 'test-only-key');
        Setting::put('salad_org', 'servernet');
        Setting::put('salad_project', 'prod');
    }

    private function order(string $app = 'gpu-jupyter', int $age = 121): Service
    {
        $customer = Customer::create(['email' => uniqid().'@example.com', 'password' => 'test-only', 'status' => 'active']);
        $plan = CloudPlan::create(['provider' => 'salad', 'provider_ref' => uniqid('test-gpu-'), 'slug' => uniqid(),
            'public_name' => 'RTX 4090', 'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 50,
            'location_code' => 'global-gpu', 'gpu_model' => 'RTX 4090', 'is_interruptible' => true,
            'cost_eur_cents' => 1, 'price_irt' => 14000, 'is_active' => true]);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'GPU test',
            'status' => 'active', 'provision_status' => 'done', 'cloud_plan_id' => $plan->id,
            'cloud_image_key' => $app, 'billing_mode' => 'hourly', 'cycle' => 'hourly',
            'hourly_rate_irt' => 14000, 'hold_reserve_irt' => 5000,
            'activated_at' => now()->subMinutes($age), 'created_at' => now()->subMinutes($age)]);
        $service->forceFill(['created_at' => now()->subMinutes($age)])->save();
        $debit = CreditEntry::create(['customer_id' => $customer->id, 'currency_code' => 'IRT',
            'amount' => -14000, 'balance_after' => 0, 'reason' => 'cloud_hourly',
            'source_type' => Service::class, 'source_id' => $service->id, 'created_at' => now()->subMinutes($age)]);
        $debit->forceFill(['created_at' => now()->subMinutes($age)])->save();
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'salad',
            'provider_ref' => 'sn-svc-'.$service->id, 'image_key' => $app, 'status' => 'building',
            'hostname' => 'test.salad.cloud', 'created_at' => now()->subMinutes($age)]);
        $instance->forceFill(['created_at' => now()->subMinutes($age)])->save();
        return $service;
    }

    private function remote(string $groupState = 'running', bool $ready = false, bool $stopImmediate = true): void
    {
        $stopped = false;
        Http::fake(function ($request) use ($groupState, $ready, $stopImmediate, &$stopped) {
            if (str_ends_with($request->url(), '/stop')) {
                $stopped = $stopImmediate;
                return Http::response([], 202);
            }
            if (str_ends_with($request->url(), '/instances')) {
                return Http::response(['instances' => $stopped ? [] : [['state' => 'running', 'started' => true, 'ready' => $ready]]]);
            }
            return Http::response(['current_state' => ['status' => $stopped ? 'stopped' : $groupState],
                'networking' => ['dns' => 'test.salad.cloud']]);
        });
    }

    public static function apps(): array
    {
        return [['gpu-comfyui'], ['gpu-ollama'], ['gpu-jupyter']];
    }

    #[DataProvider('apps')]
    public function test_timeout_cancels_stops_and_refunds_each_app_once(string $app): void
    {
        $service = $this->order($app, 120);
        $this->remote();
        $this->artisan('cloud:recover-gpu-delivery')->assertSuccessful();
        $this->artisan('cloud:recover-gpu-delivery')->assertSuccessful();
        $this->assertSame('cancelled', $service->fresh()->status);
        $this->assertSame('none', $service->fresh()->provision_status);
        $this->assertSame(0, (int) $service->fresh()->hold_reserve_irt);
        $this->assertSame('off', $service->fresh()->cloudInstance->status);
        $this->assertSame(0, (int) CreditEntry::where('customer_id', $service->customer_id)->sum('amount'));
        $this->assertSame(1, CreditEntry::where('source_id', $service->id)->where('reason', 'refund')->count());
        $this->assertFalse(data_get($service->fresh()->provision_meta, 'gpu_delivery_recovery.pending_stop'));
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame(1, CreditEntry::where('source_id', $service->id)->where('amount', '<', 0)->count());
    }

    public function test_normal_cold_start_does_not_refund_or_stop_before_deadline(): void
    {
        $service = $this->order(age: 119);
        $this->remote();
        $this->assertSame('waiting', app(GpuDeliveryRecovery::class)->recover($service)['action']);
        $this->assertSame('active', $service->fresh()->status);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertSame(1, CreditEntry::count());
        $this->assertSame(0, app(GpuDeliveryRecovery::class)->candidates()->count());
    }

    public function test_confirmed_build_failure_refunds_without_waiting_two_hours(): void
    {
        $service = $this->order(age: 20);
        $service->cloudInstance->update(['status' => 'error']);
        $this->remote('failed');
        $this->artisan('cloud:recover-gpu-delivery')->assertSuccessful();
        $this->assertSame('failed', data_get($service->fresh()->provision_meta, 'gpu_delivery_recovery.reason'));
        $this->assertSame('cancelled', $service->fresh()->status);
    }

    public function test_local_failed_create_with_confirmed_missing_group_is_cancelled(): void
    {
        $service = $this->order(age: 2);
        $service->update(['provision_status' => 'failed']);
        $service->cloudInstance->update(['provider_ref' => null]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response([], 404)]);
        $this->artisan('cloud:recover-gpu-delivery')->assertSuccessful();
        $this->assertSame('cancelled', $service->fresh()->status);
        $this->assertFalse(data_get($service->fresh()->provision_meta, 'gpu_delivery_recovery.pending_stop'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sn-svc-'.$service->id));
    }

    public function test_stale_local_building_cannot_cancel_a_live_ready_app(): void
    {
        $service = $this->order();
        $this->remote(ready: true);
        $this->assertSame('ready', app(GpuDeliveryRecovery::class)->recover($service)['action']);
        $this->assertSame('active', $service->fresh()->status);
        $this->assertNotNull(data_get($service->fresh()->cloudInstance->meta, 'gpu_first_ready_at'));
        $this->assertSame(1, CreditEntry::count());
        $this->assertSame(0, app(GpuDeliveryRecovery::class)->candidates()->count());
    }

    public function test_readiness_that_arrives_during_live_check_is_rechecked_before_money_changes(): void
    {
        $service = $this->order();
        Http::fake(function ($request) use ($service) {
            if (str_ends_with($request->url(), '/instances')) {
                $service->cloudInstance->update(['status' => 'running']);
                return Http::response(['instances' => []]);
            }
            return Http::response(['current_state' => ['status' => 'running']]);
        });
        $this->assertSame('skip', app(GpuDeliveryRecovery::class)->recover($service)['action']);
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(1, CreditEntry::count());
    }

    public function test_previous_success_is_preserved_across_migration_and_stale_metadata(): void
    {
        $service = $this->order();
        $service->cloudInstance->update(['status' => 'running']);
        $first = data_get($service->cloudInstance->meta, 'gpu_first_ready_at');
        $service->cloudInstance->update(['status' => 'building', 'meta' => ['build' => ['phase' => 'downloading']]]);
        $this->assertSame($first, data_get($service->cloudInstance->meta, 'gpu_first_ready_at'));
        Http::fake();
        $this->assertSame('skip', app(GpuDeliveryRecovery::class)->recover($service)['action']);
        Http::assertNothingSent();
        $this->assertNull(CloudDeliveryWatch::reasonFor($service->fresh()));
    }

    public function test_off_with_a_hostname_is_not_evidence_of_an_earlier_delivery(): void
    {
        $service = $this->order();
        $service->cloudInstance->update(['status' => 'off']);
        $this->remote();
        $this->assertSame('cancelled', app(GpuDeliveryRecovery::class)->recover($service)['action']);
    }

    public function test_previously_returned_credit_is_subtracted_from_the_refund(): void
    {
        $service = $this->order();
        CreditEntry::create(['customer_id' => $service->customer_id, 'currency_code' => 'IRT',
            'amount' => 4000, 'balance_after' => 0, 'reason' => 'cloud_hourly_refund',
            'source_type' => Service::class, 'source_id' => $service->id]);
        $this->remote();
        $result = app(GpuDeliveryRecovery::class)->recover($service);
        $this->assertSame(['IRT' => 10000], $result['refunds']);
        $this->assertSame(0, (int) CreditEntry::sum('amount'));
    }

    public function test_cycle_invoices_return_actual_paid_amounts_in_the_original_currencies(): void
    {
        $service = $this->order();
        CreditEntry::query()->delete();
        $service->update(['billing_mode' => 'cycle', 'cycle' => 'monthly']);
        $invoice = Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'currency_code' => 'EUR', 'total' => 8000, 'paid' => 2300, 'status' => 'partial', 'paid_at' => now()->subHours(3)]);
        $other = Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'currency_code' => 'IRT', 'total' => 15000, 'paid' => 15000, 'status' => 'paid', 'paid_at' => now()->subHours(3)]);
        $this->remote();
        $result = app(GpuDeliveryRecovery::class)->recover($service);
        $this->assertSame(['EUR' => 2300, 'IRT' => 15000], $result['refunds']);
        $this->assertSame('refunded', $invoice->fresh()->status);
        $this->assertSame('refunded', $other->fresh()->status);
        $this->assertSame('skip', app(GpuDeliveryRecovery::class)->recover($service)['action']);
        $this->assertSame(2, CreditEntry::where('reason', 'refund')->count());
    }

    public function test_deadline_starts_at_payment_not_an_old_unpaid_order_or_new_instance(): void
    {
        $service = $this->order(age: 10);
        $service->forceFill(['created_at' => now()->subDays(5), 'activated_at' => now()->subDays(5)])->save();
        $this->remote();
        $this->assertSame('waiting', app(GpuDeliveryRecovery::class)->recover($service)['action']);
        CreditEntry::query()->update(['created_at' => now()->subMinutes(120)]);
        $service->cloudInstance->forceFill(['created_at' => now()])->save();
        $this->assertSame('cancelled', app(GpuDeliveryRecovery::class)->recover($service)['action']);
    }

    public function test_api_outage_waits_before_deadline_and_retries_stop_after_refund(): void
    {
        $service = $this->order(age: 119);
        Http::fake(['*' => Http::response([], 503)]);
        $recovery = app(GpuDeliveryRecovery::class);
        $this->assertSame('waiting', $recovery->recover($service)['action']);
        $this->travel(1)->minutes();
        $result = $recovery->recover($service);
        $this->assertSame('cancelled', $result['action']);
        $this->assertFalse($result['stop_confirmed']);
        $this->assertTrue(data_get($service->fresh()->provision_meta, 'gpu_delivery_recovery.pending_stop'));
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response([], 404)]);
        $this->artisan('cloud:recover-gpu-delivery')->assertSuccessful();
        $this->assertFalse(data_get($service->fresh()->provision_meta, 'gpu_delivery_recovery.pending_stop'));
        $this->assertSame(1, CreditEntry::where('reason', 'refund')->count());
    }

    public function test_stop_accepted_is_not_confirmed_until_no_nodes_remain(): void
    {
        $service = $this->order();
        $this->remote(stopImmediate: false);
        $result = app(GpuDeliveryRecovery::class)->recover($service);
        $this->assertFalse($result['stop_confirmed']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function ($request) {
            return Http::response(str_ends_with($request->url(), '/instances') ? ['instances' => []] : ['current_state' => ['status' => 'stopped']]);
        });
        $this->artisan('cloud:recover-gpu-delivery')->assertSuccessful();
        $this->assertFalse(data_get($service->fresh()->provision_meta, 'gpu_delivery_recovery.pending_stop'));
    }

    public function test_unknown_node_response_never_confirms_zero_instances(): void
    {
        Http::fake(['*' => Http::response(['current_state' => ['status' => 'stopped']])]);
        $this->assertFalse(app(SaladClient::class)->gpuDeliveryState('test')['stopped']);
    }

    public function test_unpaid_internal_and_non_gpu_orders_are_untouched(): void
    {
        $unpaid = $this->order();
        CreditEntry::where('source_id', $unpaid->id)->delete();
        $internal = $this->order();
        config(['billing.internal_service_ids' => [$internal->id]]);
        $vps = $this->order();
        $vps->update(['cloud_image_key' => 'ubuntu-24.04']);
        Http::fake();
        foreach ([$unpaid, $internal, $vps] as $service) {
            $this->assertSame('skip', app(GpuDeliveryRecovery::class)->recover($service)['action']);
            $this->assertSame('active', $service->fresh()->status);
        }
        Http::assertNothingSent();
        $this->assertSame(0, app(GpuDeliveryRecovery::class)->candidates()->count());
    }

    public function test_dry_run_is_read_only_and_provision_workers_cannot_resurrect_cancellation(): void
    {
        $service = $this->order();
        $this->remote();
        $this->artisan('cloud:recover-gpu-delivery --dry-run')->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(1, CreditEntry::count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $stale = $service->fresh();
        app(GpuDeliveryRecovery::class)->recover($service);
        $provisioner = app(CloudProvisioner::class);
        $this->assertFalse($provisioner->provision($stale));
        (new \ReflectionMethod($provisioner, 'fail'))->invoke($provisioner, $stale, 'late failure');
        (new \ReflectionMethod($provisioner, 'finalize'))->invoke($provisioner, $stale, $service->cloudInstance, $service->cloudPlan, null);
        $this->assertSame('cancelled', $service->fresh()->status);
        $this->assertSame('none', $service->fresh()->provision_status);
        $this->assertSame(1, CreditEntry::where('reason', 'refund')->count());
    }

    public function test_gpu_watch_allows_one_hour_and_accepts_gateway_delivery_without_ssh_ip(): void
    {
        $service = $this->order(age: 45);
        $this->assertFalse(CloudDeliveryWatch::stalled()->contains('id', $service->id));
        $this->travel(16)->minutes();
        $this->assertTrue(CloudDeliveryWatch::stalled()->contains('id', $service->id));
        $service->cloudInstance->update(['status' => 'running', 'ready_notified_at' => now()]);
        $this->assertNull($service->cloudInstance->ipv4);
        $this->assertNull(CloudDeliveryWatch::reasonFor($service->fresh()));
    }

    public function test_financial_error_rolls_back_cancellation_and_never_stops_before_refund_commits(): void
    {
        $service = $this->order();
        $this->remote();
        $this->mock(\App\Services\Finance\Wallet::class, function ($mock) {
            $mock->shouldReceive('credit')->once()->andThrow(new \RuntimeException('test wallet failure'));
        });
        $this->artisan('cloud:recover-gpu-delivery')->assertFailed();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(1, CreditEntry::count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_recent_inflight_creation_is_not_cancelled_mid_request(): void
    {
        $service = $this->order();
        $service->update(['provision_status' => 'running']);
        Http::fake();
        $this->assertSame('skip', app(GpuDeliveryRecovery::class)->recover($service)['action']);
        Http::assertNothingSent();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(1, CreditEntry::count());
    }

    public function test_paid_gpu_with_no_queue_or_manual_review_is_also_refunded_at_deadline(): void
    {
        foreach (['none', 'manual'] as $status) {
            $service = $this->order();
            $service->update(['provision_status' => $status]);
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(['*' => Http::response([], 404)]);
            $this->artisan('cloud:recover-gpu-delivery')->assertSuccessful();
            $this->assertSame('cancelled', $service->fresh()->status);
            $this->assertSame(1, CreditEntry::where('source_id', $service->id)->where('reason', 'refund')->count());
        }
    }

    public function test_first_delivery_history_survives_replacement_of_the_instance_row(): void
    {
        $service = $this->order();
        $service->cloudInstance->update(['status' => 'running']);
        $this->assertNotNull(data_get($service->fresh()->provision_meta, 'gpu_first_ready_at'));
        $service->cloudInstance->delete();
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'salad', 'image_key' => 'gpu-jupyter', 'status' => 'building']);
        Http::fake();
        $this->artisan('cloud:recover-gpu-delivery')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_late_payment_on_a_refunded_invoice_goes_to_credit_without_reviving_the_order(): void
    {
        $service = $this->order();
        CreditEntry::query()->delete();
        $invoice = Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'currency_code' => 'IRT', 'total' => 20000, 'paid' => 5000, 'status' => 'partial', 'paid_at' => now()->subHours(3)]);
        $unpaid = Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'currency_code' => 'IRT', 'total' => 20000, 'paid' => 0, 'status' => 'unpaid']);
        $payment = \App\Models\Payment::create(['customer_id' => $service->customer_id, 'invoice_id' => $invoice->id,
            'gateway' => 'bank', 'amount' => 15000, 'currency_code' => 'IRT', 'status' => 'pending']);
        $this->remote();
        app(GpuDeliveryRecovery::class)->recover($service);
        $this->assertSame('canceled', $unpaid->fresh()->status);
        $payments = app(\App\Services\Payment\PaymentService::class);
        $this->assertTrue($payments->settleConfirmed($payment, 'test-receipt')->ok);
        $this->assertTrue($payments->settleConfirmed($payment->fresh(), 'test-receipt')->ok);
        $this->assertSame('refunded', $invoice->fresh()->status);
        $this->assertSame(5000, $invoice->fresh()->paid);
        $this->assertSame('cancelled', $service->fresh()->status);
        $this->assertSame(20000, (int) CreditEntry::sum('amount'));
        $this->assertSame(1, CreditEntry::where('reason', 'adjustment')->count());
    }

    public function test_a_waiting_failed_order_cannot_starve_the_next_delivery_timeout(): void
    {
        $waiting = $this->order(age: 2);
        $waiting->update(['provision_status' => 'failed']);
        $expired = $this->order();
        $expired->update(['activated_at' => now()]); // Payment, rather than this field, sets its deadline.
        $this->remote();
        $this->artisan('cloud:recover-gpu-delivery --limit=1')->assertSuccessful();
        $this->assertSame('active', $expired->fresh()->status);
        $this->artisan('cloud:recover-gpu-delivery --limit=1')->assertSuccessful();
        $this->assertSame('cancelled', $expired->fresh()->status);
        $this->assertSame('active', $waiting->fresh()->status);
    }
}
