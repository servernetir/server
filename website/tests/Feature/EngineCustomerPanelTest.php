<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\EngineOperation;
use App\Models\EngineVm;
use App\Models\Invoice;
use App\Services\Cloud\Engine\EngineAccountPresenter;
use App\Services\Cloud\Engine\EngineConsoleService;
use App\Services\Cloud\Engine\EngineOperations;
use App\Services\Cloud\Engine\EngineProvider;
use App\Services\Cloud\Engine\EngineTrafficService;
use App\Services\Cloud\Engine\EngineWorker;
use App\Services\Cloud\Engine\Placement;
use App\Services\Cloud\Engine\ProxmoxTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

class EngineCustomerPanelTest extends TestCase
{
    use EngineInventory, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function server(): EngineVm
    {
        $service = $this->engineService($this->enginePlan($this->engineNode()));
        Invoice::create(['service_id' => $service->id, 'customer_id' => $service->customer_id,
            'currency_code' => 'IRT', 'total' => 1000000, 'paid' => 1000000, 'status' => 'paid', 'paid_at' => now()]);
        $vm = app(Placement::class)->reserve($service, $service->cloudPlan, 'ubuntu-24.04');
        $vm->update(['status' => 'running']);
        $vm->image->update(['metadata' => ['guest_agent_verified' => true]]);
        $service->update(['status' => 'active', 'provision_status' => 'done', 'activated_at' => now()]);
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'cloud-engine', 'provider_ref' => $vm->uuid,
            'hostname' => 'customer.example.test', 'image_key' => 'ubuntu-24.04', 'ipv4' => $vm->address->address,
            'status' => 'running', 'location_code' => 'de-fsn', 'specs' => ['vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 20],
            'meta' => ['secret' => 'never-public'], 'root_password_enc' => encrypt('old-password-secret')]);

