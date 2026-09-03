<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Nginx Proxy Manager — دروازهٔ وبِ ماشین‌های پشتِ NAT.
 *
 * ═══ چرا این کلاس لازم شد (۱۱ شهریور ۱۴۰۵) ═══
 *
 * 🔴 مشتری SN-978603 تیکت زد: «وقتی به پورت ۸۰ سرور وصل می‌شوم صفحهٔ Nginx
 * Proxy Manager باز می‌شود، در حالی که روی سرور Caddy نصب است».
 *
 * حق داشت. IPv4 عمومی بینِ همهٔ ماشین‌های میزبانِ ایران **مشترک** است و
 * پورت‌های ۸۰/۴۴۳ آن به پروکسیِ مرکزی می‌روند. از هر ماشین فقط یک پورتِ SSH
 * فوروارد می‌شود. یعنی ما یک VPS فروخته بودیم که **اصلاً نمی‌توانست سایت سرو
 * کند** — و این نقصِ محصول بود، نه سوءتفاهمِ مشتری.
 *
 * راهِ حل همان چیزی است که زیرساختش از قبل بود: NPM بر اساسِ **نامِ میزبان**
 * مسیریابی می‌کند. پس هر ماشین یک زیر‌دامنه می‌گیرد و NPM آن را به
 * `<ip داخلی>:80` می‌برد، با گواهیِ رایگان.
 *
 * ⚠️ این کار یک‌بار دستی انجام شد و جواب داد؛ این کلاس همان را خودکار می‌کند
 * تا مشتریِ بعدی همان تیکت را نزند. تا وقتی خودکار نشده بود، فروشِ زیرساختِ
 * ایران باید بسته می‌مانْد.
 *
 * ⚠️ اعتبارنامه‌ها **فقط** از تنظیماتِ پنل می‌آیند (مثل هر زیرساختِ دیگر) و
 * هرگز در کد یا مخزن نمی‌نشینند.
 */
