<?php

namespace App\Services\Cloud\Engine;

use App\Models\{EngineOperation, EngineVm};

trait EngineResizePhases
{
    private function advanceResize(EngineOperation $op, EngineVm $vm): void
    {
        if (! app(EnginePaidGate::class)->allows($vm->service)) {
            $this->transition($op, $op->phase, 'blocked', 'confirmed_payment_required'); return;
        }
        if ($op->phase === 'queued') { $this->transition($op, 'resize_prepare'); return; }
        if (str_ends_with($op->phase, '_wait')) {
            $task = $op->receipt['task'] ?? '';
            if (! is_string($task) || ! str_starts_with($task, 'UPID:')) {
                $this->transition($op, $op->phase, 'uncertain', 'task_receipt_missing'); return;
            }
            $r = $this->transport->request($vm->node, 'GET', '/nodes/'.rawurlencode($vm->node->pve_node).'/tasks/'.rawurlencode($task).'/status');
            if (! $r['ok'] || ($r['data']['status'] ?? '') !== 'stopped') return;
            if (($r['data']['exitstatus'] ?? '') !== 'OK') {
                $this->transition($op, $op->phase, 'blocked', 'provider_task_failed'); return;
            }
            $this->transition($op, $op->phase === 'resize_config_wait' ? 'resize_config_reconcile' : 'resize_disk_reconcile'); return;
        }
        $config = $this->config($vm);
        if (! $this->owned($op, $vm, $config)) return;
        $live = $this->request($vm, 'GET', '/status/current');
        if (! $live['ok'] || ($live['data']['status'] ?? '') !== 'stopped') {
            $this->transition($op, $op->phase, 'blocked', 'resize_requires_off_server'); return;
        }
        $p = $op->payload; $c = $config['data'];
        if ($op->phase === 'resize_prepare') {
            $this->transition($op, 'resize_config_reconcile');
            $this->receipt($op, $this->request($vm, 'PUT', '/config', ['cores' => $p['vcpu'], 'sockets' => 1,
                'memory' => $p['ram_mb'], 'balloon' => 0]), 'resize_config_wait', 'resize_config_reconcile');
            return;
        }
        if ((int) ($c['cores'] ?? 0) !== $p['vcpu'] || (int) ($c['sockets'] ?? 0) !== 1
            || (int) ($c['memory'] ?? 0) !== $p['ram_mb'] || (int) ($c['balloon'] ?? -1) !== 0) {
            $this->transition($op, $op->phase, 'uncertain', 'resize_configuration_unverified'); return;
        }
        $disk = $vm->image->metadata['disk'] ?? 'scsi0';
        if (! in_array($disk, ['scsi0', 'virtio0', 'sata0'], true)
            || ! preg_match('/(?:^|,)size=([0-9]+(?:\.[0-9]+)?)([MGT])(?:,|$)/', (string) ($c[$disk] ?? ''), $m)) {
            $this->transition($op, $op->phase, 'blocked', 'boot_disk_unknown'); return;
        }
        $size = (float) $m[1] * ['M' => 1 / 1024, 'G' => 1, 'T' => 1024][$m[2]];
        if ($size > $p['disk_gb']) { $this->transition($op, $op->phase, 'blocked', 'disk_reservation_exceeded'); return; }
        if ($size === (float) $p['disk_gb']) {
            app(EngineResize::class)->complete($op, $vm, fn () => $this->transition($op, 'completed', 'succeeded')); return;
        }
        if ($op->phase === 'resize_config_reconcile') {
            $this->transition($op, 'resize_disk_reconcile');
            $this->receipt($op, $this->request($vm, 'PUT', '/resize', ['disk' => $disk, 'size' => $p['disk_gb'].'G']),
                'resize_disk_wait', 'resize_disk_reconcile');
        } else {
            $this->transition($op, $op->phase, 'uncertain', 'resize_disk_unverified');
        }
    }
}
