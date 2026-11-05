<?php

namespace App\Services\Cloud\Engine;

use App\Models\EngineOperation;
use App\Models\EngineVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One persisted phase per lease. No automatic resubmission after an uncertain mutation. */
class EngineWorker
{
    use EngineResizePhases;
    private ?EngineOperation $currentOperation = null;
    public function __construct(private ProxmoxTransport $transport, private Ipam $ipam) {}

    public static function marker(EngineVm $vm): string
    {
        return 'servernet-engine:'.$vm->uuid.':'.$vm->fingerprint;
    }

    public function tick(EngineOperation $operation): bool
    {
        $token = (string) Str::uuid();
        $claimed = EngineOperation::whereKey($operation->id)->whereIn('status', ['pending', 'running', 'uncertain'])
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->update(['lease_token' => $token, 'lease_until' => now()->addSeconds(90), 'attempts' => DB::raw('attempts + 1')]);
        if (! $claimed) {
            return false;
        }
        $operation->refresh();
        $this->currentOperation = $operation;
        try {
            if ($operation->deadline_at->isPast()) {
                $this->transition($operation, $operation->phase, 'blocked', 'operation_deadline');
                return true;
            }
            $vm = $operation->vm()->with(['node', 'image', 'address.subnet', 'service'])->firstOrFail();
            if (in_array($operation->action, ['create', 'rebuild'], true)) {
                $image = $operation->action === 'rebuild' ? \App\Models\EngineImage::find($operation->payload['image_id'] ?? 0) : $vm->image;
                $snapshot = $operation->action === 'rebuild' ? ($operation->payload['image_snapshot'] ?? []) : ($vm->metadata['image_snapshot'] ?? []);
                if (! $image || ! $image->accepted() || (int) ($snapshot['template_vmid'] ?? 0) !== (int) $image->template_vmid
                    || ($snapshot['checksum_sha256'] ?? '') !== $image->checksum_sha256 || ($snapshot['key'] ?? '') !== $image->key) {
                    $this->transition($operation, $operation->phase, 'blocked', 'image_not_accepted');
                    return true;
                }
            }
            if ($operation->action !== 'delete' && $vm->status === 'deleted') {
                $this->transition($operation, $operation->phase, 'failed', 'server_deleted');
                return true;
            }
            if ($operation->action !== 'delete' && $vm->status === 'deleting') {
                $this->transition($operation, $operation->phase, 'blocked', 'server_deleting');
                return true;
            }
            if (in_array($operation->action, ['create', 'rebuild'], true) && (! $vm->service
                || ! config('cloud-engine.enabled', false) || ! app(EnginePaidGate::class)->allows($vm->service))) {
                $this->transition($operation, $operation->phase, 'blocked', 'confirmed_payment_required');
                return true;
            }
            if (! in_array($operation->action, ['delete', 'metadata'], true) && $vm->service?->isDead()) {
                $this->transition($operation, $operation->phase, 'blocked', 'service_inactive');
                return true;
            }
            if (! in_array($operation->action, ['create', 'delete', 'metadata'], true)
                && ! $this->billingResume($operation, $vm->service)
                && ($vm->service?->status !== 'active' || $vm->service?->cancelled_at !== null || $vm->status === 'deleting')) {
                $this->transition($operation, $operation->phase, 'blocked', 'service_inactive');
                return true;
            }
            $this->advance($operation, $vm);
        } catch (\Throwable) {
            // Never include raw upstream, credentials or guest output in customer-visible state.
            if ($this->hasLease($operation)) {
                $this->transition($operation, $operation->phase, 'uncertain', 'worker_interrupted');
            }
        } finally {
            EngineOperation::whereKey($operation->id)->where('lease_token', $token)
                ->update(['lease_token' => null, 'lease_until' => null]);
            $this->currentOperation = null;
        }
        return true;
    }

    private function path(EngineVm $vm, string $suffix = ''): string
    {
        return '/nodes/'.rawurlencode($vm->node->pve_node).'/qemu/'.$vm->vmid.$suffix;
    }

    private function request(EngineVm $vm, string $method, string $suffix, array $payload = []): array
    {
        if ($method !== 'GET') { $this->assertMutationAllowed($this->currentOperation, $vm); }
        return $this->transport->request($vm->node, $method, $this->path($vm, $suffix), $payload);
    }