class NpmClient
{
    /** توکنِ NPM حدودِ یک روز اعتبار دارد؛ کمی زودتر تازه‌اش می‌کنیم. */
    private const TOKEN_TTL = 3600 * 20;

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== ''
            && filled(Setting::getSecret('npm_email'))
            && filled(Setting::getSecret('npm_password'));
    }

    private function baseUrl(): string
    {
        return rtrim((string) (Setting::get('npm_base_url') ?: ''), '/');
    }

    /**
     * دامنهٔ پایه‌ای که زیر‌دامنهٔ هر سرور زیرش ساخته می‌شود.
     *
     * خالی یعنی «این قابلیت خاموش است» — و خاموشی باید **صریح** باشد، نه
     * نتیجهٔ یک پیش‌فرضِ حدسی که روی دامنهٔ اشتباه رکورد بسازد.
     */
    public function baseDomain(): string
    {
        return strtolower(trim((string) (Setting::get('npm_base_domain') ?: '')));
    }

    /**
     * نامِ میزبانِ یک سرویس — `<کد مشتری>.<دامنهٔ پایه>`.
     *
     * ⚠️ کدِ مشتری انتخابِ کارفرماست. یکتا به‌ازای **مشتری** است نه سرویس، پس
     * اگر همان مشتری سرورِ دوم بخرد شناسهٔ سرویس هم به نام اضافه می‌شود —
     * وگرنه دو ماشین یک نام می‌گیرند و دومی اولی را از کار می‌اندازد.
     */
    public function hostnameFor(string $customerCode, int $serviceId, bool $first = true): ?string
    {
        $base = $this->baseDomain();
        $code = strtolower(trim($customerCode));

        if ($base === '' || $code === '') {
            return null;
        }

        // فقط حروف، رقم و خط تیره — هر چیزِ دیگری نامِ میزبانِ نامعتبر می‌سازد
        $code = trim((string) preg_replace('/[^a-z0-9-]/', '-', $code), '-');

        if ($code === '') {
            return null;
        }

        return $first ? $code.'.'.$base : $code.'-'.$serviceId.'.'.$base;
    }

    /**
     * توکنِ احراز هویت — کش‌شده، چون هر تماس یک login اضافه یعنی دو برابر
     * تماس و یک نقطهٔ شکستِ بیشتر.
     */
    private function token(): ?string
    {
        $cacheKey = 'npm:token:'.md5($this->baseUrl().'|'.(string) Setting::getSecret('npm_email'));

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $r = Http::acceptJson()->timeout(20)->post($this->baseUrl().'/api/tokens', [
            'identity' => (string) Setting::getSecret('npm_email'),
            'secret'   => (string) Setting::getSecret('npm_password'),
        ]);

        $token = (string) ($r->json('token') ?? '');

        if (! $r->successful() || $token === '') {
            return null;
        }

        Cache::put($cacheKey, $token, self::TOKEN_TTL);

        return $token;
    }

    /** @return array{ok:bool,message:string,id:?int} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'id' => null];
    }

    /**
     * ساختِ (یا یافتنِ) پروکسی‌هاست برای یک نامِ میزبان.
     *
     * idempotent: اگر همان نام از قبل هست، همان برگردانده می‌شود. تلاشِ دوباره
     * نباید میزبانِ دوم بسازد — NPM اجازه می‌دهد و آن‌وقت دو قاعده برای یک نام
     * وجود دارد و کدام‌یک برنده است معلوم نیست.
     *
     * @return array{ok:bool,message:string,id:?int}
     */
    public function ensureProxyHost(string $hostname, string $forwardIp, int $forwardPort = 80): array
    {
        if (! $this->isConfigured()) {
            return $this->fail('اتصالِ NPM تنظیم نشده.');
        }

        $token = $this->token();

        if ($token === null) {
            return $this->fail('ورود به NPM انجام نشد؛ ایمیل/رمز را بررسی کنید.');
        }

        $http = Http::withToken($token)->acceptJson()->timeout(30);

        // ── از قبل هست؟ ──
        $list = $http->get($this->baseUrl().'/api/nginx/proxy-hosts');

        if ($list->successful()) {
            foreach ((array) $list->json() as $row) {
                $names = (array) ($row['domain_names'] ?? []);

                if (in_array($hostname, $names, true)) {
                    return ['ok' => true, 'message' => 'از قبل موجود بود', 'id' => (int) ($row['id'] ?? 0)];
                }
            }
        }

        // ── ساخت ──
        $r = $http->post($this->baseUrl().'/api/nginx/proxy-hosts', [
            'domain_names'           => [$hostname],
            'forward_scheme'         => 'http',
            'forward_host'           => $forwardIp,
            'forward_port'           => $forwardPort,
            'access_list_id'         => 0,
            'certificate_id'         => 0,
            'ssl_forced'             => false,
            'caching_enabled'        => false,
            'block_exploits'         => true,
            'advanced_config'        => '',
            'meta'                   => ['letsencrypt_agree' => false, 'dns_challenge' => false],
            'allow_websocket_upgrade' => true,
            'http2_support'          => false,
            'hsts_enabled'           => false,
            'hsts_subdomains'        => false,
            'locations'              => [],
        ]);

        if (! $r->successful()) {
            return $this->fail('ساختِ پروکسی‌هاست انجام نشد: '
                .mb_substr((string) ($r->json('error.message') ?? $r->body()), 0, 200));
        }

        return ['ok' => true, 'message' => '', 'id' => (int) ($r->json('id') ?? 0)];
    }

    /**
     * درخواستِ گواهیِ Let's Encrypt برای یک پروکسی‌هاستِ موجود.
     *
     * ⚠️ عمداً **جدا** از ساخت است و شکستش تحویل را نمی‌شکند: صدور گواهی به
     * انتشارِ DNS وابسته است و ممکن است چند دقیقه دیرتر جواب دهد. سرویسِ بی‌SSL
     * کار می‌کند؛ سرویسی که به‌خاطر SSL اصلاً ساخته نشود، نه.
     *
     * @return array{ok:bool,message:string}
     */
    public function requestCertificate(int $proxyHostId, string $hostname): array
    {
        $token = $this->token();

        if ($token === null) {
            return ['ok' => false, 'message' => 'ورود به NPM انجام نشد.'];
        }

        $http = Http::withToken($token)->acceptJson()->timeout(120);

        $cert = $http->post($this->baseUrl().'/api/nginx/certificates', [
            'domain_names' => [$hostname],
            'meta'         => [
                'letsencrypt_email' => (string) Setting::getSecret('npm_email'),
                'letsencrypt_agree' => true,
                'dns_challenge'     => false,
            ],
            'provider' => 'letsencrypt',
        ]);

        if (! $cert->successful()) {
            return ['ok' => false, 'message' => mb_substr(
                (string) ($cert->json('error.message') ?? $cert->body()), 0, 200)];
        }

        $certId = (int) ($cert->json('id') ?? 0);

        if ($certId <= 0) {
            return ['ok' => false, 'message' => 'شناسهٔ گواهی برنگشت.'];
        }

        $put = $http->put($this->baseUrl().'/api/nginx/proxy-hosts/'.$proxyHostId, [
            'certificate_id' => $certId,
            'ssl_forced'     => true,
            'http2_support'  => true,
        ]);

        return $put->successful()
            ? ['ok' => true, 'message' => '']
            : ['ok' => false, 'message' => 'گواهی ساخته شد ولی به میزبان وصل نشد.'];
    }

    /**
     * 🔴 برچیدنِ دروازهٔ وب — نیمهٔ دومِ همان کار.
     *
     * ⚠️ ساختن بدونِ برچیدن یعنی هر سرویسِ خاتمه‌یافته یک پروکسی‌هاستِ زنده جا
     * می‌گذارد که به IPِ داخلیِ آزادشده اشاره می‌کند. آن IP بعداً به مشتریِ
     * دیگری می‌رسد و آن‌وقت نامِ مشتریِ قبلی به سرورِ مشتریِ تازه وصل است —
     * نشتِ داده، نه فقط آشغال.
     *
     * ⚠️ گواهی هم با میزبان می‌رود؛ گواهیِ یتیم در NPM بی‌صدا انباشته می‌شود و
     * سهمیهٔ Let's Encrypt را می‌سوزاند.
     *
     * @return array{ok:bool,message:string}
     */
    public function removeProxyHost(string $hostname): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'اتصالِ NPM تنظیم نشده.'];
        }

        $token = $this->token();

        if ($token === null) {
            return ['ok' => false, 'message' => 'ورود به NPM انجام نشد.'];
        }

        $http = Http::withToken($token)->acceptJson()->timeout(30);
        $list = $http->get($this->baseUrl().'/api/nginx/proxy-hosts');

        if (! $list->successful()) {
            return ['ok' => false, 'message' => 'فهرستِ میزبان‌ها خوانده نشد.'];
        }

        $hostId = null;
        $certId = 0;

        foreach ((array) $list->json() as $row) {
            if (in_array($hostname, (array) ($row['domain_names'] ?? []), true)) {
                $hostId = (int) ($row['id'] ?? 0);
                $certId = (int) ($row['certificate_id'] ?? 0);
                break;
            }
        }

        // نبودنش یعنی کار از قبل انجام شده — نه خطا. (idempotency: تلاشِ
        // دوباره‌ی کرونِ آزادسازی نباید قرمز بدهد.)
        if ($hostId === null) {
            return ['ok' => true, 'message' => 'از قبل نبود'];
        }

        $del = $http->delete($this->baseUrl().'/api/nginx/proxy-hosts/'.$hostId);

        if (! $del->successful()) {
            return ['ok' => false, 'message' => 'حذفِ میزبان انجام نشد.'];
        }

        if ($certId > 0) {
            $http->delete($this->baseUrl().'/api/nginx/certificates/'.$certId);
        }

        return ['ok' => true, 'message' => ''];
    }

    /** آزمونِ اتصال برای دکمهٔ تستِ پنل. */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'آدرس، ایمیل یا رمزِ NPM وارد نشده.'];
        }

        return $this->token() !== null
            ? ['ok' => true, 'message' => 'اتصال برقرار است.']
            : ['ok' => false, 'message' => 'ورود انجام نشد؛ آدرس/ایمیل/رمز را بررسی کنید.'];
    }
}
