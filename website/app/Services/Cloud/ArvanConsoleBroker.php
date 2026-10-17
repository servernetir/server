<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/** Short-lived, single-use grants bound to the current service owner and instance. */
class ArvanConsoleBroker
{
    public function eligible(Service $service): bool
    {
        $instance = CloudInstance::where('service_id', $service->id)->first();

        return $service->status === 'active' && (int) $service->customer_id > 0
            && $instance && $instance->provider === 'arvan'
            && preg_match('/\Air-[a-z0-9]+-[a-z0-9]+:[a-f0-9-]{36}\z/i', (string) $instance->provider_ref) === 1
            && in_array($instance->status, ['running', 'off', 'stopped'], true);
    }

    public function enabled(): bool
    {
        return Setting::get('arvan_console_worker_enabled') === '1'
            && preg_match('/\A[a-f0-9]{64}\z/', (string) Setting::getSecret('arvan_console_worker_key')) === 1;
    }

    /** Private acceptance window; never changes provider-wide capabilities. */
    private function acceptanceWindow(): bool
    {
        $until = (string) Setting::get('arvan_console_501_test_until', '');
        return ctype_digit($until) && (int) $until > now()->timestamp
            && (int) $until <= now()->addHour()->timestamp
            && preg_match('/\A[a-f0-9]{64}\z/', (string) Setting::getSecret('arvan_console_worker_key')) === 1;
    }

    public function enabledFor(Service $service): bool
    {
        if ($this->enabled()) {
            return true;
        }
        return $this->acceptanceWindow() && (int) $service->id === 501
            && (int) $service->customer_id === 1
            && $service->cloudInstance?->provider_ref === 'ir-tbz-sh1:29daf8cf-77c2-4f95-a6f5-c365020fdaad';
    }

    /** Caller must first enforce customer ownership, writable state and rate limit. */
    public function issue(Service $service, string $url): string
    {
        abort_unless($this->enabledFor($service) && $this->eligible($service), 403);
        $instance = CloudInstance::where('service_id', $service->id)->firstOrFail();
        $this->validateUrl($url, explode(':', $instance->provider_ref, 2)[0]);
        $ticket = bin2hex(random_bytes(32));
        Cache::put($this->key('ticket', $ticket), [
            'service' => (int) $service->id, 'customer' => (int) $service->customer_id,
            'instance' => (int) $instance->id, 'ref' => $instance->provider_ref,
            'url' => Crypt::encryptString($url), 'expires' => now()->addSeconds(90)->timestamp,
        ], 90);

        // Fragment never reaches HTTP access logs. The shell clears it before redeeming.
        return 'https://vnc.servernet.cloud/#'.$ticket;
    }

    public function handle(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $ts = (string) $request->header('X-SN-Time', '');
        $signature = (string) $request->header('X-SN-Signature', '');
        $secret = (string) Setting::getSecret('arvan_console_worker_key');
        if ((! $this->enabled() && ! $this->acceptanceWindow()) || strlen($body) > 256 || ! preg_match('/\A[0-9]{10}\z/', $ts)
            || abs(now()->timestamp - (int) $ts) > 30 || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)
            || ! hash_equals(hash_hmac('sha256', $ts."\n".$body, $secret), $signature)) {
            return $this->response(['ok' => false], 403);
        }
        $data = json_decode($body, true);
        $action = $data['action'] ?? null;
        $token = $data['token'] ?? null;
        if (! in_array($action, ['redeem', 'claim'], true) || ! is_string($token)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return $this->response(['ok' => false], 400);
        }
        // Cache::pull alone is not atomic. The shared lock serializes competing claims.
        $key = $this->key($action === 'redeem' ? 'ticket' : 'session', $token);
        $lock = Cache::lock($key.':lock', 10);
        if (! $lock->get()) {
            return $this->response(['ok' => false], 410);
        }
        try {
            $grant = Cache::pull($key);
            $service = is_array($grant) ? Service::find($grant['service'] ?? 0) : null;
            $instance = $service ? CloudInstance::where('service_id', $service->id)->first() : null;
            if (! is_array($grant) || ($grant['expires'] ?? 0) <= now()->timestamp
                || ! $service || ! $instance || ! $this->enabledFor($service) || ! $this->eligible($service)
                || ($grant['customer'] ?? null) !== (int) $service->customer_id
                || ($grant['instance'] ?? null) !== (int) $instance->id
                || ($grant['ref'] ?? null) !== $instance->provider_ref) {
                return $this->response(['ok' => false], 410);
            }
            if ($action === 'redeem') {
                $session = bin2hex(random_bytes(32));
                Cache::put($this->key('session', $session), $grant, max(1, $grant['expires'] - now()->timestamp));

                return $this->response(['session' => $session]);
            }
            $upstream = $this->validateUrl(Crypt::decryptString($grant['url']), explode(':', $instance->provider_ref, 2)[0]);
            // Set only after the exact path is observed in a successful provider handshake.
            $path = Setting::get('arvan_console_worker_socket_path');
            if (! in_array($path, ['/ir-tbz-sh1/websockify', '/ir-tbz-sh1/vnc_lite.html/websockify'], true)) {
                return $this->response(['ok' => false], 503);
            }

            return $this->response($upstream + ['path' => str_replace('/ir-tbz-sh1/', '/'.$upstream['region'].'/', $path)]);
        } finally {
            $lock->release();
        }
    }

    private function validateUrl(string $url, string $region): array
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $token = $query['token'] ?? null;
        abort_unless(($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'console.arvaniaas.ir'
            && preg_match('/\Air-[a-z0-9]+-[a-z0-9]+\z/', $region) === 1
            && ($parts['path'] ?? '') === '/'.$region.'/vnc_lite.html'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            && ! isset($parts['fragment']) && count($query) === 1
            && is_string($token) && preg_match('/\A[a-f0-9-]{36}\z/i', $token), 422);

        return ['region' => $region, 'token' => $token];
    }

    private function key(string $phase, string $token): string
    {
        return 'arvan-console-worker:'.$phase.':'.hash('sha256', $token);
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
