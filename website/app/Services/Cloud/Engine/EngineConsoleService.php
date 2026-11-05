<?php

namespace App\Services\Cloud\Engine;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\EngineConsoleGrant;
use App\Models\EngineNode;
use App\Models\EngineVm;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** PVE credentials exist only in the authenticated gateway response, never browser DTOs. */
class EngineConsoleService
{
    public function __construct(private ProxmoxTransport $transport) {}
    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($code);
        }
    }

    private function current(EngineVm $vm, int $customerId, bool $remote = true): EngineNode
    {
        $service = Service::whereKey($vm->service_id)->lockForUpdate()->first();
        $node = EngineNode::whereKey($vm->node_id)->first();
        $this->require($service && (int) $service->customer_id === $customerId && $service->status === 'active'
            && $service->cancelled_at === null
            && Customer::whereKey($customerId)->where('status', 'active')->exists()
            && in_array($vm->status, ['running', 'off'], true)
            && CloudInstance::where('service_id', $vm->service_id)->where('provider', 'cloud-engine')->where('provider_ref', $vm->uuid)->exists(), 'console_service_not_available');
        $this->require($node && $node->health === 'healthy' && $node->health_checked_at
            && $node->health_checked_at->timestamp <= now()->timestamp + 30
            && $node->health_checked_at->timestamp >= now()->timestamp - (int) $node->health_ttl_seconds, 'console_node_not_available');

        if ($remote) {
            $config = $this->transport->request($node, 'GET', '/nodes/'.rawurlencode($node->pve_node).'/qemu/'.$vm->vmid.'/config');
            $this->require(($config['ok'] ?? false) && is_array($config['data'])
                && ($config['data']['name'] ?? null) === $vm->name
                && ($config['data']['description'] ?? null) === EngineWorker::marker($vm)
                && (string) ($config['data']['template'] ?? '0') === '0', 'console_remote_identity_not_verified');
        }

        return $node;
    }

    private function gatewayUrl(): string
    {
        $url = (string) config('cloud-engine.console.gateway_url');
        $parts = parse_url($url);
        $this->require(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && filled($parts['host'] ?? null)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/', '/console'], true)
            && strlen((string) config('cloud-engine.console.gateway_secret')) >= 32, 'console_gateway_not_configured');

        return $url;
    }

    public function available(EngineVm $vm, int $customerId): bool
    {
        try {
            $this->gatewayUrl();
            $this->current($vm, $customerId, false);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function issue(EngineVm $vm, int $customerId): array
    {
        $url = $this->gatewayUrl();

        return DB::transaction(function () use ($vm, $customerId, $url) {
            $current = EngineVm::whereKey($vm->id)->lockForUpdate()->firstOrFail();
            $node = $this->current($current, $customerId);
            $token = bin2hex(random_bytes(32));
            $expires = now()->addSeconds(min(90, max(10, (int) config('cloud-engine.console.ttl_seconds', 90))));
            EngineConsoleGrant::create(['vm_id' => $current->id, 'service_id' => $current->service_id,
                'customer_id' => $customerId, 'node_id' => $node->id, 'vmid' => $current->vmid,
                'vm_uuid' => $current->uuid, 'fingerprint' => $current->fingerprint,
                'token_hash' => hash('sha256', $token), 'expires_at' => $expires]);

            return ['url' => rtrim($url, '/').'/#'.$token, 'token' => $token,
                'expires_at' => $expires->toIso8601String(), 'protocol' => 'rfb', 'one_use' => true];
        }, 3);
    }

    /** Only the HMAC-authenticated internal controller calls this method. */
    public function exchange(string $token, string $phase): array
    {
        $this->require(preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1
            && in_array($phase, ['redeem', 'connect', 'validate'], true), 'console_grant_invalid');

        return DB::transaction(function () use ($token, $phase) {
            $field = $phase === 'redeem' ? 'token_hash' : 'session_hash';
            $grant = EngineConsoleGrant::where($field, hash('sha256', $token))->lockForUpdate()->first();
            $this->require($grant && ! $grant->revoked_at && $grant->expires_at->isFuture(), 'console_grant_expired');
            $vm = EngineVm::whereKey($grant->vm_id)->lockForUpdate()->first();
            $this->require($vm && (int) $vm->service_id === (int) $grant->service_id
                && (int) $vm->node_id === (int) $grant->node_id && (int) $vm->vmid === (int) $grant->vmid
                && hash_equals($grant->vm_uuid, $vm->uuid) && hash_equals($grant->fingerprint, $vm->fingerprint), 'console_identity_changed');
            $node = $this->current($vm, (int) $grant->customer_id);
            $this->require($grant->expires_at->isFuture(), 'console_grant_expired');
            if ($phase === 'redeem') {
                $this->require(! $grant->redeemed_at, 'console_grant_already_used');
                $session = bin2hex(random_bytes(32));
                $grant->update(['session_hash' => hash('sha256', $session), 'redeemed_at' => now()]);

                return ['session' => $session, 'expires_at' => $grant->expires_at->toIso8601String()];
            }
            $this->require((bool) $grant->redeemed_at, 'console_session_invalid');
            if ($phase === 'validate') {
                $this->require((bool) $grant->claimed_at, 'console_session_not_connected');

                return ['valid' => true, 'expires_at' => $grant->expires_at->toIso8601String()];
            }
            $this->require(! $grant->claimed_at, 'console_session_already_claimed');
            $endpoint = parse_url((string) $node->api_url);
            $this->require(is_array($endpoint) && ($endpoint['scheme'] ?? '') === 'https'
                && filled($endpoint['host'] ?? null) && ! isset($endpoint['user']) && ! isset($endpoint['pass'])
                && ! isset($endpoint['query']) && ! isset($endpoint['fragment'])
                && in_array($endpoint['path'] ?? '', ['', '/', '/api2/json'], true)
                && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $node->pve_node) === 1
                && filled($node->token_id) && filled($node->token_secret), 'console_target_invalid');
            $grant->update(['claimed_at' => now()]);

            return ['target' => ['endpoint' => 'https://'.$endpoint['host'].(isset($endpoint['port']) ? ':'.$endpoint['port'] : ''),
                'node' => $node->pve_node, 'vmid' => (int) $vm->vmid, 'token_id' => $node->token_id,
                'token_secret' => $node->token_secret, 'tls_ca_file' => $node->tls_ca_file],
                'expires_at' => $grant->expires_at->toIso8601String()];
        }, 3);
    }

    /** Revoke immediately; retain IP until the longest possible gateway lease expires. */
    public function clearForDeletion(EngineVm $vm): bool
    {
        return DB::transaction(function () use ($vm) {
            EngineVm::whereKey($vm->id)->lockForUpdate()->firstOrFail();
            EngineConsoleGrant::where('vm_id', $vm->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            return ! EngineConsoleGrant::where('vm_id', $vm->id)->whereNotNull('claimed_at')->where('expires_at', '>', now())->exists();
        }, 3);
    }
}
