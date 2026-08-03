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

    // ═══════════════════════ مخاطب (handle مالک) ═══════════════════════

    /**
     * ساختِ handle مالک. خروجی مثل «CV904717-NL».
     *
     * 🔴 handle **یک بار به ازای هر مشتری** ساخته می‌شود و بعد بازاستفاده.
     * ساختِ handle تازه برای هر ثبت، فضای مخاطبِ حساب را پر می‌کند و بدتر:
     * تغییرِ اطلاعاتِ تماسِ مشتری فقط روی handleهای بعدی اثر می‌گذارد و
     * دامنه‌های قدیمی با اطلاعاتِ کهنه می‌مانند — که برای WHOIS تخلف است.
     *
     * @param  array<string,mixed>  $data  ساختارِ رسمی: name/address/phone/email
     * @return array{ok:bool, handle:?string, code:int, message:string}
     */
    public function createCustomer(array $data): array
    {
        $res = $this->call('POST', '/customers', $data);

        return $this->result($res, ['handle' => data_get($res, 'data.handle')]);
    }

    /** @return array{ok:bool, data:array, code:int, message:string} */
    public function getCustomer(string $handle): array
    {
        $res = $this->call('GET', '/customers/'.rawurlencode($handle));

        return $this->result($res, ['data' => (array) data_get($res, 'data', [])]);
    }

    public function updateCustomer(string $handle, array $data): array
    {
        return $this->result($this->call('PUT', '/customers/'.rawurlencode($handle), $data));
    }

    // ═══════════════════════ ثبت و مدیریتِ دامنه ═══════════════════════

    /**
     * ثبتِ دامنه.
     *
     * ⚠️ چهار handle جداگانه می‌خواهد (owner/admin/tech/billing). ما هر چهار را
     * یکی می‌گذاریم: مشتری مالکِ دامنهٔ خودش است و واسطه‌گریِ ما نباید در WHOIS
     * جای مالک بنشیند.
     *
     * ⚠️ `period` سال است، نه ماه.
     *
     * @param  array<int,string>  $nameServers
     * @param  array<string,mixed>  $additional  دادهٔ اجباریِ بعضی پسوندها
     * @return array{ok:bool, id:?int, status:?string, code:int, message:string}
     */
    public function registerDomain(
        string $name,
        string $extension,
        string $handle,
        array $nameServers,
        int $period = 1,
        bool $autoRenew = false,
        array $additional = [],
    ): array {
        $payload = [
            'domain'         => ['name' => $name, 'extension' => ltrim($extension, '.')],
            'period'         => $period,
            'owner_handle'   => $handle,
            'admin_handle'   => $handle,
            'tech_handle'    => $handle,
            'billing_handle' => $handle,
            'name_servers'   => array_values(array_map(fn ($ns) => ['name' => $ns], $nameServers)),
            // ⚠️ «off» نه «default»: تمدید را **ما** مدیریت می‌کنیم. اگر رسیلری
            // خودش تمدید کند، برای دامنه‌ای که مشتری تمدیدش را نخریده هم پول
            // می‌دهیم و راهی برای پس‌گرفتنش نداریم.
            'autorenew'      => $autoRenew ? 'on' : 'off',
        ];

        if ($additional !== []) {
            $payload['additional_data'] = $additional;
        }

        $res = $this->call('POST', '/domains', $payload);

        return $this->result($res, [
            'id'     => data_get($res, 'data.id'),
            'status' => data_get($res, 'data.status'),
        ]);
    }

    /** @return array{ok:bool, data:array, code:int, message:string} */
    public function getDomain(int $id): array
    {
        $res = $this->call('GET', '/domains/'.$id);

        return $this->result($res, ['data' => (array) data_get($res, 'data', [])]);
    }

    /**
     * پیدا کردنِ دامنه با نام — برای تشخیصِ «قبلاً ثبت شده».
     *
     * 🔴 این متد ستونِ فقراتِ idempotency است: اگر ثبت timeout بخورد ولی
     * رسیلری واقعاً ثبت کرده باشد، تلاشِ دوم بدونِ این، دامنه را **دوباره**
     * می‌خرد. پول دو بار می‌رود و دامنهٔ دوم هم به هیچ‌کس نمی‌خورد.
     */
    public function findDomain(string $name, string $extension): array
    {
        $res = $this->call('GET', '/domains', [
            'domain_name_pattern' => $name,
            'extension'           => ltrim($extension, '.'),
            'limit'               => 20,
        ]);

        $fqdn = strtolower($name.'.'.ltrim($extension, '.'));

        foreach ((array) data_get($res, 'data.results', []) as $row) {
            $rowName = strtolower(
                (string) data_get($row, 'domain.name').'.'.(string) data_get($row, 'domain.extension')
            );

            if ($rowName === $fqdn) {
                return $this->result($res, ['data' => (array) $row, 'found' => true]);
            }
        }

        return $this->result($res, ['data' => [], 'found' => false]);
    }

    /** @return array{ok:bool, results:array, code:int, message:string} */
    public function listDomains(int $limit = 100, int $offset = 0): array
    {
        $res = $this->call('GET', '/domains', ['limit' => $limit, 'offset' => $offset]);

        return $this->result($res, ['results' => (array) data_get($res, 'data.results', [])]);
    }

    /** تمدید. `period` سال است. */
    public function renewDomain(int $id, int $period = 1): array
    {
        return $this->result($this->call('POST', '/domains/'.$id.'/renew', ['period' => $period]));
    }

    /**
     * تغییرِ صفاتِ دامنه: nameserver، قفلِ انتقال، تمدیدِ خودکار.
     *
     * @param  array<string,mixed>  $data
     */
    public function updateDomain(int $id, array $data): array
    {
        return $this->result($this->call('PUT', '/domains/'.$id, $data));
    }

    /** @param array<int,string> $nameServers */
    public function setNameServers(int $id, array $nameServers): array
    {
        return $this->updateDomain($id, [
            'name_servers' => array_values(array_map(fn ($ns) => ['name' => $ns], $nameServers)),
        ]);
    }

    public function setLock(int $id, bool $locked): array
    {
        return $this->updateDomain($id, ['is_locked' => $locked]);
    }

    public function setAutoRenew(int $id, bool $on): array
    {
        return $this->updateDomain($id, ['autorenew' => $on ? 'on' : 'off']);
    }

    /**
     * کدِ انتقال (EPP/authcode).
     *
     * ⚠️ این کد **کلیدِ مالکیت** است: هرکس داشته باشد می‌تواند دامنه را ببرد.
     * پس ذخیره‌اش نمی‌کنیم؛ در لحظه گرفته و به مشتری نشان داده می‌شود.
     */
    public function authCode(int $id): array
    {
        $res = $this->call('GET', '/domains/'.$id.'/authcode');

        return $this->result($res, ['auth_code' => data_get($res, 'data.auth_code')]);
    }

    public function resetAuthCode(int $id): array
    {
        $res = $this->call('POST', '/domains/'.$id.'/authcode/reset', []);

        return $this->result($res, ['auth_code' => data_get($res, 'data.auth_code')]);
    }

    /**
     * شروعِ انتقالِ دامنه به ما.
     *
     * @param  array<int,string>  $nameServers
     */
    public function transferDomain(
        string $name,
        string $extension,
        string $handle,
        string $authCode,
        array $nameServers = [],
        int $period = 1,
    ): array {
        $payload = [
            'domain'         => ['name' => $name, 'extension' => ltrim($extension, '.')],
            'period'         => $period,
            'auth_code'      => $authCode,
            'owner_handle'   => $handle,
            'admin_handle'   => $handle,
            'tech_handle'    => $handle,
            'billing_handle' => $handle,
            'autorenew'      => 'off',
        ];

        if ($nameServers !== []) {
            $payload['name_servers'] = array_values(array_map(fn ($ns) => ['name' => $ns], $nameServers));
        }

        $res = $this->call('POST', '/domains/transfer', $payload);

        return $this->result($res, ['id' => data_get($res, 'data.id')]);
    }

    // ═══════════════════════ کاتالوگِ پسوندها ═══════════════════════

    /**
     * فهرستِ پسوندهایی که رسیلری می‌فروشد، با قیمت.
     *
     * صفحه‌بندی دارد و کلِ فهرست ~۱۵۰۰ ردیف است، پس فراخوان باید حلقه بزند.
     *
     * @return array{ok:bool, results:array, total:int, code:int, message:string}
     */
    public function listTlds(int $limit = 500, int $offset = 0, bool $withPrice = true): array
    {
        $res = $this->call('GET', '/tlds', array_filter([
            'limit'      => $limit,
            'offset'     => $offset,
            'with_price' => $withPrice ?: null,
        ], fn ($v) => $v !== null));

        return $this->result($res, [
            'results' => (array) data_get($res, 'data.results', []),
            'total'   => (int) data_get($res, 'data.total', 0),
        ]);
    }

    /**
     * پاسخِ خام → شکلِ یکنواخت.
     *
     * هیچ متدی استثنا پرتاب نمی‌کند؛ همان قراردادِ `CloudProvider` — چون این
     * فراخوان‌ها وسطِ مسیرِ پول‌اند و یک استثنای مهارنشده یعنی مشتری پول داده
     * و صفحهٔ خطای سفید دیده.
     *
     * @param  array<string,mixed>  $extra
     */
    private function result(array $res, array $extra = []): array
    {
        $code = (int) ($res['code'] ?? -1);

        return array_merge([
            'ok'      => $code === 0,
            'code'    => $code,
            'message' => (string) ($res['desc'] ?? ''),
        ], $extra);
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

        // 🔴 GET باید پارامترها را در **کوئری** بفرستد، نه در بدنه.
        //
        // قبلاً همه‌چیز `json` می‌رفت و برای `POST /domains/check` کار می‌کرد،
        // پس کسی متوجه نشد. ولی `GET /domains?domain_name_pattern=…` با بدنهٔ
        // JSON یعنی فیلتر **اعمال نمی‌شود** و رسیلری صد دامنهٔ اولِ کلِ حساب را
        // برمی‌گرداند. `findDomain()` که ستونِ idempotency است، آن‌وقت یا
        // دامنهٔ اشتباه پیدا می‌کرد یا هیچ — و نتیجه‌اش خریدِ دوبارهٔ همان دامنه.
        $options = in_array(strtoupper($method), ['GET', 'HEAD', 'DELETE'], true)
            ? ($payload === [] ? [] : ['query' => $payload])
            : ['json' => $payload];

        try {
            $response = $req->send($method, $this->base.$path, $options);
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
