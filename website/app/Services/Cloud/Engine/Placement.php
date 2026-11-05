<?php

namespace App\Services\Cloud\Engine;

use App\Models\{CloudPlan, EngineAddress, EngineImage, EngineNode, EnginePlanNode, EngineVm, Service};
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Atomic persisted reservations; uncertain guests continue consuming capacity. */
class Placement
{
    public function __construct(private Ipam $ipam) {}

    public function reserve(Service $service, CloudPlan $plan, string $imageKey, ?string $hostname = null): EngineVm
    {
        if ($plan->provider !== 'cloud-engine' || min((int) $plan->vcpu, (int) $plan->ram_mb, (int) $plan->disk_gb) < 1) {
            throw new \DomainException('A valid Cloud Engine plan is required.');
        }
        $name = 'sn-svc-'.$service->id;
        $hostname ??= $name;
        if (strlen($hostname) > 190 || ! preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?\z/', $hostname)) {
            throw new \DomainException('Invalid guest hostname.');
        }
        $fingerprint = hash('sha256', json_encode([
            'plan' => (int) $plan->id, 'image' => $imageKey, 'hostname' => $hostname,
            'vcpu' => (int) $plan->vcpu, 'ram_mb' => (int) $plan->ram_mb, 'disk_gb' => (int) $plan->disk_gb,
        ], JSON_THROW_ON_ERROR));

        return $this->transaction(function () use ($service, $plan, $imageKey, $hostname, $name, $fingerprint) {
            $lockedService = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if (in_array($lockedService->status, Service::DEAD_STATUSES, true)) {
                throw new \DomainException('A cancelled or terminated service cannot reserve infrastructure.');
            }
            $existing = EngineVm::where('service_id', $service->id)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new \DomainException('This service already has a different immutable reservation.');
                }

                return $existing->load('node', 'address.subnet', 'image');
            }
            $nodeIds = EnginePlanNode::where('cloud_plan_id', $plan->id)->where('enabled', true)
                ->orderBy('node_id')->lockForUpdate()->get()->pluck('node_id');
            // Stable lock order also serializes different services competing for capacity/IPs.
            $nodes = EngineNode::whereIn('id', $nodeIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($nodes as $node) {
                if (! $node->acceptsSales() || $node->location_code !== $plan->location_code
                    || $node->arch !== $plan->arch) {
                    continue;
                }
                $image = EngineImage::where('node_id', $node->id)->where('key', $imageKey)
                    ->where('arch', $node->arch)->lockForUpdate()->first();
                if (! $image || ! $image->accepted() || (int) $image->min_ram_mb > (int) $plan->ram_mb
                    || (int) $image->min_disk_gb > (int) $plan->disk_gb || ! $this->hasCapacity($node, $plan)) {
                    continue;
                }
                // Windows computer names have a stricter first-boot contract than
                // Linux hostnames. Refuse before reserving any VMID/IP/capacity.
                if (str_starts_with(strtolower((string) $image->family), 'windows')
                    && (strlen($hostname) > 15 || preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\z/D', $hostname) !== 1)) {
                    continue;
                }
                $hasIp = EngineAddress::where('node_id', $node->id)->where('state', 'free')
                    ->whereNull('service_id')->whereNull('vm_id')
                    ->whereHas('subnet', fn ($q) => $q->where('node_id', $node->id)->where('enabled', true))->exists();
                $vmid = $this->nextVmid($node);
                if (! $hasIp || $vmid === null) {
                    continue;
                }
                $vm = EngineVm::create([
                    'uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'node_id' => $node->id,
                    'image_id' => $image->id, 'vmid' => $vmid, 'name' => $name,
                    'vcpu' => $plan->vcpu, 'ram_mb' => $plan->ram_mb, 'disk_gb' => $plan->disk_gb,
                    'status' => 'reserved', 'fingerprint' => $fingerprint,
                    'metadata' => ['hostname' => $hostname, 'cloud_plan_id' => $plan->id,
                        'traffic_quota_bytes' => max(0, (int) $plan->traffic_gb) * 1000000000,
                        'image_snapshot' => ['key' => $image->key, 'template_vmid' => (int) $image->template_vmid,
                            'checksum_sha256' => $image->checksum_sha256, 'arch' => $image->arch,
                            'accepted_at' => $image->accepted_at->toIso8601String(),
                            'initialization_verified_at' => $image->initialization_verified_at->toIso8601String(),
                            'license_verified_at' => $image->license_verified_at?->toIso8601String()]],
                ]);
                $address = $this->ipam->reserveLocked($node, $vm);
                $vm->forceFill(['address_id' => $address->id])->save();

                return $vm->load('node', 'address.subnet', 'image');
            }

            throw new \DomainException('No healthy accepted node has sufficient capacity, image and public IPv4 inventory.');
        });
    }

    private function transaction(callable $reserve): EngineVm
    {
        $outer = DB::transactionLevel();
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction($reserve, 3);
            } catch (QueryException $e) {
                // SQLite cannot upgrade two simultaneous read snapshots to writers.
                // Retry the WHOLE reservation after rollback, giving the other writer
                // time to commit; never retry just the insert with stale capacity.
                if ($outer !== 0 || DB::getDriverName() !== 'sqlite' || $attempt >= 5
                    || ! in_array((int) ($e->errorInfo[1] ?? 0), [5, 6], true)) {
                    throw $e;
                }
                usleep(random_int(20000, 60000));
            }
        }
    }

    private function hasCapacity(EngineNode $node, CloudPlan $plan): bool
    {
        $usage = EngineVm::where('node_id', $node->id)->where('status', '!=', 'deleted')
            ->selectRaw('COALESCE(SUM(vcpu), 0) AS vcpu, COALESCE(SUM(ram_mb), 0) AS ram_mb, COALESCE(SUM(disk_gb), 0) AS disk_gb')->first();
        foreach (['vcpu', 'ram_mb', 'disk_gb'] as $resource) {
            if ((int) $node->{$resource.'_capacity'} - (int) $node->{$resource.'_reserve'} - (int) $usage->{$resource} < (int) $plan->{$resource}) {
                return false;
            }
        }

        return true;
    }

    private function nextVmid(EngineNode $node): ?int
    {
        $min = max(100, (int) $node->vmid_min);
        $max = min(999999999, (int) $node->vmid_max);
        // Never reuse IDs, even after deletion: a delayed receipt must not target a newer guest.
        $used = EngineVm::where('node_id', $node->id)->whereBetween('vmid', [$min, $max])->pluck('vmid')->all();
        $templates = EngineImage::where('node_id', $node->id)->pluck('template_vmid')->all();
        $taken = array_fill_keys(array_map('intval', array_merge($used, $templates)), true);
        for ($id = $min; $id <= $max; $id++) {
            if (! isset($taken[$id])) {
                return $id;
            }
        }

        return null;
    }
}
