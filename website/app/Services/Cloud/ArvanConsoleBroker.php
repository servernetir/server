<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/** Acceptance-only broker. Explicit flag and exact owner fixture required; expires after 48 hours. */
class ArvanConsoleBroker
{
    public const REF = 'ir-tbz-sh1:0f0a4265-f1fb-4545-bd6e-99ac1157e72b';

    public function eligible(Service $service): bool
    {
        $instance = CloudInstance::where('service_id', $service->id)->first();

        return (int) $service->id === 456 && (int) $service->customer_id === 1
            && $service->status === 'active'
            && $instance && (int) $instance->id === 293
            && $instance->provider === 'arvan' && $instance->provider_ref === self::REF
            && ($instance->meta['acceptance_test'] ?? false) === true
            && ! in_array($instance->status, ['deleted', 'deleting', 'terminated'], true)
            && now()->lt('2026-10-16 05:38:00 UTC');
    }

    public function enabled(): bool
    {
        return Setting::get('arvan_console_worker_enabled') === '1'
            && preg_match('/\A[a-f0-9]{64}\z/', (string) Setting::getSecret('arvan_console_worker_key')) === 1;
    }

    /** Caller must first enforce customer ownership, writable state and rate limit. */
    public function issue(Service $service, string $url): string
    {
        abort_unless($this->enabled() && $this->eligible($service), 403);
        $this->validateUrl($url);
        $ticket = bin2hex(random_bytes(32));
        Cache::put($this->key('ticket', $ticket), [
            'service' => 456, 'customer' => 1, 'ref' => self::REF,
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
        if (! $this->enabled() || strlen($body) > 256 || ! preg_match('/\A[0-9]{10}\z/', $ts)
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
            $service = Service::find(456);
            if (! is_array($grant) || ($grant['expires'] ?? 0) <= now()->timestamp
                || ($grant['service'] ?? null) !== 456 || ($grant['customer'] ?? null) !== 1
                || ($grant['ref'] ?? null) !== self::REF || ! $service || ! $this->eligible($service)) {
                return $this->response(['ok' => false], 410);
            }
            if ($action === 'redeem') {
                $session = bin2hex(random_bytes(32));
                Cache::put($this->key('session', $session), $grant, max(1, $grant['expires'] - now()->timestamp));

                return $this->response(['session' => $session]);
            }
            $upstream = $this->validateUrl(Crypt::decryptString($grant['url']));
            // Set only after the exact path is observed in a successful provider handshake.
            $path = Setting::get('arvan_console_worker_socket_path');
            if (! in_array($path, ['/ir-tbz-sh1/websockify', '/ir-tbz-sh1/vnc_lite.html/websockify'], true)) {
                return $this->response(['ok' => false], 503);
            }

            return $this->response($upstream + ['path' => $path]);
        } finally {
            $lock->release();
        }
    }

    private function validateUrl(string $url): array
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $token = $query['token'] ?? null;
        abort_unless(($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'console.arvaniaas.ir'
            && ($parts['path'] ?? '') === '/ir-tbz-sh1/vnc_lite.html'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            && ! isset($parts['fragment']) && count($query) === 1
            && is_string($token) && preg_match('/\A[a-f0-9-]{36}\z/i', $token), 422);

        return ['region' => 'ir-tbz-sh1', 'token' => $token];
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
