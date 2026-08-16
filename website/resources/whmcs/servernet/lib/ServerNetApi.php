<?php

namespace ServerNet\Registrar;

/**
 * کلاینتِ APIِ نمایندگیِ دامنهٔ سرورنت.
 *
 * ═══ چرا این کلاس هیچ تابعِ WHMCS صدا نمی‌زند ═══
 *
 * تا این‌جا هیچ‌چیز به WHMCS وابسته نیست: نه `logModuleCall`، نه `Capsule`،
 * نه هیچ کلاسِ `WHMCS\*`. یعنی می‌شود بدونِ نصبِ WHMCS تستش کرد — و ما
 * WHMCSِ واقعی برای تست نداریم. هر منطقی که این‌جا بماند، آزموده می‌شود؛
 * هر منطقی که به `servernet.php` برود، فقط روی سرورِ نماینده کشف می‌شود.
 *
 * @author ServerNet — servernet.cloud
 */
class ServerNetApi
{
    public const VERSION = '1.0.0';

    /** نشانیِ پیش‌فرض — نماینده معمولاً دست نمی‌زند */
    public const DEFAULT_BASE = 'https://servernet.cloud/api/v1';

    private string $base;

    /** آخرین تماس — برای `logModuleCall` در لایهٔ WHMCS */
    public array $lastRequest = [];

    public array $lastResponse = [];

    public function __construct(
        private string $token,
        string $base = self::DEFAULT_BASE,
        private int $timeout = 60,
    ) {
        $this->base = rtrim($base, '/');
    }

    // ═══════════════════════ عملیات ═══════════════════════

    public function ping(): array
    {
        return $this->call('GET', '/ping');
    }

    /** @param string[] $tlds */
    public function tlds(array $tlds = []): array
    {
        $q = $tlds ? '?'.http_build_query(['tlds' => $tlds]) : '';

        return $this->call('GET', '/tlds'.$q);
    }

    /** @param string[] $tlds */
    public function check(string $domain, array $tlds = []): array
    {
        return $this->call('POST', '/domains/check', ['domain' => $domain, 'tlds' => $tlds]);
    }

    public function domain(string $fqdn): array
    {
        return $this->call('GET', '/domains/'.rawurlencode($fqdn));
    }

    /**
     * @param  string[]  $nameservers
     */
    public function register(string $fqdn, int $years, array $nameservers, string $idempotencyKey): array
    {
        return $this->call('POST', '/domains', [
            'domain'      => $fqdn,
            'years'       => $years,
            'nameservers' => array_values($nameservers),
        ], $idempotencyKey);
    }

    public function renew(string $fqdn, int $years, string $idempotencyKey): array
    {
        return $this->call('POST', '/domains/'.rawurlencode($fqdn).'/renew', [
            'years' => $years,
        ], $idempotencyKey);
    }

    /** @param string[] $nameservers */
    public function saveNameservers(string $fqdn, array $nameservers): array
    {
        return $this->call('PUT', '/domains/'.rawurlencode($fqdn).'/nameservers', [
            'nameservers' => array_values($nameservers),
        ]);
    }

    public function lock(string $fqdn, bool $locked): array
    {
        return $this->call('POST', '/domains/'.rawurlencode($fqdn).'/lock', ['locked' => $locked]);
    }

    public function autoRenew(string $fqdn, bool $on): array
    {
        return $this->call('POST', '/domains/'.rawurlencode($fqdn).'/auto-renew', ['auto_renew' => $on]);
    }

    // ═══════════════════════ کلیدِ یکتاسازی ═══════════════════════