    private function config(EngineVm $vm): array
    {
        return $this->request($vm, 'GET', '/config');
    }

    private function owned(EngineOperation $op, EngineVm $vm, array $response): bool
    {
        if (! $response['ok']) {
            $this->transition($op, $op->phase, 'uncertain', $response['error']);
            return false;
        }
        $config = $response['data'];
        if (! is_array($config) || ($config['description'] ?? '') !== self::marker($vm)
            || ($config['name'] ?? '') !== $vm->name) {
            $this->updateVm($op, $vm, ['status' => 'quarantined']);
            $this->transition($op, $op->phase, 'blocked', 'vm_identity_mismatch');
            return false;
        }
        return true;
    }

    private function advance(EngineOperation $op, EngineVm $vm): void
    {
        if ($op->action === 'metadata') {
            DB::transaction(function () use ($op, $vm) {
                $service = \App\Models\Service::whereKey($vm->service_id)->lockForUpdate()->firstOrFail();
                $vm = EngineVm::whereKey($vm->id)->lockForUpdate()->firstOrFail();
                $this->assertLease($op);
                if (! app(EnginePublicMetadata::class)->editable($service, $vm)) {
                    $this->transition($op, $op->phase, 'blocked', 'service_inactive'); return;
                }
                $metadata = $vm->metadata ?? [];
                if (array_key_exists('labels', $op->payload)) $metadata['customer_labels'] = $op->payload['labels'];
                if (array_key_exists('protection', $op->payload)) $metadata['customer_protection'] = [
                    ...($metadata['customer_protection'] ?? []), ...$op->payload['protection']];
                $vm->update(['metadata' => $metadata]);
                $this->transition($op, 'completed', 'succeeded');
            }, 3);
            return;
        }
        if ($op->action === 'resize') { $this->advanceResize($op, $vm); return; }
        if ($op->phase === 'queued') {
            if ($op->action === 'create') {
                $this->transition($op, 'clone_prepare');
            } elseif ($op->action === 'delete') {
                $this->updateVm($op, $vm, ['status' => 'deleting']);
                $this->transition($op, 'delete_prepare');
            } else {
                $this->transition($op, $op->action.'_prepare');
            }
            return;
        }
        if (str_ends_with($op->phase, '_wait')) {
            $this->pollTask($op, $vm);
            return;
        }
        if ($op->phase === 'clone_prepare') {
            $existing = $this->config($vm);
            if ($existing['ok']) {
                if ($this->owned($op, $vm, $existing)) {
                    $this->transition($op, 'configure_prepare');
                }
                return;
            }
            if ($existing['status'] !== 404) {
                $this->transition($op, 'clone_prepare', 'uncertain', $existing['error']);
                return;
            }
            $snapshot = $vm->metadata['image_snapshot'] ?? [];
            if (! $vm->image->accepted() || (int) ($snapshot['template_vmid'] ?? 0) !== (int) $vm->image->template_vmid
                || ($snapshot['checksum_sha256'] ?? '') !== $vm->image->checksum_sha256
                || ($snapshot['key'] ?? '') !== $vm->image->key) {
                $this->transition($op, 'clone_prepare', 'blocked', 'image_not_accepted');
                return;
            }
            $this->transition($op, 'clone_reconcile'); // intent durably precedes the HTTP request
            $this->assertMutationAllowed($op, $vm);
            $r = $this->transport->request($vm->node, 'POST', '/nodes/'.rawurlencode($vm->node->pve_node)
                .'/qemu/'.$vm->image->template_vmid.'/clone', [
                    'newid' => $vm->vmid, 'name' => $vm->name, 'description' => self::marker($vm),
                    'full' => 1, 'storage' => $vm->node->storage,
                    'pool' => $vm->node->metadata['pool'] ?? 'servernet-customers',
                ]);
            $this->updateVm($op, $vm, ['status' => 'building']);
            $this->receipt($op, $r, 'clone_wait', 'clone_reconcile');
            return;
        }
        if ($op->phase === 'clone_reconcile') {
            $r = $this->config($vm);
            if ($r['ok'] && $this->owned($op, $vm, $r) && empty($r['data']['lock'])) {
                $this->transition($op, 'configure_prepare');
            } elseif (! $r['ok']) {
                // Includes authoritative 404: lost clone response might still be queued remotely.
                $this->transition($op, 'clone_reconcile', 'uncertain', 'clone_outcome_unknown');
            }
            return;
        }
        $config = $this->config($vm);
        if (($op->action === 'delete' || ($op->action === 'rebuild'
            && in_array($op->phase, ['delete_prepare', 'stop_reconcile', 'delete_submit', 'delete_reconcile'], true)))
            && $config['status'] === 404) {
            $this->verifyDeleted($op, $vm);
            return;
        }
        if (! $this->owned($op, $vm, $config)) {
            return;
        }
        switch ($op->phase) {
            case 'initialization_prepare':
                $windows = strtolower($vm->image->family) === 'windows';
                $command = $windows
                    ? ['C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
                        "[Console]::OutputEncoding=[System.Text.UTF8Encoding]::new(\$false); Get-Content -LiteralPath 'C:\\ProgramData\\ServerNet\\cloud-engine-ready.json' -Raw -ErrorAction Stop"]
                    : ['/usr/bin/cloud-init', 'status', '--format', 'json'];
                $this->transition($op, 'initialization_reconcile');
                $r = $this->request($vm, 'POST', '/agent/exec', ['command' => $command]);
                if ($r['ok'] && is_numeric($r['data']['pid'] ?? null)) {
                    $this->updateOperation($op, ['receipt' => ['initialization_pid' => (int) $r['data']['pid']]]);
                } else {
                    $this->transition($op, $op->phase, 'uncertain', 'guest_initialization_unverified');
                }
                return;
            case 'initialization_reconcile':
                if (! isset($op->receipt['initialization_pid'])) {
                    $this->transition($op, $op->phase, 'uncertain', 'guest_initialization_unverified');
                    return;
                }
                $r = $this->request($vm, 'GET', '/agent/exec-status', ['pid' => $op->receipt['initialization_pid']]);
                if (! $r['ok'] || empty($r['data']['exited'])) {
                    $this->transition($op, $op->phase, 'uncertain', 'guest_initialization_pending');
                    return;
                }
                $output = (string) ($r['data']['out-data'] ?? '');
                $status = json_decode($output, true);
                if (! is_array($status)) { $status = json_decode(base64_decode($output, true) ?: '', true); }
                $valid = is_array($status) && (strtolower($vm->image->family) === 'windows'
                    ? $this->windowsReady($op, $vm, $status)
                    : (($status['status'] ?? '') === 'done' && empty($status['errors']) && empty($status['recoverable_errors'])));
                if (($r['data']['exitcode'] ?? -1) !== 0 || ! $valid) {
                    $this->transition($op, $op->phase, 'blocked', 'guest_initialization_failed');
                    return;
                }
                $this->finishReady($op, $vm);
                return;
            case 'rebuild_prepare':
                if (! app(EngineConsoleService::class)->clearForDeletion($vm)) {
                    $this->transition($op, $op->phase, 'uncertain', 'console_session_active');
                    return;
                }
                $this->updateVm($op, $vm, ['status' => 'building']);
                $this->transition($op, 'delete_prepare');
                return;
            case 'reset_password_prepare':
                $this->transition($op, 'reset_password_reconcile');
                $r = $this->request($vm, 'POST', '/agent/set-user-password', [
                    'username' => $vm->credentials['username'] ?? 'root', 'password' => $op->payload['password'],
                ]);
                if ($r['ok'] && $r['data'] === null) {
                    // QGA set-user-password is synchronous: successful return is the guest operation acknowledgement.
                    $this->saveCredentials($op, $vm, $op->payload['password']);
                    $this->transition($op, 'completed', 'succeeded');
                } else {
                    $this->transition($op, 'reset_password_reconcile', 'uncertain', 'guest_password_outcome_unknown');
                }
                return;
            case 'reset_password_reconcile':
                // A lost QGA acknowledgement cannot be verified by reading cloud-init configuration.
                $this->transition($op, $op->phase, 'uncertain', 'guest_password_outcome_unknown');
                return;
            case 'hostname_prepare':
                $this->transition($op, 'hostname_reconcile');
                $r = $this->request($vm, 'POST', '/agent/exec', [
                    'command' => ['/usr/bin/hostnamectl', 'set-hostname', $op->payload['hostname']],
                ]);
                if ($r['ok'] && is_numeric($r['data']['pid'] ?? null)) {
                    $this->updateOperation($op, ['receipt' => ['guest_pid' => (int) $r['data']['pid']]]);
                }
                return;
            case 'hostname_reconcile':
                if (isset($op->receipt['guest_pid'])) {
                    $r = $this->request($vm, 'GET', '/agent/exec-status', ['pid' => $op->receipt['guest_pid']]);
                    if (! $r['ok'] || empty($r['data']['exited'])) {
                        $this->transition($op, $op->phase, 'uncertain', 'guest_operation_pending');
                        return;
                    }
                    if (($r['data']['exitcode'] ?? -1) !== 0) {
                        $this->transition($op, $op->phase, 'failed', 'guest_command_failed');
                        return;
                    }
                }
                $r = $this->request($vm, 'GET', '/agent/get-host-name');
                if ($r['ok'] && strtolower((string) ($r['data']['result']['host-name'] ?? $r['data']['host-name'] ?? '')) === strtolower($op->payload['hostname'])) {
                    $this->updateVm($op, $vm, ['metadata' => [...($vm->metadata ?? []), 'hostname' => $op->payload['hostname']]]);
                    DB::transaction(function () use ($op, $vm) {
                        $this->assertLease($op);
                        $vm->service->cloudInstance?->update(['hostname' => $op->payload['hostname']]);
                    });
                    $this->transition($op, 'completed', 'succeeded');
                } else {
                    $this->transition($op, $op->phase, 'uncertain', 'guest_hostname_not_verified');
                }
                return;
            case 'configure_prepare':
                $password = $vm->credentials['password'] ?? null;
                if (! is_string($password) || $password === '') {
                    $this->transition($op, $op->phase, 'blocked', 'initial_credentials_unavailable');
                    return;
                }
                $payload = [
                    'cores' => $vm->vcpu, 'sockets' => 1, 'memory' => $vm->ram_mb, 'balloon' => 0,
                    'description' => self::marker($vm), 'name' => $vm->name,
                    'net0' => 'virtio,bridge='.$vm->node->bridge.',firewall=1',
                    'ipconfig0' => $this->ipConfig($vm),
                    'ciuser' => $vm->credentials['username'] ?? 'root', 'cipassword' => $password,
                    'agent' => 'enabled=1',
                ];
                if (! empty($vm->credentials['ssh_keys'])) {
                    $payload['sshkeys'] = rawurlencode(implode("\n", $vm->credentials['ssh_keys']));
                }
                $this->transition($op, 'configure_reconcile');
                $this->receipt($op, $this->request($vm, 'PUT', '/config', $payload), 'configure_wait', 'configure_reconcile');
                return;
            case 'configure_reconcile':
                $c = $config['data'];
                if ((int) ($c['cores'] ?? 0) !== (int) $vm->vcpu || (int) ($c['sockets'] ?? 0) !== 1
                    || (int) ($c['memory'] ?? 0) !== (int) $vm->ram_mb || (int) ($c['balloon'] ?? -1) !== 0
                    || ($c['ipconfig0'] ?? '') !== $this->ipConfig($vm)
                    || ! str_contains((string) ($c['net0'] ?? ''), 'bridge='.$vm->node->bridge)
                    || ! str_contains((string) ($c['net0'] ?? ''), 'firewall=1')) {
                    $this->transition($op, $op->phase, 'uncertain', 'configuration_not_verified');
                    return;
                }
                $this->transition($op, 'firewall_prepare');
                return;
            case 'firewall_prepare':
            case 'firewall_set_reconcile':
                $r = $this->request($vm, 'GET', '/firewall/ipset');
                if (! $r['ok'] || ! is_array($r['data'])) {
                    $this->transition($op, $op->phase, 'uncertain', 'firewall_unverified');
                    return;
                }
                $found = collect($r['data'])->contains(fn ($entry) => ($entry['name'] ?? '') === 'ipfilter-net0');
                if ($found) {
                    $this->transition($op, 'firewall_address_prepare');
                } elseif ($op->phase === 'firewall_prepare') {
                    $this->transition($op, 'firewall_set_reconcile');
                    $r = $this->request($vm, 'POST', '/firewall/ipset', ['name' => 'ipfilter-net0']);
                    if (! $r['ok']) { $this->transition($op, $op->phase, 'uncertain', 'firewall_unverified'); }
                } else {
                    $this->transition($op, $op->phase, 'uncertain', 'firewall_unverified');
                }
                return;
            case 'firewall_address_prepare':
            case 'firewall_address_reconcile':
                $r = $this->request($vm, 'GET', '/firewall/ipset/ipfilter-net0');
                if (! $r['ok'] || ! is_array($r['data'])) {
                    $this->transition($op, $op->phase, 'uncertain', 'firewall_unverified');
                    return;
                }
                if (count($r['data']) === 1 && in_array($r['data'][0]['cidr'] ?? '', [$vm->address->address, $vm->address->address.'/32'], true)) {
                    $this->transition($op, 'firewall_options_prepare');
                } elseif (count($r['data']) === 0 && $op->phase === 'firewall_address_prepare') {
                    $this->transition($op, 'firewall_address_reconcile');
                    $r = $this->request($vm, 'POST', '/firewall/ipset/ipfilter-net0', ['cidr' => $vm->address->address.'/32']);
                    if (! $r['ok']) { $this->transition($op, $op->phase, 'uncertain', 'firewall_unverified'); }
                } else {
                    $this->transition($op, $op->phase, 'blocked', 'firewall_address_mismatch');
                }
                return;
            case 'firewall_options_prepare':
                $this->transition($op, 'firewall_options_reconcile');
                $r = $this->request($vm, 'PUT', '/firewall/options', ['enable' => 1, 'ipfilter' => 1, 'macfilter' => 1]);
                if (! $r['ok']) { $this->transition($op, $op->phase, 'uncertain', 'firewall_unverified'); }
                return;
            case 'firewall_options_reconcile':
                $r = $this->request($vm, 'GET', '/firewall/options');
                if ($r['ok'] && (int) ($r['data']['enable'] ?? 0) === 1
                    && (int) ($r['data']['ipfilter'] ?? 0) === 1 && (int) ($r['data']['macfilter'] ?? 0) === 1) {
                    $this->transition($op, 'disk_prepare');
                } else {
                    $this->transition($op, $op->phase, 'uncertain', 'firewall_unverified');
                }
                return;
            case 'disk_prepare':
            case 'disk_reconcile':
                $disk = (string) ($vm->image->metadata['disk'] ?? 'scsi0');
                if (! in_array($disk, ['scsi0', 'virtio0', 'sata0'], true)
                    || ! preg_match('/(?:^|,)size=([0-9]+(?:\.[0-9]+)?)([MGT])(?:,|$)/', (string) ($config['data'][$disk] ?? ''), $m)) {
                    $this->transition($op, $op->phase, 'blocked', 'boot_disk_unknown');
                    return;
                }
                $size = (float) $m[1] * ['M' => 1 / 1024, 'G' => 1, 'T' => 1024][$m[2]];
                if ($size > $vm->disk_gb) {
                    $this->transition($op, $op->phase, 'blocked', 'disk_reservation_exceeded');
                } elseif ($size === (float) $vm->disk_gb) {
                    $this->transition($op, 'start_prepare');
                } elseif ($op->phase === 'disk_prepare') {
                    $this->transition($op, 'disk_reconcile');
                    $this->receipt($op, $this->request($vm, 'PUT', '/resize', ['disk' => $disk, 'size' => $vm->disk_gb.'G']),
                        'disk_wait', 'disk_reconcile');
                } else {
                    $this->transition($op, $op->phase, 'uncertain', 'disk_resize_not_verified');
                }
                return;
            case 'start_prepare':
                $this->transition($op, 'start_reconcile');
                $this->receipt($op, $this->request($vm, 'POST', '/status/start'), 'start_wait', 'start_reconcile');
                return;
            case 'start_reconcile':
                $this->verifyReady($op, $vm);
                return;
            case 'power_prepare':
                $action = $op->payload['action'];
                $endpoint = ['on' => 'start', 'off' => 'stop', 'shutdown' => 'shutdown', 'reboot' => 'reboot', 'reset' => 'reset'][$action];
                $status = $this->request($vm, 'GET', '/status/current');
                if (! $status['ok']) {
                    $this->transition($op, $op->phase, 'uncertain', $status['error']);
                    return;
                }
                $this->updateOperation($op, ['receipt' => ['baseline_uptime' => $status['data']['uptime'] ?? null]]);
                $this->transition($op, 'power_reconcile');
                $this->receipt($op, $this->request($vm, 'POST', '/status/'.$endpoint), 'power_wait', 'power_reconcile');
                return;
            case 'power_reconcile':
                $status = $this->request($vm, 'GET', '/status/current');
                $action = $op->payload['action'];
                $expected = in_array($action, ['off', 'shutdown'], true) ? 'stopped' : 'running';
                $reset = in_array($action, ['reboot', 'reset'], true);
                if ($status['ok'] && ($status['data']['status'] ?? '') === $expected
                    && (! $reset || (($op->receipt['task_ok'] ?? false)
                        && isset($status['data']['uptime'], $op->receipt['baseline_uptime'])
                        && $status['data']['uptime'] < $op->receipt['baseline_uptime']))) {
                    $this->updateVm($op, $vm, ['status' => $expected === 'stopped' ? 'off' : 'running']);
                    $this->transition($op, 'completed', 'succeeded');
                } else {
                    $this->transition($op, $op->phase, 'uncertain', 'power_not_verified');
                }
                return;
            case 'delete_prepare':
                $status = $this->request($vm, 'GET', '/status/current');
                if (! $status['ok']) {
                    $this->transition($op, $op->phase, 'uncertain', $status['error']);
                    return;
                }
                if (($status['data']['status'] ?? '') === 'stopped') {
                    $this->transition($op, 'delete_submit');
                } else {
                    $this->transition($op, 'stop_reconcile');
                    $this->receipt($op, $this->request($vm, 'POST', '/status/stop'), 'stop_wait', 'stop_reconcile');
                }
                return;
            case 'stop_reconcile':
                $status = $this->request($vm, 'GET', '/status/current');
                if ($status['ok'] && ($status['data']['status'] ?? '') === 'stopped') {
                    $this->transition($op, 'delete_submit');
                } else {
                    $this->transition($op, $op->phase, 'uncertain', 'stop_not_verified');
                }
                return;
            case 'delete_submit':
                $this->transition($op, 'delete_reconcile');
                $this->receipt($op, $this->request($vm, 'DELETE', '', ['purge' => 1, 'destroy-unreferenced-disks' => 1]),
                    'delete_wait', 'delete_reconcile');
                return;
            case 'delete_reconcile':
                $this->transition($op, $op->phase, 'uncertain', 'deletion_not_verified');
                return;
        }
    }

