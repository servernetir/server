<?php

namespace App\Services\Cloud;

use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/** Separate, default-off owner pilot. Public capability and supplier sales flags are untouched. */
class VpsTcConsoleBroker
{
    public function __construct(private VpsTcConsole $console) {}

    public function enabledFor(Service $service): bool
    {
        // Read persisted flags so another process can revoke a pending launch.
        return Setting::query()->whereKey('vpstc_console_owner_pilot_enabled')->value('value') === '1'
            && (string) $service->id === Setting::query()->whereKey('vpstc_console_owner_service_id')->value('value')
            && (int) $service->customer_id === 1
            && Customer::whereKey(1)->where('code', 'SN-457136')->where('status', 'active')->exists()
            && preg_match('/\A[a-f0-9]{64}\z/D', (string) Setting::getSecret('vpstc_console_worker_key')) === 1
            && $this->console->origin() !== null
            && $this->console->context($service) !== null;
    }

    public function issue(Service $service, string $locale): string
    {
        $current = Service::find($service->id);
        abort_unless($current && (int) $current->customer_id === (int) $service->customer_id && $this->enabledFor($current), 403);
        $context = $this->console->context($current);
        abort_unless($context !== null, 403);
        $token = bin2hex(random_bytes(32));
        Cache::put($this->key('ticket', $token), ['context' => $context, 'expires' => now()->addSeconds(90)->timestamp], 90);
        $locale = in_array($locale, ['fa', 'en', 'tr'], true) ? $locale : 'fa';
        return 'https://vps-vnc.servernet.cloud/'.$locale.'/#'.$token;
    }

    public function handle(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $ts = (string) $request->header('X-SN-Time', '');
        $sig = (string) $request->header('X-SN-Signature', '');
        $secret = (string) Setting::getSecret('vpstc_console_worker_key');
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $secret) || strlen($body) > 256
            || ! preg_match('/\A[0-9]{10}\z/D', $ts) || abs(now()->timestamp - (int) $ts) > 30
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $sig)
            || ! hash_equals(hash_hmac('sha256', $ts."\n".$body, $secret), $sig)) {
            return $this->response(['ok' => false], 403);
        }
        $data = json_decode($body, true);
        $action = is_array($data) ? ($data['action'] ?? null) : null;
        $token = is_array($data) ? ($data['token'] ?? null) : null;
        if (! in_array($action, ['redeem', 'claim'], true) || ! is_string($token)
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $token)) { return $this->response(['ok' => false], 400); }
        $key = $this->key($action === 'redeem' ? 'ticket' : 'session', $token);
        $lock = Cache::lock($key.':lock', 90);
        if (! $lock->get()) { return $this->response(['ok' => false], 410); }
        try {
            // Pull while locked; failed attempts cannot reuse a grant or launch a second proxy.
            $grant = Cache::pull($key);
            $context = is_array($grant) ? ($grant['context'] ?? null) : null;
            $service = is_array($context) ? Service::find($context['service'] ?? 0) : null;
            if (! $service || ($grant['expires'] ?? 0) <= now()->timestamp || ! $this->enabledFor($service)
                || $this->console->context($service) !== $context) { return $this->response(['ok' => false], 410); }
            if ($action === 'redeem') {
                $upstream = $this->console->prepare($service, $context);
                if (! $this->enabledFor($service) || $this->console->context($service) !== $context) {
                    return $this->response(['ok' => false], 410);
                }
                $session = bin2hex(random_bytes(32));
                $password = $upstream['password'];
                unset($upstream['password']);
                Cache::put($this->key('session', $session), ['context' => $context,
                    'upstream' => Crypt::encryptString(json_encode($upstream, JSON_THROW_ON_ERROR)),
                    'expires' => now()->addSeconds(30)->timestamp], 30);
                // Only VM-console-scoped VNC challenge material; never the PVE login cookie, CSRF or service password.
                return $this->response(['session' => $session, 'password' => $password]);
            }
            $upstream = json_decode(Crypt::decryptString($grant['upstream']), true, 8, JSON_THROW_ON_ERROR);
            return $this->response($upstream);
        } catch (\Throwable) {
            // A provider exception may contain credentials or request bodies. Never report or echo it.
            return $this->response(['ok' => false], 502);
        } finally { $lock->release(); }
    }

    private function key(string $phase, string $token): string { return 'vpstc-console:'.$phase.':'.hash('sha256', $token); }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Content-Type-Options', 'nosniff');
    }
}