        return $vm->fresh();
    }

    public function test_real_customer_page_renders_localized_controls_and_exact_byte_totals_without_infrastructure_secrets(): void
    {
        $vm = $this->server();
        $transport = Mockery::mock(ProxmoxTransport::class);
        $transport->shouldReceive('request')->once()->andReturn(['ok' => true, 'data' => [
            'name' => $vm->name, 'description' => EngineWorker::marker($vm), 'template' => 0,
        ]]);
        $this->app->instance(ProxmoxTransport::class, $transport);
        $traffic = app(EngineTrafficService::class);
        $base = ['vm_uuid' => $vm->uuid, 'interface' => 'tap'.$vm->vmid.'i0', 'epoch' => str_repeat('a', 32)];
        $traffic->ingest($vm->node, $base + ['sequence' => 1, 'in_bytes' => 0, 'out_bytes' => 0,
            'sampled_at' => now('UTC')->subSeconds(60)->format('Y-m-d\TH:i:s\Z')]);
        $traffic->ingest($vm->node, $base + ['sequence' => 2, 'in_bytes' => 9007199254740993, 'out_bytes' => 1000,
            'sampled_at' => now('UTC')->subSeconds(30)->format('Y-m-d\TH:i:s\Z')]);
        $this->actingAs($vm->service->customer, 'customer');
        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            $response = $this->get($prefix.'/account/cloud/'.$vm->service_id)->assertOk();
            $response->assertSee('data-engine-panel', false)->assertSee('name="request_key"', false)
                ->assertSee('name="_token"', false)->assertDontSee('cloud_engine.customer_')
                ->assertSee($prefix.'/account/cloud/'.$vm->service_id.'/engine/status', false);
            foreach (['fixture-only-secret', 'never-public', 'pve.example.test', 'engine@pve', 'old-password-secret', 'cloud_engine.customer_'] as $secret) {
                $response->assertDontSee($secret);
            }
            $this->assertStringContainsString($locale === 'fa' ? '۹,۰۰۷,۱۹۹,۲۵۴,۷۴۰,۹۹۳' : '9,007,199,254,740,993', $response->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_status_is_session_owned_and_exposes_only_safe_operation_and_decimal_byte_strings(): void
    {
        $vm = $this->server();
        $operation = app(EngineOperations::class)->submit($vm, 'power', ['action' => 'off'], 'browser-status');
        $operation->update(['receipt' => ['task' => 'UPID:secret'], 'error_code' => 'pve-unavailable-internal']);
        $this->actingAs($vm->service->customer, 'customer');
        $response = $this->getJson('/account/cloud/'.$vm->service_id.'/engine/status')->assertOk()
            ->assertJsonPath('data.operation.id', (string) $operation->id)->assertJsonPath('data.traffic.in_bytes', '0');
        foreach (['node_id', 'vmid', 'token_secret', 'UPID', 'pve-unavailable-internal', 'receipt', 'fingerprint'] as $private) {
            $response->assertDontSee($private);
        }
        $other = Customer::create(['email' => 'other-panel@example.test', 'password' => 'fixture-only', 'status' => 'active']);
        $this->actingAs($other, 'customer');
        $this->get('/account/cloud/'.$vm->service_id)->assertNotFound();
        $this->getJson('/account/cloud/'.$vm->service_id.'/engine/status')->assertNotFound();
        $this->post('/account/cloud/'.$vm->service_id.'/engine/power', ['action' => 'off', 'request_key' => 'cross-owner-key'])->assertNotFound();
        $this->assertDatabaseCount('cloud_engine_operations', 1);
    }

    public function test_existing_password_route_queues_once_and_does_not_claim_a_password_changed_early(): void
    {
        $vm = $this->server();
        $this->actingAs($vm->service->customer, 'customer');
        $before = $vm->service->cloudInstance->root_password_enc;
        $body = ['confirm' => '1', 'request_key' => 'browser-password-request'];
        $this->post('/en/account/cloud/'.$vm->service_id.'/password', $body)->assertRedirect('/en/account/cloud/'.$vm->service_id)
            ->assertSessionHas('ok', __('cloud_engine.customer_queued'));
        $this->post('/en/account/cloud/'.$vm->service_id.'/password', $body)->assertRedirect();
        $this->assertDatabaseCount('cloud_engine_operations', 1);
        $this->assertSame('reset_password', EngineOperation::first()->action);
        $this->assertSame($before, $vm->service->cloudInstance->fresh()->root_password_enc);
        $this->assertSame('running', $vm->service->cloudInstance->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_browser_rebuild_requires_typed_confirmation_and_accepted_image_before_queueing(): void
    {
        $vm = $this->server();
        $this->actingAs($vm->service->customer, 'customer');
        $url = '/account/cloud/'.$vm->service_id.'/engine/rebuild';
        $body = ['image' => 'ubuntu-24.04', 'request_key' => 'browser-rebuild-key'];
        $this->post($url, $body)->assertSessionHasErrors('confirm');
        $this->post($url, $body + ['confirm' => 'NO'])->assertSessionHasErrors('confirm');
        $this->assertDatabaseCount('cloud_engine_operations', 0);
        $this->post($url, $body + ['confirm' => 'DELETE'])->assertRedirect('/account/cloud/'.$vm->service_id);
        $this->post($url, $body + ['confirm' => 'DELETE'])->assertRedirect();
        $this->assertDatabaseCount('cloud_engine_operations', 1);
        $this->assertTrue(EngineOperation::first()->payload['confirmed']);
        $this->assertSame($vm->address_id, $vm->fresh()->address_id);
        Http::assertNothingSent();
    }

    public function test_guest_capability_and_service_state_hide_controls_and_reject_direct_posts(): void
    {
        $vm = $this->server();
        $this->actingAs($vm->service->customer, 'customer');
        $vm->image->update(['metadata' => []]);
        $this->get('/account/cloud/'.$vm->service_id)->assertOk()->assertDontSee('/engine/password', false)->assertDontSee('/engine/hostname', false);
        $this->post('/account/cloud/'.$vm->service_id.'/engine/password', ['confirm' => '1', 'request_key' => 'unsupported-password'])
            ->assertSessionHasErrors();
        $vm->service->update(['status' => 'suspended']);
        $this->post('/account/cloud/'.$vm->service_id.'/power', ['action' => 'on', 'request_key' => 'suspended-power-key'])->assertSessionHasErrors();
        $this->assertDatabaseCount('cloud_engine_operations', 0);
    }

    public function test_console_existing_route_redirects_only_to_opaque_gateway_grant(): void
    {
        $vm = $this->server();
        $this->actingAs($vm->service->customer, 'customer');
        $broker = Mockery::mock(EngineConsoleService::class);
        $broker->shouldReceive('issue')->once()->withArgs(fn ($row, $owner) => $row->id === $vm->id && $owner === (int) $vm->service->customer_id)
            ->andReturn(['url' => 'https://console.example.test/#opaque-one-use-token']);
        $this->app->instance(EngineConsoleService::class, $broker);
        $response = $this->post('/account/cloud/'.$vm->service_id.'/console')->assertRedirect('https://console.example.test/#opaque-one-use-token');
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertDontSee('fixture-only-secret')->assertDontSee('pve.example.test');
    }

    public function test_browser_mutation_retains_csrf_protection(): void
    {
        $vm = $this->server();
        $this->actingAs($vm->service->customer, 'customer');
        $this->app['env'] = 'local';
        try {
            $this->post('/account/cloud/'.$vm->service_id.'/engine/power', ['action' => 'off', 'request_key' => 'csrf-rejected-key'])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('cloud_engine_operations', 0);
    }

    public function test_status_failure_and_panel_fallback_never_expose_infrastructure_debug_details(): void
    {
        $vm = $this->server();
        $this->actingAs($vm->service->customer, 'customer');
        $presenter = Mockery::mock(EngineAccountPresenter::class);
        $presenter->shouldReceive('snapshot')->twice()->andThrow(new \RuntimeException('pve.example.test fixture-only-secret'));
        $this->app->instance(EngineAccountPresenter::class, $presenter);
        config(['app.debug' => true]);
        $this->getJson('/account/cloud/'.$vm->service_id.'/engine/status')->assertStatus(503)
            ->assertExactJson(['ok' => false, 'error' => 'temporarily_unavailable']);
        $this->get('/account/cloud/'.$vm->service_id)->assertOk()
            ->assertDontSee('fixture-only-secret')->assertDontSee('pve.example.test')
            ->assertDontSee('/engine/power', false)->assertDontSee('/engine/password', false);
    }

    public function test_existing_monitoring_route_exposes_observed_uptime_and_estimated_boot_without_private_metadata(): void
    {
        $vm = $this->server();
        $this->actingAs($vm->service->customer, 'customer');
        $provider = Mockery::mock(EngineProvider::class);
        $provider->shouldReceive('metrics')->once()->with($vm->uuid, '24h')->andReturn([
            'ok' => true, 'message' => 'never-public', 'series' => ['cpu' => [[100, 0.2]], 'token' => 'never-public'],
            'current' => ['uptime_seconds' => 3600, 'last_boot_at' => '2026-11-03T00:00:00+00:00',
                'boot_time_estimated' => true, 'observed_at' => '2026-11-03T01:00:00+00:00', 'node_id' => 'never-public'],
        ]);
        $this->app->instance(EngineProvider::class, $provider);
        $this->getJson('/account/cloud/'.$vm->service_id.'/metrics')->assertOk()
            ->assertJsonPath('current.uptime_seconds', 3600)->assertJsonPath('current.boot_time_estimated', true)
            ->assertDontSee('never-public');
        Http::assertNothingSent();
    }
}