    private function receipt(EngineOperation $op, array $r, string $wait, string $reconcile): void
    {
        if ($r['ok'] && is_string($r['data']) && str_starts_with($r['data'], 'UPID:')) {
            $this->updateOperation($op, ['receipt' => [...($op->receipt ?? []), 'task' => $r['data'], 'task_ok' => false]]);
            $this->transition($op, $wait);
        } else {
            $this->transition($op, $reconcile, $r['ok'] ? 'running' : 'uncertain', $r['error']);
        }
    }

    private function pollTask(EngineOperation $op, EngineVm $vm): void
    {
        $task = $op->receipt['task'] ?? '';
        if (! is_string($task) || ! str_starts_with($task, 'UPID:')) {
            $this->transition($op, $op->phase, 'blocked', 'task_receipt_missing');
            return;
        }
        $r = $this->transport->request($vm->node, 'GET', '/nodes/'.rawurlencode($vm->node->pve_node).'/tasks/'.rawurlencode($task).'/status');
        if (! $r['ok'] || ! is_array($r['data'])) {
            $this->transition($op, $op->phase, 'uncertain', $r['error'] ?? 'task_response_invalid');
            return;
        }
        if (($r['data']['status'] ?? '') === 'running') {
            return;
        }
        if (($r['data']['status'] ?? '') !== 'stopped' || ($r['data']['exitstatus'] ?? '') !== 'OK') {
            $this->transition($op, $op->phase, 'blocked', 'provider_task_failed');
            return;
        }
        $this->updateOperation($op, ['receipt' => [...$op->receipt, 'task_ok' => true]]);
        $next = ['clone_wait' => 'configure_prepare', 'configure_wait' => 'configure_reconcile',
            'disk_wait' => 'disk_reconcile', 'start_wait' => 'start_reconcile',
            'power_wait' => 'power_reconcile', 'stop_wait' => 'stop_reconcile', 'delete_wait' => 'delete_reconcile'];
        $this->transition($op, $next[$op->phase]);
    }

