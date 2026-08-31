<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * درایورِ ابرآروان (ArvanCloud ECC) — زیرساختِ **ایرانی**.
 *
 * نامِ فیلدها و مسیرها **حدسی نیست**: از Terraform-providerِ رسمیِ خودِ آروان
 * (github.com/arvancloud/terraform-provider-arvan) درآمده — همان‌جا که
 * ساختارها تایپ‌شده نوشته شده‌اند.
 *
 * ═══ سه تفاوتِ مهم با هتزنر/آیزا ═══
 *
 * ۱) **سفیدبرچسبی اینجا هم برقرار است، ولی به دلیلِ دیگری.** آروان زیرساختِ
 *    ایرانی است و مشتری از دیدنِ «سرور تهران» می‌فهمد ایرانی است — این اشکالی
 *    ندارد. ولی نامِ **خودِ برند** («ابرآروان») باز هم پنهان می‌مانَد، چون
 *    کارفرما آن را در کنارِ هتزنر/آیزا به‌عنوان یک زیرساختِ بی‌نام می‌فروشد و
 *    نمی‌خواهد رقیبش بداند از کجا می‌خرد. پس مثلِ بقیه: فقط مکان دیده می‌شود.
 *
 * ۲) **قیمت‌ها به تومان‌اند، نه یورو.** آروان `price_per_month` را عددِ اعشاری
 *    به **ریال/تومانِ ایران** می‌دهد. پس زنجیرهٔ «یورو → تومان»ِ بقیهٔ درایورها
 *    این‌جا لازم نیست: عدد مستقیم قیمتِ پایهٔ تومانی است. برای یکدست‌ماندن با
 *    قرارداد، آن را به «سنتِ یورویِ معادل» تبدیل می‌کنیم تا `CloudPricing` بتواند
 *    مثلِ بقیه رویش حاشیهٔ سود بگذارد — با نرخِ روزِ یورو، معکوسِ کاری که برای
 *    آیزا می‌کنیم.
 *
 * ۳) **ساختِ سرور به شبکه نیاز دارد.** برخلافِ هتزنر که خودش IP می‌دهد، آروان
 *    `network_ids` می‌خواهد. شبکهٔ عمومیِ پیش‌فرضِ هر منطقه را یک‌بار پیدا و
 *    نگه می‌داریم.
 */
class ArvanClient implements CloudProvider
{
    private const BASE = 'https://napi.arvancloud.ir';

    /** پیشوندِ همهٔ مسیرهای ECC */
    private const ECC = '/ecc/v1';

    /**
     * فهرستِ مناطق در نسخه‌های مختلفِ APIِ آروان مسیرِ یکسانی نداشته. به‌جای
     * حدسِ یک مسیر، چند کاندید را به‌ترتیب امتحان می‌کنیم و اولی که پاسخِ موفق
     * با دادهٔ منطقه بدهد استفاده می‌شود. اگر هیچ‌کدام نشد، پیامِ آزمونِ اتصال
     * وضعیتِ هرکدام را نشان می‌دهد تا معلوم شود عیب از مسیر است یا از توکن.
     * (عملیاتِ منطقه‌محور جدا و قطعی‌اند: «/ecc/v1/regions/{code}/…».)
     */
    private const REGION_ENDPOINTS = [
        self::ECC.'/regions',          // مرسوم‌ترین (RESTful)
        self::ECC.'/details',          // آنچه terraform-provider می‌سازد
        self::ECC.'/regions/details',  // نسخهٔ قدیمی‌ترِ محتمل
    ];

    public function slug(): string
    {
        return 'arvan';
    }

    private function token(): ?string
    {
        return Setting::getSecret('arvan_api_token');
    }

    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    public function capabilities(): array
    {
        return [
            'console' => false,          // کنسولِ تحتِ وب در API عمومی نیست
            'rebuild' => true,
            'resize'  => true,           // change-flavor
            'snapshot' => true,
            'metrics' => false,
            'reset_password' => true,
            'ipv6' => false,
            'rescue' => false,
            'ssh_key' => true,
            'extra_ip' => false,         // floating IP جدا API دارد؛ فعلاً نه
        ];
    }

    public function uploadSshKey(string $name, string $publicKey): array
    {
        // آروان کلیدِ SSH را per-region نگه می‌دارد و ساختش نیاز به region دارد،
        // پس نمی‌شود این‌جا (بی‌منطقه) بارگذاری کرد. تحویل با رمزِ root انجام
        // می‌شود؛ اگر روزی لازم شد، در createServer per-region ساخته می‌شود.
        return ['ok' => false, 'message' => 'بارگذاریِ کلیدِ SSH برای این سرور در دسترس نیست.', 'ref' => null];
    }

    public function addExtraIps(string $ref, int $count): array
    {
        return ['ok' => false, 'message' => 'IP اضافه برای این سرور در دسترس نیست.', 'ips' => []];
    }

    // ───────────────────────── لایهٔ تماس ─────────────────────────

