<?php

namespace Tests\Feature;

use App\Models\{CloudInstance, CreditEntry, EngineOperation, EngineVm, Invoice, Payment, Service};
use App\Services\Cloud\Engine\{EngineProvider, EngineWorker, Placement};
use App\Services\Notify\{AdminNotifier, CustomerNotifier, Notifier};
use App\Services\Payment\PaymentService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

/** Exercise the existing monthly billing pipeline with the real Engine adapter. */
class EngineBillingLifecycleTest extends TestCase
{
    use RefreshDatabase, EngineInventory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cloud-engine.enabled' => true, 'billing.complimentary_services' => []]);
        Http::preventStrayRequests();
        Mail::fake();
        $this->mock(Notifier::class)->shouldIgnoreMissing();
        $this->mock(CustomerNotifier::class)->shouldIgnoreMissing();
        $this->mock(AdminNotifier::class)->shouldIgnoreMissing();
    }

    private function delivered(): EngineVm
    {
        $plan = $this->enginePlan($this->engineNode());
        $service = $this->engineService($plan);
        $vm = app(Placement::class)->reserve($service, $plan, 'ubuntu-24.04');
        $vm->update(['status' => 'running']);
        $service->update(['status' => 'active', 'provision_status' => 'done',
            'activated_at' => now()->subMonth(), 'next_due_at' => now()->subDays(2)->startOfDay()]);
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'cloud-engine',
            'provider_ref' => $vm->uuid, 'status' => 'running', 'hostname' => $vm->name,
            'ipv4' => $vm->address->address, 'image_key' => 'ubuntu-24.04']);

        return $vm->fresh(['service', 'node', 'address']);
    }

    private function fakePower(EngineVm $vm, string &$state): void
    {
        Http::fake(function ($request) use ($vm, &$state) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_ends_with($path, '/config')) {
                return Http::response(['data' => ['name' => $vm->name, 'description' => EngineWorker::marker($vm)]]);
            }
            if ($request->method() === 'GET' && str_ends_with($path, '/status/current')) {
                return Http::response(['data' => ['status' => $state, 'uptime' => $state === 'running' ? 100 : 0]]);
            }
            if ($request->method() === 'GET' && str_contains($path, '/tasks/')) {
                return Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]);
            }
            if ($request->method() === 'POST' && (str_ends_with($path, '/status/stop') || str_ends_with($path, '/status/start'))) {
                $state = str_ends_with($path, '/status/stop') ? 'stopped' : 'running';
                return Http::response(['data' => 'UPID:fixture:'.$state]);
            }

            throw new \RuntimeException('Unexpected fake infrastructure request: '.$request->method().' '.$path);
        });
    }

    private function complete(EngineOperation $operation): void
    {
        for ($i = 0; $i < 8 && $operation->fresh()->status !== 'succeeded'; $i++) {
            app(EngineWorker::class)->tick($operation->fresh());
        }
        $this->assertSame('succeeded', $operation->fresh()->status, (string) $operation->fresh()->error_code);
    }

    public function test_expiration_payment_replay_and_async_renewal_restore_share_existing_business_pipeline(): void
    {
        $vm = $this->delivered();
        $service = $vm->service;
        $renewal = Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 1000000, 'tax' => 0,
            'total' => 1000000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()->subDay(), 'due_at' => now()->subDay()]);
        $state = 'running';
        $this->fakePower($vm, $state);

        $result = app(ProvisioningService::class)->suspend($service);
        $this->assertFalse($result->ok, 'Accepted stop must not be reported as confirmed suspension.');
        $this->assertSame('active', $service->fresh()->status);
        $stop = EngineOperation::where('vm_id', $vm->id)->where('action', 'power')->firstOrFail();
        $this->assertSame('off', $stop->payload['action']);
        $this->complete($stop);
        $this->artisan('services:lifecycle')->assertSuccessful();
        $service->refresh();
        $this->assertSame('suspended', $service->status);
        $this->assertNotNull($service->suspended_at);
        $this->assertSame('off', $vm->fresh()->status);
        $this->assertSame('allocated', $vm->address()->first()->state);
        $this->assertSame(1, Invoice::where('service_id', $service->id)->count());

        $payment = Payment::create(['invoice_id' => $renewal->id, 'customer_id' => $service->customer_id,
            'gateway' => 'bale', 'currency_code' => 'IRT', 'amount' => 1000000,
            'status' => 'redirected', 'external_ref' => 'engine-renewal-fixture']);
        $this->assertTrue(app(PaymentService::class)->settleConfirmed($payment, 'engine-fixture-ref')->ok);
        $this->assertTrue(app(PaymentService::class)->settleConfirmed($payment->fresh(), 'engine-fixture-ref')->ok);
        $this->assertSame('paid', $renewal->fresh()->status);
        $this->assertSame(1000000, (int) $renewal->fresh()->paid);
        $service->refresh();
        $this->assertSame('suspended', $service->status, 'Payment records entitlement; confirmed power restores the service.');
        $this->assertTrue($service->next_due_at->isFuture());

        $restore = app(ProvisioningService::class)->unsuspend($service);
        $this->assertFalse($restore->ok, 'Accepted start must not be reported as completed restoration.');
        $this->assertSame('suspended', $service->fresh()->status);
        $start = EngineOperation::where('vm_id', $vm->id)->where('action', 'power')->where('id', '!=', $stop->id)->firstOrFail();
        $this->assertSame('billing_resume', $start->payload['origin']);
        $this->complete($start);
        $this->assertTrue(app(ProvisioningService::class)->unsuspend($service->fresh())->ok);
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame('running', $vm->fresh()->status);
        $this->assertSame(1, Invoice::where('service_id', $service->id)->count());
        $this->assertSame(1, Payment::where('invoice_id', $renewal->id)->count());
        $this->assertSame(0, CreditEntry::where('customer_id', $service->customer_id)->count());
        $this->assertSame(2, EngineOperation::where('vm_id', $vm->id)->count());
        $this->assertSame(1, DB::table('business_ledger')->where('source_type', Payment::class)->where('source_id', $payment->id)->count());
    }

    public function test_unpaid_suspended_service_cannot_use_trusted_resume_path(): void
    {
        $vm = $this->delivered();
        $vm->service->update(['status' => 'suspended', 'suspended_at' => now()->subDay()]);
        Http::preventStrayRequests();
        $result = app(EngineProvider::class)->resumeBilling($vm->uuid);
        $this->assertFalse($result['ok']);
        $this->assertSame(0, EngineOperation::count());
        Http::assertNothingSent();
    }
}