    private function verifyReady(EngineOperation $op, EngineVm $vm): void
    {
        $r = $this->request($vm, 'GET', '/status/current');
        if (! $r['ok'] || ($r['data']['status'] ?? '') !== 'running') {
            $this->transition($op, $op->phase, 'uncertain', 'boot_not_verified');
            return;
        }
        // Accepted images must contain QGA. Boot alone never proves the reserved public IP works.
        $network = $this->request($vm, 'GET', '/agent/network-get-interfaces');
        $interfaces = $network['data']['result'] ?? null;
        if (! $network['ok'] || ! is_array($interfaces)) {
            $this->transition($op, $op->phase, 'uncertain', 'guest_initialization_unverified');
            return;
        }
        foreach ($interfaces as $interface) {
            foreach ($interface['ip-addresses'] ?? [] as $address) {
                if (($address['ip-address'] ?? '') === $vm->address->address) {
                    $this->transition($op, 'initialization_prepare');
                    return;
                }
            }
        }
        $this->transition($op, $op->phase, 'uncertain', 'guest_address_unverified');
    }

    private function finishReady(EngineOperation $op, EngineVm $vm): void
    {
        if ($op->action === 'rebuild') {
            $this->saveCredentials($op, $vm, $vm->credentials['password']);
            DB::transaction(function () use ($op, $vm) {
                $this->assertLease($op);
                $vm->service->update(['cloud_image_key' => $vm->image->key]);
                $vm->service->cloudInstance?->update(['image_key' => $vm->image->key]);
            });
        }
        $this->updateVm($op, $vm, ['status' => 'running', 'metadata' => [...($vm->metadata ?? []), 'ready_at' => now()->toIso8601String()]]);
        $this->transition($op, 'completed', 'succeeded');
    }

