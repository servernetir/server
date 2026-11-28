<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Owner-bound pilot for the two explicitly authorized existing VMs. */
class ProxmoxConsoleBroker
{
    private const PILOT = [44 => [7, '116'], 1044 => [550, '130']];
    private const ORIGIN = 'https://pve-console-ir.servernet.cloud';

    private function context(Service $service): ?array
    {
        $s = Service::find($service->id);
        $wanted = self::PILOT[$service->id] ?? null;
        $i = $s ? CloudInstance::where('service_id', $s->id)->first() : null;
        if (! $s || ! $wanted || (int) $s->customer_id !== $wanted[0]
            || $s->status !== 'active' || ! $i || $i->provider !== 'proxmox'
            || (string) $i->provider_ref !== $wanted[1] || $i->status !== 'running'
            || ! $i->isDelivered()
            || ! Customer::whereKey($wanted[0])->where('status', 'active')->exists()
            || CloudInstance::where('provider', 'proxmox')->where('provider_ref', $wanted[1])->count() !== 1) {
            return null;
        }
        return ['service' => (int) $s->id, 'customer' => $wanted[0], 'instance' => (int) $i->id, 'vm' => $wanted[1]];
    }

    public function enabledFor(Service $service): bool
    {
        return Setting::get('proxmox_console_enabled', '0') === '1' && $this->context($service) !== null;
    }

    public function issue(Service $service): string
    {
        $context = $this->context($service);
        abort_unless($context && $this->enabledFor($service) && (int) $service->customer_id === $context['customer'], 403);
        $nonce = bin2hex(random_bytes(32));
        Cache::put($this->key($nonce), $context + ['expires' => now()->addSeconds(75)->timestamp], 75);
        return $nonce;
    }

    /** The gateway receives only the VM number after a one-use claim. */
    public function claim(Request $request): JsonResponse
    {
        $nonce = $request->input('grant');
        if (! is_string($nonce) || ! preg_match('/\A[a-f0-9]{64}\z/D', $nonce)) {
            return $this->result(['ok' => false], 400);
        }
        $key = $this->key($nonce);
        $lock = Cache::lock($key.':lock', 10);
        if (! $lock->get()) {
            return $this->result(['ok' => false], 410);
        }
        try {
            $grant = Cache::pull($key);
            $s = is_array($grant) ? Service::find($grant['service'] ?? 0) : null;
            if (! $s || ($grant['expires'] ?? 0) <= now()->timestamp || ! $this->enabledFor($s)) {
                return $this->result(['ok' => false], 410);
            }
            $expires = $grant['expires'];
            unset($grant['expires']);
            if ($this->context($s) !== $grant) {
                return $this->result(['ok' => false], 410);
            }
            return $this->result(['ok' => true, 'vm' => $grant['vm']]);
        } finally {
            $lock->release();
        }
    }

    public function prepare(Service $service, string $grant): ?array
    {
        if (! $this->enabledFor($service) || ! preg_match('/\A[a-f0-9]{64}\z/D', $grant)) {
            return null;
        }
        try {
            $r = Http::acceptJson()->connectTimeout(4)->timeout(15)
                ->withOptions(['verify' => true, 'allow_redirects' => false])
                ->post(self::ORIGIN.'/prepare', ['grant' => $grant]);
            $data = $r->successful() && strlen($r->body()) < 4096 ? $r->json() : null;
            if (! is_array($data) || ! is_string($data['url'] ?? null)
                || ! preg_match('#\Awss://pve-console-ir\.servernet\.cloud/ws/[a-f0-9]{64}\z#D', $data['url'])
                || ! is_string($data['password'] ?? null)
                || ! preg_match('/\A[\x21-\x7e]{8}\z/D', $data['password'])) {
                return null;
            }
            return ['url' => $data['url'], 'password' => $data['password']];
        } catch (\Throwable) {
            return null; // Never log a grant, challenge, ticket or response.
        }
    }

    private function key(string $nonce): string
    {
        return 'sn-proxmox-console:'.hash('sha256', $nonce);
    }

    private function result(array $body, int $code = 200): JsonResponse
    {
        return response()->json($body, $code)->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Content-Type-Options', 'nosniff');
    }
}
