<?php

namespace App\Services\Cloud\Engine;

use App\Models\{CloudImage, CloudLocation, CloudPlan, EngineAddress, EngineImage, EngineNode, EngineOperation, EnginePlanNode, EngineVm, Service};
use App\Services\Cloud\CloudProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Dedicated infrastructure adapter. Existing Iran Proxmox and other providers are independent. */
class EngineProvider implements CloudProvider
{
    public function __construct(private Placement $placement, private EngineOperations $operations,
        private ProxmoxTransport $transport, private EnginePaidGate $paid) {}

    public function slug(): string { return 'cloud-engine'; }

    public function isConfigured(): bool
    {
        return (bool) config('cloud-engine.enabled', false) && Schema::hasTable('cloud_engine_nodes')
            && EngineNode::query()->get()->contains(fn ($n) => $n->acceptsSales());
    }

    public function capabilities(): array
    {
        return ['console' => false, 'rebuild' => true, 'resize' => false, 'snapshot' => false,
            'metrics' => true, 'reset_password' => true, 'ipv6' => false, 'rescue' => false,
            'ssh_key' => false, 'extra_ip' => false, 'hostname' => true];
    }

    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'Cloud Engine is disabled or has no accepted healthy node.'];
        }
        foreach (EngineNode::all() as $node) {
            if ($node->acceptsSales()) {
                $r = $this->transport->request($node, 'GET', '/nodes/'.rawurlencode($node->pve_node).'/status');
                if (! $r['ok'] || ! is_array($r['data'])) {
                    return ['ok' => false, 'message' => 'Infrastructure health could not be verified.'];
                }
            }
        }
        return ['ok' => true, 'message' => 'Accepted infrastructure nodes responded.'];
    }

    public function fetchCatalog(): array
    {
        $empty = ['locations' => [], 'plans' => [], 'images' => []];
        if (! Schema::hasTable('cloud_engine_nodes') || ! Schema::hasTable('cloud_engine_plan_nodes')) {
            return ['ok' => false, 'message' => 'Infrastructure inventory has not been initialized.'] + $empty;
        }
        $nodes = EngineNode::with('images')->get()->keyBy('id');
        $plans = CloudPlan::where('provider', $this->slug())->get();
        $locations = CloudLocation::whereIn('code', $plans->pluck('location_code'))->get()->map(fn ($location) => [
            'code' => $location->code, 'country' => $location->country, 'city' => $location->city,
            'provider_location' => $location->code, 'latitude' => $location->latitude, 'longitude' => $location->longitude,
        ])->all();
        $imageGroups = EngineImage::all()->filter(fn ($image) => $image->accepted())->groupBy('key');
        foreach ($imageGroups as $versions) {
            if ($versions->map(fn ($image) => $image->family.'|'.$image->version.'|'.$image->arch)->unique()->count() !== 1) {
                return ['ok' => false, 'message' => 'Accepted image identities conflict across infrastructure nodes.'] + $empty;
            }
        }
        $images = $imageGroups->map(function ($versions, $key) {
            $image = $versions->first();
            return ['provider_ref' => $key, 'key' => $key, 'kind' => 'os', 'family' => $image->family,
                'version' => $image->version, 'label' => $image->family.' '.$image->version, 'arch' => $image->arch,
                'min_disk_gb' => (int) $versions->min('min_disk_gb'), 'min_ram_mb' => (int) $versions->min('min_ram_mb')];
        })->values()->all();
        $rows = $plans->map(function ($plan) use ($nodes) {
            $eligible = EnginePlanNode::where('cloud_plan_id', $plan->id)->where('enabled', true)->pluck('node_id');
            $available = config('cloud-engine.enabled', false) && $eligible->contains(function ($id) use ($nodes, $plan) {
                $node = $nodes->get($id);
                return $node && $this->placementAvailable($node, $plan);
            });
            return ['provider_ref' => $plan->provider_ref, 'provider_location' => $plan->provider_location ?: $plan->location_code,
                'location_code' => $plan->location_code, 'vcpu' => (int) $plan->vcpu, 'ram_mb' => (int) $plan->ram_mb,
                'disk_gb' => (int) $plan->disk_gb, 'disk_type' => $plan->disk_type, 'traffic_gb' => (int) $plan->traffic_gb,
                'cpu_kind' => $plan->cpu_kind, 'arch' => $plan->arch, 'cost_eur_cents' => (int) $plan->cost_eur_cents,
                'in_stock' => (bool) $available, 'name' => $plan->public_name,
                'network_terms' => $plan->network_terms];
        })->all();
        return ['ok' => true, 'message' => '', 'locations' => $locations, 'plans' => $rows, 'images' => $images];
    }

    private function placementAvailable(EngineNode $node, CloudPlan $plan): bool
    {
        if (! $node->acceptsSales() || $node->location_code !== $plan->location_code || $node->arch !== $plan->arch
            || ! $node->images->contains(fn ($image) => $image->accepted() && $image->arch === $plan->arch
                && (int) $image->min_disk_gb <= $plan->disk_gb && (int) $image->min_ram_mb <= $plan->ram_mb)) {
            return false;
        }
        $usage = EngineVm::where('node_id', $node->id)->where('status', '!=', 'deleted')
            ->selectRaw('COALESCE(SUM(vcpu),0) vcpu, COALESCE(SUM(ram_mb),0) ram_mb, COALESCE(SUM(disk_gb),0) disk_gb')->first();
        foreach (['vcpu', 'ram_mb', 'disk_gb'] as $resource) {
            if ((int) $node->{$resource.'_capacity'} - (int) $node->{$resource.'_reserve'} - (int) $usage->{$resource} < (int) $plan->{$resource}) {
                return false;
            }
        }
        $min = max(100, (int) $node->vmid_min);
        $max = min(999999999, (int) $node->vmid_max);
        $reservedIds = EngineVm::where('node_id', $node->id)->whereBetween('vmid', [$min, $max])->pluck('vmid');
        $templateIds = $node->images->pluck('template_vmid')->filter(fn ($id) => $id >= $min && $id <= $max);
        if ($max < $min || $reservedIds->merge($templateIds)->unique()->count() >= $max - $min + 1) {
            return false;
        }
        return EngineAddress::where('node_id', $node->id)->where('state', 'free')->whereNull('service_id')->whereNull('vm_id')
            ->whereHas('subnet', fn ($q) => $q->where('enabled', true)->where('node_id', $node->id))->exists();
    }

    public function createServer(array $spec): array
    {
        $fail = ['ok' => false, 'message' => 'The server could not be reserved.', 'ref' => null,
            'ipv4' => null, 'ipv6' => null, 'root_password' => null, 'status' => 'building'];
        try {
            $service = Service::find((int) ($spec['labels']['snet-service'] ?? 0));
            if (! $service || ($spec['name'] ?? '') !== 'sn-svc-'.$service->id
                || ! $this->paid->allows($service) || ! $this->isConfigured()) {
                return $fail + ['error_code' => 'confirmed_payment_or_infrastructure_required'];
            }
            $plan = CloudPlan::where('provider', $this->slug())->where('provider_ref', $spec['plan_ref'] ?? '')
                ->where('id', $service->cloud_plan_id)->first();
            if (! $plan) {
                return $fail;
            }
            $imageRef = (string) ($spec['image_ref'] ?? '');
            $image = CloudImage::where('provider', $this->slug())->where('provider_ref', $imageRef)->first();
            $vm = DB::transaction(function () use ($service, $plan, $image, $imageRef, $spec) {
                $vm = $this->placement->reserve($service, $plan, $image?->key ?? $imageRef);
                $vm = EngineVm::whereKey($vm->id)->lockForUpdate()->firstOrFail();
                if (empty($vm->credentials['password'])) {
                    $vm->update(['credentials' => ['username' => $vm->image->family === 'windows' ? 'Administrator' : 'root',
                        'password' => bin2hex(random_bytes(24)), 'ssh_keys' => array_values($spec['ssh_keys'] ?? [])]]);
                }
                $this->operations->submit($vm, 'create', [], 'create:'.$service->id);
                return $vm->load('address');
            });
            return ['ok' => true, 'message' => '', 'ref' => $vm->uuid, 'ipv4' => $vm->address->address,
                'ipv6' => null, 'root_password' => $vm->credentials['password'], 'status' => 'building'];
        } catch (\Throwable) {
            return $fail;
        }
    }

    public function serverStatus(string $ref): array
    {
        $vm = EngineVm::where('uuid', $ref)->with(['address', 'node'])->first();
        $empty = ['ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
        if (! $vm) {
            return ['ok' => false, 'message' => 'Server record is unavailable.', 'status' => 'unknown'] + $empty;
        }
        if ($vm->status === 'deleted') {
            return ['ok' => true, 'message' => '', 'status' => 'deleted'] + $empty;
        }
        if ($vm->status === 'quarantined') {
            return ['ok' => false, 'message' => 'Server state requires an infrastructure review.', 'status' => 'unknown'] + $empty;
        }
        $building = EngineOperation::where('vm_id', $vm->id)->whereIn('action', ['create', 'rebuild'])
            ->whereIn('status', ['pending', 'running', 'uncertain', 'blocked', 'failed'])->latest('id')->first();
        if ($building && in_array($building->status, ['blocked', 'failed'], true)) {
            return ['ok' => false, 'message' => 'Provisioning requires an infrastructure review.', 'status' => 'error'] + $empty;
        }
        if ($building) {
            return ['ok' => true, 'message' => '', 'status' => 'building', 'ipv4' => $vm->address?->address,
                'ipv6' => null, 'traffic_used_gb' => null];
        }
        $config = $this->request($vm, 'GET', '/config');
        if (! $this->identity($vm, $config)) {
            return ['ok' => false, 'message' => 'Server state could not be verified.', 'status' => 'unknown'] + $empty;
        }
        $r = $this->request($vm, 'GET', '/status/current');
        if (! $r['ok'] || ! in_array($r['data']['status'] ?? '', ['running', 'stopped'], true)) {
            return ['ok' => false, 'message' => 'Server state could not be verified.', 'status' => 'unknown'] + $empty;
        }
        return ['ok' => true, 'message' => '', 'status' => ($r['data']['status'] === 'running' ? 'running' : 'off'),
            'ipv4' => $vm->address?->address, 'ipv6' => null, 'traffic_used_gb' => null];
    }

    public function listServers(): array
    {
        $fail = ['ok' => false, 'message' => 'Complete infrastructure inventory could not be verified.', 'servers' => []];
        if (! Schema::hasTable('cloud_engine_nodes')) { return $fail; }
        $servers = [];
        $seen = [];
        foreach (EngineNode::all() as $node) {
            $prefix = '/nodes/'.rawurlencode($node->pve_node).'/qemu';
            $list = $this->transport->request($node, 'GET', $prefix);
            if (! $list['ok'] || ! is_array($list['data'])) { return $fail; }
            foreach ($list['data'] as $row) {
                if (! is_array($row) || ! is_numeric($row['vmid'] ?? null)) { return $fail; }
                $r = $this->transport->request($node, 'GET', $prefix.'/'.(int) $row['vmid'].'/config');
                if (! $r['ok'] || ! is_array($r['data'])) { return $fail; }
                $description = (string) ($r['data']['description'] ?? '');
                if (! str_starts_with($description, 'servernet-engine:')) { continue; }
                if (! preg_match('/\Aservernet-engine:([a-f0-9-]{36}):([a-f0-9]{64})\z/D', $description, $marker)
                    || isset($seen[$marker[1]]) || ! is_string($r['data']['name'] ?? null)) { return $fail; }
                $vm = EngineVm::where('uuid', $marker[1])->with('address')->first();
                if ($vm && ((int) $vm->node_id !== (int) $node->id || (int) $vm->vmid !== (int) $row['vmid']
                    || ! hash_equals($vm->fingerprint, $marker[2]) || $vm->name !== $r['data']['name'])) { return $fail; }
                $seen[$marker[1]] = true;
                $servers[] = ['ref' => $marker[1], 'name' => $r['data']['name'],
                    'status' => match ($row['status'] ?? '') { 'running' => 'running', 'stopped' => 'off', default => 'unknown' },
                    'ipv4' => $vm?->status === 'deleted' ? null : $vm?->address?->address, 'ipv6' => null,
                    'plan' => null, 'location' => $node->location_code, 'created' => $vm?->created_at?->toIso8601String()];
            }
        }
        return ['ok' => true, 'message' => '', 'servers' => $servers];
    }

    public function power(string $ref, string $action): array
    {
        return $this->enqueue($ref, 'power', ['action' => $action]);
    }

    /** Only the existing paid-renewal lifecycle may call this; never accept origin from customer input. */
    public function resumeBilling(string $ref): array
    {
        return $this->enqueue($ref, 'power', ['action' => 'on', 'origin' => 'billing_resume']);
    }

    public function deleteServer(string $ref): array
    {
        $vm = EngineVm::where('uuid', $ref)->first();
        if ($vm?->status === 'deleted') {
            return ['ok' => true, 'message' => ''];
        }
        $result = $this->enqueue($ref, 'delete', []);
        // Existing CloudProvisioner must retain release intent until authoritative disappearance.
        return ['ok' => false, 'pending' => ($result['ok'] ?? false), 'message' => $result['message'] ?: 'Deletion is awaiting verified completion.'];
    }

    private function enqueue(string $ref, string $action, array $payload): array
    {
        try {
            $vm = EngineVm::where('uuid', $ref)->with('service')->firstOrFail();
            if ($action !== 'delete' && in_array($vm->status, ['deleting', 'deleted'], true)) {
                return ['ok' => false, 'message' => 'This service is awaiting verified deletion.'];
            }
            $billingResume = $action === 'power' && ($payload['action'] ?? '') === 'on'
                && ($payload['origin'] ?? '') === 'billing_resume' && $vm->service
                && $this->paid->allowsBillingResume($vm->service);
            if ($action !== 'delete' && ! $billingResume && (! $vm->service || $vm->service->status !== 'active'
                || $vm->service->cancelled_at !== null || $vm->status === 'deleting')) {
                return ['ok' => false, 'message' => 'This service cannot be changed in its current state.'];
            }
            $active = EngineOperation::where('vm_id', $vm->id)->where('action', $action)
                ->whereIn('status', ['pending', 'running', 'uncertain'])->first();
            if ($active && $active->payload === $payload) {
                return ['ok' => true, 'pending' => true, 'message' => 'Operation is pending.'];
            }
            $this->operations->submit($vm, $action, $payload, 'adapter:'.bin2hex(random_bytes(16)));
            return ['ok' => true, 'pending' => true, 'message' => 'Operation is pending.'];
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Operation could not be queued.'];
        }
    }

    public function metrics(string $ref, string $window = '24h'): array
    {
        $fail = ['ok' => false, 'message' => 'Monitoring is unavailable.', 'series' => []];
        $vm = EngineVm::where('uuid', $ref)->with('node')->first();
        if (! $vm || ! $this->identity($vm, $this->request($vm, 'GET', '/config'))) {
            return $fail;
        }
        $r = $this->request($vm, 'GET', '/rrddata', ['timeframe' => ['1h' => 'hour', '24h' => 'day', '7d' => 'week', '30d' => 'month'][$window] ?? 'day', 'cf' => 'AVERAGE']);
        if (! $r['ok'] || ! is_array($r['data'])) {
            return $fail;
        }
        $series = [];
        foreach ($r['data'] as $row) {
            if (! is_array($row) || ! is_numeric($row['time'] ?? null)) { continue; }
            foreach (['cpu' => 'cpu', 'mem' => 'ram_bytes', 'disk' => 'disk_bytes', 'netin' => 'net_in',
                'netout' => 'net_out', 'diskread' => 'disk_read', 'diskwrite' => 'disk_write'] as $key => $public) {
                if (is_numeric($row[$key] ?? null) && is_finite((float) $row[$key])) {
                    $series[$public][] = [(int) $row['time'], (float) $row[$key]];
                }
            }
        }
        $current = ['status' => 'unknown', 'uptime_seconds' => null, 'last_boot_at' => null,
            'boot_time_estimated' => false, 'cpu_fraction' => null, 'memory_bytes' => null,
            'memory_capacity_bytes' => null, 'guest_filesystem_used_bytes' => null,
            'disk_allocated_bytes' => null, 'observed_at' => now()->toIso8601String()];
        $status = $this->request($vm, 'GET', '/status/current');
        if ($status['ok'] && is_array($status['data'])) {
            $data = $status['data'];
            $current['status'] = match ($data['status'] ?? '') { 'running' => 'running', 'stopped' => 'off', default => 'unknown' };
            if ($current['status'] === 'running' && is_numeric($data['uptime'] ?? null) && $data['uptime'] >= 0
                && $data['uptime'] <= now()->timestamp) {
                $current['uptime_seconds'] = (int) $data['uptime'];
                $current['last_boot_at'] = now()->subSeconds($current['uptime_seconds'])->toIso8601String();
                $current['boot_time_estimated'] = true;
            }
            if (is_numeric($data['cpu'] ?? null) && is_finite((float) $data['cpu']) && $data['cpu'] >= 0 && $data['cpu'] <= 1) {
                $current['cpu_fraction'] = (float) $data['cpu'];
            }
            foreach (['mem' => 'memory_bytes', 'maxmem' => 'memory_capacity_bytes', 'maxdisk' => 'disk_allocated_bytes'] as $source => $target) {
                if (is_numeric($data[$source] ?? null) && $data[$source] >= 0 && $data[$source] <= PHP_INT_MAX) {
                    $current[$target] = (int) $data[$source];
                }
            }
        }
        return ['ok' => true, 'message' => '', 'series' => $series, 'current' => $current];
    }

    private function request(EngineVm $vm, string $method, string $suffix, array $payload = []): array
    {
        return $this->transport->request($vm->node, $method, '/nodes/'.rawurlencode($vm->node->pve_node).'/qemu/'.$vm->vmid.$suffix, $payload);
    }

    private function identity(EngineVm $vm, array $r): bool
    {
        return $r['ok'] && is_array($r['data']) && ($r['data']['name'] ?? '') === $vm->name
            && ($r['data']['description'] ?? '') === EngineWorker::marker($vm);
    }

    public function rebuild(string $ref, string $imageRef, ?string $password = null): array
    {
        $image = CloudImage::where('provider', $this->slug())->where('provider_ref', $imageRef)->first();
        return $this->enqueue($ref, 'rebuild', ['image' => $image?->key ?? $imageRef, 'confirmed' => true]) + ['root_password' => null];
    }
    public function resetPassword(string $ref): array { return $this->enqueue($ref, 'reset_password', []) + ['root_password' => null]; }
    public function changeHostname(string $ref, string $hostname): array { return $this->enqueue($ref, 'hostname', ['hostname' => $hostname]); }
    public function console(string $ref): array { return $this->unsupported() + ['url' => null, 'password' => null]; }
    public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array { return $this->unsupported(); }
    public function uploadSshKey(string $name, string $publicKey): array { return $this->unsupported() + ['ref' => null]; }
    public function addExtraIps(string $ref, int $count): array { return $this->unsupported() + ['ips' => []]; }
    private function unsupported(): array { return ['ok' => false, 'message' => 'This operation is not available for this server.']; }
}