    /**
     * کلیدِ `Idempotency-Key` — قطعی، نه تصادفی.
     *
     * ═══ 🔴 چرا `expiry` در کلیدِ تمدید هست و نبودش یک باگِ سالانه می‌سازد ═══
     *
     * کلید باید در **تلاشِ دوبارهٔ همان درخواست** یکسان باشد (وگرنه بی‌فایده
     * است) و در **درخواستِ بعدیِ واقعی** متفاوت (وگرنه فاجعه است).
     *
     * برای ثبت، `domainid` کافی است: یک دامنه یک بار ثبت می‌شود.
     *
     * برای تمدید نه. اگر کلید فقط `domainid|renew|years` باشد، تمدیدِ سالِ
     * بعدِ **همان** دامنه دقیقاً همان کلید را می‌سازد — و سرور آن را
     * «درخواستِ تکراری» می‌بیند و پاسخِ پارسالِ همان را دوباره پخش می‌کند.
     * نتیجه: WHMCS «تمدید شد» می‌گیرد، مشتری پول می‌دهد، و **هیچ تمدیدی
     * انجام نمی‌شود** — تا روزی که دامنه منقضی شود. کاملاً خاموش، با کدِ ۲۰۰.
     *
     * تاریخِ انقضای فعلی این را حل می‌کند: در تلاش‌های دوبارهٔ یک درخواست
     * ثابت است (هنوز تمدید نشده) و بعد از تمدیدِ موفق عوض می‌شود.
     */
    public static function keyFor(string $action, string $fqdn, array $parts = []): string
    {
        $seed = implode('|', array_merge([$action, strtolower($fqdn)], array_map('strval', $parts)));

        return substr(hash('sha256', $seed), 0, 48);
    }

    // ═══════════════════════ انتقال ═══════════════════════

    /**
     * @param  array<string,mixed>|null  $body
     * @return array{ok:bool, error:?string, message:string, data:mixed, status:int}
     */
    public function call(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $url = $this->base.$path;

        $headers = [
            'Authorization: Bearer '.$this->token,
            'Accept: application/json',
            'User-Agent: ServerNet-WHMCS/'.self::VERSION,
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: '.$idempotencyKey;
        }

        $this->lastRequest = [
            'url'    => $url,
            'method' => $method,
            'body'   => $body,
            // ⚠️ توکن هرگز در لاگِ ماژول نمی‌نشیند. `logModuleCall` خروجی‌اش را
            //    در دیتابیسِ WHMCS نگه می‌دارد و آن دیتابیس بکاپ می‌شود.
            'idempotency_key' => $idempotencyKey,
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            // ⚠️ بررسیِ گواهی هرگز خاموش نمی‌شود: توکنی که پول خرج می‌کند
            //    روی این اتصال می‌رود.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            /*
            | 🔴 «نشنیدیم» با «نه گفت» یکی نیست.
            |
            | تایم‌اوتِ وسطِ ثبت یعنی ممکن است دامنه **ثبت شده باشد**. اگر
            | ماژول این را «ناموفق» بخواند، اپراتور دوباره سفارش می‌دهد یا
            | لغو می‌کند — در حالی که ثبت انجام شده. کدِ خطای جدا لازم است تا
            | لایهٔ بالا بتواند به‌جای شکست، «در حالِ بررسی» نشان دهد.
            */
            $this->lastResponse = ['transport_error' => $err];

            return [
                'ok' => false, 'error' => 'transport_error', 'status' => 0,
                'message' => 'ارتباط با سرورنت برقرار نشد: '.$err,
                'data' => null, 'transport' => true,
            ];
        }

        $json = json_decode((string) $raw, true);
        $this->lastResponse = is_array($json) ? $json : ['raw' => substr((string) $raw, 0, 500)];

        if (! is_array($json)) {
            return [
                'ok' => false, 'error' => 'bad_response', 'status' => $status,
                'message' => 'پاسخِ نامعتبر از سرورنت (HTTP '.$status.').',
                'data' => null, 'transport' => false,
            ];
        }

        /*
        | ⚠️ به کدِ HTTP تنها تکیه نکن — به `ok` در بدنه. این قاعده در همین
        | پروژه با پول ثابت شده: چند سرویسِ بالادستی روی خطا هم ۲۰۰ می‌دهند.
        */
        return [
            'ok'        => (bool) ($json['ok'] ?? false),
            'error'     => $json['error'] ?? null,
            'message'   => (string) ($json['message'] ?? ''),
            'data'      => $json['data'] ?? null,
            'status'    => $status,
            'transport' => false,
            'raw'       => $json,
        ];
    }
}
