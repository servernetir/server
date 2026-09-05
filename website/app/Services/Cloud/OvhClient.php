<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * زیرساختِ ۴ — OVHcloud.
 *
 * ═══ چرا این درایور با بقیه فرق دارد ═══
 *
 * هتزنر و زیرساختِ ۲ یک توکنِ ساده دارند. OVH **سه‌کلیدی و امضادار** است و هر
 * درخواست باید جداگانه امضا شود. اشتباه در امضا خطای عمومیِ ۴۰۳ می‌دهد بی‌آنکه
 * بگوید کجا غلط بوده، پس فرمولش دقیقاً از داکیومنت و کلاینتِ رسمیِ خودشان
 * برداشته شده و در `sign()` مستند است.
 *
 * ⚠️ **سفارشِ سرورِ تازه عمداً پیاده نشده.** خریدِ VPS در OVH از سبدِ
 * `/order/cart` می‌گذرد: ساختِ سبد، افزودنِ آیتم، اعتبارسنجی، و بعد
 * `checkout` — چند مرحله، برگشت‌ناپذیر، و مستقیماً پول. آن مسیر را نمی‌شود
 * بدونِ یک حسابِ واقعی و یک سفارشِ آزمایشی درست کرد؛ حدس‌زدنش یعنی یا سفارشِ
 * ناقص یا پولِ خرج‌شدهٔ بی‌سرور. تا آن روز `createServer()` صریح می‌گوید
 * «دستی» و سرویس به صفِ تحویلِ دستیِ مدیر می‌رود — نه اینکه بی‌صدا شکست بخورد.
 *
 * پس امروز این درایور برای **مدیریتِ سرورهای موجود** و **همگام‌سازیِ کاتالوگ**
 * کامل است، و برای خریدِ خودکار نیمه‌کاره و صادق.
 *
 * @see https://docs.ovhcloud.com/en/guides/manage-and-operate/api/first-steps
 */
class OvhClient implements CloudProvider
{
    /**
     * ⚠️ نقطهٔ پایانی **منطقه‌ای** است، و این تزئینی نیست: `ovh-eu`، `ovh-ca` و
     * `ovh-us` سه شرکتِ حقوقیِ جدا با پایگاهِ کاربریِ جدا هستند. حسابی که روی
     * `manager.us.ovhcloud.com` ساخته شده روی `eu.api.ovh.com` اصلاً **وجود
     * ندارد** — و پاسخ، همان ۴۰۳ِ بی‌توضیحِ همیشگی است که هیچ اشاره‌ای به
     * منطقه نمی‌کند. پس عوضی‌گرفتنِ منطقه دقیقاً شبیهِ کلیدِ غلط دیده می‌شود.
     */
    private const ENDPOINTS = [
        'eu' => 'https://eu.api.ovh.com/1.0',
        'ca' => 'https://ca.api.ovh.com/1.0',
        'us' => 'https://api.us.ovhcloud.com/1.0',
    ];

    /** اختلافِ ساعتِ ما با سرورِ OVH؛ یک‌بار محاسبه و کش می‌شود */
    private ?int $delta = null;

    /**
     * منطقهٔ حساب. پیش‌فرض `eu` است چون رفتارِ قبلیِ همین کلاس بود؛ نصب‌هایی که
     * این تنظیم را ندارند نباید بی‌خبر جابه‌جا شوند.
     */
    private function region(): string
    {
        $r = strtolower(trim((string) Setting::get('ovh_region', 'eu')));

        return isset(self::ENDPOINTS[$r]) ? $r : 'eu';
    }

    private function base(): string
    {
        return self::ENDPOINTS[$this->region()];
    }

    public function slug(): string
    {
        return 'ovh';
    }

    private function appKey(): ?string
    {
        return Setting::getSecret('ovh_app_key');
    }

    private function appSecret(): ?string
    {
        return Setting::getSecret('ovh_app_secret');
    }

