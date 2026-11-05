<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\EngineOperation;
use App\Models\EngineVm;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Cloud\Engine\EngineOperations;
use App\Services\Cloud\Engine\EnginePaidGate;
use App\Services\Cloud\Engine\EngineProvider;
use App\Services\Cloud\Engine\Placement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

class CloudEngineApiTest extends TestCase
{
    use EngineInventory, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function paidService(): Service
    {
        $service = $this->engineService($this->enginePlan($this->engineNode()));
        Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'currency_code' => 'IRT', 'subtotal' => 1000000, 'total' => 1000000, 'paid' => 1000000,
            'status' => 'paid', 'paid_at' => now()]);

        return $service;
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => 'api-'.Customer::count().'@example.test', 'password' => 'fixture-only', 'status' => 'active', 'locale' => 'fa']);
    }

    private function headers(Customer $customer, array $abilities = ['servers:read'], string $key = 'request-12345'): array
    {
        [, $plain] = CustomerApiToken::issue($customer->id, 'engine-api-test', $abilities);

        return ['Authorization' => 'Bearer '.$plain, 'Idempotency-Key' => $key];
    }

    private function readyVm(?Service $service = null): EngineVm
    {
        $service ??= $this->paidService();
        $vm = app(Placement::class)->reserve($service, $service->cloudPlan, 'ubuntu-24.04');
        $service->update(['status' => 'active', 'provision_status' => 'done']);
        $vm->update(['status' => 'running', 'metadata' => ['secret' => 'never-public']]);
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'cloud-engine',
            'provider_ref' => $vm->uuid, 'hostname' => 'customer.example.test', 'status' => 'running',
            'meta' => ['token' => 'never-public'], 'root_password_enc' => encrypt('never-public')]);

        return $vm->fresh();
    }

    private function fakeExistingPipeline(): void
    {
        $mock = Mockery::mock(CloudProvisioner::class);
        $mock->shouldReceive('provision')->once()->andReturnUsing(function (Service $service) {
            $vm = app(Placement::class)->reserve($service, $service->cloudPlan, $service->cloud_image_key);
            app(EngineOperations::class)->submit($vm, 'create', [], 'initial-create-'.$service->id);

            return true;
        });
        $this->app->instance(CloudProvisioner::class, $mock);
    }

    public function test_bearer_and_explicit_scopes_are_required_without_browser_session(): void
    {
        $customer = $this->customer();
        $this->getJson('/api/v1/servers')->assertUnauthorized();
        foreach ([['read'], ['*']] as $abilities) {
            $this->getJson('/api/v1/servers', $this->headers($customer, $abilities))->assertForbidden();
        }
        foreach (['servers:read', 'servers:write', 'servers:manage'] as $ability) {
            $response = $this->getJson('/api/v1/servers', $this->headers($customer, [$ability]))->assertOk();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertFalse($response->headers->has('Set-Cookie'));
        }
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_cross_customer_server_and_operation_lookups_are_hidden(): void
    {
        $vm = $this->readyVm();
        $operation = app(EngineOperations::class)->submit($vm, 'power', ['action' => 'off'], 'owner-operation');
        $other = $this->customer();
        $headers = $this->headers($other, ['servers:manage']);
        $this->getJson('/api/v1/servers', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/servers/'.$vm->uuid, $headers)->assertNotFound();
        $this->getJson('/api/v1/operations/'.$operation->id, $headers)->assertNotFound();
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/power', ['action' => 'on'], $headers)->assertNotFound();
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/console', [], $headers)->assertNotFound();
        $this->assertDatabaseCount('cloud_engine_operations', 1);
    }

    public function test_public_server_and_operation_dtos_exclude_infrastructure_data(): void
    {
        $vm = $this->readyVm();
        $operation = app(EngineOperations::class)->submit($vm, 'power', ['action' => 'off'], 'safe-operation');
        $operation->update(['receipt' => ['task' => 'UPID:never-public'], 'error_code' => 'pve-secret-internal']);
        $headers = $this->headers($vm->service->customer);
        $server = $this->getJson('/api/v1/servers/'.$vm->uuid, $headers)->assertOk()->assertJsonPath('data.id', $vm->uuid);
        $job = $this->getJson('/api/v1/operations/'.$operation->id, $headers)->assertOk()
            ->assertJsonPath('data.error', 'operation_requires_review');
        foreach ([$server->getContent(), $job->getContent()] as $json) {
            foreach (['node_id', 'vmid', 'provider', 'metadata', 'token_secret', 'receipt', 'never-public', 'pve-secret-internal', 'fingerprint'] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }
        }
    }

    public function test_unpaid_foreign_partial_and_expired_services_cannot_start_provisioning(): void
    {
        $service = $this->engineService($this->enginePlan($this->engineNode()));
        $headers = $this->headers($service->customer, ['servers:write']);
        $mock = Mockery::mock(CloudProvisioner::class);
        $mock->shouldNotReceive('provision');
        $this->app->instance(CloudProvisioner::class, $mock);
        $this->postJson('/api/v1/servers', ['service_id' => $service->id], $headers)
            ->assertUnprocessable()->assertJsonPath('error', 'confirmed_payment_required');
        Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'currency_code' => 'IRT', 'total' => 1000000, 'paid' => 100, 'status' => 'paid', 'paid_at' => now()]);
        $this->postJson('/api/v1/servers', ['service_id' => $service->id], $headers)->assertUnprocessable();
        $otherHeaders = $this->headers($this->customer(), ['servers:write']);
        $this->postJson('/api/v1/servers', ['service_id' => $service->id], $otherHeaders)->assertNotFound();
        $service->invoices()->update(['paid' => 1000000]);
        $service->update(['next_due_at' => now()->subDay()]);
        $this->postJson('/api/v1/servers', ['service_id' => $service->id], $headers)->assertUnprocessable();
        $this->assertDatabaseCount('cloud_engine_operations', 0);
    }

    public function test_create_replays_existing_pipeline_operation_and_does_not_duplicate_billing(): void
    {
        $service = $this->paidService();
        $headers = $this->headers($service->customer, ['servers:write']);
        $this->fakeExistingPipeline();
        $first = $this->postJson('/api/v1/servers', ['service_id' => $service->id], $headers)->assertAccepted();
        $this->postJson('/api/v1/servers', ['service_id' => $service->id], $headers)->assertAccepted()
            ->assertJsonPath('operation.id', $first->json('operation.id'));
        $this->assertDatabaseCount('cloud_engine_vms', 1);
        $this->assertDatabaseCount('cloud_engine_operations', 1);
        $this->assertDatabaseCount('cloud_engine_api_requests', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseCount('services', 1);
    }

    public function test_same_key_for_different_service_or_action_is_a_conflict(): void
    {
        $vm = $this->readyVm();
        $headers = $this->headers($vm->service->customer, ['servers:manage', 'servers:write']);
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/power', ['action' => 'off'], $headers)->assertAccepted();
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/power', ['action' => 'on'], $headers)
            ->assertConflict()->assertJsonPath('error', 'idempotency_conflict');
        $this->postJson('/api/v1/servers', ['service_id' => $vm->service_id], $headers)->assertConflict();
        $this->assertDatabaseCount('cloud_engine_operations', 1);
    }

    public function test_mutations_require_idempotency_and_manage_scope_and_deny_dead_or_expired_service(): void
    {
        $vm = $this->readyVm();
        foreach (['servers:read', 'servers:write'] as $scope) {
            $this->postJson('/api/v1/servers/'.$vm->uuid.'/power', ['action' => 'off'], $this->headers($vm->service->customer, [$scope]))->assertForbidden();
        }
        $headers = $this->headers($vm->service->customer, ['servers:manage'], '');
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/power', ['action' => 'off'], $headers)
            ->assertUnprocessable()->assertJsonPath('error', 'idempotency_key_required');
        $headers = $this->headers($vm->service->customer, ['servers:manage']);
        $vm->service->update(['next_due_at' => now()->subDay()]);
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/power', ['action' => 'off'], $headers)->assertConflict();
        $vm->service->update(['next_due_at' => null, 'status' => 'terminated']);
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/power', ['action' => 'off'], $headers)->assertConflict();
        $this->assertDatabaseCount('cloud_engine_operations', 0);
    }

    public function test_review_outcome_is_committed_without_claiming_delivery(): void
    {
        $service = $this->paidService();
        $mock = Mockery::mock(CloudProvisioner::class);
        $mock->shouldReceive('provision')->once()->andReturnUsing(function (Service $row) {
            $row->update(['provision_status' => 'manual']);

            return false;
        });
        $this->app->instance(CloudProvisioner::class, $mock);
        $this->postJson('/api/v1/servers', ['service_id' => $service->id], $this->headers($service->customer, ['servers:write']))
            ->assertConflict()->assertJsonPath('error', 'provisioning_pending_review');
        $this->assertSame('manual', $service->fresh()->provision_status);
        $this->assertDatabaseCount('cloud_engine_operations', 0);
    }

    public function test_delete_requires_confirmation_and_stops_existing_service_billing_once(): void
    {
        $vm = $this->readyVm();
        $headers = $this->headers($vm->service->customer, ['servers:manage']);
        $this->deleteJson('/api/v1/servers/'.$vm->uuid, [], $headers)->assertUnprocessable();
        $first = $this->deleteJson('/api/v1/servers/'.$vm->uuid, ['confirm' => true], $headers)->assertAccepted();
        $this->deleteJson('/api/v1/servers/'.$vm->uuid, ['confirm' => true], $headers)->assertAccepted()
            ->assertJsonPath('operation.id', $first->json('operation.id'));
        $this->assertSame('terminated', $vm->service->fresh()->status);
        $this->assertSame(Service::PROVISION_RELEASING, $vm->service->fresh()->provision_status);
        $this->assertDatabaseCount('cloud_engine_operations', 1);
        $this->assertDatabaseCount('cloud_engine_vms', 1);
        $this->assertDatabaseHas('cloud_engine_addresses', ['vm_id' => $vm->id, 'state' => 'allocated']);
    }

    public function test_gateway_and_monitoring_failures_do_not_leak_debug_or_provider_errors(): void
    {
        $vm = $this->readyVm();
        $headers = $this->headers($vm->service->customer, ['servers:manage']);
        config(['app.debug' => true, 'cloud-engine.console.gateway_url' => null]);
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/console', [], $headers)
            ->assertConflict()->assertExactJson(['ok' => false, 'error' => 'console_unavailable']);
        $mock = Mockery::mock(EngineProvider::class);
        $mock->shouldReceive('metrics')->once()->andThrow(new \RuntimeException('secret PVE fixture-only-secret'));
        $this->app->instance(EngineProvider::class, $mock);
        $this->getJson('/api/v1/servers/'.$vm->uuid.'/metrics', $headers)->assertStatus(503)
            ->assertExactJson(['ok' => false, 'error' => 'temporarily_unavailable']);
    }

    public function test_metrics_are_allowlisted_and_separate_from_durable_traffic(): void
    {
        $vm = $this->readyVm();
        $headers = $this->headers($vm->service->customer);
        $mock = Mockery::mock(EngineProvider::class);
        $mock->shouldReceive('metrics')->with($vm->uuid, '24h')->once()->andReturn([
            'ok' => true, 'message' => 'never-public', 'series' => ['cpu' => [[100, 0.5]], 'token' => 'never-public'],
            'current' => ['uptime_seconds' => 3600, 'last_boot_at' => '2026-11-03T00:00:00+00:00', 'boot_time_estimated' => true,
                'node_id' => 'never-public', 'token_secret' => 'never-public'],
        ]);
        $this->app->instance(EngineProvider::class, $mock);
        $this->getJson('/api/v1/servers/'.$vm->uuid.'/metrics', $headers)->assertOk()
            ->assertJsonPath('data.series.cpu.0.1', 0.5)->assertJsonMissing(['token' => 'never-public'])
            ->assertJsonPath('data.current.uptime_seconds', 3600)->assertJsonPath('data.current.boot_time_estimated', true)
            ->assertDontSee('never-public');
        $this->getJson('/api/v1/servers/'.$vm->uuid.'/traffic', $headers)->assertOk()
            ->assertJsonPath('data.coverage', 'unknown')->assertJsonPath('data.billable', false)->assertJsonPath('data.in_bytes', '0');
    }

    public function test_initial_monthly_only_gate_rejects_hourly_records_even_with_existing_wallet_debits(): void
    {
        $service = $this->engineService($this->enginePlan($this->engineNode()));
        $service->update(['billing_mode' => 'hourly', 'activated_at' => now(), 'hourly_rate_irt' => 100]);
        $gate = app(EnginePaidGate::class);
        $this->assertFalse($gate->allows($service));
        CreditEntry::create(['customer_id' => $service->customer_id, 'currency_code' => 'IRT', 'amount' => -100,
            'balance_after' => 0, 'reason' => 'cloud_hourly', 'source_type' => Service::class, 'source_id' => $service->id]);
        $this->assertFalse($gate->allows($service));
        CreditEntry::create(['customer_id' => $service->customer_id, 'currency_code' => 'IRT', 'amount' => 100,
            'balance_after' => 100, 'reason' => 'cloud_hourly_refund', 'source_type' => Service::class, 'source_id' => $service->id]);
        $this->assertFalse($gate->allows($service));
        $service->update(['status' => 'terminated', 'cancelled_at' => now()]);
        $this->assertFalse($gate->allows($service));
    }

    public function test_expired_revoked_and_inactive_customer_tokens_cannot_control_servers(): void
    {
        $customer = $this->customer();
        [, $expired] = CustomerApiToken::issue($customer->id, 'expired', ['servers:manage'], expiresAt: now()->subSecond());
        $this->getJson('/api/v1/servers', ['Authorization' => 'Bearer '.$expired])->assertUnauthorized()->assertJsonPath('error', 'token_expired');
        [$token, $revoked] = CustomerApiToken::issue($customer->id, 'revoked', ['servers:manage']);
        $token->revoke();
        $this->getJson('/api/v1/servers', ['Authorization' => 'Bearer '.$revoked])->assertUnauthorized()->assertJsonPath('error', 'token_revoked');
        $headers = $this->headers($customer, ['servers:manage']);
        $customer->update(['status' => 'suspended']);
        $this->getJson('/api/v1/servers', $headers)->assertForbidden();
    }

    public function test_guest_mutations_require_verified_image_capabilities_and_rebuild_requires_accepted_image(): void
    {
        $vm = $this->readyVm();
        $vm->image->update(['metadata' => []]);
        $headers = $this->headers($vm->service->customer, ['servers:manage']);
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/reset-password', ['confirm' => true], $headers)
            ->assertUnprocessable()->assertJsonPath('error', 'unsupported_operation');
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/rebuild', ['confirm' => true, 'image' => 'unaccepted-image'], $headers)
            ->assertUnprocessable()->assertJsonPath('error', 'unsupported_operation');
        $this->assertDatabaseCount('cloud_engine_operations', 0);
        $vm->image->update(['metadata' => ['guest_agent_verified' => true]]);
        $response = $this->postJson('/api/v1/servers/'.$vm->uuid.'/hostname', ['hostname' => 'customer.example.test'], $headers)->assertAccepted();
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/hostname', ['hostname' => 'customer.example.test'], $headers)
            ->assertAccepted()->assertJsonPath('operation.id', $response->json('operation.id'));
        $this->assertDatabaseCount('cloud_engine_operations', 1);
        Http::assertNothingSent();
    }

    public function test_rebuild_queues_one_confirmed_operation_and_preserves_reserved_identity(): void
    {
        $vm = $this->readyVm();
        $headers = $this->headers($vm->service->customer, ['servers:manage']);
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/rebuild', ['image' => 'ubuntu-24.04'], $headers)->assertUnprocessable();
        $first = $this->postJson('/api/v1/servers/'.$vm->uuid.'/rebuild', ['image' => 'ubuntu-24.04', 'confirm' => true], $headers)->assertAccepted();
        $this->postJson('/api/v1/servers/'.$vm->uuid.'/rebuild', ['image' => 'ubuntu-24.04', 'confirm' => true], $headers)
            ->assertAccepted()->assertJsonPath('operation.id', $first->json('operation.id'));
        $operation = EngineOperation::firstOrFail();
        $this->assertTrue($operation->payload['confirmed']);
        $this->assertSame('rebuild', $operation->action);
        $this->assertDatabaseCount('cloud_engine_operations', 1);
        $this->assertSame($vm->address_id, $vm->fresh()->address_id);
        $this->assertSame($vm->vmid, $vm->fresh()->vmid);
        $this->assertStringNotContainsString($operation->payload['password'], $first->getContent());
        Http::assertNothingSent();
    }

    public function test_billing_resume_requires_fresh_settlement_and_never_opens_the_customer_power_gate(): void
    {
        $service = $this->paidService();
        $service->invoices()->update(['paid_at' => now()->subDay()]);
        $service->update(['status' => 'suspended', 'suspended_at' => now()->subHour(), 'next_due_at' => now()->addMonth()]);
        $gate = app(EnginePaidGate::class);
        $this->assertFalse($gate->allows($service));
        $this->assertFalse($gate->allowsBillingResume($service));
        $service->invoices()->update(['paid_at' => now()]);
        $this->assertTrue($gate->allowsBillingResume($service));
        $this->assertFalse($gate->allows($service));
        $service->update(['next_due_at' => now()->subDay()]);
        $this->assertFalse($gate->allowsBillingResume($service));

        $service->update(['next_due_at' => now()->addMonth(), 'billing_mode' => 'hourly', 'hourly_rate_irt' => 100]);
        $entry = CreditEntry::create(['customer_id' => $service->customer_id, 'currency_code' => 'IRT', 'amount' => -100,
            'balance_after' => 0, 'reason' => 'cloud_hourly', 'source_type' => Service::class, 'source_id' => $service->id]);
        $entry->forceFill(['created_at' => now()->subDay()])->save();
        $this->assertFalse($gate->allowsBillingResume($service));
        $entry->forceFill(['created_at' => now()])->save();
        $this->assertFalse($gate->allowsBillingResume($service));
        $service->update(['cancelled_at' => now()]);
        $this->assertFalse($gate->allowsBillingResume($service));
    }

    public function test_engine_plans_cannot_advertise_hourly_sales_even_with_a_forged_hourly_cost(): void
    {
        $plan = $this->enginePlan($this->engineNode());
        $plan->update(['cost_hour_eur_micro' => 1000000]);
        $this->assertFalse($plan->fresh()->supportsHourly());
        $this->assertFalse(CloudPlan::query()->hourlyCapable()->whereKey($plan->id)->exists());
    }
}
