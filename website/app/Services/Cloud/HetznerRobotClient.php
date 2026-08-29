<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * زیرساختِ ۷ — Hetzner Robot (سرورِ اختصاصی/برمتال + مزایده + GPU اختصاصی).
 *
 * ═══ چرا این درایور جدا از HetznerClient است ═══
 *
 * Hetzner دو دنیای کاملاً جدا دارد: «Cloud» (VPS، توکنِ Bearer، api.hetzner.cloud)
 * که HetznerClient پوشش می‌دهد، و «Robot» (سرورِ فیزیکی، Basic Auth با کاربرِ
 * جداگانهٔ webservice، robot-ws.your-server.de). توکنِ یکی روی دیگری کار
 * نمی‌کند و کاربرِ Robot را مدیر باید در پنلِ Robot (تنظیمات ← Webservice)
 * جداگانه بسازد.
 *
 * 🔴 **خریدِ خودکار عمداً پیاده نشده** — همان قاعدهٔ OVH: سفارشِ Robot یک
 * تراکنشِ برگشت‌ناپذیرِ پولی است (و مزایده تک‌موجودی است؛ ممکن است بینِ
 * سفارشِ مشتری و خریدِ ما فروخته شود). `createServer()` صریح `manual`
 * برمی‌گرداند تا سرویس به صفِ تحویلِ دستیِ مدیر برود.
 *
 * ═══ درجهٔ اطمینانِ نگاشت ═══
 *
 * مسیرها از داکیومنتِ رسمیِ Robot webservice اند، ولی مثل زیرساختِ ۲ نمونهٔ
 * کاملِ JSON در دست نبود؛ پس پارس **استنتاجی و دفاعی** است: پوشش‌دهنده
 * (`product`/`server_product`/…) هرچه باشد باز می‌شود، هر مقدار از چند کلیدِ
 * ممکن خوانده می‌شود، و ردیفِ ناقص **رد** می‌شود نه ذخیره با صفر.
 * `rawProbe()` برای دقیق‌کردنِ نگاشت با پاسخِ واقعی است (/admin/cloud/probe).
 *
 * ═══ سه قاعدهٔ پولیِ این کاتالوگ ═══
 *
 * ۱) 🔴 **APIِ Robot تعدادِ هسته نمی‌دهد** — فقط مدلِ CPU. جدولِ CPU_CORES
 *    مدل را به هسته نگاشت می‌کند؛ مدلِ ناشناخته ردیفش **رد و شمارش** می‌شود
 *    (در پیامِ سینک دیده می‌شود تا مدیر جدول را کامل کند). حدسِ هسته یعنی
 *    اسلاگ و گروه‌بندی و قاعدهٔ حذفِ مغلوب همه روی عددِ غلط بنشینند.
 * ۲) 🔴 **محصولِ دارای هزینهٔ نصب رد می‌شود.** مدلِ قیمتیِ cloud_plans جایی
 *    برای setup fee ندارد؛ فروختنِ سرورِ ۷۹یورو-نصب با قیمتِ بی‌نصب یعنی
 *    ضررِ قطعیِ ماهِ اول — خطِ قرمز. مزایده و GEX131 عمدتاً بی‌نصب‌اند.
 * ۳) کارمزدِ انتقالِ ارز با کلیدِ **hetzner** حساب می‌شود (همان حساب و همان
 *    مسیرِ پرداختِ Cloud است، پس همان سربار).
 */
class HetznerRobotClient implements CloudProvider
{
    private const BASE = 'https://robot-ws.your-server.de';

    /**
     * دیتاسنترِ Robot → کدِ مکانِ ما (قاعدهٔ «کشور-شهر»، ممیزی ۷).
     * `FSN1-DC5` هم با پیشوندش به `FSN1` می‌رسد.
     */
    public const DC_MAP = [
        'FSN1' => ['code' => 'de-falkenstein', 'country' => 'DE', 'city' => 'Falkenstein'],
        'NBG1' => ['code' => 'de-nuremberg',   'country' => 'DE', 'city' => 'Nuremberg'],
        'HEL1' => ['code' => 'fi-helsinki',    'country' => 'FI', 'city' => 'Helsinki'],
    ];