    private function consumerKey(): ?string
    {
        return Setting::getSecret('ovh_consumer_key');
    }

    public function isConfigured(): bool
    {
        return filled($this->appKey()) && filled($this->appSecret()) && filled($this->consumerKey());
    }

    public function capabilities(): array
    {
        return [
            'console'        => false,   // OVH کنسولِ وب از راهِ API نمی‌دهد
            'rebuild'        => true,
            'resize'         => false,   // تغییرِ پلن از مسیرِ سفارش می‌گذرد
            'metrics'        => false,
            'reset_password' => false,   // فقط با نصبِ دوباره
            'rescue'         => true,
        ];
    }

    // ───────────────────────── لایهٔ امضا ─────────────────────────

    /**
     * اختلافِ ساعت با سرورِ OVH.
     *
     * ⚠️ لازم است، نه تزئینی: امضا شاملِ timestamp است و OVH انحرافِ ساعت را
     * سخت‌گیرانه رد می‌کند. یک سرورِ چند ثانیه عقب، **همهٔ** درخواست‌ها را
     * ۴۰۳ می‌گیرد — و پیامِ خطا هیچ اشاره‌ای به ساعت نمی‌کند.
     */
    private function timeDelta(): int
    {
        if ($this->delta !== null) {
            return $this->delta;
        }

        try {
            $r = Http::timeout(10)->get($this->base().'/auth/time');
            $server = (int) trim((string) $r->body());

            return $this->delta = $r->successful() && $server > 0 ? $server - time() : 0;
        } catch (\Throwable) {
            return $this->delta = 0;
        }
    }

    /**
     * امضای یک درخواست.
     *
     * فرمولِ رسمی:
     *   `'$1$' . sha1(AS + '+' + CK + '+' + METHOD + '+' + URL + '+' + BODY + '+' + TS)`
     *
     * ⚠️ سه نکته که هرکدام بی‌سروصدا امضا را خراب می‌کند:
     *   • `URL` باید **کاملِ** آدرس باشد، با `https://` و رشتهٔ کوئری — نه مسیرِ تنها.
     *   • `BODY` باید **دقیقاً** همان بایت‌هایی باشد که فرستاده می‌شود؛ پس بدنه
     *     یک بار JSON می‌شود و همان رشته هم امضا می‌شود هم ارسال.
     *   • برای درخواستِ بی‌بدنه، `BODY` رشتهٔ خالی است، نه `"null"` یا `"[]"`.
     */
    private function sign(string $method, string $url, string $body, int $ts): string
    {
        return '$1$'.sha1(implode('+', [
            (string) $this->appSecret(),
            (string) $this->consumerKey(),
            $method,
            $url,
            $body,
            (string) $ts,
        ]));
    }