    /**
     * ⚠️ ساختارِ پاسخ: آروان همه‌چیز را در `{"data": …}` می‌پیچد و **کدِ HTTP
     * را درست می‌دهد** (برخلافِ زحل/آیزا). ولی پیامِ خطا هم در بدنه است، پس
     * هم کد را می‌بینیم هم `message`.
     *
     * @return array{ok:bool,status:int,data:mixed,message:string}
     */
    private function req(string $method, string $path, array $payload = [], array $query = []): array
    {
        $token = $this->token();

        if (blank($token)) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'توکنِ زیرساختِ ایرانی تنظیم نشده است.'];
        }

        try {
            // ⚠️ کلیدِ آروان **خودش** شاملِ پیشوندِ «Apikey » است (کاربر همان را
            // از پنلِ آروان کپی می‌کند)، پس عیناً در هدر می‌نشیند — نه Bearer،
            // نه پیشوندِ دستی. این را از api.go رسمی گرفتیم: `Set("Authorization", ApiKey)`.
            $http = Http::withHeaders(['Authorization' => $token])
                ->acceptJson()
                ->timeout(30)
                ->connectTimeout(10);

            $url = self::BASE.$path;

            $res = match (strtoupper($method)) {
                'GET'    => $http->get($url, $query),
                'POST'   => $http->post($url, $payload),
                'PATCH'  => $http->patch($url, $payload),
                'DELETE' => $http->delete($url, $payload),
                default  => throw new \InvalidArgumentException($method),
            };
        } catch (\Throwable $e) {
            Log::warning('arvan.transport', ['path' => $path, 'err' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'ارتباط با زیرساخت برقرار نشد.'];
        }

        $body = (array) ($res->json() ?? []);
        $data = $body['data'] ?? $body;

        if ($res->successful()) {
            return ['ok' => true, 'status' => $res->status(), 'data' => $data, 'message' => (string) ($body['message'] ?? '')];
        }

        /*
        | ═══ 🔴 پیامِ خطا باید **قابلِ اقدام** باشد، نه «Bad Request» ═══
        |
        | آروان خطاهای اعتبارسنجی را در `errors` می‌گذارد — گاهی نگاشتِ
        | فیلد→پیام، گاهی فهرست. شکلِ قبلی فقط `message` و `errors.0` را
        | می‌دید و روی پاسخِ نگاشتی به عبارتِ خشکِ وضعیت سقوط می‌کرد. مدیر
        | «Bad Request» می‌دید و هیچ نمی‌فهمید چه را درست کند — سرِ سرویسِ #۹۳
        | یک دورِ کاملِ عیب‌یابی خرج برداشت تا شکلِ درستِ `security_groups`
        | پیدا شود.
        */
        $msg = (string) ($body['message'] ?? '');
        $errors = $body['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            $parts = [];

            foreach ($errors as $field => $err) {
                $text = is_array($err) ? implode('، ', array_map('strval', $err)) : (string) $err;
                $parts[] = is_string($field) ? $field.': '.$text : $text;
            }

            $detail = implode(' · ', $parts);
            $msg = $msg === '' ? $detail : $msg.' — '.$detail;
        }

        if (trim($msg) === '') {
            $msg = 'خطای نامشخص (HTTP '.$res->status().')';
        }

        return ['ok' => false, 'status' => $res->status(), 'data' => $data, 'message' => $msg,
            // بدنهٔ خام برای وقتی حتی این هم کافی نیست — لایهٔ بالاتر آن را
            // در provision_error می‌نشاند.
            'raw' => mb_substr((string) json_encode($body, JSON_UNESCAPED_UNICODE), 0, 400)];
    }

    public function testConnection(): array
    {
        $r = $this->resolveRegions();

        if (! $r['ok']) {
            // کنترل: یک مسیرِ منطقه‌محورِ شناخته‌شده. اگر این هم ۴۰۴/۴۰۱ بدهد،
            // عیب از توکن/دسترسی است نه از مسیرِ فهرستِ مناطق.
            $ctrl = $this->req('GET', self::ECC.'/regions/ir-thr-c2/sizes');
            $diag = implode(' | ', $r['tried'])
                .' | control(sizes) → '.($ctrl['status'] ?: 'ERR').' '.mb_substr((string) $ctrl['message'], 0, 40);

            return ['ok' => false, 'message' => 'اتصال برقرار نشد. جزئیاتِ تلاش‌ها: '.$diag];
        }

        $regions = $this->creatableRegions($r['data']);
        $n = count($regions);

        // نمونهٔ خامِ سایزهای منطقهٔ اول — تا اگر پلنی روی سایت نیامد، همین‌جا
        // ببینیم آروان واقعاً چه می‌دهد (تعداد، ماهانه/ساعتی، ارزان‌ترین‌ها).
        $sample = $n > 0 ? $this->flavorSample((string) ($regions[0]['code'] ?? '')) : '';

        return [
            'ok' => true,
            'message' => "اتصال برقرار است — {$n} منطقهٔ قابلِ ساخت.{$sample}",
            'meta' => ['regions' => $n, 'endpoint' => $r['endpoint']],
        ];
    }

    /** خلاصهٔ تشخیصیِ سایزهای یک منطقه — برای فهمیدنِ چرا پلنی نمی‌آید. */
    private function flavorSample(string $regionCode): string
    {
        if ($regionCode === '') {
            return '';
        }

        $sizes = $this->regionSizes($regionCode);

        if ($sizes === []) {
            return " | منطقهٔ {$regionCode}: هیچ سایزی برنگشت.";
        }

        $withMonth = 0;
        $hourOnly = 0;
        $rows = [];

        foreach ($sizes as $s) {
            if (! is_array($s)) {
                continue;
            }
            $m = (float) ($s['price_per_month'] ?? 0);
            $h = (float) ($s['price_per_hour'] ?? 0);
            $m > 0 ? $withMonth++ : ($h > 0 ? $hourOnly++ : null);
            $rows[] = [
                'name' => (string) ($s['name'] ?? $s['id'] ?? '?'),
                'm' => $m, 'h' => $h,
                'cpu' => (int) ($s['cpu_count'] ?? 0),
                'ram' => (int) ($s['memory'] ?? 0),
                'eff' => $m > 0 ? $m : $h * 720,
            ];
        }

        usort($rows, fn ($a, $b) => $a['eff'] <=> $b['eff']);
        $cheap = array_map(
            fn ($x) => "{$x['name']}(cpu{$x['cpu']}،ram{$x['ram']}،ماه={$x['m']}،ساعت={$x['h']})",
            array_slice($rows, 0, 3)
        );

        return " | منطقهٔ {$regionCode}: ".count($sizes)." سایز ({$withMonth} ماهانه، {$hourOnly} فقط‌ساعتی). ارزان‌ترین: ".implode(' ، ', $cheap);
    }

    /**
     * فهرستِ مناطق را از اولین مسیرِ کاندیدی که پاسخِ موفقِ غیرخالی بدهد می‌گیرد.
     *
     * @return array{ok:bool,data:array,endpoint:?string,tried:array<int,string>}
     */
    private function resolveRegions(): array
    {
        $tried = [];

        foreach (self::REGION_ENDPOINTS as $path) {
            $r = $this->req('GET', $path);

            if ($r['ok'] && is_array($r['data']) && $r['data'] !== []) {
                return ['ok' => true, 'data' => $r['data'], 'endpoint' => $path, 'tried' => $tried];
            }

            $tried[] = ltrim($path, '/').' → '.($r['status'] ?: 'ERR').' '.mb_substr((string) $r['message'], 0, 40);
        }

        return ['ok' => false, 'data' => [], 'endpoint' => null, 'tried' => $tried];
    }

    // ───────────────────────── کاتالوگ ─────────────────────────

    public function fetchCatalog(): array
    {
        $empty = ['locations' => [], 'plans' => [], 'images' => []];

        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'توکنِ ابرآروان تنظیم نشده.'] + $empty;
        }

        $reg = $this->resolveRegions();

        if (! $reg['ok']) {
            return ['ok' => false, 'message' => 'فهرستِ مناطقِ آروان دریافت نشد.'] + $empty;
        }

        $regions = $this->creatableRegions($reg['data']);

        if ($regions === []) {
            return ['ok' => false, 'message' => 'هیچ منطقهٔ قابلِ ساختی برنگشت.'] + $empty;
        }

        // نرخِ یورو برای تبدیلِ معکوس (تومان → سنتِ یورو). بی‌آن، نمی‌شود قیمت را
        // مثلِ بقیهٔ زیرساخت‌ها در یک واحد نگه داشت و حاشیهٔ سود گذاشت.
        $eurToman = $this->eurToman();

        if ($eurToman <= 0) {
            return ['ok' => false, 'message' => 'نرخِ یورو در دسترس نبود؛ قیمت‌گذاری انجام نشد.'] + $empty;
        }

        $locations = [];
        $plans = [];
        $images = [];
        $seenImageKey = [];

        foreach ($regions as $region) {
            $code = (string) ($region['code'] ?? '');

            if ($code === '') {
                continue;
            }

            // آروان زیرساختِ **ایران** است و در پاسخ نامِ کاملِ کشور («IRAN») را
            // می‌دهد؛ ولی ستونِ cloud_locations.country دو-کاراکتریِ ISO-3166 است
            // (مثلِ DE). پس همیشه «IR» — وگرنه «Data too long for column country».
            $raw = strtoupper(trim((string) ($region['country'] ?? '')));
            $country = strlen($raw) === 2 ? $raw : 'IR';
            $city = $this->cityOf($region);

            $locCode = CloudNaming::locationCode($country, $city, $code);

            $locations[$locCode] = [
                'code'              => $locCode,
                'country'           => $country,
                'city'              => $city ?: null,
                'provider_location' => $code,     // کدِ منطقهٔ آروان برای ساخت
                'latitude'          => null,
                'longitude'         => null,
            ];

            // پلن‌ها (sizes) این منطقه
            foreach ($this->regionSizes($code) as $size) {
                $plan = $this->mapSize($size, $code, $locCode, $eurToman);

                if ($plan !== null) {
                    $plans[] = $plan;
                }
            }

            // ایمیج‌ها یک‌بار کافی‌اند (بین مناطق تکرارند)، ولی چون شناسه
            // per-region است، همه را با منطقه‌شان نگه می‌داریم.
            foreach ($this->regionImages($code) as $img) {
                $key = $img['key'];

                // برای فهرستِ مشتری فقط یک‌بار (unique key)؛ ولی refِ per-region
                // را نگه می‌داریم تا تحویل روی همان منطقه درست باشد.
                $dedupe = $key.'@'.$code;

                if (isset($seenImageKey[$dedupe])) {
                    continue;
                }

                $seenImageKey[$dedupe] = true;
                $images[] = $img;
            }
        }

        return [
            'ok' => true, 'message' => '',
            'locations' => array_values($locations),
            'plans'     => $plans,
            'images'    => $images,
        ];
    }

    /** فقط مناطقی که واقعاً می‌شود در آنها سرور ساخت */
    private function creatableRegions(mixed $data): array
    {
        $out = [];

        foreach ((array) $data as $region) {
            if (! is_array($region)) {
                continue;
            }

            // فقط ایران. دیتاسنترِ آلمانِ آروان («گوته/Goethe») هرگز سینک نشود —
            // آلمان را مستقیم از هتزنر می‌فروشیم، جدا از این زیرساخت.
            $cc = strtoupper(trim((string) ($region['country'] ?? '')));
            $isIran = $cc === '' || $cc === 'IR' || str_starts_with($cc, 'IRAN');
            $name = strtolower((string) ($region['dc'] ?? '').' '.(string) ($region['code'] ?? '').' '.(string) ($region['city_code'] ?? ''));

            if (! $isIran || str_contains($name, 'goethe') || str_contains($name, 'german')) {
                continue;
            }

            // `create` یعنی ساختِ سرور مجاز است؛ `soon`/`visible=false` را رد کن
            if (($region['create'] ?? false) === true && ($region['visible'] ?? true) !== false) {
                $out[] = $region;
            }
        }

        return $out;
    }

    /**
     * نگاشتِ کدنامِ دیتاسنترهای آروان → نامِ شهرِ واقعی.
     * (سیمین=تهران، فروغ=اصفهان، شهریار=ارومیه، بامداد=شیراز، قیصر=اهواز)
     */
    private const CITY_MAP = [
        'simin'    => 'Tehran',
        'forough'  => 'Isfahan',
        'foroogh'  => 'Isfahan',
        'shahriar' => 'Urmia',
        'bamdad'   => 'Shiraz',
        'gheysar'  => 'Ahvaz',
        'ghaisar'  => 'Ahvaz',
        'qeysar'   => 'Ahvaz',
        'gheisar'  => 'Ahvaz',
    ];

    private function cityOf(array $region): string
    {
        // اول کدنام را به شهر نگاشت کن (در هر یک از فیلدهای نام ممکن است باشد)
        foreach (['dc', 'city_code', 'code', 'region'] as $k) {
            $v = strtolower(trim((string) ($region[$k] ?? '')));

            if ($v === '') {
                continue;
            }

            foreach (self::CITY_MAP as $codename => $city) {
                if (str_contains($v, $codename)) {
                    return $city;
                }
            }
        }

        // نگاشت نبود: همان رفتارِ قبلی (نامِ خام)
        foreach (['dc', 'city_code', 'region'] as $k) {
            $v = trim((string) ($region[$k] ?? ''));

            if ($v !== '') {
                return $v;
            }
        }

        return (string) ($region['code'] ?? '');
    }

    private function regionSizes(string $regionCode): array
    {
        $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($regionCode).'/sizes');

        return $r['ok'] ? (array) $r['data'] : [];
    }

    /**
     * یک size آروان → ساختارِ پلنِ ما.
     *
     * قیمت: `price_per_month` تومان است. حاشیهٔ سود را CloudPricing می‌گذارد،
     * پس این‌جا فقط بهایِ تمام‌شده را به سنتِ یورویِ معادل تبدیل می‌کنیم تا با
     * بقیهٔ زیرساخت‌ها یکدست بماند.
     */
    private function mapSize(array $size, string $regionCode, string $locCode, float $eurToman): ?array
    {
        $ref = (string) ($size['id'] ?? '');
        $vcpu = (int) ($size['cpu_count'] ?? 0);

        // ⚠️ آروان memory را به **گیگابایت** می‌دهد (نامِ فلِیور مثلِ g1-1-1 یعنی
        // ۱ هسته، ۱ گیگ)، نه مگابایت. قبلاً مگابایت فرض می‌شد، پس فیلترِ ram<۱۲۸
        // هر پلنِ زیرِ ۱۲۸ گیگ — یعنی تقریباً همهٔ ارزان‌ها — را حذف می‌کرد و فقط
        // غول‌ها می‌ماندند. از memory_in_bytes (بی‌ابهام) می‌خوانیم، وگرنه گیگ×۱۰۲۴.
        $ramBytes = (int) ($size['memory_in_bytes'] ?? 0);
        $ram = $ramBytes > 0
            ? (int) round($ramBytes / 1048576)
            : (int) ($size['memory'] ?? 0) * 1024;   // مگابایت

        $diskBytes = (int) ($size['disk_in_bytes'] ?? 0);
        $disk = $diskBytes > 0
            ? (int) round($diskBytes / 1073741824)
            : (int) ($size['disk'] ?? 0);            // گیگابایت

        // ⚠️ قیمت به **ریال** است (ما به تومان می‌فروشیم، ÷۱۰). تیرِ اقتصادیِ
        // «ابرک» اغلب فقط **ساعتی** قیمت دارد و `price_per_month` صفر است — برای
        // همین قبلاً هیچ‌کدام نمی‌آمدند. اگر ماهانه صفر بود، از ساعتی بساز
        // (۳۰ روز × ۲۴ = ۷۲۰ ساعت). price_per_hour در API رشته است.
        $rialMonth = (float) ($size['price_per_month'] ?? 0);
        $rialHour = (float) ($size['price_per_hour'] ?? 0);

        if ($rialMonth <= 0 && $rialHour > 0) {
            $rialMonth = $rialHour * 720;
        }

        $priceToman = $rialMonth / 10;

        // مشخصاتِ ناقص = رد (کفِ ۵۱۲ مگابایت رم؛ حالا $ram درست به مگابایت است)
        if ($ref === '' || $vcpu < 1 || $ram < 512 || $disk < 1 || $priceToman <= 0) {
            return null;
        }

        // ⚠️ تیرِ اقتصادیِ «ابرک» (ارزان‌ترین سرورهای آروان) با off_percent می‌آید.
        // قبلاً پیش‌فرض این‌ها را کنار می‌گذاشت، پس فقط تیرهای گرانِ dedicated
        // می‌ماند (کفِ ~۳۶ م.ت). کارفرما همه را می‌خواهد بفروشد، پس پیش‌فرض
        // «همه بیایند» است؛ برای کنارگذاشتنِ تخفیف‌دارها arvan_exclude_promo='1'.
        if (Setting::get('arvan_exclude_promo') === '1'
            && (($size['off'] ?? '') !== '' || ((float) ($size['off_percent'] ?? 0)) > 0)) {
            return null;
        }

        // بهایِ تمام‌شده به سنتِ یورو: تومان ÷ (تومانِ هر یورو) × ۱۰۰
        $costEurCents = (int) round(($priceToman / $eurToman) * 100);

        if ($costEurCents <= 0) {
            return null;
        }

        // `cpu_share` نوعِ پردازنده را می‌گوید (dedicated / shared / general)
        $share = strtolower((string) ($size['cpu_share'] ?? ''));
        $cpuKind = str_contains($share, 'dedicat') ? 'dedicated' : 'shared';

        return [
            'provider_ref'      => $ref,
            'provider_location' => $regionCode,
            'location_code'     => $locCode,
            'name'              => (string) ($size['name'] ?? $ref),
            'vcpu'              => $vcpu,
            'ram_mb'            => $ram,
            'disk_gb'           => $disk,
            'disk_type'         => 'ssd',
            'traffic_gb'        => 0,                // آروان مصرفِ منصفانه دارد
            'cpu_kind'          => $cpuKind,
            'arch'              => 'x86',
            'cost_eur_cents'    => $costEurCents,
            'in_stock'          => true,
        ];
    }

    private function regionImages(string $regionCode): array
    {
        $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($regionCode).'/images', [], ['type' => 'distributions']);

        if (! $r['ok']) {
            return [];
        }

        $out = [];

        // ساختار: [{name: "Ubuntu", images: [{id, name, ...}]}] یا فهرستِ تخت
        foreach ((array) $r['data'] as $group) {
            $children = is_array($group['images'] ?? null) ? $group['images'] : [$group];
            $family = strtolower((string) ($group['name'] ?? ''));

            foreach ($children as $img) {
                if (! is_array($img)) {
                    continue;
                }

                $ref = (string) ($img['id'] ?? '');
                $label = (string) ($img['name'] ?? $img['distribution_name'] ?? $ref);

                if ($ref === '' || $label === '') {
                    continue;
                }

                [$fam, $ver] = $this->splitOs($label, $family);

                $out[] = [
                    'provider_ref' => $ref,
                    'key'          => CloudNaming::imageKey('os', $fam, $ver, $label),
                    'kind'         => 'os',
                    'family'       => $fam,
                    'version'      => $ver,
                    'label'        => $label,
                    'arch'         => 'x86',
                    'min_disk_gb'  => (int) ($img['disk'] ?? $img['min_disk'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /** «Ubuntu 22.04» → ['ubuntu', '22.04'] */
    private function splitOs(string $label, string $familyHint): array
    {
        if (preg_match('/^\s*([A-Za-z][A-Za-z\s]*?)\s*v?([0-9]+(?:\.[0-9]+)*)/', $label, $m)) {
            return [CloudNaming::slug($m[1]), $m[2]];
        }

        return [$familyHint !== '' ? CloudNaming::slug($familyHint) : CloudNaming::slug($label), ''];
    }

    /**
     * نرخِ یک یورو به تومان — همان منبعِ CloudPricing، تا هر دو یکی ببینند.
     */
    private function eurToman(): int
    {
        $override = (int) Setting::get('pricing_rate_override', '0');

        if ($override > 0) {
            return $override;
        }

        try {
            return (int) (app(\App\Services\ExchangeRate::class)->toToman('EUR') ?: 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    // ───────────────────────── ساخت و مدیریت ─────────────────────────

    /**
     * شبکهٔ عمومیِ پیش‌فرضِ یک منطقه — برای ساختِ سرور لازم است.
     *
     * آروان بی‌network_ids سرور نمی‌سازد. اولین شبکهٔ عمومی را می‌گیریم و
     * per-region کش می‌کنیم (شبکه‌ها به‌ندرت عوض می‌شوند).
     */
    private function publicNetworkId(string $regionCode): ?string
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'arvan.net.'.$regionCode,
            3600,
            function () use ($regionCode) {
                $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($regionCode).'/networks');

                if (! $r['ok']) {
                    return null;
                }

                // شبکهٔ عمومی: اولی که gateway دارد یا نامش public است
                foreach ((array) $r['data'] as $net) {
                    $id = (string) ($net['network_id'] ?? $net['id'] ?? '');
                    $name = strtolower((string) ($net['name'] ?? ''));

                    if ($id !== '' && (str_contains($name, 'public') || ($net['enable_gateway'] ?? false))) {
                        return $id;
                    }
                }

                // وگرنه اولین شبکه
                $first = (array) (($r['data'][0] ?? []));

                return (string) ($first['network_id'] ?? $first['id'] ?? '') ?: null;
            }
        );
    }

    /**
     * ═══ 🔴 گروهِ امنیتیِ اجباری — علتِ «تحویل نشد»های چند روزِ اخیر ═══
     *
     * آروان روی ساختِ سرور دستِ‌کم **یک** گروهِ امنیتی می‌خواهد و بی‌آن
     * می‌گوید: «At least one firewall should be selected». پیلودِ ما هیچ‌وقت
     * این فیلد را نمی‌فرستاد، پس هر سفارشِ آروان با همان یک جمله شکست
     * می‌خورد — سرویسِ ۹۳ (۹ شهریور ۱۴۰۵) و چند تای پیش از آن.
     *
     * ⚠️ چرا قرنطینهٔ خودکار جلویش را نگرفت: فهرستِ خطاهای «ساختاری» در
     * CloudProvisioner دنبالِ permission/quota/balance می‌گردد و این پیام
     * هیچ‌کدام نیست. پس پلن‌ها در فروش ماندند و مشتریِ بعدی همان شکست را
     * خرید. (فهرستِ «پلن‌های پرخطا» در مرکزِ تحویل‌ها دقیقاً برای همین حالت
     * است.)
     *
     * ⚠️ شناسه **کشف** می‌شود، نه سخت‌نویس: نامِ گروهِ پیش‌فرض روی هر حساب
     * فرق می‌کند و یک رشتهٔ ثابت روزی بی‌صدا نامعتبر می‌شود. اولویت با گروهی
     * است که آروان `default` علامت زده؛ وگرنه اولین گروهِ موجود.
     *
     * @return array<int,string>
     */
    private function securityGroupIds(string $regionCode): array
    {
        /*
        | ═══ 🔴 راهِ فرارِ مدیر — و چرا کشف به‌تنهایی کافی نیست ═══
        |
        | کشفِ خودکار روی دو مسیرِ **حدسی** تکیه دارد. اگر آروان هیچ‌کدام را
        | نشناسد (همان تلهٔ `resolveRegions` که یک بار همین درایور را سوزاند:
        | مسیرِ حدسی ۴۰۴ می‌داد و «زیرساخت خالی است» تعبیر می‌شد)، تحویل برای
        | همیشه بسته می‌مانَد و مدیر **هیچ کاری از دستش برنمی‌آید** — پیام
        | می‌گوید «در پنل یک firewall بساز»، او می‌سازد، و باز هم کار نمی‌کند.
        |
        | پس یک درِ دستی: شناسه یا نامِ گروه در تنظیمات. اگر پر باشد، هم بر
        | انتخابِ خودکار مقدم است، و هم وقتی فهرست اصلاً خوانده نشود مستقیماً
        | فرستاده می‌شود.
        |
        | ⚠️ انتخابِ مدیر داخلِ کلیدِ کش است. بی‌آن، کسی که پس از یک شکست
        | مقدار را تنظیم می‌کند تا یک ساعت همان شکست را می‌بیند و نتیجه
        | می‌گیرد تنظیمات کار نمی‌کند.
        */
        $wanted = trim((string) \App\Models\Setting::get('arvan_security_group', ''));

        return \Illuminate\Support\Facades\Cache::remember(
            'arvan.sg.'.$regionCode.'.'.md5($wanted),
            3600,
            function () use ($regionCode, $wanted) {
                // ⚠️ دو نامزدِ دیگر: نامِ مسیر قطعی نیست و هزینهٔ امتحانشان یک
                // درخواستِ ۴۰۴ است، در برابرِ تحویلی که اصلاً انجام نمی‌شود.
                foreach (['/securities', '/security-groups', '/securitygroups', '/firewalls'] as $path) {
                    $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($regionCode).$path);

                    if (! $r['ok']) {
                        continue;
                    }

                    $rows = array_values(array_filter((array) $r['data'], 'is_array'));

                    if ($rows === []) {
                        continue;
                    }

                    // انتخابِ صریحِ مدیر بر پیش‌فرض مقدم است — با شناسه یا نام
                    /*
                    | 🔴 **نام** برمی‌گردد، نه شناسه.
                    |
                    | آروان پیلود را این‌طور رد کرد:
                    |   expected=abrak.securityGroupName, got=string
                    | یعنی عنصرِ `security_groups` باید ساختاری با کلیدِ `name`
                    | باشد. با شناسه — چه رشته چه آبجکت — همان ۴۰۰ برمی‌گردد.
                    | (این را فقط پس از غنی‌سازیِ پیامِ خطا فهمیدیم؛ «Bad
                    | Request»ِ خالی هیچ نمی‌گفت.)
                    */
                    /*
                    | 🔴🔴 `real_name` است که آروان می‌شناسد، نه `name`.
                    |
                    | پاسخِ واقعیِ آروان برای گروهِ پیش‌فرض:
                    |     {"name": "default", "real_name": "arDefault", ...}
                    |
                    | `name` برچسبِ نمایشیِ پنل است؛ چیزی که موقعِ ساختِ سرور
                    | باید فرستاده شود `real_name` است. با `name` آروان گروه را
                    | پیدا نمی‌کند و پیامِ عمومیِ **«Instance not found»** می‌دهد
                    | — که هیچ نمی‌گوید کدام منبع پیدا نشده و یک دورِ کاملِ
                    | عیب‌یابی خرج برد. این را فقط با دیدنِ بدنهٔ خامِ پاسخ
                    | می‌شد فهمید.
                    */
                    $pick = static fn (array $g): string => (string) ($g['real_name'] ?? $g['name'] ?? '');

                    // انتخابِ صریحِ مدیر: با شناسه، نامِ نمایشی، یا نامِ واقعی
                    if ($wanted !== '') {
                        foreach ($rows as $g) {
                            $real = $pick($g);

                            if ($real !== '' && ((string) ($g['id'] ?? '') === $wanted
                                || strcasecmp((string) ($g['name'] ?? ''), $wanted) === 0
                                || strcasecmp($real, $wanted) === 0)) {
                                return [$real];
                            }
                        }
                    }

                    foreach ($rows as $g) {
                        $real = $pick($g);

                        if ($real !== '' && (($g['default'] ?? false)
                            || str_contains(strtolower((string) ($g['name'] ?? '')), 'default'))) {
                            return [$real];
                        }
                    }

                    $first = $pick((array) ($rows[0] ?? []));

                    if ($first !== '') {
                        return [$first];
                    }
                }

                // هیچ مسیری جواب نداد. اگر مدیر شناسه را دستی گذاشته، همان
                // فرستاده می‌شود: غلط بودنش خطای روشنِ خودِ آروان را می‌آورد،
                // که از بن‌بستِ خاموش بهتر است.
                return $wanted !== '' ? [$wanted] : [];
            }
        );
    }

    /**
     * ═══ 🔴 ایمیجِ آروان **per-region** است — کاتالوگِ ما نیست ═══
     *
     * `cloud_images` ستونِ منطقه ندارد و `CloudImage::refFor()` هم منطقه
     * نمی‌گیرد: یک شناسه برای همهٔ مکان‌ها. برای هتزنر/آیزا درست است (ایمیجِ
     * سراسری)، ولی آروان برای هر منطقه شناسهٔ جداگانه می‌دهد.
     *
     * نتیجهٔ واقعی (سرویسِ #۹۳، شهریور ۱۴۰۵): سفارشِ منطقهٔ `ir-thr-si1` با
     * شناسهٔ ایمیجِ منطقهٔ دیگری فرستاده شد. آروان **پیامِ ایمیج نداد** —
     * «Requested firewall was not found» گفت و ساعت‌ها ما را دنبالِ فایروال
     * کشاند. درسِ ثابت‌شده: پیامِ خطای این API به منبعِ واقعیِ خطا اشاره
     * نمی‌کند، پس هر ورودی باید **پیش از ارسال** خودمان اعتبارسنجی شود.
     *
     * ⚠️ چرا این‌جا و نه در کاتالوگ: افزودنِ ستونِ منطقه یک مهاجرت است و
     * کاتالوگِ موجود را هم باید دوباره ساخت. این تطبیقِ لحظهٔ تحویل همان
     * نتیجه را بی‌مهاجرت می‌دهد و **خودترمیم** است: هر بار از خودِ منطقه
     * می‌پرسد، پس با تغییرِ شناسه‌ها هم کهنه نمی‌شود.
     *
     * @return string شناسهٔ معتبر در همین منطقه، یا همان ورودی اگر تطبیقی نبود
     */
    private function imageForRegion(string $regionCode, string $imageRef): string
    {
        $images = $this->regionImageIndex($regionCode);

        if ($images === []) {
            return $imageRef;                 // نمی‌دانیم؛ همان را بفرست
        }

        // شناسه در همین منطقه معتبر است؟ دست نزن.
        if (isset($images['byId'][$imageRef])) {
            return $imageRef;
        }

        /*
        | معادلِ همین ایمیج در این منطقه: با **برچسب** پیدا می‌شود، چون همان
        | «Ubuntu 24.04» در هر منطقه شناسهٔ دیگری دارد ولی برچسبش یکی است.
        */
        $label = (string) (\App\Models\CloudImage::query()
            ->where('provider', 'arvan')
            ->where('provider_ref', $imageRef)
            ->value('label') ?? '');

        if ($label !== '' && isset($images['byLabel'][mb_strtolower($label)])) {
            return $images['byLabel'][mb_strtolower($label)];
        }

        return $imageRef;
    }

    /**
     * فهرستِ ایمیج‌های یک منطقه، نمایه‌شده بر شناسه و برچسب.
     *
     * ⚠️ `type=distributions` اجباری است: بی‌آن، آروان فقط ایمیج‌های
     * **شخصیِ** آپلودشده را می‌دهد (اغلب صفر) — که یک بار ما را به این
     * نتیجهٔ غلط رساند که «این منطقه ایمیج ندارد».
     *
     * @return array{byId:array<string,bool>,byLabel:array<string,string>}
     */
    private function regionImageIndex(string $regionCode): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'arvan.imgidx.'.$regionCode,
            3600,
            function () use ($regionCode) {
                $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($regionCode).'/images',
                    [], ['type' => 'distributions']);

                if (! $r['ok']) {
                    return [];
                }

                $byId = [];
                $byLabel = [];

                foreach ((array) $r['data'] as $group) {
                    $children = is_array($group['images'] ?? null) ? $group['images'] : [$group];

                    foreach ($children as $img) {
                        if (! is_array($img)) {
                            continue;
                        }

                        $id = (string) ($img['id'] ?? '');

                        if ($id === '') {
                            continue;
                        }

                        $byId[$id] = true;
                        $label = mb_strtolower((string) ($img['name'] ?? ''));

                        if ($label !== '' && ! isset($byLabel[$label])) {
                            $byLabel[$label] = $id;
                        }
                    }
                }

                return $byId === [] ? [] : ['byId' => $byId, 'byLabel' => $byLabel];
            }
        );
    }

    public function createServer(array $spec): array
    {
        $fail = ['ref' => null, 'ipv4' => null, 'ipv6' => null, 'root_password' => null, 'status' => 'error'];

        $region = (string) $spec['location_ref'];
        $networkId = $this->publicNetworkId($region);

        if ($networkId === null) {
            return ['ok' => false, 'message' => 'شبکهٔ عمومیِ این منطقه پیدا نشد.'] + $fail;
        }

        // idempotency: نامِ قطعی. اگر از قبل ساخته شده، همان را برگردان.
        $existing = $this->findByName($region, $spec['name']);

        if ($existing !== null) {
            return $this->serverToResult($existing, $region);
        }

        /*
        | 🔴 بی‌گروهِ امنیتی، آروان سفارش را رد می‌کند. اگر کشف نشد، **همین‌جا**
        | با پیامِ روشن می‌ایستیم — نه اینکه درخواستِ ناقص بفرستیم و پیامِ گنگِ
        | زنجیره را به مدیر نشان دهیم.
        */
        $securityGroups = $this->securityGroupIds($region);

        if ($securityGroups === []) {
            return ['ok' => false,
                'message' => 'گروهِ امنیتیِ آروان پیدا نشد؛ در پنلِ آروان دستِ‌کم یک firewall بسازید. '
                    .'اگر ساخته‌اید و باز هم این پیام آمد، شناسه‌اش را در '
                    .'«تنظیمات ← زیرساخت ← گروهِ فایروال» بگذارید — یعنی فهرستِ گروه‌ها '
                    .'از این حساب خوانده نمی‌شود.'] + $fail;
        }

        $r = $this->req('POST', self::ECC.'/regions/'.rawurlencode($region).'/servers', [
            'name'        => $spec['name'],
            'flavor_id'   => (string) $spec['plan_ref'],
            // 🔴 ایمیج per-region است — شناسهٔ منطقهٔ دیگر «firewall not found» می‌دهد
            'image_id'    => $this->imageForRegion($region, (string) $spec['image_ref']),
            'network_ids' => [$networkId],
            /*
            | ⚠️ آرایه‌ای از **آبجکتِ نام** — نه رشته. شکلِ رشته‌ای را آروان با
            | «Unmarshal type error … abrak.securityGroupName» رد می‌کند.
            */
            'security_groups' => array_map(fn ($n) => ['name' => $n], $securityGroups),
            'disk_size'   => (int) ($spec['disk_gb'] ?? 25),
            'count'       => 1,
            'ha_enabled'  => false,
        ]);

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'],
                'raw' => ['detail' => (string) ($r['raw'] ?? '')]] + $fail;
        }

        // پاسخ ممکن است تکِ سرور یا آرایه (count) باشد
        $server = is_array($r['data'][0] ?? null) ? $r['data'][0] : (array) $r['data'];

        $out = $this->serverToResult($server, $region);
        // رمزِ root فقط لحظهٔ ساخت برمی‌گردد
        $out['root_password'] = $server['password'] ?? null;

        return $out;
    }

    private function findByName(string $region, string $name): ?array
    {
        $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($region).'/servers');

        if (! $r['ok']) {
            return null;
        }

        foreach ((array) $r['data'] as $s) {
            if (is_array($s) && (string) ($s['name'] ?? '') === $name) {
                return $s;
            }
        }

        return null;
    }

    /** @return array{ok:bool,message:string,ref:?string,ipv4:?string,ipv6:?string,root_password:?string,status:string} */
    private function serverToResult(array $server, string $region): array
    {
        $id = (string) ($server['id'] ?? '');

        return [
            'ok' => true, 'message' => '',
            // ⚠️ ref را `region:id` می‌کنیم چون آروان **region-محور** است و هر
            // عملیاتِ بعدی (روشن/خاموش/حذف) به کدِ منطقه نیاز دارد، ولی قرارداد
            // فقط یک `$ref` می‌دهد. `split()` بعداً بازش می‌کند.
            'ref'  => $id !== '' ? $region.':'.$id : null,
            'ipv4' => $this->firstIp($server),
            'ipv6' => null,
            'root_password' => null,
            'status' => $this->mapStatus((string) ($server['status'] ?? '')),
            'raw' => ['region' => $region],
        ];
    }

    private function firstIp(array $server): ?string
    {
        foreach (['addresses', 'ips', 'ip', 'public_ip'] as $path) {
            $v = data_get($server, $path);

            foreach ((is_array($v) ? \Illuminate\Support\Arr::flatten($v) : [$v]) as $ip) {
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * فهرستِ سرورهای همهٔ مناطق.
     *
     * ⚠️ آروان **منطقه‌محور** است و مسیرِ «همهٔ سرورها» ندارد؛ باید منطقه‌به‌منطقه
     * پرسید. پس اگر خواندنِ فهرستِ مناطق شکست بخورد، جوابِ درست «نمی‌دانم» است نه
     * «صفر سرور» — وگرنه گزارشِ سرورِ یتیم می‌گفت همه‌چیز مرتب است، درحالی‌که
     * حتی یک منطقه هم پرسیده نشده.
     *
     * خطای یک منطقهٔ منفرد ولی کلِ گزارش را نمی‌شکند: بقیهٔ مناطق شمرده می‌شوند
     * و منطقهٔ خطادار در پیام می‌آید تا معلوم باشد فهرست ناقص است.
     */
    public function listServers(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'توکنِ ابرآروان تنظیم نشده.', 'servers' => []];
        }

        $reg = $this->resolveRegions();

        if (! $reg['ok']) {
            return ['ok' => false, 'message' => 'فهرستِ مناطقِ آروان دریافت نشد.', 'servers' => []];
        }

        $servers = [];
        $failed = [];

        // ⚠️ عمداً `creatableRegions()` نیست. آن فیلتر برای **ساختِ** سرور است و
        // منطقهٔ غیرقابلِ‌ساخت و دیتاسنترِ آلمان را کنار می‌گذارد. برای **شمردنِ**
        // سرورهای موجود، همان کنار گذاشتن یعنی سروری که هنوز پولش را می‌دهیم از
        // گزارش غیب می‌شود — و گزارشِ ناقصِ یتیم از نبودِ گزارش بدتر است.
        foreach ((array) $reg['data'] as $region) {
            $code = is_array($region) ? (string) ($region['code'] ?? '') : '';

            if ($code === '') {
                continue;
            }

            $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($code).'/servers');

            if (! $r['ok']) {
                $failed[] = $code;

                continue;
            }

            foreach ((array) $r['data'] as $s) {
                if (! is_array($s)) {
                    continue;
                }

                $id = (string) ($s['id'] ?? '');

                if ($id === '') {
                    continue;
                }

                $servers[] = [
                    // همان رمزگذاریِ `region:id` که بقیهٔ متدها انتظار دارند —
                    // وگرنه شناسه‌ای تحویل می‌دادیم که هیچ عملیاتی رویش کار نمی‌کند.
                    'ref'      => $code.':'.$id,
                    'name'     => (string) ($s['name'] ?? $id),
                    'status'   => $this->mapStatus((string) ($s['status'] ?? '')),
                    'ipv4'     => $this->firstIp($s),
                    'ipv6'     => null,
                    'plan'     => data_get($s, 'flavor.name') ?? data_get($s, 'flavor_id'),
                    'location' => (string) ($region['name'] ?? $code),
                    'created'  => $s['created_at'] ?? $s['created'] ?? null,
                ];
            }
        }

        return [
            'ok'      => true,
            'message' => $failed === [] ? '' : 'فهرستِ این مناطق خوانده نشد: '.implode('، ', $failed),
            'servers' => $servers,
        ];
    }

    private function mapStatus(string $s): string
    {
        return match (strtolower($s)) {
            'active', 'running'                       => 'running',
            'shutoff', 'stopped', 'paused', 'suspended' => 'off',
            'build', 'building', 'rebuild', 'creating', 'installing' => 'building',
            'deleted', 'deleting'                     => 'deleted',
            default                                   => 'unknown',
        };
    }

    /**
     * ⚠️ آروان region-محور است: هر عملیات به کدِ منطقه نیاز دارد، ولی ما فقط
     * شناسهٔ سرور را داریم. منطقه را در `provider_location`ِ نمونه نگه داشته‌ایم
     * و از آن‌جا می‌آید — ولی امضای قرارداد فقط `$ref` می‌دهد. پس شناسه را
     * `region:id` رمزگذاری می‌کنیم تا هر متد بتواند بازش کند.
     */
    private function split(string $ref): array
    {
        return str_contains($ref, ':') ? explode(':', $ref, 2) : ['', $ref];
    }

    public function serverStatus(string $ref): array
    {
        [$region, $id] = $this->split($ref);
        $none = ['ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];

        if ($region === '') {
            return ['ok' => false, 'message' => 'منطقهٔ سرور نامشخص است.', 'status' => 'unknown'] + $none;
        }

        $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id));

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'status' => 'unknown'] + $none;
        }

        $s = (array) $r['data'];

        return [
            'ok' => true, 'message' => '',
            'status'          => $this->mapStatus((string) ($s['status'] ?? '')),
            'ipv4'            => $this->firstIp($s),
            'ipv6'            => null,
            'traffic_used_gb' => null,
        ];
    }

    public function power(string $ref, string $action): array
    {
        [$region, $id] = $this->split($ref);

        $path = match ($action) {
            'on'       => 'power-on',
            'off', 'shutdown' => 'power-off',
            'reboot', 'reset' => 'reboot',
            default    => null,
        };

        if ($path === null || $region === '') {
            return ['ok' => false, 'message' => 'عملیاتِ نامعتبر.'];
        }

        $r = $this->req('POST', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id).'/'.$path);

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }

    public function rebuild(string $ref, string $imageRef, ?string $password = null): array
    {
        [$region, $id] = $this->split($ref);

        $r = $this->req('POST', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id).'/rebuild', [
            'image_id' => $imageRef,
        ]);

        return [
            'ok' => $r['ok'],
            'message' => $r['ok'] ? '' : $r['message'],
            'root_password' => data_get($r['data'], 'password'),
        ];
    }

    public function resetPassword(string $ref): array
    {
        [$region, $id] = $this->split($ref);

        $r = $this->req('POST', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id).'/reset-root-password');

        return [
            'ok' => $r['ok'],
            'message' => $r['ok'] ? '' : $r['message'],
            // آروان رمزِ تازه را در پاسخ یا با ایمیل می‌دهد؛ اگر در پاسخ بود، بده
            'root_password' => data_get($r['data'], 'password'),
        ];
    }

    public function console(string $ref): array
    {
        return ['ok' => false, 'message' => 'کنسولِ تحتِ وب برای این سرور در دسترس نیست.', 'url' => null, 'password' => null];
    }

    public function metrics(string $ref, string $window = '24h'): array
    {
        return ['ok' => false, 'message' => 'نمودارِ مصرف برای این سرور در دسترس نیست.', 'series' => []];
    }

    public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array
    {
        [$region, $id] = $this->split($ref);

        $r = $this->req('POST', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id).'/resize', [
            'flavor_id' => $planRef,
        ]);

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }

    public function deleteServer(string $ref): array
    {
        [$region, $id] = $this->split($ref);

        $r = $this->req('DELETE', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id));

        if (! $r['ok'] && $r['status'] === 404) {
            return ['ok' => true, 'message' => 'سرور از قبل حذف شده بود.'];
        }

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }
}
