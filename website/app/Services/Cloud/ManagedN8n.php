<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Service;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Durable, resumable application delivery; it never purchases another VM. */
class ManagedN8n
{
    public function advance(Service $service): bool
    {
        if (! ManagedN8nCatalog::handles($service) || $service->isDead()
            || $service->status !== 'active' || $service->provision_status !== 'done') { return false; }
        $lock = Cache::lock('n8n-install-'.$service->id, 240);
        if (! $lock->get()) { return false; }
        try {
            $service->refresh();
            if ($service->isDead() || $service->status !== 'active' || $service->provision_status !== 'done') { return false; }
            $instance = CloudInstance::where('service_id', $service->id)->first();
            if (! $instance || ! in_array($instance->status, CloudInstance::READY_STATUSES, true)
                || blank($instance->provider_ref) || blank($instance->ipv4)) { return false; }
            $state = (array) data_get($instance->meta, 'n8n', []);
            if (($state['state'] ?? '') === 'ready') { return true; }
            if (($state['state'] ?? '') === 'failed') { return false; }
            if (! isset($state['hostname'], $state['nonce'], $state['password_enc'], $state['started_at'])) {
                $domain = $instance->hasPrivateIp() ? app(NpmClient::class)->baseDomain() : config('managed_n8n.domain');
                if (! $domain || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    throw new \RuntimeException('proxy_not_ready');
                }
                $state = ['state' => 'waiting_ssh', 'hostname' => 'n8n-'.$service->id.'-'.Str::lower(Str::random(8)).'.'.$domain,
                    'owner_email' => $service->customer->email,
                    'nonce' => Str::random(40), 'version' => config('managed_n8n.version'),
                    'password_enc' => Crypt::encryptString('Sn!'.Str::random(25).'9a'),
                    'started_at' => now()->toIso8601String(), 'attempts' => 0];
                $this->save($instance, $state);
            }
            if (now()->diffInMinutes(\Illuminate\Support\Carbon::parse($state['started_at']), true)
                > (int) config('managed_n8n.install_timeout_minutes', 30)) {
                throw new \RuntimeException('installation_timeout');
            }
            $dns = app(\App\Services\Dns\CloudflareDns::class)->pointSubdomain(
                $state['hostname'], $instance->hasPrivateIp() ? CloudInstance::publicHost() : $instance->ipv4
            );
            if (! ($dns['ok'] ?? false)) { throw new \RuntimeException('dns_not_ready'); }
            $result = app(ManagedN8nSsh::class)->step($service, $instance, $state);
            // Transport may have pinned the allocation's first SSH host key.
            $state['ssh_key_sha256'] = data_get($instance->fresh()->meta, 'n8n.ssh_key_sha256');
            $state['state'] = $result['state'];
            if ($result['state'] === 'installed') {
                $state['state'] = 'https';
                if ($instance->hasPrivateIp()) {
                    $npm = app(NpmClient::class);
                    $proxy = $npm->ensureProxyHost($state['hostname'], (string) $instance->ipv4, 80);
                    if (! ($proxy['ok'] ?? false) || empty($proxy['id'])) { throw new \RuntimeException('proxy_not_ready'); }
                    $cert = $npm->ensureApplicationCertificate((int) $proxy['id'], $state['hostname'], (string) $instance->ipv4);
                    if (! ($cert['ok'] ?? false)) { $this->save($instance, $state); return false; }
                }
                // Validate TLS, the exact allocation marker, and the actual n8n readiness endpoint.
                $url = 'https://'.$state['hostname'];
                $marker = Http::timeout(8)->withoutRedirecting()->get($url.'/.well-known/servernet-n8n');
                $health = Http::timeout(8)->withoutRedirecting()->get($url.'/healthz/readiness');
                if ($marker->status() === 200 && $health->status() === 200
                    && (int) $marker->json('service_id') === (int) $service->id
                    && hash_equals($state['nonce'], (string) $marker->json('nonce'))
                    && $health->json('status') === 'ok') {
                    $state['state'] = 'ready';
                    $state['ready_at'] = now()->toIso8601String();
                    $state['last_issue'] = null;
                }
            }
            $this->save($instance, $state);
            if ($state['state'] === 'ready') {
                CloudInstance::whereKey($instance->id)->update(['meta->public_domain' => $state['hostname']]);
                Service::whereKey($service->id)->update(['domain' => $state['hostname']]);
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            // Never log transport exceptions: their messages can contain credentials or script data.
            $code = in_array($e->getMessage(), ['installation_timeout', 'ssh_host_key_changed', 'guest_install_failed',
                'dns_not_ready', 'proxy_not_ready', 'ssh_login_failed', 'ssh_host_key_missing', 'guest_directory_failed'], true)
                ? $e->getMessage() : 'installation_retry';
            if (isset($instance, $state) && $instance) {
                $state['attempts'] = ($state['attempts'] ?? 0) + 1;
                $state['last_issue'] = $code;
                if (in_array($code, ['installation_timeout', 'ssh_host_key_changed', 'guest_install_failed'], true)) {
                    $state['state'] = 'failed';
                }
                $this->save($instance, $state);
            }
            ErrorTracker::noteOnce('provision', 'n8n application delivery: '.$code, 1800, ['service' => $service->id]);
            return false;
        } finally { $lock->release(); }
    }

    private function save(CloudInstance $instance, array $state): void
    {
        DB::transaction(function () use ($instance, $state) {
            $fresh = CloudInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $meta = (array) $fresh->meta;
            $pinned = data_get($meta, 'n8n.ssh_key_sha256');
            if ($pinned) { $state['ssh_key_sha256'] = $pinned; }
            $meta['n8n'] = $state;
            // Avoid dialect-specific JSON object casts and NULL JSON_SET. Merge
            // under the row lock so no transport state or peer metadata is lost.
            $fresh->update(['meta' => $meta]);
        });
    }

    /** Remove every public route before this allocation or its IP can be reused. */
    public function removeGateway(CloudInstance $instance): bool
    {
        $lock = Cache::lock('n8n-install-'.$instance->service_id, 240);
        if (! $lock->get()) { return false; }
        try {
            $instance->refresh();
            $host = data_get($instance->meta, 'n8n.hostname');
            if (! $host) { return true; }
            if ($instance->hasPrivateIp()
                && ! (app(NpmClient::class)->removeProxyHost($host)['ok'] ?? false)) { return false; }
            return app(\App\Services\Dns\CloudflareDns::class)->removeSubdomain($host)['ok'] ?? false;
        } finally { $lock->release(); }
    }
}