    /** @return array{ok:bool,status:int,body:mixed,message:string} */
    private function req(string $method, string $path, array $payload = []): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'message' => 'کلیدهای زیرساختِ ۴ تنظیم نشده است.'];
        }

        $method = strtoupper($method);
        $url = $this->base().'/'.ltrim($path, '/');

        // GET پارامترها را در کوئری می‌برد و بدنه ندارد؛ بقیه برعکس.
        $body = '';

        if ($method === 'GET' || $method === 'DELETE') {
            if ($payload !== []) {
                $url .= '?'.http_build_query($payload);
            }
        } elseif ($payload !== []) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        $ts = time() + $this->timeDelta();

        try {
            $req = Http::withHeaders([
                'X-Ovh-Application' => (string) $this->appKey(),
                'X-Ovh-Consumer'    => (string) $this->consumerKey(),
                'X-Ovh-Timestamp'   => (string) $ts,
                'X-Ovh-Signature'   => $this->sign($method, $url, $body, $ts),
                'Content-Type'      => 'application/json; charset=utf-8',
            ])->timeout(25)->connectTimeout(10);

            // ⚠️ `withBody` و نه `->post($url, $payload)`: باید **همان رشته‌ای**
            // برود که امضا شده. اگر لاراول خودش دوباره JSON بسازد (ترتیبِ کلید
            // یا اسکیپِ متفاوت)، امضا با بدنه نمی‌خوانَد و ۴۰۳ می‌گیریم.
            $res = $body === ''
                ? $req->send($method, $url)
                : $req->withBody($body, 'application/json')->send($method, $url);
        } catch (\Throwable $e) {
            Log::warning('ovh.transport', ['path' => $path, 'err' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'body' => null, 'message' => 'ارتباط با ارائه‌دهنده برقرار نشد.'];
        }

        $json = $res->json();

        if ($res->successful()) {
            return ['ok' => true, 'status' => $res->status(), 'body' => $json, 'message' => ''];
        }

        // OVH خطا را در `message` می‌دهد و گاهی `class` هم دارد
        $msg = is_array($json)
            ? (string) ($json['message'] ?? ($json['class'] ?? 'خطای نامشخص'))
            : 'خطای نامشخص';

        // ۴۰۳ در OVH تقریباً همیشه یعنی امضا/دسترسی، نه «ممنوع» به معنای عادی.
        // منطقه را هم می‌گوییم چون کلیدِ درستِ منطقهٔ اشتباه، عیناً همین ۴۰۳ را
        // می‌دهد و بدونِ این جمله ساعت‌ها دنبالِ کلید می‌گردی.
        if ($res->status() === 403) {
            $msg .= ' — کلید یا دسترسیِ آن درست نیست، یا ساعتِ سرور اختلاف دارد،'
                .' یا کلید برای منطقهٔ دیگری ساخته شده (منطقهٔ فعلی: '.strtoupper($this->region()).').';
        }

        return ['ok' => false, 'status' => $res->status(), 'body' => $json, 'message' => $msg];
    }

    // ───────────────────────── قرارداد ─────────────────────────

    public function testConnection(): array
    {
        $r = $this->req('GET', '/me');

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message']];
        }

        $me = (array) $r['body'];
        $vps = $this->req('GET', '/vps');
        $n = is_array($vps['body'] ?? null) ? count($vps['body']) : 0;

        return [
            'ok' => true,
            'message' => 'اتصال برقرار است — حسابِ '.($me['nichandle'] ?? '?').' · '.fa_num($n).' سرورِ مجازی.',
        ];
    }

    /**
     * فهرستِ سرورهای این حساب.
     *
     * `GET /vps` فقط **نام** برمی‌گرداند، پس برای هر کدام یک درخواستِ جزئیات
     * لازم است. سقفِ ۱۰۰ عمدی است: گزارشِ موجودی نباید صد تماسِ شبکه‌ای بزند.
     */
    public function listServers(): array
    {
        $r = $this->req('GET', '/vps');

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'servers' => []];
        }

        $names = array_values(array_filter((array) $r['body'], 'is_string'));
        $capped = array_slice($names, 0, 100);

        $servers = [];

        foreach ($capped as $name) {
            $d = $this->req('GET', '/vps/'.rawurlencode($name));
            $v = is_array($d['body'] ?? null) ? $d['body'] : [];

            $servers[] = [
                'ref'      => $name,
                'name'     => (string) ($v['displayName'] ?? $name),
                'status'   => $this->mapStatus((string) ($v['state'] ?? '')),
                'ipv4'     => $this->firstIp($name, 4),
                'ipv6'     => null,
                'plan'     => $v['model']['name'] ?? null,
                'location' => $v['zone'] ?? ($v['datacenter'] ?? null),
                'created'  => null,
            ];
        }

        return [
            'ok' => true,
            // اگر بریده شد صریح بگو — فهرستِ ناقصِ خاموش، گزارشِ یتیم را دروغ می‌کند
            'message' => count($names) > count($capped)
                ? 'فهرست ناقص است: '.count($names).' سرور دارید و '.count($capped).' تا خوانده شد.'
                : '',
            'servers' => $servers,
        ];
    }

    private function firstIp(string $name, int $version): ?string
    {
        $r = $this->req('GET', '/vps/'.rawurlencode($name).'/ips');

        foreach ((array) ($r['body'] ?? []) as $ip) {
            if (! is_string($ip)) {
                continue;
            }

            $is6 = str_contains($ip, ':');

            if (($version === 6) === $is6) {
                return $ip;
            }
        }

        return null;
    }

    public function serverStatus(string $ref): array
    {
        $none = ['ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
        $r = $this->req('GET', '/vps/'.rawurlencode($ref));

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'status' => 'unknown'] + $none;
        }

        $v = (array) $r['body'];

        return [
            'ok' => true, 'message' => '',
            'status'          => $this->mapStatus((string) ($v['state'] ?? '')),
            'ipv4'            => $this->firstIp($ref, 4),
            'ipv6'            => $this->firstIp($ref, 6),
            'traffic_used_gb' => null,
            'raw'             => ['model' => $v['model']['name'] ?? null, 'zone' => $v['zone'] ?? null],
        ];
    }

    /** وضعیتِ OVH → واژگانِ ما */
    private function mapStatus(string $s): string
    {
        return match (strtolower($s)) {
            'running'                       => 'running',
            'stopped', 'stopping'           => 'off',
            'installing', 'rebooting', 'upgrading' => 'building',
            default                         => 'unknown',
        };
    }

    public function power(string $ref, string $action): array
    {
        $path = match ($action) {
            'on'              => 'start',
            'off', 'shutdown' => 'stop',
            'reboot', 'reset' => 'reboot',
            default           => null,
        };

        if ($path === null) {
            return ['ok' => false, 'message' => 'عملیاتِ نامعتبر.'];
        }

        $r = $this->req('POST', '/vps/'.rawurlencode($ref).'/'.$path);

        return ['ok' => $r['ok'], 'message' => $r['message']];
    }

    public function rebuild(string $ref, string $imageRef, ?string $password = null): array
    {
        // OVH رمزِ دلخواه نمی‌گیرد؛ پس از نصب، دسترسی را خودش ایمیل می‌کند.
        $r = $this->req('POST', '/vps/'.rawurlencode($ref).'/reinstall', [
            'imageId' => $imageRef,
        ]);

        return ['ok' => $r['ok'], 'message' => $r['message'], 'root_password' => null];
    }

    public function resetPassword(string $ref): array
    {
        return ['ok' => false, 'message' => 'این زیرساخت تغییرِ رمزِ root از راهِ API ندارد؛ از نصبِ دوباره استفاده کنید.'];
    }

    public function console(string $ref): array
    {
        return ['ok' => false, 'message' => 'کنسولِ تحتِ وب برای این زیرساخت در دسترس نیست.'];
    }

    public function metrics(string $ref, string $window = '24h'): array
    {
        return ['ok' => false, 'message' => 'نمودارِ مصرف برای این زیرساخت در دسترس نیست.'];
    }

    public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array
    {
        return ['ok' => false, 'message' => 'تغییرِ پلن در این زیرساخت از مسیرِ سفارش انجام می‌شود، نه API.'];
    }

    public function deleteServer(string $ref): array
    {
        // ⚠️ در OVH «حذف» یعنی لغوِ تمدیدِ سرویس، نه پاک‌کردنِ آنی. سرور تا
        // پایانِ دورهٔ پرداخت‌شده زنده می‌مانَد. این با معنای «خاتمه» در بقیهٔ
        // درایورها فرق دارد و عمداً همین‌جا نوشته شده تا کسی انتظارِ حذفِ فوری
        // نداشته باشد.
        $r = $this->req('POST', '/vps/'.rawurlencode($ref).'/terminate');

        return ['ok' => $r['ok'], 'message' => $r['ok']
            ? 'لغوِ تمدید ثبت شد؛ سرور تا پایانِ دورهٔ پرداخت‌شده فعال می‌مانَد.'
            : $r['message']];
    }

    public function uploadSshKey(string $name, string $publicKey): array
    {
        return ['ok' => false, 'message' => 'کلیدِ SSH برای این زیرساخت پشتیبانی نمی‌شود.'];
    }

    public function addExtraIps(string $ref, int $count): array
    {
        return ['ok' => false, 'message' => 'IP اضافه برای این زیرساخت از مسیرِ سفارش انجام می‌شود.'];
    }

    /**
     * 🔴 خریدِ سرورِ تازه عمداً پیاده نشده.
     *
     * مسیرِ واقعی `/order/cart` است: ساختِ سبد → افزودنِ آیتم → اعتبارسنجی →
     * checkout. چند مرحله، **برگشت‌ناپذیر**، و مستقیماً پول. ساختنش بدونِ یک
     * حسابِ واقعی و یک سفارشِ آزمایشی یعنی یا سفارشِ ناقص یا پولِ خرج‌شدهٔ
     * بی‌سرور — همان چیزی که در این پروژه سابقه دارد.
     *
     * `manual` برمی‌گردانیم نه `fail`: سرویس به صفِ تحویلِ دستیِ مدیر می‌رود،
     * مشتری پیامِ درست می‌بیند، و هیچ پولی بی‌نتیجه خرج نمی‌شود.
     */
    public function createServer(array $spec): array
    {
        return [
            'ok' => false, 'manual' => true,
            'message' => 'خریدِ خودکار برای این زیرساخت هنوز فعال نیست؛ سفارش به صفِ تحویلِ دستی رفت.',
            'ref' => null, 'ipv4' => null, 'ipv6' => null, 'root_password' => null,
            'status' => 'building',
        ];
    }

    /**
     * کاتالوگ — از **سرورهای موجودِ خودمان**، نه از فهرستِ فروشِ OVH.
     *
     * چرا: قیمتِ فروشِ OVH از `/order/catalog` می‌آید که ساختارش با حسابِ
     * تجاری فرق می‌کند و راستی‌آزمایی نشده. تا وقتی خریدِ خودکار نداریم،
     * ساختنِ کاتالوگِ فروش از روی آن، قیمتی روی سایت می‌گذارد که نمی‌شود
     * خرید — همان چیزی که CLAUDE.md می‌گوید از نبودِ قیمت بدتر است.
     */
    public function fetchCatalog(): array
    {
        return [
            'ok' => false,
            'message' => 'کاتالوگِ خودکارِ این زیرساخت هنوز فعال نیست؛ پلن‌هایش را دستی در پنل ثبت کنید.',
            'locations' => [], 'plans' => [], 'images' => [],
        ];
    }

    /**
     * ساختارِ خامِ پاسخ — برای صفحهٔ عیب‌یابیِ `/admin/cloud/probe`.
     *
     * چون بخشی از نگاشتِ این زیرساخت استنتاجی است، دیدنِ پاسخِ واقعی تنها راهِ
     * دقیق‌کردنش است.
     */
    public function rawProbe(): array
    {
        $out = [];

        foreach (['/me', '/vps'] as $p) {
            $r = $this->req('GET', $p);
            $out[$p] = ['ok' => $r['ok'], 'status' => $r['status'], 'sample' => $r['body']];
        }

        $names = (array) ($out['/vps']['sample'] ?? []);

        if (($first = reset($names)) && is_string($first)) {
            foreach (['', '/ips'] as $suffix) {
                $r = $this->req('GET', '/vps/'.rawurlencode($first).$suffix);
                $out['/vps/{name}'.$suffix] = ['ok' => $r['ok'], 'status' => $r['status'], 'sample' => $r['body']];
            }
        }

        return $out;
    }
}