    /**
     * مدلِ CPU → هستهٔ فیزیکی. کلیدها زیررشتهٔ نرمال‌شده (حروفِ کوچک).
     *
     * ⚠️ ناقص‌بودنش «خرابی» نیست: مدلِ جاافتاده رد و در پیامِ سینک اعلام
     * می‌شود. کامل‌کردنش یک ردیفِ این جدول است، نه یک باگ‌فیکس.
     */
    public const CPU_CORES = [
        // Intel Core
        'i7-2600' => 4, 'i7-3770' => 4, 'i7-4770' => 4, 'i7-6700' => 4, 'i7-7700' => 4,
        'i7-8700' => 6, 'i9-9900k' => 8, 'i9-12900k' => 16, 'i9-13900' => 24, 'i5-13500' => 14,
        // Intel Xeon
        'e3-1246' => 4, 'e3-1270' => 4, 'e3-1275' => 4, 'e5-1650' => 6,
        'w-2145' => 8, 'w-2295' => 18, 'gold 5412u' => 24,
        // AMD Ryzen / EPYC / Threadripper
        'ryzen 5 3600' => 6, 'ryzen 7 1700x' => 8, 'ryzen 7 3700x' => 8, 'ryzen 7 7700' => 8,
        'ryzen 9 3900' => 12, 'ryzen 9 5950x' => 16, 'ryzen 9 7950x3d' => 16, 'ryzen 9 9950x' => 16,
        'threadripper 2950x' => 16,
        'epyc 7401p' => 24, 'epyc 7502p' => 32, 'epyc 9454p' => 48,
    ];

    /**
     * مشخصاتِ سخت‌افزاریِ خطِ GEX — ثابتِ کارخانه، در API نیست.
     * (منبع: صفحهٔ محصولِ رسمی، شهریور ۱۴۰۵.)
     */
    public const GEX_SPECS = [
        'GEX44'  => ['vcpu' => 14, 'ram_mb' => 65536,  'disk_gb' => 3840, 'gpu_model' => 'RTX 4000 SFF Ada',        'gpu_vram_mb' => 20480],
        'GEX131' => ['vcpu' => 24, 'ram_mb' => 262144, 'disk_gb' => 3840, 'gpu_model' => 'RTX PRO 6000 Blackwell', 'gpu_vram_mb' => 98304],
        'GEX130' => ['vcpu' => 24, 'ram_mb' => 131072, 'disk_gb' => 3840, 'gpu_model' => 'RTX 6000 Ada',           'gpu_vram_mb' => 49152],
    ];

    public function slug(): string
    {
        return 'hetzner-robot';
    }

    private function user(): ?string
    {
        return Setting::getSecret('hetzner_robot_user');
    }

    private function pass(): ?string
    {
        return Setting::getSecret('hetzner_robot_pass');
    }

    public function isConfigured(): bool
    {
        return filled($this->user()) && filled($this->pass());
    }

    public function capabilities(): array
    {
        return [
            'console'        => false,
            'rebuild'        => false,  // نصبِ دوباره از Robot ممکن است ولی داده‌پاک‌کن است؛ فاز ۱ نه
            'resize'         => false,  // سخت‌افزارِ فیزیکی resize ندارد
            'metrics'        => false,
            'reset_password' => false,
            'ipv6'           => false,
            'ssh_key'        => false,
            'extra_ip'       => false,
        ];
    }