    private function verifyDeleted(EngineOperation $op, EngineVm $vm): void
    {
        $list = $this->transport->request($vm->node, 'GET', '/nodes/'.rawurlencode($vm->node->pve_node).'/qemu');
        if (! $list['ok'] || ! is_array($list['data'])) {
            $this->transition($op, 'delete_reconcile', 'uncertain', 'deletion_inventory_unavailable');
            return;
        }
        foreach ($list['data'] as $row) {
            if (! is_array($row) || ! isset($row['vmid']) || (int) $row['vmid'] === (int) $vm->vmid) {
                $this->transition($op, 'delete_reconcile', 'uncertain', 'deletion_not_verified');
                return;
            }
        }
        // If a console session can remain alive, its IP must not be reused yet.
        if (! app(EngineConsoleService::class)->clearForDeletion($vm)) {
            $this->transition($op, 'delete_reconcile', 'uncertain', 'console_session_active');
            return;
        }
        // Routed addresses have no NAT/floating-IP bindings. Reject unverified external dependencies.
        if (! empty($vm->metadata['network_dependencies'])) {
            $this->transition($op, 'delete_reconcile', 'blocked', 'network_dependencies_unverified');
            return;
        }
        if ($op->action === 'rebuild') {
            $this->updateVm($op, $vm, ['image_id' => $op->payload['image_id'], 'status' => 'building',
                'metadata' => [...($vm->metadata ?? []), 'image_snapshot' => $op->payload['image_snapshot']],
                'credentials' => ['username' => \App\Models\EngineImage::findOrFail($op->payload['image_id'])->family === 'windows' ? 'Administrator' : 'root',
                    'password' => $op->payload['password'], 'ssh_keys' => $vm->credentials['ssh_keys'] ?? []]]);
            $this->transition($op, 'clone_prepare');
            return;
        }
        $this->updateVm($op, $vm, ['status' => 'deleting']);
        DB::transaction(function () use ($op, $vm) {
            $this->assertLease($op);
            $this->ipam->releaseVerified($vm, ['uuid' => $vm->uuid, 'node_id' => $vm->node_id, 'vmid' => $vm->vmid,
                'absent' => true, 'network_clear' => true, 'console_clear' => true, 'verified_at' => now()->toIso8601String()]);
        });
        $this->updateVm($op, $vm, ['status' => 'deleted']);
        $this->transition($op, 'completed', 'succeeded');
    }

