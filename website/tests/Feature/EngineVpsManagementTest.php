<?php

namespace Tests\Feature;

use App\Models\{CloudInstance, CustomerApiToken, EngineOperation, EngineVm, Invoice};
use App\Services\Cloud\Engine\{EngineOperations, EngineProvider, EngineWorker, Placement};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

class EngineVpsManagementTest extends TestCase
{
    use RefreshDatabase, EngineInventory;

    private function server(): EngineVm
    {
        Http::preventStrayRequests();
        $service = $this->engineService($this->enginePlan($this->engineNode()));
        Invoice::create(['service_id' => $service->id, 'customer_id' => $service->customer_id,
            'currency_code' => 'IRT', 'status' => 'paid', 'total' => 1000000, 'paid' => 1000000, 'paid_at' => now()]);
        $vm = app(Placement::class)->reserve($service, $service->cloudPlan, 'ubuntu-24.04');
        $vm->update(['status' => 'off']);
        $service->update(['status' => 'active', 'activated_at' => now(), 'next_due_at' => now()->addMonth(), 'provision_status' => 'done']);
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'cloud-engine', 'provider_ref' => $vm->uuid, 'status' => 'off', 'ipv4' => $vm->address->address]);
        return $vm->fresh();
    }

    private function headers(EngineVm $vm, array $scopes = ['servers:manage'], string $key = 'vps-management-key'): array
    {
        [, $token] = CustomerApiToken::issue($vm->service->customer_id, 'fixture', $scopes);
        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => $key];
    }

    public function test_public_resize_options_scope_ownership_idempotency_and_async_contract(): void
    {
        $vm = $this->server(); $target = $this->enginePlan($vm->node);
        $target->update(['vcpu' => 4, 'ram_mb' => 4096, 'disk_gb' => 40, 'price_irt' => 2000000]);
        $read = $this->headers($vm, ['servers:read']); $manage = $this->headers($vm);
        $url = '/api/v1/servers/'.$vm->uuid;
        $options = $this->getJson($url.'/resize-options', $read)->assertOk()
            ->assertJsonPath('data.plans.0.ref', $target->slug)->assertJsonPath('data.plans.0.amount', '2000000')
            ->assertJsonPath('data.price_effective', 'next_renewal');
        $this->assertStringNotContainsString('provider_ref', $options->getContent());
        $body = ['plan' => $target->slug, 'upgrade_disk' => true];
        $this->postJson($url.'/resize', $body, $read)->assertForbidden();
        $accepted = $this->postJson($url.'/resize', $body, $manage)->assertAccepted();
        $this->postJson($url.'/resize', $body, $manage)->assertAccepted()->assertJsonPath('operation.id', $accepted->json('operation.id'));
        $this->assertSame(1000000, (int) $vm->service->fresh()->price);
        $this->assertSame(20, (int) $vm->fresh()->disk_gb);
        $this->getJson($url.'/operations', $read)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'resize');
        $other = $this->engineService($target);
        [, $token] = CustomerApiToken::issue($other->customer_id, 'other', ['servers:manage']);
        foreach (['resize-options', 'operations'] as $path) $this->getJson($url.'/'.$path, ['Authorization' => 'Bearer '.$token])->assertNotFound();
        $this->postJson($url.'/resize', $body, ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'other-resize-key'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_labels_protection_and_history_use_owned_audited_operations_without_upstream_calls(): void
    {
        $vm = $this->server(); $url = '/api/v1/servers/'.$vm->uuid; $headers = $this->headers($vm);
        $body = ['labels' => ['environment' => 'production', 'description' => '<script>owned</script>'],
            'protection' => ['delete' => true, 'rebuild' => true]];
        $response = $this->patchJson($url.'/metadata', $body, $headers)->assertAccepted();
        $this->assertArrayNotHasKey('customer_labels', $vm->fresh()->metadata);
        $body['labels'] = array_reverse($body['labels'], true);
        $this->patchJson($url.'/metadata', $body, $headers)->assertAccepted()->assertJsonPath('operation.id', $response->json('operation.id'));
        $op = EngineOperation::firstOrFail(); app(EngineWorker::class)->tick($op);
        $this->assertSame('succeeded', $op->fresh()->status);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.protection.delete', true)
            ->assertJsonPath('data.labels.environment', 'production')->assertJsonMissingPath('data.metadata');
        $this->deleteJson($url, ['confirm' => true], [...$headers, 'Idempotency-Key' => 'protected-delete-key'])->assertForbidden()->assertJsonPath('error', 'server_protected');
        $this->postJson($url.'/rebuild', ['image' => 'ubuntu-24.04', 'confirm' => true], [...$headers, 'Idempotency-Key' => 'protected-rebuild-key'])->assertForbidden();
        $this->assertSame('active', $vm->service->fresh()->status);
        $this->actingAs($vm->service->customer, 'customer');
        $this->post('/account/services/'.$vm->service_id.'/terminate/start')->assertSessionHasErrors();
        $this->get('/en/account/cloud/'.$vm->service_id)->assertOk()->assertDontSee('<script>owned</script>', false)
            ->assertSee('&lt;script&gt;owned&lt;/script&gt;', false)->assertSee('Recent operations')->assertDontSee('cloud_engine.customer_');
        $this->assertSame(1, EngineOperation::count());
        // Protection is customer consent safety, not an extension of unpaid entitlement.
        $this->assertFalse(app(EngineProvider::class)->deleteServer($vm->uuid)['ok']);
        $this->assertSame(2, EngineOperation::count());
        Http::assertNothingSent();
    }

    public function test_metadata_validation_rejects_reserved_fields_invalid_names_and_unbounded_values(): void
    {
        $vm = $this->server(); $headers = $this->headers($vm); $url = '/api/v1/servers/'.$vm->uuid.'/metadata';
        foreach ([[], ['labels' => ['bad key' => 'x']], ['labels' => [12 => 'x']],
            ['labels' => ['valid' => "line\nbreak"]], ['labels' => ['valid' => str_repeat('x', 257)]],
            ['labels' => array_fill_keys(array_map(fn ($i) => 'label'.$i, range(1, 51)), 'x')],
            ['protection' => ['firewall' => true]], ['node_id' => 1], ['labels' => [], 'receipt' => 'forged']] as $body) {
            $this->patchJson($url, $body, $headers)->assertUnprocessable()->assertJsonPath('error', 'invalid_input');
        }
        $this->assertSame(0, EngineOperation::count()); Http::assertNothingSent();
    }

    public function test_expired_owner_can_disable_protection_without_renewal_or_power_mutations(): void
    {
        $vm = $this->server();
        $vm->update(['metadata' => [...$vm->metadata, 'customer_protection' => ['delete' => true, 'rebuild' => true]]]);
        $vm->service->update(['status' => 'expired', 'next_due_at' => now()->subDay()]);
        $url = '/api/v1/servers/'.$vm->uuid; $headers = $this->headers($vm);
        $this->patchJson($url.'/metadata', ['protection' => ['delete' => false]], $headers)->assertAccepted();
        $op = EngineOperation::firstOrFail(); app(EngineWorker::class)->tick($op);
        $this->assertSame('succeeded', $op->fresh()->status);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.protection.delete', false)->assertJsonPath('data.protection.rebuild', true);
        $this->assertSame('expired', $vm->service->fresh()->status);
        $this->assertTrue($vm->service->fresh()->next_due_at->isPast());
        $this->actingAs($vm->service->customer, 'customer');
        $this->post('/account/services/'.$vm->service_id.'/terminate/start')->assertSessionHas('svc_terminate_open', $vm->service_id);
        Http::assertNothingSent();
    }

    public function test_browser_forms_queue_owned_resize_and_metadata_in_all_locales(): void
    {
        $vm = $this->server(); $plan = $this->enginePlan($vm->node); $plan->update(['vcpu' => 4]);
        $this->actingAs($vm->service->customer, 'customer');
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $this->get($prefix.'/account/cloud/'.$vm->service_id)->assertOk()
                ->assertSee($prefix.'/account/cloud/'.$vm->service_id.'/engine/resize', false)
                ->assertSee('name="labels_text"', false)->assertDontSee('cloud_engine.customer_')->assertDontSee('cloud_engine.action_');
        }
        $this->post('/en/account/cloud/'.$vm->service_id.'/engine/metadata', ['request_key' => 'browser-metadata-key',
            'labels_text' => "environment=production\nteam=ops", 'protect_delete' => '1', 'protect_rebuild' => '0'])->assertSessionHas('ok');
        $op = EngineOperation::firstOrFail(); app(EngineWorker::class)->tick($op);
        $this->assertSame('production', $vm->fresh()->metadata['customer_labels']['environment']);
        $this->post('/en/account/cloud/'.$vm->service_id.'/engine/resize', ['request_key' => 'browser-resize-key',
            'plan' => $plan->slug, 'upgrade_disk' => '0'])->assertSessionHas('ok');
        $this->assertSame('resize', EngineOperation::latest('id')->first()->action);
        $this->assertSame(2, EngineOperation::count()); Http::assertNothingSent();
    }
}
