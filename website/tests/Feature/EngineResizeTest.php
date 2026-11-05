<?php

namespace Tests\Feature;

use App\Models\{CloudInstance, EngineCapacityHold, EngineOperation, EngineVm, Invoice};
use App\Services\Cloud\Engine\{EngineCapacity, EngineOperations, EngineWorker, Placement};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

class EngineResizeTest extends TestCase
{
    use RefreshDatabase, EngineInventory;

    private function guest(): array
    {
        $node = $this->engineNode(); $old = $this->enginePlan($node); $service = $this->engineService($old);
        $service->update(['status' => 'active', 'cycle' => 'quarterly', 'price' => 2850000, 'next_due_at' => now()->addMonths(3)]);
        Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'kind' => 'service', 'currency_code' => 'IRT', 'total' => 2850000, 'paid' => 2850000, 'status' => 'paid', 'paid_at' => now()]);
        $vm = app(Placement::class)->reserve($service, $old, 'ubuntu-24.04'); $vm->update(['status' => 'off']);
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'cloud-engine', 'provider_ref' => $vm->uuid,
            'status' => 'off', 'specs' => ['vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 20]]);
        $target = $this->enginePlan($node); $target->update(['vcpu' => 6, 'ram_mb' => 4096, 'disk_gb' => 40, 'price_irt' => 2000000]);
        return [$vm->fresh(), $target];
    }

    private function config(EngineVm $vm): array
    {
        return ['name' => $vm->name, 'description' => EngineWorker::marker($vm),
            'cores' => 2, 'sockets' => 1, 'memory' => 2048, 'balloon' => 0, 'scsi0' => 'local:vm-'.$vm->vmid.'-disk-0,size=20G'];
    }

    private function complete(EngineOperation $op): void
    {
        for ($i = 0; $i < 10 && $op->fresh()->status !== 'succeeded'; $i++) app(EngineWorker::class)->tick($op->fresh());
        $this->assertSame('succeeded', $op->fresh()->status, (string) $op->fresh()->error_code);
    }

    public function test_resize_holds_capacity_and_commits_contract_only_after_verified_hardware(): void
    {
        [$vm, $target] = $this->guest(); $due = $vm->service->next_due_at->toDateString(); $oldPlan = $vm->service->cloud_plan_id;
        $op = app(EngineOperations::class)->submit($vm, 'resize', ['plan' => $target->slug], 'resize-fixture');
        $this->assertSame(6, app(EngineCapacity::class)->usage($vm->node)['vcpu']);
        $this->assertSame($oldPlan, $vm->service->fresh()->cloud_plan_id);
        $this->assertSame(2850000, (int) $vm->service->fresh()->price);
        $this->assertSame($op->id, app(EngineOperations::class)->submit($vm, 'resize', ['plan' => $target->slug], 'resize-fixture')->id);
        $this->assertSame(1, EngineCapacityHold::count());
        $config = $this->config($vm);
        Http::fake(function ($request) use (&$config) {
            if (str_contains($request->url(), '/tasks/')) return Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]);
            if (str_ends_with($request->url(), '/status/current')) return Http::response(['data' => ['status' => 'stopped']]);
            if ($request->method() === 'PUT' && str_ends_with($request->url(), '/config')) {
                $config = [...$config, ...$request->data()]; return Http::response(['data' => 'UPID:resize:config']);
            }
            if ($request->method() === 'PUT' && str_ends_with($request->url(), '/resize')) {
                $config['scsi0'] = 'local:vm-100-disk-0,size=40G'; return Http::response(['data' => 'UPID:resize:disk']);
            }
            return Http::response(['data' => $config]);
        });
        $this->complete($op);
        $this->assertSame($target->id, $vm->service->fresh()->cloud_plan_id);
        $this->assertSame(5700000, (int) $vm->service->fresh()->price);
        $this->assertSame($due, $vm->service->fresh()->next_due_at->toDateString());
        $this->assertSame(2850000, (int) Invoice::first()->paid, 'Never rewrite the already paid term.');
        $this->assertSame(1, Invoice::count());
        $this->assertSame(40, (int) $vm->fresh()->disk_gb);
        $this->assertSame(6, (int) $vm->fresh()->vcpu);
        $this->assertSame('committed', EngineCapacityHold::first()->status);
        $this->assertSame(6, app(EngineCapacity::class)->usage($vm->node)['vcpu']);
    }

    public function test_unknown_resize_keeps_capacity_and_never_resubmits_or_changes_price(): void
    {
        [$vm, $target] = $this->guest(); $config = $this->config($vm); $writes = 0;
        $op = app(EngineOperations::class)->submit($vm, 'resize', ['plan' => $target->slug], 'lost-resize');
        Http::fake(function ($request) use ($config, &$writes) {
            if ($request->method() === 'PUT') { $writes++; throw new ConnectionException('fixture lost response'); }
            return Http::response(['data' => str_ends_with($request->url(), '/status/current') ? ['status' => 'stopped'] : $config]);
        });
        for ($i = 0; $i < 5; $i++) app(EngineWorker::class)->tick($op->fresh());
        $this->assertSame(1, $writes);
        $this->assertSame('uncertain', $op->fresh()->status);
        $this->assertSame(2850000, (int) $vm->service->fresh()->price);
        $this->assertSame('reserved', EngineCapacityHold::first()->status);
        $this->assertSame(6, app(EngineCapacity::class)->usage($vm->node)['vcpu']);
        $this->expectException(\DomainException::class);
        app(Placement::class)->reserve($this->engineService($vm->service->cloudPlan), $vm->service->cloudPlan, 'ubuntu-24.04');
    }

    public function test_disk_can_be_retained_when_changing_cpu_and_ram(): void
    {
        [$vm, $target] = $this->guest(); $config = $this->config($vm);
        $op = app(EngineOperations::class)->submit($vm, 'resize', ['plan' => $target->slug, 'upgrade_disk' => false], 'keep-disk');
        Http::fake(function ($request) use (&$config) {
            if (str_ends_with($request->url(), '/status/current')) return Http::response(['data' => ['status' => 'stopped']]);
            if ($request->method() === 'PUT') { $config = [...$config, ...$request->data()]; return Http::response(['data' => null]); }
            return Http::response(['data' => $config]);
        });
        $this->complete($op);
        $this->assertSame(20, (int) $vm->fresh()->disk_gb);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/resize'));
    }

    public function test_running_foreign_location_shrinking_and_over_capacity_targets_fail_before_mutation(): void
    {
        [$vm, $target] = $this->guest(); Http::preventStrayRequests();
        foreach ([['status' => 'running'], ['status' => 'off', 'disk_gb' => 50]] as $changes) {
            $vm->update($changes);
            try { app(EngineOperations::class)->submit($vm->fresh(), 'resize', ['plan' => $target->slug], 'invalid-resize'); $this->fail('Unsafe resize accepted'); }
            catch (\RuntimeException) { $this->assertSame(0, EngineCapacityHold::count()); }
        }
        $vm->update(['disk_gb' => 20]); $target->update(['vcpu' => 8]);
        try { app(EngineOperations::class)->submit($vm->fresh(), 'resize', ['plan' => $target->slug], 'over-capacity'); $this->fail('Over-capacity resize'); }
        catch (\RuntimeException) { $this->assertSame(0, EngineCapacityHold::count()); }
        $target->update(['vcpu' => 4, 'location_code' => 'us-test']);
        $this->expectException(\RuntimeException::class);
        app(EngineOperations::class)->submit($vm->fresh(), 'resize', ['plan' => $target->slug], 'wrong-location');
    }
}