    private function transition(EngineOperation $op, string $phase, string $status = 'running', ?string $error = null): void
    {
        DB::transaction(function () use ($op, $phase, $status, $error) {
            $this->assertLease($op);
            $op->update(['phase' => $phase, 'status' => $status, 'error_code' => $error,
                'completed_at' => in_array($status, ['succeeded', 'failed', 'blocked'], true) ? now() : null]);
            DB::table('cloud_engine_operation_events')->insert(['operation_id' => $op->id,
                'phase' => $phase, 'status' => $status, 'error_code' => $error, 'created_at' => now()]);
        });
    }

    private function hasLease(?EngineOperation $op): bool
    {
        return $op !== null && EngineOperation::whereKey($op->id)->where('lease_token', $op->lease_token)
            ->whereNotNull('lease_token')->where('lease_until', '>', now())->lockForUpdate()->first() !== null;
    }

    private function saveCredentials(EngineOperation $op, EngineVm $vm, string $password): void
    {
        DB::transaction(function () use ($op, $vm, $password) {
            $this->assertLease($op);
            $vm->update(['credentials' => [...($vm->credentials ?? []), 'password' => $password]]);
            if ($instance = $vm->service->cloudInstance) {
                $instance->setPassword($password);
                $instance->save();
            }
            $vm->service->update(['password' => $password]);
        });
    }

