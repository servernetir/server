<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OVHcloud US VPS: signed API, monthly cart orders, durable checkout receipts,
 * exact order/resource reconciliation and verified guest credentials.
 * OvhOrderFlow and OvhGuestAccess own purchase and initial access state.
 * No hourly VPS orders or long-term commitments are made by this driver.
 * @see https://api.us.ovhcloud.com/1.0/order.json
 * @see https://api.us.ovhcloud.com/1.0/vps.json
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

    /** Account-bound cache identity; credentials are never returned to callers. */
    public function accountFingerprint(): string
    {
        return hash_hmac('sha256', implode("\0", [
            $this->base(), (string) $this->appKey(),
            (string) $this->appSecret(), (string) $this->consumerKey(),
        ]), (string) config('app.key'));
    }

    /**
     * Read the active prepaid account's reported balance, without crediting it.
     * The official schema defines billing.OvhAccount.balance as order.Price
     * (value + currencyCode). Promotional credits and availableAfterCredit are
     * deliberately excluded. Multiple accounts need an explicit future mapping.
     *
     * @see https://eu.api.ovh.com/1.0/me.json
     * @return array{known:bool,amount?:mixed,currency?:mixed,reason?:string}
     */
    public function accountBalance(): array
    {
        $list = $this->req('GET', '/me/ovhAccount');
        $ids = $list['body'] ?? null;
        if (! $list['ok'] || ! is_array($ids) || ! array_is_list($ids)) {
            return ['known' => false, 'reason' => 'balance_unavailable'];
        }
        if (count($ids) !== 1) {
            return ['known' => false, 'reason' => $ids === [] ? 'no_prepaid_account' : 'ambiguous_accounts'];
        }
        $id = $ids[0];
        if (! is_string($id) || $id === '' || strlen($id) > 128) {
            return ['known' => false, 'reason' => 'balance_unavailable'];
        }
        $result = $this->req('GET', '/me/ovhAccount/'.rawurlencode($id));
        $account = $result['body'] ?? null;
        if (! $result['ok'] || ! is_array($account)) {
            return ['known' => false, 'reason' => 'balance_unavailable'];
        }
        if (($account['isActive'] ?? null) !== true) {
            return ['known' => false, 'reason' => 'prepaid_account_inactive'];
        }
        $price = $account['balance'] ?? null;
        if (! is_array($price)) {
            return ['known' => false, 'reason' => 'balance_unavailable'];
        }

        return ['known' => true, 'amount' => $price['value'] ?? null,
            'currency' => $price['currencyCode'] ?? null];
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
            'ssh_key'        => false,
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
        if (!$vps['ok'] || !is_array($vps['body']) || !array_is_list($vps['body'])) {
            return ['ok' => false, 'message' => 'حساب قابل دسترس است، اما فهرست VPS تأیید نشد؛ مجوز GET /vps بررسی شود.'];
        }
        if (Setting::get('ovh_auto_order_enabled') === '1') {
            foreach (['/order/cart','/me/order'] as $path) {
                if (!$this->req('GET',$path)['ok']) {
                    return ['ok'=>false,'message'=>'مدیریت VPS متصل است، اما مجوز سفارش یا پیگیری سفارش تأیید نشد.'];
                }
            }
        }
        $n = count($vps['body']);

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
        if (str_starts_with($ref, 'order:ovh:')) {
            return $this->orders()->status($ref);
        }
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
        return $this->orders()->rebuild($ref,$imageRef);
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
        if (str_starts_with($ref,'order:')) {
            return ['ok'=>false,'message'=>'سفارش در حال تطبیق است؛ خاتمه نیازمند بررسی مدیر است.'];
        }
        $current=$this->req('GET','/vps/'.rawurlencode($ref));
        if (!$current['ok'] && $current['status']===404) {return ['ok'=>true,'message'=>'نبود همین ماشین تأیید شد.'];}
        if (!$current['ok']) {return ['ok'=>false,'message'=>'وضعیت ماشین برای خاتمه تأیید نشد.'];}
        $instance=\App\Models\CloudInstance::where('provider','ovh')->where('provider_ref',$ref)->first();
        if (!$instance) {return ['ok'=>false,'message'=>'رکورد مالکیت ماشین پیدا نشد.'];}
        $meta=(array)$instance->meta;
        if (!isset($meta['ovh_termination_requested_at'])) {
            $meta['ovh_termination_requested_at']=now()->toIso8601String();
            $instance->update(['meta'=>$meta]);
            $this->req('POST','/vps/'.rawurlencode($ref).'/terminate');
        }
        return ['ok'=>false,'message'=>'تلاش خاتمه ثبت است؛ پذیرش درخواست، تأیید ایمیلی مالک و نبود نهایی ماشین باید بررسی شود.'];
    }

    public function uploadSshKey(string $name, string $publicKey): array
    {
        return ['ok' => false, 'message' => 'کلیدِ SSH برای این زیرساخت پشتیبانی نمی‌شود.'];
    }

    public function addExtraIps(string $ref, int $count): array
    {
        return ['ok' => false, 'message' => 'IP اضافه برای این زیرساخت از مسیرِ سفارش انجام می‌شود.'];
    }

    /** Signed transport stays inside this driver; the order state machine receives a callable. */
    private function orders(): OvhOrderFlow
    {
        return new OvhOrderFlow(fn(string $method,string $path,array $payload=[]): array => $this->req($method,$path,$payload));
    }

    public function createServer(array $spec): array
    {
        return $this->orders()->create($spec);
    }

    public function previewOrder(array $spec): array
    {
        return $this->orders()->preview($spec);
    }

    /**
     * کاتالوگ — از **سرورهای موجودِ خودمان**، نه از فهرستِ فروشِ OVH.
     *
     * چرا: قیمتِ فروشِ OVH از `/order/catalog` می‌آید که ساختارش با حسابِ
     * تجاری فرق می‌کند و راستی‌آزمایی نشده. تا وقتی خریدِ خودکار نداریم،
     * ساختنِ کاتالوگِ فروش از روی آن، قیمتی روی سایت می‌گذارد که نمی‌شود
     * خرید — همان چیزی که CLAUDE.md می‌گوید از نبودِ قیمت بدتر است.
     */
    /**
     * دیتاسنترهای OVH → کشور و شهر.
     *
     * ⚠️ صریح و دستی، **نه** استنتاج از روی کد. `US-EAST-LZ-ATL` را می‌شود
     * «آتلانتا» خواند، ولی حدسِ غلط یعنی مشتری «نیویورک» می‌خرد و سرورش جای
     * دیگری بالا می‌آید — و چون تحویل دستی است، تا شکایتِ خودش معلوم نمی‌شود.
     * کدِ ناشناخته **ردیف نمی‌گیرد** و گزارش می‌شود؛ همان قاعدهٔ `CPU_CORES`.
     */
    /**
     * مشخصاتِ سخت‌افزاری که API نمی‌دهد — پرکنندهٔ خلأ، نه منبعِ اول.
     *
     * ⚠️ عمداً خالی است. تا وقتی از منبعِ **واقعی** (کاتالوگِ `formatted` یا
     * صفحهٔ محصولِ OVH) پر نشود، پلنِ بی‌مشخصات فروخته نمی‌شود — و این بهتر
     * از پر کردنش با حدس است: «VPS-1» را می‌شود «۲ هسته» خواند و غلط بود،
     * و خطایش تا شکایتِ مشتری معلوم نمی‌شود.
     *
     * کلید = planCodeِ دقیق · مقدار = [هسته، رمِ مگابایت، دیسکِ گیگابایت]
     *
     * @var array<string, array{0:int,1:int,2:int}>
     */
    private const MODEL_SPECS = [
        // 'vps-2025-model1.LZ' => [2, 2048, 40],
    ];

    private const DATACENTERS = [
        // Local Zoneهای آمریکا — همان‌هایی که در کاتالوگِ حسابِ ما آمدند
        'US-EAST-LZ-ATL' => ['US', 'Atlanta'],
        'US-EAST-LZ-DAL' => ['US', 'Dallas'],
        'US-EAST-LZ-MIA' => ['US', 'Miami'],
        'US-EAST-LZ-NYC' => ['US', 'New York'],
        'US-WEST-LZ-DEN' => ['US', 'Denver'],
        'US-WEST-LZ-LAX' => ['US', 'Los Angeles'],
        'US-WEST-LZ-PAO' => ['US', 'Palo Alto'],
        'US-WEST-LZ-SEA' => ['US', 'Seattle'],
        // دیتاسنترهای اصلیِ نهادِ US
        'US-EAST-VA'     => ['US', 'Vint Hill'],
        'US-WEST-OR'     => ['US', 'Hillsboro'],
        // اگر روزی حسابِ اروپایی/کانادایی اضافه شد
        'GRA' => ['FR', 'Gravelines'], 'SBG' => ['FR', 'Strasbourg'], 'RBX' => ['FR', 'Roubaix'],
        'BHS' => ['CA', 'Beauharnois'], 'WAW' => ['PL', 'Warsaw'],
        'DE'  => ['DE', 'Frankfurt'],   'UK'  => ['GB', 'London'],
        'SGP' => ['SG', 'Singapore'],   'SYD' => ['AU', 'Sydney'],
    ];

    /**
     * کاتالوگِ فروش — از `/order/catalog/public/vps`.
     *
     * ═══ چه چیزی از پاسخِ **واقعیِ** حساب آمده، نه از حدس ═══
     *
     * ۱) **پلنِ واقعی از افزونه با `vps_datacenter` جدا می‌شود.** پاسخ ۲۴۳ ردیف
     *    دارد که بیشترشان افزونه‌اند (`option-cpanel-*`، `option-snapshot-*`،
     *    `option-storage-*`). فیلترِ نام‌محور شکننده است؛ ولی فقط یک VPSِ واقعی
     *    پیکربندیِ «کدام دیتاسنتر» دارد.
     *
     * ۲) **قیمت در واحدِ ۱۰⁻⁸ است.** `price: 850000000` ⇒ ۸٫۵۰، و خودِ پاسخ با
     *    `formattedPrice: "$8.50 USD"` تأییدش می‌کند. مقسومٌ‌علیه از همان‌جا
     *    راستی‌آزمایی شد، نه از حافظه.
     *
     * ۳) 🔴 **ارز دلار است، نه یورو.** `/me` می‌گوید `currency.code: USD` و
     *    کلِ زنجیرهٔ قیمت‌گذاریِ این پروژه یورویی است. اگر سنتِ دلار را در
     *    `cost_eur_cents` بنشانیم، بهایِ تمام‌شده حدودِ ۸٪ کمتر از واقع ثبت
     *    می‌شود و حاشیهٔ کوچکِ مدیر بی‌صدا منفی می‌شود — همان الگوی «سربارِ
     *    ارزیِ جاافتاده» که یک بار بکاپ را زیرِ بها فروخت.
     *
     * ⚠️ **ردیفِ بی‌مشخصات ذخیره نمی‌شود.** پاسخِ `public` هسته/رم/دیسک ندارد
     * (فقط `blobs.commercial`)، پس مشخصات از `formatted` خوانده می‌شود و اگر
     * نیامد یا از بازهٔ معقول بیرون بود، آن ردیف **رد** می‌شود و شمرده. ذخیره
     * با صفر یعنی فروشِ پلنی که مشتری نمی‌داند چه می‌خرد.
     *
     * ⚠️ این متد کاتالوگ مستقل از فعال‌بودن خرید خودکار است و
     * قیمت و موجودی را بدون خرید می‌خواند.
     */
    public function fetchCatalog(): array
    {
        $empty = ['locations' => [], 'plans' => [], 'images' => []];

        $me = $this->req('GET', '/me');

        if (! $me['ok']) {
            return ['ok' => false, 'message' => 'حساب خوانده نشد: '.$me['message']] + $empty;
        }

        $sub = (string) data_get($me['body'], 'ovhSubsidiary', '');
        $currency = strtoupper((string) data_get($me['body'], 'currency.code', ''));

        if ($sub === '' || $currency === '') {
            return ['ok' => false, 'message' => 'زیرمجموعه یا ارزِ حساب خوانده نشد؛ '
                .'بی‌آن‌ها کاتالوگ یا اشتباه است یا قیمتش قابلِ تبدیل نیست.'] + $empty;
        }

        $rate = $this->toEurFactor($currency);

        if ($rate === null) {
            return ['ok' => false, 'message' => 'نرخِ تبدیلِ '.$currency.' به یورو در دسترس نیست؛ '
                .'کاتالوگ ساخته نشد تا بهایِ تمام‌شده اشتباه ثبت نشود.'] + $empty;
        }

        $cat = $this->req('GET', '/order/catalog/public/vps', ['ovhSubsidiary' => $sub]);

        if (! $cat['ok']) {
            return ['ok' => false, 'message' => 'کاتالوگ خوانده نشد: '.$cat['message']] + $empty;
        }

        // Exact public product descriptions include new generations absent from formatted plans.
        $publicSpecs=[];
        foreach($cat['body']['products']??[]as$product){
            $parsed=$this->parseSpecs((string)($product['description']??''));
            if(is_string($product['name']??null)&&$parsed!==null){
                $interface=strtolower((string)data_get($product,'blobs.technical.storage.disks.0.interface',''));
                $technology=strtolower((string)data_get($product,'blobs.technical.storage.disks.0.technology',''));
                $parsed['disk_type']=$interface==='nvme'?'nvme':($technology==='ssd'?'ssd':null);
                $publicSpecs[$product['name']]=$parsed;
            }
        }
        $specs = $this->technicalSpecs($sub);
        $codes=[];$stocks=[];
        foreach ($cat['body']['plans']??[] as $p) {
            if ($this->datacentersOf($p)!==[] && OvhOrderFlow::orderable($p)) {$codes[]=$p['planCode'];}
        }
        foreach (array_chunk(array_values(array_unique($codes)),30) as $batch) {
            $availability=$this->req('GET','/vps/order/rule/datacenter/aggregated',[
                'ovhSubsidiary'=>$sub,'planCodes'=>implode(',',$batch),
            ]);
            foreach (($availability['ok'] ? ($availability['body']['models']??[]) : []) as $model) {
                foreach ($model['datacenters']??[] as $row) {
                    if (is_string($row['datacenter']??null) && ($row['remoteStorage']??false)===false) {
                        $stocks[$model['planCode']][$row['datacenter']]=($row['linuxStatus']??null)==='available';
                    }
                }
            }
        }

        $outPlans = [];
        $locations = [];
        $noSpecs = [];
        $unknownDc = [];
        $osNames = [];
        $noPrice = 0;

        foreach ((array) data_get($cat['body'], 'plans', []) as $plan) {
            $code = (string) ($plan['planCode'] ?? '');
            $dcs = $this->datacentersOf($plan);

            // بی‌«کدام دیتاسنتر» یعنی افزونه است، نه سرور
            if ($code === '' || $dcs === [] || !OvhOrderFlow::orderable($plan)) {
                continue;
            }

            $usd = $this->monthlyPrice($plan);
            // Mandatory options are part of the supplier's recurring cost, including backup.
            foreach ($plan['addonFamilies']??[] as $family) {
                if (($family['mandatory']??false)!==true) {continue;}
                $amounts=[];
                foreach ($cat['body']['addons']??[] as $option) {
                    if (!in_array($option['planCode']??'', $family['addons']??[],true)
                        || (($family['name']??'')==='os' && ($option['planCode']??'')!=='option-linux')
                        || (($family['name']??'')==='storage' && !str_starts_with($option['planCode']??'','option-storage-local-'))) {continue;}
                    $amount=$this->monthlyPrice($option);
                    if ($amount!==null && $amount>=0) {$amounts[]=$amount;}
                }
                if ($amounts===[]) {$usd=null;break;}
                if ($usd!==null) {$usd+=min($amounts);}
            }

            if ($usd === null || $usd <= 0) {
                /*
                | ⚠️ این رد **شمرده** می‌شود. نسخهٔ اول فقط `continue` می‌کرد و
                | همان سکوت باعث شد از ۲۴۲ ردیفِ کاتالوگ فقط ۸۲ ردیف ساخته شود
                | بی‌آنکه گزارش بگوید بقیه کجا رفتند — دقیقاً همان الگویی که
                | در این پروژه بارها گران تمام شده.
                */
                $noPrice++;

                continue;   // پلنِ بی‌قیمتِ ماهانه (فقط تعهدی) — فروختنی نیست
            }

            $stock=$stocks[$code]??[];
            $spec = $publicSpecs[$plan['product']??''] ?? $specs[$this->baseCode($code)] ?? $specs[$code] ?? null;

            if ($spec === null) {
                $noSpecs[] = $code;

                continue;
            }

            foreach ($this->configValues($plan, 'vps_os') as $os) {
                $osNames[$os] = true;
            }

            foreach ($dcs as $dc) {
                $meta = self::DATACENTERS[strtoupper($dc)] ?? null;

                if ($meta === null) {
                    $unknownDc[strtoupper($dc)] = true;

                    continue;
                }

                [$country, $city] = $meta;
                $locCode = CloudNaming::locationCode($country, $city, $dc);

                $locations[$locCode] = [
                    'code' => $locCode, 'country' => $country, 'city' => $city,
                    'provider_location' => $dc, 'latitude' => null, 'longitude' => null,
                ];

                $outPlans[] = [
                    'provider_ref'      => $code,
                    'provider_location' => $dc,
                    'location_code'     => $locCode,
                    'name'              => (string) ($plan['invoiceName'] ?? $code),
                    'vcpu'              => $spec['vcpu'],
                    'ram_mb'            => $spec['ram_mb'],
                    'disk_gb'           => $spec['disk_gb'],
                    'disk_type'         => $spec['disk_type'] ?? 'nvme',
                    'traffic_gb'        => 0,        // ترافیکِ VPSِ OVH سنجیده نمی‌شود
                    'cpu_kind'          => 'shared',
                    'arch'              => 'x86',
                    'cost_eur_cents'    => (int) ceil(
                        app(CloudPricing::class)->costWithFee($usd * $rate, 'ovh') * 100
                    ),
                    'in_stock'          => ($stock[$dc] ?? false) === true,
                ];
            }
        }

        $notes = [];

        if ($noPrice > 0) {
            $notes[] = fa_num((string) $noPrice).' پلن بدونِ نرخِ ماهانهٔ بی‌تعهد رد شد';
        }

        if ($noSpecs !== []) {
            $notes[] = fa_num((string) count($noSpecs)).' پلن بی‌مشخصاتِ سخت‌افزاری رد شد';
        }

        if ($unknownDc !== []) {
            $notes[] = 'دیتاسنترِ ناشناخته: '.implode('، ', array_slice(array_keys($unknownDc), 0, 6))
                .' — به DATACENTERS اضافه شود';
        }

        if ($outPlans === []) {
            return ['ok' => false, 'message' => 'هیچ پلنِ کاملی ساخته نشد'
                .($notes !== [] ? ' ('.implode(' · ', $notes).')' : '').'.'] + $empty;
        }

        return [
            'ok' => true,
            'message' => $notes === [] ? '' : '⚠️ '.implode(' · ', $notes),
            'locations' => array_values($locations),
            'plans' => $outPlans,
            'images' => $this->imagesFrom(array_keys($osNames)),
        ];
    }

    /**
     * ضریبِ تبدیلِ ارزِ حساب به یورو — از نرخِ تومانِ هر دو.
     *
     * ⚠️ overrideِ دستیِ مدیر مقدم است (همان الگوی `SaladOperations`): اگر
     * نرخِ صرافیِ خودکار با نرخی که واقعاً پول را با آن می‌فرستیم نخوانَد،
     * پنل باید حرفِ مدیر را بزند نه حرفِ فید را. بی‌این، بهایِ تمام‌شده و
     * صفحهٔ مالی دو عددِ متفاوت می‌گویند.
     *
     * ⚠️ و نبودِ نرخ `null` می‌دهد نه ۱: ضریبِ ۱ یعنی دلار را یورو حساب کنیم
     * و بهایِ تمام‌شده ~۸٪ کمتر ثبت شود — دقیقاً همان‌جور خطای خاموشی که
     * ماه‌ها بعد در صورت‌حساب پیدا می‌شود.
     */
    private function toEurFactor(string $currency): ?float
    {
        if ($currency === 'EUR') {
            return 1.0;
        }

        $eur = (int) app(CloudPricing::class)->eurToToman();

        try {
            $own = (int) (app(\App\Services\ExchangeRate::class)->toToman($currency) ?? 0);
        } catch (\Throwable) {
            $own = 0;
        }

        return $eur > 0 && $own > 0 ? $own / $eur : null;
    }

    /** مقادیرِ پیکربندیِ `vps_datacenter` — نبودشان یعنی این ردیف افزونه است */
    /**
     * پایهٔ planCode — «vps-2025-model1.LZ» و «vps-2025-model1» یک **مدل**اند.
     *
     * 🔴 این تفاوت علتِ شکستِ دورِ اول بود: کاتالوگِ `public` واریانتِ منطقه‌ای
     * را با پسوند می‌دهد (`.LZ` برای Local Zone) و کاتالوگِ `formatted` فقط
     * مدلِ پایه را. نگاشتِ کلیدِ دقیق هرگز تطبیق نمی‌خورد و هر ۲۴۲ ردیف
     * «بی‌مشخصات» رد می‌شدند.
     *
     * ⚠️ قیمت عمداً از `public` می‌آید نه از `formatted`: همان مدل در Local
     * Zone هشت‌ونیم دلار است و در VA/OR هفت‌وشصت. برداشتنِ قیمتِ پایه یعنی
     * حدودِ ۱۱٪ زیرِ بها فروختنِ واریانتِ گران‌تر.
     */
    private function baseCode(string $planCode): string
    {
        $dot = strpos($planCode, '.');

        return $dot === false ? $planCode : substr($planCode, 0, $dot);
    }

    /**
     * مشخصات از متنِ توصیفِ محصول — «VPS 4 vCPU 8 GB RAM 75 GB disk».
     *
     * @return array{vcpu:int,ram_mb:int,disk_gb:int}|null
     */
    private function parseSpecs(string $description): ?array
    {
        if ($description === '') {
            return null;
        }

        if (! preg_match('/(\d+)\s*vCPU/i', $description, $c)
            || ! preg_match('/(\d+)\s*GB\s*RAM/i', $description, $r)
            || ! preg_match('/(\d+)\s*GB\s*disk/i', $description, $d)) {
            return null;
        }

        return $this->validSpecs((int) $c[1], (int) $r[1] * 1024, (int) $d[1]);
    }

    /**
     * پشتیبان: خانواده‌هایی که مشخصات را در نامشان دارند
     * (`vps-comfort-4-16-160` ⇒ ۴ هسته، ۱۶ گیگ، ۱۶۰ گیگ).
     *
     * ⚠️ فقط وقتی توصیف نباشد. متنِ محصول حرفِ خودِ OVH است؛ الگوی نام یک
     * **استنتاج** است و استنتاج هرگز نباید بر داده مقدم شود.
     *
     * @return array{vcpu:int,ram_mb:int,disk_gb:int}|null
     */
    private function specsFromCode(string $planCode): ?array
    {
        if (! preg_match('/-(\d+)-(\d+)-(\d+)$/', $this->baseCode($planCode), $m)) {
            return null;
        }

        return $this->validSpecs((int) $m[1], (int) $m[2] * 1024, (int) $m[3]);
    }

    /**
     * بازهٔ معقول — عددِ بی‌معنا از نبودِ عدد بدتر است.
     *
     * ردیفِ «۰ هسته» فروختنی می‌شود و مشتری نمی‌داند چه خریده؛ ردیفِ نبود
     * فقط فروخته نمی‌شود و در گزارشِ سینک شمرده می‌شود.
     *
     * @return array{vcpu:int,ram_mb:int,disk_gb:int}|null
     */
    private function validSpecs(int $vcpu, int $ramMb, int $diskGb): ?array
    {
        if ($vcpu < 1 || $vcpu > 256 || $ramMb < 256 || $ramMb > 1048576
            || $diskGb < 5 || $diskGb > 100000) {
            return null;
        }

        return ['vcpu' => $vcpu, 'ram_mb' => $ramMb, 'disk_gb' => $diskGb];
    }

    /** مقادیرِ یک پیکربندیِ نام‌دار — `vps_os`، `vps_datacenter`، … */
    private function configValues(array $plan, string $name): array
    {
        foreach ((array) ($plan['configurations'] ?? []) as $c) {
            if ((string) ($c['name'] ?? '') === $name) {
                return array_values(array_filter(
                    array_map(fn ($v) => trim((string) $v), (array) ($c['values'] ?? []))
                ));
            }
        }

        return [];
    }

    /**
     * خانوادهٔ سیستم‌عامل — نامِ نمایشیِ OVH به همان واژه‌ای که هتزنر می‌دهد.
     *
     * 🔴 این نگاشت تزئینی نیست: `CloudNaming::imageKey()` کلید را از
     * «خانواده-نسخه» می‌سازد و **همان کلید** است که سیستم‌عاملِ دو زیرساخت را
     * یکی می‌کند. اگر OVH بگوید `rocky-linux-9` و هتزنر `rocky-9`، مشتری در
     * صفحهٔ خرید دو تا «راکی ۹» می‌بیند — بی‌هیچ خطایی.
     */
    private const OS_FAMILIES = [
        'almalinux'     => 'alma',
        'rocky linux'   => 'rocky',
        'cloudlinux'    => 'cloudlinux',
        'ubuntu'        => 'ubuntu',
        'debian'        => 'debian',
        'fedora'        => 'fedora',
        'centos'        => 'centos',
        'freebsd'       => 'freebsd',
        'windows server' => 'windows',
    ];

    /** پسوندهایی که نرم‌افزارِ آماده‌اند، نه سیستم‌عامل */
    private const OS_APPS = ['cpanel', 'plesk', 'docker', 'n8n'];

    /**
     * ایمیج‌ها از فهرستِ `vps_os` کاتالوگ.
     *
     * ⚠️ `provider_ref` **عیناً** همان رشتهٔ نمایشی است، چون سفارشِ OVH همان را
     * می‌خواهد. هر «تمیزکاری»‌ای این‌جا یعنی سفارشی که رد می‌شود.
     *
     * ⚠️ نامِ نافهم **رد** می‌شود، نه اینکه با کلیدِ حدسی ذخیره شود: کلیدِ غلط
     * سیستم‌عامل را از گروهِ درستش جدا می‌کند و در لحظهٔ تحویل به ایمیجی
     * می‌رسد که آن زیرساخت ندارد.
     *
     * @param  array<int, string>  $names
     */
    private function imagesFrom(array $names): array
    {
        $out = [];

        foreach ($names as $name) {
            if (!in_array($name, OvhOrderFlow::IMAGES, true)) {continue;}
            // «Debian 12 - Docker» ⇒ پایه + پسوند · «… (Desktop)» ⇒ توضیحِ اضافه
            $parts = array_map('trim', explode(' - ', $name, 2));
            $base = preg_replace('/\s*\([^)]*\)\s*/', ' ', $parts[0]) ?? $parts[0];
            $suffix = strtolower(trim($parts[1] ?? ''));

            $family = null;
            $rest = '';

            foreach (self::OS_FAMILIES as $needle => $slug) {
                if (stripos($base, $needle) === 0) {
                    $family = $slug;
                    $rest = trim(substr($base, strlen($needle)));
                    break;
                }
            }

            // FreeBSD نسخه را با خط‌تیره می‌چسباند: «FreeBSD-14.3»
            if ($family === null && stripos($base, 'freebsd') === 0) {
                $family = 'freebsd';
                $rest = trim(substr($base, 7), '- ');
            }

            if ($family === null) {
                continue;   // نامِ ناشناخته — حدس نمی‌زنیم
            }

            if (! preg_match('/(\d[\d.]*)/', $rest, $m)) {
                continue;   // بی‌نسخه، کلیدِ قابلِ اتکا ساخته نمی‌شود
            }

            $version = $m[1];
            $isApp = in_array($suffix, self::OS_APPS, true);
            $kind = $isApp ? 'app' : 'os';

            $out[] = [
                'provider_ref' => $name,
                'key'          => CloudNaming::imageKey($kind, $family, $version, $name),
                'kind'         => $kind,
                'family'       => $isApp ? CloudNaming::appFamily($name) : $family,
                'version'      => $version,
                'label'        => $name,
                'arch'         => 'x86',       // VPSهای OVH همه x86اند
                'min_disk_gb'  => 0,
            ];
        }

        return $out;
    }

    private function datacentersOf(array $plan): array
    {
        foreach ((array) ($plan['configurations'] ?? []) as $c) {
            if ((string) ($c['name'] ?? '') === 'vps_datacenter') {
                return array_values(array_filter((array) ($c['values'] ?? [])));
            }
        }

        return [];
    }

    /**
     * قیمتِ ماهانهٔ بی‌تعهد.
     *
     * ⚠️ `commitment === 0` شرطِ لازم است: همان پلن نرخِ ۶ و ۱۲ ماههٔ ارزان‌تر
     * هم دارد و برداشتنِ آن‌ها یعنی بهایی ثبت کنیم که فقط با پیش‌پرداختِ
     * یک‌ساله واقعی است — و ما ماهانه می‌خریم.
     */
    private function monthlyPrice(array $plan): ?float
    {
        foreach ((array) ($plan['pricings'] ?? []) as $p) {
            $caps = (array) ($p['capacities'] ?? []);

            if (in_array('renew', $caps, true)
                && (string) ($p['intervalUnit'] ?? '') === 'month'
                && (int) ($p['interval'] ?? 0) === 1
                && (string) ($p['mode'] ?? '') === 'default'
                && (int) ($p['commitment'] ?? 0) === 0) {
                // واحدِ ۱۰⁻⁸ — با formattedPrice همان پاسخ راستی‌آزمایی شد
                return ((int) ($p['price'] ?? 0)) / 100_000_000;
            }
        }

        return null;
    }

    /**
     * مشخصاتِ سخت‌افزاری به تفکیکِ planCode.
     *
     * پاسخِ `public` این‌ها را ندارد (فقط `blobs.commercial`), پس از کاتالوگِ
     * `formatted` خوانده می‌شود. اگر آن هم نداد، آرایهٔ خالی برمی‌گردد و
     * `fetchCatalog` همهٔ ردیف‌ها را رد و **گزارش** می‌کند.
     *
     * ⚠️ هر عدد بازهٔ معقول دارد. ذخیرهٔ مقدارِ بی‌معنا از رد کردن بدتر است:
     * ردیفِ «۰ هسته» فروختنی می‌شود و مشتری نمی‌داند چه خریده.
     *
     * @return array<string, array{vcpu:int,ram_mb:int,disk_gb:int}>
     */
    private function technicalSpecs(string $sub): array
    {
        $out = [];

        /*
        | راهِ دوم، برای وقتی API مشخصات نمی‌دهد.
        |
        | پاسخِ `public` روی حسابِ ما `blobs.technical` **ندارد** (فقط
        | `commercial` و `tags` — با چشم دیده شد). اگر `formatted` هم ندهد،
        | تنها منبعِ باقی‌مانده صفحهٔ محصولِ خودِ OVH است و آن یک واقعیتِ
        | **دستی** است، نه چیزی که بشود استنتاج کرد.
        |
        | ⚠️ همان الگوی `GEX_SPECS` و `CPU_CORES`: عددِ کارخانه که در API نیست،
        | صریح نوشته می‌شود و کلیدِ نبود یعنی آن پلن **فروخته نمی‌شود** — نه
        | اینکه با حدس پر شود. «VPS-1» را می‌شود «۲ هسته» خواند و غلط بود.
        |
        | ⚠️ و این جدول فقط **پرکنندهٔ خلأ** است: هر جا API عددی بدهد، همان
        | برنده است. وگرنه جدولِ دستی روزی کهنه می‌شود و بی‌صدا دروغ می‌گوید.
        |
        | کلید = planCode (دقیق) · مقدار = [هسته، رمِ مگابایت، دیسکِ گیگابایت]
        */
        foreach (self::MODEL_SPECS as $code => [$vcpu, $ram, $disk]) {
            $out[$code] = ['vcpu' => $vcpu, 'ram_mb' => $ram, 'disk_gb' => $disk];
        }

        $r = $this->req('GET', '/order/catalog/formatted/vps', ['ovhSubsidiary' => $sub]);

        if (! $r['ok']) {
            return $out;
        }

        foreach ((array) data_get($r['body'], 'plans', []) as $plan) {
            $code = (string) ($plan['planCode'] ?? '');

            if ($code === '') {
                continue;
            }

            /*
            | 🔴 مشخصات این‌جا **متنِ انسانی** است، نه فیلدِ عددی:
            |     "VPS 4 vCPU 8 GB RAM 75 GB disk"
            |
            | این پاسخ اصلاً `blobs` ندارد — دورِ اول دنبالِ `blobs.technical`
            | گشتیم و هیچ نبود. همان «هیچ» درست‌ترین نتیجه بود: ردیف رد شد
            | به‌جای اینکه با صفر ذخیره شود.
            */
            $spec = $this->parseSpecs((string) data_get($plan, 'details.product.description', ''))
                ?? $this->specsFromCode($code);

            if ($spec === null) {
                continue;
            }

            $out[$code] = $spec;
        }

        return $out;
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

        /*
        | کاتالوگِ فروش — تنها راهِ دیدنِ شکلِ واقعیِ پاسخ پیش از نگاشت‌کردنش.
        |
        | 🔴 درسِ همین هفته: من شکلِ `locations[]`ِ هتزنر را **حدس** زدم، تست را
        | با همان حدس نوشتم، هر دو سبز شدند، و رفع روی پروداکشن **هیچ ردیفی را
        | فیلتر نکرد**. حدس‌زدنِ ساختار، تستِ سبزِ بی‌اثر می‌سازد.
        |
        | ⚠️ زیرمجموعه (`ovhSubsidiary`) حدس زده نمی‌شود: از خودِ `/me` می‌آید.
        | حسابِ US و FR کاتالوگِ متفاوت دارند و پارامترِ اشتباه یا خطا می‌دهد یا
        | — بدتر — کاتالوگِ کشورِ دیگری را برمی‌گرداند با قیمت‌هایی که ما اصلاً
        | نمی‌توانیم بخریم.
        */
        $sub = (string) data_get($out['/me']['sample'] ?? [], 'ovhSubsidiary', '');
        $out['ovhSubsidiary'] = $sub !== '' ? $sub : '⚠️ خوانده نشد';

        if ($sub !== '') {
            $c = $this->req('GET', '/order/catalog/public/vps', ['ovhSubsidiary' => $sub]);

            $plans = (array) data_get($c['body'], 'plans', []);

            /*
            | ⚠️ نمونه از **یک پلنِ واقعی** برداشته می‌شود، نه از ردیفِ اول.
            |
            | بارِ اول دو ردیفِ اول را دادیم و ردیفِ اول یک افزونه بود — یعنی
            | از ۲۴۲ سرورِ واقعی فقط یکی را دیدیم و همان یکی `blobs.technical`
            | نداشت. نمونه‌ای که نمایندهٔ چیزی که می‌سازیم نباشد، همان‌قدر
            | گمراه‌کننده است که ندیدن.
            */
            $real = [];

            foreach ($plans as $p) {
                if ($this->datacentersOf((array) $p) !== []) {
                    $real[] = $p;
                }

                if (count($real) >= 2) {
                    break;
                }
            }

            $out['/order/catalog/public/vps'] = [
                'ok' => $c['ok'], 'status' => $c['status'],
                'plan_count' => count($plans),
                'with_datacenter' => count(array_filter($plans,
                    fn ($p) => $this->datacentersOf((array) $p) !== [])),
                // کلیدهای blobs مهم‌ترین چیزِ این صفحه است: مشخصاتِ سخت‌افزاری
                // اگر جایی باشد، همان‌جاست.
                'blob_keys' => array_keys((array) data_get($real[0] ?? [], 'blobs', [])),
                'sample' => array_slice($real, 0, 1),
                'message' => $c['ok'] ? '' : $c['message'],
            ];

            /*
            | 🔴 همان پرسش از کاتالوگِ `formatted` — تنها جایی که ممکن است
            | هسته/رم/دیسک بدهد. سینک گفت «۲۴۲ پلن بی‌مشخصات»، یعنی یا این
            | مسیر جواب نمی‌دهد یا شکلش با نامزدهای ما نمی‌خواند. این بلوک
            | دقیقاً می‌گوید کدام.
            */
            $f = $this->req('GET', '/order/catalog/formatted/vps', ['ovhSubsidiary' => $sub]);
            $fPlans = (array) data_get($f['body'], 'plans', []);

            $out['/order/catalog/formatted/vps'] = [
                'ok' => $f['ok'], 'status' => $f['status'],
                'message' => $f['ok'] ? '' : $f['message'],
                'plan_count' => count($fPlans),
                'top_keys' => is_array($f['body']) ? array_keys($f['body']) : [],
                'first_plan_keys' => array_keys((array) ($fPlans[0] ?? [])),
                /*
                | 🔴 مشخصات این‌جاست، اگر جایی باشد.
                |
                | دورِ قبل معلوم شد این پاسخ `blobs` **ندارد** و به‌جایش
                | `details` دارد — و ۳۸ ردیف است نه ۲۴۲، یعنی ساختارش با
                | کاتالوگِ public یکی نیست. تا خودِ `details` دیده نشود، هر
                | نگاشتی حدس است.
                */
                'first_plan_code' => (string) data_get($fPlans[0] ?? [], 'planCode', ''),
                'first_details' => data_get($fPlans[0] ?? [], 'details'),
                // یک ردیفِ دیگر هم، چون ردیفِ اول ممکن است افزونه باشد
                'second_plan_code' => (string) data_get($fPlans[1] ?? [], 'planCode', ''),
                'second_details' => data_get($fPlans[1] ?? [], 'details'),
                'all_plan_codes' => array_slice(
                    array_map(fn ($p) => (string) ($p['planCode'] ?? ''), $fPlans), 0, 40
                ),
            ];
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
