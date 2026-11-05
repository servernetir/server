<?php

namespace App\Services\Cloud\Engine;

use App\Models\EngineNode;

/** Read-only admission observation; a heartbeat alone cannot accept node capacity. */
class NodeHealth
{
    public function __construct(private ProxmoxTransport $transport) {}

    public function check(EngineNode $node): array
    {
        $result = $this->transport->request($node, 'GET', '/nodes/'.rawurlencode($node->pve_node).'/status');
        $data = $result['data'] ?? null;
        $valid = ($result['ok'] ?? false) && is_array($data)
            && is_numeric($data['uptime'] ?? null) && (int) $data['uptime'] >= 0
            && is_numeric(data_get($data, 'cpuinfo.cpus')) && (int) data_get($data, 'cpuinfo.cpus') > 0
            && (int) data_get($data, 'cpuinfo.cores') > 0 && (int) data_get($data, 'cpuinfo.sockets') > 0
            && is_numeric(data_get($data, 'memory.total')) && (int) data_get($data, 'memory.total') > 0;
        $state = $valid ? 'healthy' : 'unreachable';
        if ($valid && ((int) $node->vcpu_capacity > (int) data_get($data, 'cpuinfo.cores') * (int) data_get($data, 'cpuinfo.sockets')
            || (int) $node->ram_mb_capacity * 1048576 > (int) data_get($data, 'memory.total'))) {
            $state = 'capacity_mismatch';
        }
        if ($valid) {
            $base = '/nodes/'.rawurlencode($node->pve_node);
            $storage = $this->transport->request($node, 'GET', $base.'/storage/'.rawurlencode($node->storage).'/status');
            $network = $this->transport->request($node, 'GET', $base.'/network');
            $inventory = $this->transport->request($node, 'GET', $base.'/qemu');
            $usage = $node->vms()->where('status', '!=', 'deleted')->get();
            $reserved = app(EngineCapacity::class)->usage($node);
            $remainingDisk = max(0, (int) $node->disk_gb_capacity - (int) $node->disk_gb_reserve - $reserved['disk_gb']);
            $remainingRam = max(0, (int) $node->ram_mb_capacity - (int) $node->ram_mb_reserve - $reserved['ram_mb']);
            $s = $storage['data'] ?? [];
            if (! ($storage['ok'] ?? false) || ! is_array($s) || ($s['active'] ?? 0) != 1
                || ! is_numeric($s['total'] ?? null) || ! is_numeric($s['avail'] ?? null)
                || (int) $s['total'] < (int) $node->disk_gb_capacity * 1073741824
                || (int) $s['avail'] < $remainingDisk * 1073741824
                || (int) data_get($data, 'memory.free', -1) < $remainingRam * 1048576) {
                $state = 'capacity_mismatch';
            }
            $bridge = ($network['ok'] ?? false) && is_array($network['data'])
                ? collect($network['data'])->first(fn ($i) => is_array($i) && ($i['iface'] ?? '') === $node->bridge
                    && ($i['type'] ?? '') === 'bridge' && ($i['active'] ?? 0) == 1) : null;
            if (! $bridge) {
                $state = 'network_unverified';
            }
            if (! ($inventory['ok'] ?? false) || ! is_array($inventory['data'])) {
                $state = 'inventory_unverified';
            } else {
                $known = $usage->pluck('vmid')->map(fn ($id) => (int) $id)->all();
                $templates = $node->images()->get()->filter(fn ($i) => $i->accepted())->pluck('template_vmid')->map(fn ($id) => (int) $id)->unique()->all();
                $observedTemplates = [];
                foreach ($inventory['data'] as $guest) {
                    if (! is_array($guest) || ! is_numeric($guest['vmid'] ?? null)) {
                        $state = 'inventory_unverified';

                        continue;
                    }
                    $id = (int) $guest['vmid'];
                    if (in_array($id, $templates, true) && ($guest['template'] ?? 0) == 1) {
                        $observedTemplates[] = $id;
                    } elseif (! in_array($id, $known, true)) {
                        $state = 'inventory_unverified';
                    }
                }
                if (array_diff($templates, $observedTemplates)) {
                    $state = 'inventory_unverified';
                }
            }
        }
        // Never copy a raw provider response, URL or token into metadata/logs.
        $metadata = $node->metadata ?? [];
        $metadata['health_observation'] = [
            'state' => $state, 'observed_at' => now()->toIso8601String(),
            'error_code' => $valid ? null : ($result['error'] ?? 'invalid_health_response'),
        ];
        $node->update(['health' => $state, 'health_checked_at' => now(), 'metadata' => $metadata]);

        return ['ok' => $state === 'healthy', 'state' => $state];
    }
}