    /** @return array{ok:bool,status:int,body:mixed,message:string} */
    private function req(string $method, string $path, array $payload = []): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'message' => 'کاربرِ webserviceِ زیرساختِ ۷ تنظیم نشده است.'];
        }

        try {
            $req = Http::withBasicAuth((string) $this->user(), (string) $this->pass())
                ->acceptJson()->asForm()->timeout(30);

            $res = strtoupper($method) === 'GET'
                ? $req->get(self::BASE.$path, $payload)
                : $req->post(self::BASE.$path, $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'message' => 'خطای شبکه: '.$e->getMessage()];
        }

        $json = $res->json();

        if ($res->successful()) {
            return ['ok' => true, 'status' => $res->status(), 'body' => $json, 'message' => ''];
        }

        // Robot خطا را در {error:{status,code,message}} می‌دهد
        $msg = is_array($json) ? (string) ($json['error']['message'] ?? 'خطای نامشخص') : 'خطای نامشخص';

        if ($res->status() === 401) {
            $msg .= ' — کاربر/رمزِ webservice درست نیست (در پنلِ Robot ← Settings ← Webservice ساخته می‌شود).';
        }

        return ['ok' => false, 'status' => $res->status(), 'body' => $json, 'message' => $msg];
    }

    public function testConnection(): array
    {
        // /server هم اعتبارسنجی را می‌سنجد هم یک عددِ به‌دردبخور می‌دهد.
        // ⚠️ حسابِ بی‌سرور 404 با کدِ SERVER_NOT_FOUND می‌دهد — آن هم «اتصالِ سالم» است.
        $r = $this->req('GET', '/server');

        if ($r['ok']) {
            $n = is_array($r['body']) ? count($r['body']) : 0;

            return ['ok' => true, 'message' => 'اتصال برقرار است — '.fa_num($n).' سرورِ اختصاصی در حساب.'];
        }

        if ($r['status'] === 404) {
            return ['ok' => true, 'message' => 'اتصال برقرار است — هنوز سرورِ اختصاصی‌ای در حساب نیست.'];
        }

        return ['ok' => false, 'message' => $r['message']];
    }

    // ───────────────────────── کاتالوگ ─────────────────────────

    public function fetchCatalog(): array
    {
        $plans = [];
        $skippedCpu = [];
        $skippedSetup = 0;
        $skippedDc = 0;
        $messages = [];

        // ── محصولاتِ استاندارد (EX/AX/GEX) ──
        $stdErr = '';
        $std = $this->firstOkOf(['/order/server_product', '/order/server/product'], $stdErr);

        if ($std !== null) {
            foreach ($this->unwrapRows($std) as $row) {
                $this->standardRowToPlans($row, $plans, $skippedCpu, $skippedSetup);
            }
        } else {
            // ⚠️ علتِ واقعی باید در گزارش باشد — «خوانده نشد»ِ تنها، عیب‌یابی را
            // به حدس می‌سپارد (درسِ همین امروز: اولین اجرای واقعی همین را داد).
            $messages[] = 'فهرستِ محصولاتِ استاندارد خوانده نشد ('.$stdErr.').';
        }

        // ── مزایده (Server Auction / Serverbörse) ──
        $mktErr = '';
        $mkt = $this->firstOkOf(['/order/server_market/product', '/order/server_market_product'], $mktErr);

        if ($mkt !== null) {
            foreach ($this->unwrapRows($mkt) as $row) {
                $this->marketRowToPlans($row, $plans, $skippedCpu, $skippedSetup, $skippedDc);
            }
        } else {
            $messages[] = 'فهرستِ مزایده خوانده نشد ('.$mktErr.').';
        }

        if ($plans === [] && $messages !== []) {
            return ['ok' => false, 'message' => implode(' · ', $messages), 'locations' => [], 'plans' => [], 'images' => []];
        }

        if ($skippedCpu !== []) {
            $models = array_slice(array_unique($skippedCpu), 0, 8);
            $messages[] = count($skippedCpu).' ردیف به‌خاطرِ CPUِ ناشناخته رد شد ('.implode('، ', $models).') — CPU_CORES را کامل کنید.';
        }

        if ($skippedSetup > 0) {
            $messages[] = $skippedSetup.' محصول به‌خاطرِ هزینهٔ نصب رد شد (فاز ۱ فقط بی‌نصب می‌فروشد).';
        }

        if ($skippedDc > 0) {
            $messages[] = $skippedDc.' ردیفِ مزایده بی‌دیتاسنتر بود و رد شد.';
        }

        $locations = [];

        foreach (self::DC_MAP as $m) {
            $locations[] = [
                'code' => $m['code'], 'country' => $m['country'], 'city' => $m['city'],
                'provider_location' => $m['code'], 'latitude' => null, 'longitude' => null,
            ];
        }

        return [
            'ok'        => true,
            'message'   => count($plans).' پلنِ اختصاصی ساخته شد.'.($messages !== [] ? ' · '.implode(' · ', $messages) : ''),
            'locations' => $locations,
            'plans'     => $plans,
            // تحویل دستی است؛ فهرستِ توزیع‌ها به تصمیمِ مدیر در لحظهٔ نصب است.
            'images'    => [],
        ];
    }

    /** اولین مسیرِ نامزدی که جواب داد. هر دو شکست ⇒ null و علت در $err */
    private function firstOkOf(array $paths, string &$err = ''): ?array
    {
        $seen = [];

        foreach ($paths as $p) {
            $r = $this->req('GET', $p);

            if ($r['ok'] && is_array($r['body'])) {
                return $r['body'];
            }

            // 404ِ «محصولی نیست» ⇒ فهرستِ خالی، نه مسیرِ غلط
            if ($r['status'] === 404 && is_array($r['body'] ?? null)
                && str_contains(strtolower(json_encode($r['body'])), 'not_found')) {
                return [];
            }

            $seen[] = $p.' → '.$r['status'].' '.$r['message'];
        }

        $err = implode(' · ', $seen);

        return null;
    }

    /** [{"product":{...}}] یا [{"server_product":{...}}] یا [{...}] → [[...], …] */
    private function unwrapRows(array $body): array
    {
        $rows = [];

        foreach ($body as $item) {
            if (! is_array($item)) {
                continue;
            }

            // پوشش‌دهندهٔ تک‌کلیدی؟ بازش کن، هرچه نام داشته باشد.
            if (count($item) === 1 && is_array($first = reset($item))) {
                $rows[] = $first;

                continue;
            }

            $rows[] = $item;
        }

        return $rows;
    }

    /** «Intel Core i7-8700» → 6 · ناشناخته → null */
    public static function coresFor(?string $cpu): ?int
    {
        $c = strtolower(trim((string) $cpu));

        if ($c === '') {
            return null;
        }

        foreach (self::CPU_CORES as $needle => $cores) {
            if (str_contains($c, $needle)) {
                return $cores;
            }
        }

        return null;
    }

    /** «FSN1-DC5» یا «fsn1» → ردیفِ DC_MAP · ناشناخته → null */
    public static function dcFor(?string $dc): ?array
    {
        $d = strtoupper(trim((string) $dc));

        foreach (self::DC_MAP as $prefix => $m) {
            if ($d === $prefix || str_starts_with($d, $prefix)) {
                return $m;
            }
        }

        return null;
    }

    /** بهایِ تمام‌شده به سنتِ یورو، با کارمزدِ انتقالِ hetzner */
    private function costCents(float $netEur): int
    {
        // گردِ رو به بالا، ولی بعد از خنثی‌کردنِ خطای ممیزِ شناور — وگرنه
        // 38×1.08×100 = 4104.0000001 می‌شود ۴۱۰۵ و بها یک سنت دروغ می‌گوید.
        return (int) ceil(round(app(CloudPricing::class)->costWithFee($netEur, 'hetzner') * 100, 4));
    }

    /**
     * یک محصولِ استاندارد (EX/AX/GEX) → یک پلن به‌ازای هر دیتاسنترِ قیمت‌دار.
     *
     * شکلِ مورد انتظار (استنتاجی):
     *   id, name, description[], traffic, location[], prices:[{location,
     *   price:{net,gross}, price_setup:{net,gross}}]
     */
    private function standardRowToPlans(array $p, array &$plans, array &$skippedCpu, int &$skippedSetup): void
    {
        $id = (string) ($p['id'] ?? '');
        $name = (string) ($p['name'] ?? $id);

        if ($id === '') {
            return;
        }

        $desc = implode(' ', array_filter((array) ($p['description'] ?? []), 'is_string'));
        $gex = null;

        foreach (self::GEX_SPECS as $prefix => $spec) {
            if (str_starts_with(strtoupper($id), $prefix) || str_starts_with(strtoupper($name), $prefix)) {
                $gex = $spec;

                break;
            }
        }

        // مشخصات: GEX از جدولِ ثابت؛ بقیه از متنِ توضیح (CPU از جدولِ هسته)
        if ($gex !== null) {
            $vcpu = $gex['vcpu'];
            $ram = $gex['ram_mb'];
            $disk = $gex['disk_gb'];
            $gpuModel = $gex['gpu_model'];
            $gpuVram = $gex['gpu_vram_mb'];
        } else {
            $vcpu = self::coresFor($desc);

            if ($vcpu === null) {
                $skippedCpu[] = $name;

                return;
            }

            $ram = self::ramMbFromText($desc);
            $disk = self::diskGbFromText($desc);
            $gpuModel = null;
            $gpuVram = null;

            if ($ram === null || $disk === null) {
                $skippedCpu[] = $name.' (مشخصاتِ ناخوانا)';

                return;
            }
        }

        foreach ((array) ($p['prices'] ?? []) as $pr) {
            if (! is_array($pr)) {
                continue;
            }

            $m = self::dcFor((string) ($pr['location'] ?? ''));
            $net = (float) (($pr['price']['net'] ?? null) ?? 0);
            $setup = (float) (($pr['price_setup']['net'] ?? null) ?? 0);

            if ($m === null || $net <= 0) {
                continue;
            }

            if ($setup > 0) {
                $skippedSetup++;

                continue;
            }

            $plans[] = [
                'provider_ref'       => $id,
                'provider_location'  => (string) $pr['location'],
                'location_code'      => $m['code'],
                'vcpu'               => $vcpu,
                'ram_mb'             => $ram,
                'disk_gb'            => $disk,
                'disk_type'          => self::diskTypeFromText($desc),
                'traffic_gb'         => 0,   // ترافیکِ Robot عملاً نامحدود است؛ برچسبش از تنظیمات می‌آید
                'cpu_kind'           => 'dedicated',
                'arch'               => 'x86',
                'cost_eur_cents'     => $this->costCents($net),
                'in_stock'           => true,
                'name'               => $name,
                'gpu_model'          => $gpuModel,
                'gpu_count'          => $gpuModel !== null ? 1 : null,
                'gpu_vram_mb'        => $gpuVram,
                'metal'              => true,
            ];
        }
    }

    /**
     * یک ردیفِ مزایده → یک پلن (هر ردیف یک ماشینِ واقعیِ تک‌موجودی است).
     *
     * شکلِ مورد انتظار (استنتاجی):
     *   id(int), name, cpu, memory_size(GB), hdd_size(GB), hdd_count,
     *   price(net/ماهانه), price_setup, datacenter?, fixed_price, next_reduce
     */
    private function marketRowToPlans(array $p, array &$plans, array &$skippedCpu, int &$skippedSetup, int &$skippedDc): void
    {
        $id = (string) ($p['id'] ?? '');

        if ($id === '') {
            return;
        }

        $cpu = (string) ($p['cpu'] ?? '');
        $vcpu = self::coresFor($cpu);

        if ($vcpu === null) {
            $skippedCpu[] = $cpu !== '' ? $cpu : ('#'.$id);

            return;
        }

        $ramGb = (int) ($p['memory_size'] ?? ($p['memory'] ?? 0));
        $hddGb = (int) ($p['hdd_size'] ?? 0);
        $hddCount = max(1, (int) ($p['hdd_count'] ?? 1));

        if ($ramGb <= 0 || $hddGb <= 0) {
            $skippedCpu[] = ($cpu !== '' ? $cpu : '#'.$id).' (مشخصاتِ ناخوانا)';

            return;
        }

        $net = (float) ($p['price'] ?? 0);
        $setup = (float) ($p['price_setup'] ?? 0);

        if ($net <= 0) {
            return;
        }

        if ($setup > 0) {
            $skippedSetup++;

            return;
        }

        $m = null;

        foreach (['datacenter', 'dc', 'location'] as $k) {
            if (filled($p[$k] ?? null)) {
                $m = self::dcFor(is_array($p[$k]) ? (string) reset($p[$k]) : (string) $p[$k]);

                break;
            }
        }

        if ($m === null) {
            $skippedDc++;

            return;
        }

        $hddText = strtolower(implode(' ', array_filter((array) ($p['hdd_arr'] ?? []), 'is_string')).' '.(string) ($p['hdd_text'] ?? ''));

        $plans[] = [
            'provider_ref'      => 'market-'.$id,
            'provider_location' => strtoupper((string) ($p['datacenter'] ?? $m['code'])),
            'location_code'     => $m['code'],
            'vcpu'              => $vcpu,
            'ram_mb'            => $ramGb * 1024,
            'disk_gb'           => $hddGb * $hddCount,
            'disk_type'         => str_contains($hddText, 'nvme') ? 'nvme' : (str_contains($hddText, 'ssd') ? 'ssd' : 'hdd'),
            'traffic_gb'        => 0,
            'cpu_kind'          => 'dedicated',
            'arch'              => 'x86',
            'cost_eur_cents'    => $this->costCents($net),
            'in_stock'          => true,
            'name'              => (string) ($p['name'] ?? ('SB-'.$id)),
            'gpu_model'         => null,
            'gpu_count'         => null,
            'gpu_vram_mb'       => null,
            'metal'             => true,
        ];
    }

    /** «64 GB DDR5» / «RAM: 128 GB» → مگابایت · ناخوانا → null */
    public static function ramMbFromText(string $t): ?int
    {
        if (preg_match('/(\d+)\s*GB\s*(?:DDR|ECC|RAM)/i', $t, $m)
            || preg_match('/RAM[:\s]+(\d+)\s*GB/i', $t, $m)) {
            return ((int) $m[1]) * 1024;
        }

        return null;
    }

    /** «2 x 1.92 TB NVMe» / «2x 512 GB SSD» → مجموعِ گیگابایت · ناخوانا → null */
    public static function diskGbFromText(string $t): ?int
    {
        if (preg_match('/(\d+)\s*x\s*([\d.]+)\s*(TB|GB)/i', $t, $m)) {
            $each = (float) $m[2] * (strtoupper($m[3]) === 'TB' ? 1000 : 1);

            return (int) round(((int) $m[1]) * $each);
        }

        if (preg_match('/([\d.]+)\s*(TB|GB)\s*(?:NVMe|SSD|SATA|HDD)/i', $t, $m)) {
            return (int) round((float) $m[1] * (strtoupper($m[2]) === 'TB' ? 1000 : 1));
        }

        return null;
    }

    public static function diskTypeFromText(string $t): string
    {
        $t = strtolower($t);

        return str_contains($t, 'nvme') ? 'nvme' : (str_contains($t, 'ssd') ? 'ssd' : 'hdd');
    }

    // ───────────────────────── سرورهای موجود ─────────────────────────

    public function listServers(): array
    {
        $r = $this->req('GET', '/server');

        if (! $r['ok']) {
            // حسابِ بی‌سرور 404 می‌دهد — فهرستِ خالیِ سالم، نه خطا
            if ($r['status'] === 404) {
                return ['ok' => true, 'message' => '', 'servers' => []];
            }

            return ['ok' => false, 'message' => $r['message'], 'servers' => []];
        }

        $servers = [];

        foreach ($this->unwrapRows((array) $r['body']) as $s) {
            $num = $s['server_number'] ?? null;

            if ($num === null) {
                continue;
            }

            $servers[] = [
                'ref'      => (string) $num,
                'name'     => (string) ($s['server_name'] ?? ($s['server_ip'] ?? $num)),
                'status'   => (($s['status'] ?? '') === 'ready') ? 'running' : 'building',
                'ipv4'     => $s['server_ip'] ?? null,
                'ipv6'     => $s['server_ipv6_net'] ?? null,
                'plan'     => $s['product'] ?? null,
                'location' => $s['dc'] ?? null,
                'created'  => $s['paid_until'] ?? null,
            ];
        }

        return ['ok' => true, 'message' => '', 'servers' => $servers];
    }

    public function serverStatus(string $ref): array
    {
        $none = ['ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
        $r = $this->req('GET', '/server/'.rawurlencode($ref));

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'status' => 'unknown'] + $none;
        }

        $rows = $this->unwrapRows([(array) $r['body']]);
        $s = $rows[0] ?? [];

        return [
            'ok' => true, 'message' => '',
            'status'          => (($s['status'] ?? '') === 'ready') ? 'running' : 'building',
            'ipv4'            => $s['server_ip'] ?? null,
            'ipv6'            => $s['server_ipv6_net'] ?? null,
            'traffic_used_gb' => null,
            'raw'             => ['product' => $s['product'] ?? null, 'dc' => $s['dc'] ?? null],
        ];
    }

    public function power(string $ref, string $action): array
    {
        // Robot فقط «ریست» دارد (سخت‌افزاری/نرم‌افزاری) — خاموشِ نرم از داخلِ OS است.
        if (! in_array($action, ['reboot', 'reset'], true)) {
            return ['ok' => false, 'message' => 'برای سرورِ فیزیکی فقط راه‌اندازیِ دوباره از این‌جا ممکن است.'];
        }

        $r = $this->req('POST', '/reset/'.rawurlencode($ref), ['type' => $action === 'reset' ? 'hw' : 'sw']);

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? 'فرمانِ راه‌اندازیِ دوباره رفت.' : $r['message']];
    }

    /**
     * 🔴 «حذف» در Robot یعنی **لغوِ اجاره** — و برگشت‌ناپذیر است؛ سرور تا پایانِ
     * دورهٔ پرداخت‌شده می‌مانَد و بعد پس گرفته می‌شود. مثل OVH، معنایش با
     * حذفِ آنیِ Cloud فرق دارد.
     */
    public function deleteServer(string $ref): array
    {
        $r = $this->req('POST', '/server/'.rawurlencode($ref).'/cancellation', [
            'cancellation_date' => 'now',
        ]);

        return ['ok' => $r['ok'], 'message' => $r['ok']
            ? 'لغوِ اجاره ثبت شد؛ سرور تا پایانِ دورهٔ پرداخت‌شده فعال می‌مانَد.'
            : $r['message']];
    }

    /** 🔴 خریدِ خودکار عمداً نیست — صفِ تحویلِ دستی (قاعدهٔ OVH). */
    public function createServer(array $spec): array
    {
        return [
            'ok' => false, 'manual' => true,
            'message' => 'سفارشِ سرورِ اختصاصی به صفِ تحویلِ دستی رفت؛ مدیر آن را در پنلِ زیرساخت نهایی می‌کند.',
            'ref' => null, 'ipv4' => null, 'ipv6' => null, 'root_password' => null,
            'status' => 'building',
        ];
    }

    public function rebuild(string $ref, string $imageRef, ?string $password = null): array
    {
        return ['ok' => false, 'message' => 'نصبِ دوبارهٔ سرورِ فیزیکی فعلاً از پنلِ زیرساخت انجام می‌شود.'];
    }

    public function resetPassword(string $ref): array
    {
        return ['ok' => false, 'message' => 'تغییرِ رمز برای سرورِ فیزیکی از این‌جا ممکن نیست.'];
    }

    public function console(string $ref): array
    {
        return ['ok' => false, 'message' => 'کنسولِ KVM با تیکت به دیتاسنتر درخواست می‌شود.'];
    }

    public function metrics(string $ref, string $window = '24h'): array
    {
        return ['ok' => false, 'message' => 'نمودارِ مصرف برای سرورِ فیزیکی در دسترس نیست.'];
    }

    public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array
    {
        return ['ok' => false, 'message' => 'سخت‌افزارِ فیزیکی تغییرِ پلن ندارد؛ جابه‌جایی = سفارشِ تازه.'];
    }

    public function uploadSshKey(string $name, string $publicKey): array
    {
        return ['ok' => false, 'message' => 'کلیدِ SSH در تحویلِ دستی توسطِ مدیر ست می‌شود.'];
    }

    public function addExtraIps(string $ref, int $count): array
    {
        return ['ok' => false, 'message' => 'IP اضافه برای سرورِ فیزیکی از پنلِ زیرساخت سفارش داده می‌شود.'];
    }

    /** ساختارِ خام برای /admin/cloud/probe — نگاشتِ استنتاجی را با واقعیت چک کنید. */
    public function rawProbe(): array
    {
        $out = [];

        foreach (['/server', '/order/server_product', '/order/server_market/product'] as $p) {
            $r = $this->req('GET', $p);
            $body = $r['body'];

            // فقط نمونه، نه کلِ فهرستِ چندصدتایی
            if (is_array($body) && count($body) > 3) {
                $body = array_slice($body, 0, 3);
            }

            $out[$p] = ['ok' => $r['ok'], 'status' => $r['status'], 'sample' => $body];
        }

        return $out;
    }
}
