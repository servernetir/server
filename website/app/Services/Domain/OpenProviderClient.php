<?php

namespace App\Services\Domain;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * کلاینت OpenProvider (API نسخهٔ v1beta).
 *
 * ⚠️ نکتهٔ حیاتی که با آزمایش واقعی کشف شد:
 * این API **حتی برای خطای احراز هویت هم HTTP 500 برمی‌گرداند**، نه ۴۰۱.
 * نمونهٔ واقعی: {"desc":"Authentication/Authorization Failed","code":196}
 * پس هرگز به کد وضعیت HTTP تکیه نکن — فیلد `code` در بدنه حرف آخر است.
 * code === 0 یعنی موفق.
 */
class OpenProviderClient
{
    private const TOKEN_KEY = 'openprovider.token';

    public function __construct(
        private ?string $base = null,
        private ?string $username = null,
        private ?string $password = null,
    ) {
        $cfg = config('services.openprovider');
        $this->base     ??= rtrim($cfg['base_url'] ?? 'https://api.openprovider.eu/v1beta', '/');
        $this->username ??= $cfg['username'] ?? null;
        $this->password ??= $cfg['password'] ?? null;
    }

    public function enabled(): bool
    {
        return filled($this->username) && filled($this->password);
    }

    /**
     * توکن معتبر. OpenProvider توکن را حدود ۱۲ ساعت زنده نگه می‌دارد؛
     * ما محافظه‌کارانه ۶ ساعت کش می‌کنیم.
     */
    public function token(bool $forceFresh = false): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        if (! $forceFresh && ($cached = Cache::get(self::TOKEN_KEY))) {
            return $cached;
        }

        $res = $this->raw('POST', '/auth/login', [
            'username' => $this->username,
            'password' => $this->password,
        ], withToken: false);

        $token = data_get($res, 'data.token');

        if (blank($token)) {
            Log::warning('OpenProvider login failed', [
                'code' => data_get($res, 'code'),
                'desc' => data_get($res, 'desc'),
            ]);
            return null;
        }

        Cache::put(self::TOKEN_KEY, $token, now()->addHours(6));

        return $token;
    }

    /**
     * بررسی موجودی و قیمت دامنه‌ها.
     *
     * `with_price` حیاتی است: قیمت دامنهٔ پرمیوم **فقط از پاسخ همین متد**
     * می‌آید، نه از فهرست قیمت TLD. اگر از فهرست قیمت بخوانیم، برای دامنهٔ
     * پرمیوم قیمت استاندارد نشان می‌دهیم — دقیقاً همان فاجعه‌ای که
     * می‌خواهیم از آن جلوگیری کنیم.
     *
     * @param  array<int,array{name:string,extension:string}>  $domains
     * @return array<int,array>  آرایهٔ نتایج خام OpenProvider
     */
    public function check(array $domains): array
    {
        $res = $this->call('POST', '/domains/check', [
            'domains'    => array_values($domains),
            'with_price' => true,
        ]);

        return data_get($res, 'data.results', []);
    }

    /** فراخوانی با توکن، با یک بار تلاش دوباره اگر توکن منقضی شده باشد */
    public function call(string $method, string $path, array $payload = []): array
    {
        $res = $this->raw($method, $path, $payload);

        // ۱۹۶ = احراز هویت رد شد → شاید توکن کهنه است، یک بار تازه بگیر
        if ((int) data_get($res, 'code') === 196) {
            Cache::forget(self::TOKEN_KEY);
            $res = $this->raw($method, $path, $payload, forceFreshToken: true);
        }

        return $res;
    }

    /** درخواست خام. همیشه آرایه برمی‌گرداند؛ خطا در فیلد code است نه استثنا. */
    private function raw(
        string $method,
        string $path,
        array $payload = [],
        bool $withToken = true,
        bool $forceFreshToken = false,
    ): array {
        $req = Http::acceptJson()->asJson()->timeout(25)->retry(2, 400, throw: false);

        if ($withToken) {
            $token = $this->token($forceFreshToken);
            if (blank($token)) {
                return ['code' => 196, 'desc' => 'No token available'];
            }
            $req = $req->withToken($token);
        }

        try {
            $response = $req->send($method, $this->base.$path, ['json' => $payload]);
        } catch (\Throwable $e) {
            Log::warning('OpenProvider transport error', ['path' => $path, 'err' => $e->getMessage()]);
            return ['code' => -1, 'desc' => 'transport: '.$e->getMessage()];
        }

        $body = $response->json();

        if (! is_array($body)) {
            return ['code' => -1, 'desc' => 'invalid JSON body'];
        }

        // اینجا عمداً وضعیت HTTP را نادیده می‌گیریم — این API روی خطا هم ۵۰۰ می‌دهد
        if ((int) ($body['code'] ?? 0) !== 0) {
            Log::info('OpenProvider API error', [
                'path' => $path,
                'code' => $body['code'] ?? null,
                'desc' => $body['desc'] ?? null,
            ]);
        }

        return $body;
    }
}
