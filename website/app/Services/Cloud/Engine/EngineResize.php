<?php

namespace App\Services\Cloud\Engine;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\{CloudPlan, EngineCapacityHold, EngineNode, EngineOperation, EnginePlanNode, EngineVm, Service};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Capacity is retained across uncertain mutations; billing changes only after verification. */
class EngineResize
{
    /** Public slugs and contract prices only; admission rechecks under the node lock. */
    public function options(EngineVm $vm): array
    {
        if ($vm->status !== 'off' || ! app(EnginePaidGate::class)->allows($vm->service)
            || ($vm->service->currency_code ?: 'IRT') !== 'IRT' || ! $vm->node->acceptsSales()
            || EngineOperation::where('vm_id', $vm->id)->whereIn('status', ['pending', 'running', 'uncertain', 'blocked'])->exists()) return [];
        $usage = app(EngineCapacity::class)->usage($vm->node);
        return CloudPlan::where('provider', 'cloud-engine')->where('id', '!=', $vm->service->cloud_plan_id)
            ->where('location_code', $vm->node->location_code)->where('arch', $vm->node->arch)
            ->where('is_active', true)->where('in_stock', true)->where('admin_disabled', false)
            ->where('ram_mb', '>=', $vm->image->min_ram_mb)->where('disk_gb', '>=', max($vm->disk_gb, $vm->image->min_disk_gb))
            ->whereIn('id', EnginePlanNode::where('node_id', $vm->node_id)->where('enabled', true)->select('cloud_plan_id'))
            ->orderBy('vcpu')->orderBy('ram_mb')->get()->filter(function ($plan) use ($vm, $usage) {
                foreach (['vcpu', 'ram_mb', 'disk_gb'] as $key) {
                    if ((int) $plan->$key < 1 || (int) $vm->node->{$key.'_capacity'} - (int) $vm->node->{$key.'_reserve'} - $usage[$key]
                        < max(0, (int) $plan->$key - (int) $vm->$key)) return false;
                }
                return true;
            })->map(fn ($plan) => ['ref' => $plan->slug, 'name' => $plan->public_name,
                'vcpu' => (int) $plan->vcpu, 'ram_mb' => (int) $plan->ram_mb, 'disk_gb' => (int) $plan->disk_gb,
                'amount' => (string) app(EngineBillingPolicy::class)->quote($plan, $vm->service->cycle, (array) $vm->service->cloud_addons)])
            ->filter(fn ($plan) => (int) $plan['amount'] > 0)->values()->all();
    }

    public function prepare(EngineVm $vm, array $input): array
    {
        if (! is_string($input['plan'] ?? null) || $vm->status !== 'off' || ! app(EnginePaidGate::class)->allows($vm->service)) {
            throw new \RuntimeException('resize_requires_paid_off_server');
        }
        $node = EngineNode::whereKey($vm->node_id)->lockForUpdate()->firstOrFail();
        $target = CloudPlan::where('provider', 'cloud-engine')->where('slug', $input['plan'])
            ->where('location_code', $node->location_code)->where('arch', $node->arch)->first();
        if (! $target || $target->id === $vm->service->cloud_plan_id || ! $target->is_active || ! $target->in_stock || $target->admin_disabled
            || ! $node->acceptsSales() || $target->disk_gb < $vm->disk_gb
            || $target->ram_mb < $vm->image->min_ram_mb || $target->disk_gb < $vm->image->min_disk_gb
            || ! EnginePlanNode::where('node_id', $node->id)->where('cloud_plan_id', $target->id)->where('enabled', true)->exists()
            || min((int) $target->vcpu, (int) $target->ram_mb, (int) $target->disk_gb) < 1) {
            throw new \RuntimeException('resize_target_unavailable');
        }
        if (($vm->service->currency_code ?: 'IRT') !== 'IRT') throw new \RuntimeException('resize_currency_unavailable');
        $price = CloudStoreController::priceForCycle($target, $vm->service->cycle, (array) $vm->service->cloud_addons);
        if ($price <= 0) throw new \RuntimeException('resize_target_unavailable');
        $usage = app(EngineCapacity::class)->usage($node, true);
        $desired = ['vcpu' => (int) $target->vcpu, 'ram_mb' => (int) $target->ram_mb,
            'disk_gb' => ($input['upgrade_disk'] ?? true) ? (int) $target->disk_gb : (int) $vm->disk_gb];
        $delta = [];
        foreach (['vcpu', 'ram_mb', 'disk_gb'] as $key) {
            $delta[$key] = max(0, $desired[$key] - (int) $vm->$key);
            if ((int) $node->{$key.'_capacity'} - (int) $node->{$key.'_reserve'} - $usage[$key] < $delta[$key]) {
                throw new \RuntimeException('insufficient_capacity');
            }
        }
        $hold = EngineCapacityHold::create(['id' => (string) Str::uuid(), 'node_id' => $node->id,
            'vm_id' => $vm->id, 'purpose' => 'resize', ...$delta]);
        return ['plan' => $target->slug, 'plan_id' => $target->id, 'price' => $price,
            'old_plan_id' => $vm->service->cloud_plan_id, 'old_price' => (int) $vm->service->price,
            'hold_id' => $hold->id, ...$desired, 'traffic_gb' => (int) $target->traffic_gb];
    }

    public function complete(EngineOperation $op, EngineVm $vm, callable $finish): void
    {
        DB::transaction(function () use ($op, $vm, $finish) {
            $service = Service::whereKey($vm->service_id)->lockForUpdate()->firstOrFail();
            $vm = EngineVm::whereKey($vm->id)->lockForUpdate()->firstOrFail();
            EngineNode::whereKey($vm->node_id)->lockForUpdate()->firstOrFail();
            $current = EngineOperation::whereKey($op->id)->where('lease_token', $op->lease_token)
                ->whereNotNull('lease_token')->where('lease_until', '>', now())->lockForUpdate()->first();
            $hold = EngineCapacityHold::whereKey($op->payload['hold_id'])->where('vm_id', $vm->id)->lockForUpdate()->firstOrFail();
            if (! $current || $hold->status !== 'reserved' || ! app(EnginePaidGate::class)->allows($service)
                || (int) $service->cloud_plan_id !== (int) $op->payload['old_plan_id']
                || (int) $service->price !== (int) $op->payload['old_price']) {
                throw new \RuntimeException('resize_contract_changed');
            }
            $attributes = collect($op->payload)->only(['vcpu', 'ram_mb', 'disk_gb'])->all();
            $vm->update($attributes + ['metadata' => [...($vm->metadata ?? []),
                'cloud_plan_id' => $op->payload['plan_id'], 'traffic_quota_bytes' => $op->payload['traffic_gb'] * 1000000000]]);
            $service->update(['cloud_plan_id' => $op->payload['plan_id'], 'price' => $op->payload['price']]);
            if ($instance = $service->cloudInstance) {
                $instance->update(['specs' => [...($instance->specs ?? []), ...$attributes, 'traffic_gb' => $op->payload['traffic_gb']]]);
            }
            $hold->update(['status' => 'committed']);
            $finish(); // Resource, contract and operation completion share one commit.
        }, 3);
    }
}