    private function updateVm(EngineOperation $op, EngineVm $vm, array $attributes): void
    {
        DB::transaction(function () use ($op, $vm, $attributes) {
            $this->assertLease($op);
            $vm->update($attributes);
        });
    }

    private function updateOperation(EngineOperation $op, array $attributes): void
    {
        DB::transaction(function () use ($op, $attributes) {
            $this->assertLease($op);
            $op->update($attributes);
        });
    }

    private function assertLease(?EngineOperation $op): void
    {
        if (! $this->hasLease($op)) { throw new \RuntimeException('operation_lease_lost'); }
    }

    private function assertMutationAllowed(?EngineOperation $op, EngineVm $vm): void
    {
        $this->assertLease($op);
        $service = $vm->service()->firstOrFail();
        if ($op->action === 'delete') { return; }
        if ($service->isDead() || $service->cancelled_at !== null
            || ($service->status === 'suspended' && ! $this->billingResume($op, $service))
            || (in_array($op->action, ['create', 'rebuild', 'resize'], true) && ! app(EnginePaidGate::class)->allows($service))) {
            throw new \RuntimeException('service_inactive');
        }
    }

    private function billingResume(EngineOperation $op, ?\App\Models\Service $service): bool
    {
        return $service !== null && $op->action === 'power' && ($op->payload['action'] ?? '') === 'on'
            && ($op->payload['origin'] ?? '') === 'billing_resume'
            && app(EnginePaidGate::class)->allowsBillingResume($service);
    }

