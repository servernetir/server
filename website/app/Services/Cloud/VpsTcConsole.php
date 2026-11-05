<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\CloudProviderOperation;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Independently implemented Proxmox protocol; never redirects a PVE login ticket to a browser. */
class VpsTcConsole
{
    public function __construct(private VpsTcApi $api) {}

    public function origin(?string $location = 'tr-istanbul'): ?string
    {
        // Provider-confirmed origins from support ticket 876841. Never use a
        // hostname supplied by a browser, service payload or guessed fallback.
        if ($location === 'us-new-york') { return 'https://nyc1.whitelabelservices.us:8006'; }
        if ($location !== 'tr-istanbul') { return null; }
        $origin = (string) Setting::get('vpstc_console_pve_origin', '');
        // Preserve the existing, explicitly configured Istanbul gateway.
        return in_array($origin, ['https://console.whitelabelservices.us', 'https://console.whitelabelservices.us:8006'], true)
            ? $origin : null;
    }

    /** This lookup is local; opening a service page performs no upstream login. */
    public function context(Service $service): ?array
    {
        $service = Service::find($service->getKey());
        $instance = $service ? CloudInstance::where('service_id', $service->id)->first() : null;
        $origin = $instance ? $this->origin($instance->location_code) : null;
        if (! $service || $service->status !== 'active' || ! $instance || $instance->provider !== 'vpstc'
            || $origin === null
            || ! in_array($instance->status, ['running', 'off', 'stopped'], true)
            || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $instance->provider_ref)
            || CloudInstance::where('provider', 'vpstc')->where('provider_ref', $instance->provider_ref)->count() !== 1) {
            return null;
        }
        $ops = CloudProviderOperation::where('provider', 'vpstc')->whereIn('action', ['create', 'attach'])
            ->where('resource_ref', $instance->provider_ref)->get();
        $op = $ops->count() === 1 ? $ops->first() : null;
        if (! $op || ($op->payload['_account'] ?? null) !== $this->api->accountFingerprint()
            || ($op->payload['_spec']['name'] ?? null) !== 'sn-svc-'.$service->id) {
            return null;
        }
        $native = $op->payload['_native'] ?? [];
        if ($op->action === 'attach' && ($op->state !== 'complete'
            || (int) ($native['customer_id'] ?? 0) !== (int) $service->customer_id
            || (int) ($native['local_service_id'] ?? 0) !== (int) $service->id
            || (string) ($native['service_id'] ?? '') !== (string) $instance->provider_ref
            || (string) ($native['ipv4'] ?? '') !== (string) $instance->ipv4)) {
            return null;
        }
        return ['service' => (int) $service->id, 'customer' => (int) $service->customer_id,
            'instance' => (int) $instance->id, 'ref' => (string) $instance->provider_ref,
            'account' => $this->api->accountFingerprint(), 'origin' => $origin, 'location' => $instance->location_code,
            'attached_vm' => $op->action === 'attach' ? (string) ($native['vm_id'] ?? '') : null];
    }

    /** Only login, inventory and console-grant calls; no power, rebuild, order or billing mutation. */
    public function prepare(Service $service, array $expected): array
    {
        $context = $this->context($service);
        if (! $context || $context !== $expected || ! $context['origin']) { $this->unavailable(); }
        $r = $this->api->service($context['ref']);
        $remote = $r['data']['service'] ?? [];
        if (! $r['ok'] || (string) ($remote['id'] ?? '') !== $context['ref'] || ($remote['status'] ?? '') !== 'Active') {
            $this->unavailable();
        }
        // The parent service credentials are distinct from the guest's SSH/root credentials.
        $user = $remote['username'] ?? null; $password = $remote['password'] ?? null;
        if (! is_string($user) || ! preg_match('/\A[a-zA-Z0-9_.-]{1,100}(?:@pve)?\z/D', $user)
            || ! is_string($password) || $password === '' || strlen($password) > 4096) { $this->unavailable(); }
        if (! str_ends_with($user, '@pve')) { $user .= '@pve'; }
        $r = $this->api->vms($context['ref']); $rows = $r['data']['vms'] ?? [];
        if (! $r['ok'] || ! is_array($rows) || count($rows) !== 1) { $this->unavailable(); }
        $row = reset($rows);
        $vm = is_array($row) ? (string) ($row['vmid'] ?? $row['id'] ?? array_key_first($rows)) : '';
        if (! preg_match('/\A[1-9][0-9]{0,9}\z/D', $vm)
            || ($context['attached_vm'] !== null && $context['attached_vm'] !== $vm)) { $this->unavailable(); }
        $login = $this->pve($context['origin'], 'POST', '/api2/json/access/ticket', ['username' => $user, 'password' => $password]);
        $ticket = $login['ticket'] ?? null; $csrf = $login['CSRFPreventionToken'] ?? null;
        if (! $this->safeSecret($ticket, 'PVE:') || ! is_string($csrf) || ! preg_match('/\A[A-Za-z0-9+\/=:-]{1,512}\z/D', $csrf)
            || ($login['username'] ?? null) !== $user) { $this->unavailable(); }
        $resources = $this->pve($context['origin'], 'GET', '/api2/json/cluster/resources?type=vm', [], $ticket);
        // Reject accounts exposing any other VM. A parent-level account must never enter this flow.
        if (! array_is_list($resources) || count($resources) !== 1) { $this->unavailable(); }
        $resource = $resources[0]; $node = $resource['node'] ?? null;
        if ((string) ($resource['vmid'] ?? '') !== $vm || ($resource['type'] ?? '') !== 'qemu'
            || ! is_string($node) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $node)) { $this->unavailable(); }
        if ($this->context($service) !== $context) { $this->unavailable(); }
        $base = '/api2/json/nodes/'.$node.'/qemu/'.$vm;
        $grant = $this->pve($context['origin'], 'POST', $base.'/vncproxy', ['websocket' => 1], $ticket, $csrf);
        $port = $grant['port'] ?? null; $vnc = $grant['ticket'] ?? null;
        // The gateway serializes ports as decimal strings. Normalize only the exact allowed range.
        if (is_string($port) && preg_match('/\A59[0-9]{2}\z/D', $port)) { $port = (int) $port; }
        $password = $grant['password'] ?? $vnc;
        // Current Proxmox prepends the eight-character VNC password to its scoped ticket
        // for old clients. Keep that password separate from the websocket ticket.
        if (is_string($vnc) && preg_match('/\A([\x21-\x7e]{8}):(PVEVNC:[A-Za-z0-9+\/=:@_.-]+)\z/D', $vnc, $parts)) {
            if (! is_string($password) || ! hash_equals($parts[1], $password)) { $this->unavailable(); }
            $vnc = $parts[2];
        }
        if (! is_int($port) || $port < 5900 || $port > 5999 || ! $this->safeSecret($vnc, 'PVEVNC:')
            || ! is_string($password) || ! (preg_match('/\A[\x21-\x7e]{8}\z/D', $password)
                || ($password === $vnc && $this->safeSecret($password, 'PVEVNC:')))
            || $this->context($service) !== $context) { $this->unavailable(); }
        return ['origin' => $context['origin'], 'path' => $base.'/vncwebsocket',
            'port' => $port, 'cookie' => $ticket, 'vnc' => $vnc, 'password' => $password];
    }

    private function pve(string $origin, string $method, string $path, array $body = [], ?string $ticket = null, ?string $csrf = null): array
    {
        $http = Http::acceptJson()->asForm()->connectTimeout(5)->timeout(12)
            ->withOptions(['verify' => true, 'allow_redirects' => false]);
        if ($ticket !== null) { $http = $http->withHeaders(['Cookie' => 'PVEAuthCookie='.$ticket]); }
        if ($csrf !== null) { $http = $http->withHeaders(['CSRFPreventionToken' => $csrf]); }
        // No retry. Never include provider response, URL or credentials in an exception.
        try {
            $r = $method === 'POST' ? $http->post($origin.$path, $body) : $http->get($origin.$path);
            $data = strlen($r->body()) <= 65536 && $r->successful() ? $r->json('data') : null;
        } catch (\Throwable) { $this->unavailable(); }
        if (! is_array($data)) { $this->unavailable(); }
        return $data;
    }

    private function safeSecret(mixed $value, string $prefix): bool
    {
        return is_string($value) && str_starts_with($value, $prefix) && strlen($value) <= 4096
            && preg_match('/\A[A-Za-z0-9+\/=:@_.-]+\z/D', $value) === 1;
    }

    private function unavailable(): never { throw new RuntimeException('Console unavailable'); }
}
