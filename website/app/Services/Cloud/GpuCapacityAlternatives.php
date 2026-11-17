<?php

namespace App\Services\Cloud;

use App\Models\{ActivityLog, CloudImage, CloudPlan, Customer, Service};
use App\Services\Finance\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only discovery and an explicit, idempotent replacement of an unpaid intent. */
class GpuCapacityAlternatives
{
    public function __construct(private SaladClient $provider, private GpuCapacityOrders $orders,
        private HourlyStartCredit $credit, private Wallet $wallet) {}

    public function candidates(Service $service): Collection
    {
        $service->loadMissing('cloudPlan');
        if (! GpuCapacityOrders::replaceable($service) || SaladClient::salesPaused((string) $service->cloud_image_key)) {
            return collect();
        }
        $source = $service->cloudPlan;
        return CloudPlan::sellable()->hourlyCapable()->where('provider', 'salad')
            ->where('id', '!=', $source->id)->where('location_code', $source->location_code)
            ->where('vcpu', '>=', $source->vcpu)->where('ram_mb', '>=', $source->ram_mb)
            ->where('disk_gb', '>=', $source->disk_gb)
            ->where('gpu_vram_mb', '>=', (int) $source->gpu_vram_mb)
            ->get()->filter(fn (CloudPlan $plan) => $plan->hourlyIrt() > 0
                && CloudImage::compatibleRefFor($plan, (string) $service->cloud_image_key) !== null)
            ->sortBy(fn (CloudPlan $plan) => $plan->hourlyIrt())->values();
    }

    private function candidate(Service $service, int $planId): CloudPlan
    {
        $plan = $this->candidates($service)->firstWhere('id', $planId);
        if (! $plan) {
            throw ValidationException::withMessages(['plan' => __('gpu-alternatives.changed')]);
        }
        return $plan;
    }

    public function preview(Service $service, int $planId): array
    {
        $service->refresh()->load('cloudPlan');
        $plan = $this->candidate($service, $planId);
        $probe = new Service(['billing_mode' => 'hourly']);
        $probe->setRelation('cloudPlan', $plan);
        $rate = $this->orders->currentRate($probe);
        $capacity = $this->provider->capacityFor($plan, true);
        $minimum = $rate === null ? null : $this->credit->minimum($service->customer_id, $rate, $service->id);
        $available = $this->wallet->availableOf($service->customer_id);
        return compact('plan', 'rate', 'capacity', 'minimum', 'available');
    }

    private function replay(Service $source, int $planId, int $rate): ?Service
    {
        $id = data_get($source->provision_meta, 'gpu_capacity_order.replacement_service_id');
        if (! $id) { return null; }
        $replacement = Service::whereKey($id)->where('customer_id', $source->customer_id)->first();
        if (! $replacement || (int) data_get($replacement->provision_meta, 'gpu_capacity_order.replaces_service_id') !== $source->id
            || (int) $replacement->cloud_plan_id !== $planId || (int) $replacement->hourly_rate_irt !== $rate) {
            throw ValidationException::withMessages(['plan' => __('gpu-alternatives.changed')]);
        }
        return $replacement;
    }

    public function replace(Service $source, int $planId, int $quotedRate): Service
    {
        $source->refresh()->load('cloudPlan');
        if ($replacement = $this->replay($source, $planId, $quotedRate)) { return $replacement; }
        // Provider reads happen outside database locks. No create/start/payment call is made here.
        $snapshot = [$source->cloud_plan_id, $source->cloud_image_key];
        $preview = $this->preview($source, $planId);
        if ($preview['rate'] === null || $preview['rate'] !== $quotedRate
            || ! $preview['capacity']['ok'] || $preview['capacity']['available'] !== true) {
            throw ValidationException::withMessages(['plan' => __('gpu-alternatives.changed')]);
        }
        $plan = $preview['plan'];
        $fingerprint = $plan->getAttributes();

        return DB::transaction(function () use ($source, $planId, $quotedRate, $preview, $snapshot, $fingerprint): Service {
            $customer = Customer::whereKey($source->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Service::whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->customer_id !== (int) $customer->id) { abort(404); }
            if ($replacement = $this->replay($locked, $planId, $quotedRate)) { return $replacement; }
            $locked->load('cloudPlan');
            if ([$locked->cloud_plan_id, $locked->cloud_image_key] !== $snapshot) {
                throw ValidationException::withMessages(['plan' => __('gpu-alternatives.changed')]);
            }
            // The normal provisioner claims this same customer/service lock before allocation.
            CloudPlan::whereKey($planId)->lockForUpdate()->firstOrFail();
            $plan = $this->candidate($locked, $planId);
            if ($plan->getAttributes() !== $fingerprint) {
                throw ValidationException::withMessages(['plan' => __('gpu-alternatives.changed')]);
            }
            $label = (string) data_get($locked->provision_meta, 'gpu_capacity_order.label', 'gpu');
            $description = implode("\n", [
                $plan->gpu_model,
                __('ui.svd_specs', ['core' => fa_num($plan->vcpu), 'ram' => $plan->ramLabel(),
                    'disk' => $plan->diskLabel(), 'traffic' => $plan->trafficLabel()]),
                __('ui.svd_srvname', ['name' => $label]),
            ]);
            $replacement = $this->orders->register($customer, $plan, [
                'image' => $locked->cloud_image_key, 'billing_mode' => 'hourly', 'cycle' => 'monthly',
                'gpu_request_key' => substr(hash('sha256', 'replacement|'.$locked->id.'|'.$planId.'|'.$quotedRate), 0, 40),
            ], $label, $description, $preview['capacity']);
            $replacement->forceFill(['hourly_rate_irt' => $quotedRate,
                'provision_meta->gpu_capacity_order->replaces_service_id' => $locked->id])->save();
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'provision_status' => 'none',
                'provision_meta->gpu_capacity_order->state' => 'replaced',
                'provision_meta->gpu_capacity_order->charge_pending' => false,
                'provision_meta->gpu_capacity_order->stop_pending' => false,
                'provision_meta->gpu_capacity_order->replacement_service_id' => $replacement->id])->save();
            ActivityLog::forService($locked, 'gpu_request_replaced', __('gpu-alternatives.activity_old',
                ['id' => $replacement->id]), 'customer');
            ActivityLog::forService($replacement, 'gpu_request_replacement', __('gpu-alternatives.activity_new',
                ['id' => $locked->id]), 'customer');
            return $replacement->fresh();
        }, 3);
    }
}