    private function ipConfig(EngineVm $vm): string
    {
        $bits = explode('/', $vm->address->subnet->cidr)[1] ?? '';
        if (! ctype_digit($bits) || (int) $bits < 1 || (int) $bits > 30) {
            throw new \RuntimeException('subnet_configuration_invalid');
        }
        return 'ip='.$vm->address->address.'/'.$bits.',gw='.$vm->address->subnet->gateway;
    }

    private function windowsReady(EngineOperation $op, EngineVm $vm, array $receipt): bool
    {
        $builds = ['2019' => 17763, '2022' => 20348, '2025' => 26100];
        try {
            $written = \Carbon\Carbon::parse($receipt['written_at'] ?? 'invalid');
        } catch (\Throwable) { return false; }
        return $vm->image->accepted() && ($receipt['schema_version'] ?? null) === 1
            && ($receipt['image_key'] ?? null) === $vm->image->key
            && ($receipt['initialized'] ?? null) === true && ($receipt['credential_setup'] ?? null) === true
            && isset($builds[$vm->image->version]) && (int) ($receipt['os_build'] ?? 0) === $builds[$vm->image->version]
            && strcasecmp((string) ($receipt['hostname'] ?? ''), (string) ($vm->metadata['hostname'] ?? $vm->name)) === 0
            && $written->gte($op->created_at) && $written->lte(now()->addSeconds(30));
    }
}
