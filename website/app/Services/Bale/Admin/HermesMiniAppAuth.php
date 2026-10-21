<?php

namespace App\Services\Bale\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/** Owner-only signature exchange; browser receives no provider credentials. */
final class HermesMiniAppAuth
{
    public const SESSION_SECONDS = 28800;

    public function __construct(private AdminBaleGate $gate) {}

    public function owner(Request $request): ?array
    {
        $bind = $this->gate->binding();
        if (! $bind || ! $this->gate->enabled() || ! $this->gate->boundUser()) { return null; }
        $token = (string) $request->header('X-Hermes-Session', '');
        if ($token !== '') {
            try {
                if (strlen($token) > 4096) { return null; }
                $s = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
                return ($s['aud'] ?? '') === 'hermes-miniapp'
                    && ($s['exp'] ?? 0) > time()
                    && ($s['iat'] ?? PHP_INT_MAX) <= time()
                    && ($s['exp'] - $s['iat']) <= self::SESSION_SECONDS
                    && hash_equals($this->bindingHash($bind), (string) ($s['binding'] ?? ''))
                    ? $bind : null;
            } catch (\Throwable) { return null; }
        }
        $raw = (string) $request->header('X-Bale-Init-Data', '');
        if ($raw === '' || strlen($raw) > 16000) { return null; }
        parse_str($raw, $data);
        $signature = $data['hash'] ?? null;
        unset($data['hash']);
        if (! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/iD', $signature)) { return null; }
        foreach ($data as $value) { if (! is_string($value)) { return null; } }
        if (! ctype_digit($data['auth_date'] ?? '') || time() - (int) $data['auth_date'] > 600
            || (int) $data['auth_date'] - time() > 30) { return null; }
        ksort($data, SORT_STRING);
        $pairs = [];
        foreach ($data as $key => $value) { $pairs[] = $key.'='.$value; }
        $bot = (string) config('services.bale.token');
        if ($bot === '') { return null; }
        $key = hash_hmac('sha256', $bot, 'WebAppData', true);
        if (! hash_equals(hash_hmac('sha256', implode("\n", $pairs), $key), strtolower($signature))) { return null; }
        $user = json_decode($data['user'] ?? '', true);
        return is_array($user) && hash_equals((string) $bind['chat_id'], (string) ($user['id'] ?? '')) ? $bind : null;
    }

    /** Only fresh Bale authentication may issue a new absolute-lifetime session. */
    public function issue(array $owner): array
    {
        $now = time();
        return ['token' => Crypt::encryptString(json_encode([
            'aud' => 'hermes-miniapp', 'iat' => $now, 'exp' => $now + self::SESSION_SECONDS,
            'binding' => $this->bindingHash($owner),
        ], JSON_THROW_ON_ERROR)), 'expires_at' => $now + self::SESSION_SECONDS];
    }

    private function bindingHash(array $bind): string
    {
        return hash('sha256', json_encode([$bind['chat_id'], $bind['user_id'], $bind['at'] ?? null]));
    }
}

