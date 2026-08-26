<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use App\Services\ExchangeRate;

/**
 * تکهٔ دومِ درایورِ Salad — کاتالوگ، ساخت، وضعیت، فهرست، روشن/خاموش، حذف.
 *
 * جدا نگه داشته شد چون `SaladClient` بخشِ «قرارداد و آنچه نداریم» است و این
 * بخش «آنچه واقعاً پول جابه‌جا می‌کند». هر دو یک کلاس‌اند از دیدِ بیرون
 * (trait)، ولی موقعِ خواندن قاطی نمی‌شوند.
 */
trait SaladOperations
{
    /**
     * تومانِ هر دلار — با راهِ فرارِ دستی، مثلِ یورو.
     *
     * 🔴 چرا لازم شد: `CloudPricing::eurToToman()` تنظیمِ
     * `pricing_rate_override` را دارد ولی دلار هیچ معادلی نداشت. یعنی اگر
     * منبعِ نرخِ زنده بخوابد، بهایِ **همهٔ** پلن‌های این زیرساخت صفر می‌شود و
     * `scopeSellable` همه را از فروشگاه بیرون می‌گذارد — کلِ محصول بی‌صدا غیب
     * می‌شود و هیچ خطایی هم ثبت نمی‌شود.
     *
     * صفر عمداً نگه داشته شده (بهتر از فروشِ کارتِ گران به قیمتِ هیچ)، ولی
     * حالا مدیر می‌تواند نرخ را دستی بگذارد و فروش را برگردانَد.
     */
    private function usdToman(): int
    {
        $override = (int) Setting::get('pricing_usd_rate_override', '0');

        if ($override > 0) {
            return $override;
        }

        try {
            return (int) (app(ExchangeRate::class)->toToman('USD') ?: 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * درصدِ کارمزدِ انتقالِ ارز — روی **بهایِ تمام‌شده** می‌نشیند، نه روی قیمتِ فروش.
     *
     * ⚠️ جای نشستنش مهم است: چون بهایِ تمام‌شده بالا می‌رود، حاشیهٔ سود هم روی
     * عددِ درست حساب می‌شود. اگر روی قیمتِ فروش می‌نشست، کارمزد از جیبِ حاشیه
     * می‌رفت و در گزارشِ مالی نامرئی می‌مانْد.
     *
     * ⚠️ سقفِ ۲۵٪: کارمزدِ حواله در بدترین حالت هم به آن نمی‌رسد؛ عددِ بزرگ‌تر
     * یعنی غلطِ تایپی، و غلطِ تایپیِ اینجا مستقیم روی هر قیمت می‌نشیند.
     */
    private function fxFeePct(): float
    {
        $v = (float) Setting::get('pricing_fx_fee_pct', '');

        return $v > 0 ? min(25.0, $v) : 0.0;
    }

    /** نرخِ vCPU بر ساعت (دلار) — قابلِ اصلاح از تنظیمات */
    private function vcpuUsdHour(): float
    {
        $v = (float) Setting::get('salad_vcpu_usd_hour', '');

        return $v > 0 ? $v : self::DEFAULT_VCPU_USD_HOUR;
    }

    /** نرخِ هر گیگ رم بر ساعت (دلار) — قابلِ اصلاح از تنظیمات */
    private function ramGbUsdHour(): float
    {
        $v = (float) Setting::get('salad_ram_gb_usd_hour', '');

        return $v > 0 ? $v : self::DEFAULT_RAM_GB_USD_HOUR;
    }

    /**
     * اولویتی که با آن می‌خریم — و مستقیماً هم قیمت را تعیین می‌کند هم اینکه
     * نمونه چقدر زود قطع می‌شود.
     *
     * ⚠️ پیش‌فرض `high` است، نه ارزان‌ترین. ارزان‌ترین (`batch`) یعنی هر بارِ
     * کاریِ گران‌ترِ دیگری می‌تواند سرورِ مشتریِ ما را کنار بزند — پولِ کمتر،
     * ولی شکایتی که ارزشش را ندارد.
     */
    private function priority(): string
    {
        $p = strtolower(trim((string) Setting::get('salad_priority', '')));

        return in_array($p, ['high', 'medium', 'low', 'batch'], true) ? $p : 'high';
    }

    /**
     * ایمیجِ کانتینری که تحویل می‌دهیم.
     *
     * 🔴 عمداً تنظیماتی است و پیش‌فرضش خالی: تا وقتی مدیر ایمیجِ SSH-داری که
     * آزموده انتخاب نکند، `createServer()` **چیزی نمی‌سازد**. ایمیجِ حدسی یعنی
     * کانتینری که بالا می‌آید و مشتری هیچ راهی به داخلش ندارد — پولِ گرفته‌شده
     * و سرویسِ بی‌فایده.
     */
    private function image(): string
    {
        return trim((string) Setting::get('salad_image', ''));
    }

    /**
     * بهایِ تمام‌شدهٔ **ماهانه** به سنتِ یورو — قراردادِ `fetchCatalog`.
     *
     * 🔴 زنجیرهٔ کامل، و هر حلقه‌اش یک‌بار جای دیگری در این پروژه اشتباه شده:
     *
     *   دلار/ساعت (GPU + vCPU + رم)
     *     × ۷۳۰ ساعت            → دلار/ماه
     *     × تومانِ هر دلار       → تومان/ماه      ← نرخِ **دلار**، نه یورو
     *     ÷ تومانِ هر یورو × ۱۰۰ → سنتِ یورو/ماه
     *
     * ⚠️ نرخِ هر ارز با خودش: تلهٔ ثبت‌شدهٔ `servers.monthly_cost` که سرورِ
     * دلاری را با نرخِ یورو حساب می‌کرد.
     *
     * ⚠️ نبودِ نرخ ⇒ **صفر**، نه حدس. `scopeSellable` پلنِ بی‌قیمت را از
     * فروشگاه بیرون می‌گذارد؛ همان رفتارِ عمدیِ «بهتر از فروشِ سرورِ ۵۰ یورویی
     * به قیمتِ صفر».
     */
    private function monthlyEurCents(float $usdPerHour): int
    {
        if ($usdPerHour <= 0) {
            return 0;
        }

        $usdToman = $this->usdToman();
        $eurToman = (int) app(CloudPricing::class)->eurToToman();

        if ($usdToman <= 0 || $eurToman <= 0) {
            return 0;
        }

        /*
        | 🔴 کارمزدِ واقعیِ رساندنِ پول به زیرساخت هم بخشی از بهایِ تمام‌شده است.
        |
        | نرخِ بازار «قیمتِ اسمیِ دلار» است، ولی دلاری که واقعاً به حسابِ آنها
        | می‌رسد گران‌تر تمام می‌شود: کارمزدِ حواله (Wise و مانندش)، اسپردِ صرافی،
        | کارمزدِ کارت. تا امروز این تکه هیچ‌جا حساب نمی‌شد، یعنی حاشیهٔ سودِ
        | واقعی از آنچه فکر می‌کردیم **کمتر** بود — همان خوش‌بینیِ ثبت‌شدهٔ
        | «سودِ خالص = درآمدِ واقعی − هزینه‌ای که یادمان مانده وارد کنیم».
        |
        | ⚠️ پیش‌فرض **صفر** است، نه یک عددِ حدسی. کارمزدِ هر مسیر فرق دارد و
        | حدسِ ما به‌جای مدیر یعنی قیمتی که هیچ‌کس نمی‌داند از کجا آمده.
        */
        $tomanPerMonth = $usdPerHour * self::HOURS_PER_MONTH * $usdToman
            * (1 + $this->fxFeePct() / 100);

        return (int) round(($tomanPerMonth / $eurToman) * 100);
    }

    /**
     * کاتالوگ: هر «کلاسِ GPU» یک پلن می‌شود.
     *
     * ⚠️ مکان یکی است و نمادین — این زیرساخت دیتاسنتر ندارد.
     * ⚠️ ایمیج **خالی** برمی‌گردد: مشتری این‌جا سیستم‌عامل انتخاب نمی‌کند،
     *    ایمیجِ کانتینر را ما تعیین می‌کنیم. فهرستِ ایمیجِ جعلی یعنی صفحهٔ
     *    خریدی که انتخابی نشان می‌دهد و در تحویل نادیده‌اش می‌گیرد.
     */
    public function fetchCatalog(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'تنظیم نشده است.', 'locations' => [], 'plans' => [], 'images' => []];
        }

        $r = $this->req('GET', '/organizations/'.rawurlencode($this->org()).'/gpu-classes');

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'locations' => [], 'plans' => [], 'images' => []];
        }

        $rows = $this->items($r['body']);

        if ($rows === []) {
            // ⚠️ فهرستِ خالی «صفر کارت» نیست، «چیزی نفهمیدیم» است. اگر موفق
            // بخوانیمش، همگام‌ساز همهٔ پلن‌های قبلی را ناموجود می‌کند.
            return ['ok' => false, 'message' => 'هیچ کلاسِ GPU خوانده نشد.', 'locations' => [], 'plans' => [], 'images' => []];
        }

        $locations = [[
            'code'              => self::LOCATION,
            'country'           => 'شبکهٔ توزیع‌شده',
            'city'              => null,
            'provider_location' => 'global',
            'latitude'          => null,
            'longitude'         => null,
        ]];

        $priority = $this->priority();
        $plans = [];
        $skipped = 0;

        foreach ($rows as $g) {
            $plan = $this->planFromGpuClass($g, $priority);

            if ($plan === null) {
                $skipped++;

                continue;
            }

            $plans[] = $plan;
        }

        if ($plans === []) {
            return ['ok' => false, 'message' => 'هیچ کلاسِ GPU قابلِ استفاده نبود.', 'locations' => [], 'plans' => [], 'images' => []];
        }

        return [
            'ok'      => true,
            // ⚠️ ردیفِ کنارگذاشته‌شده **شمرده و اعلام** می‌شود. سکوت این‌جا یعنی
            // کارتی که قیمتش را نفهمیدیم بی‌صدا از فروشگاه غایب بماند.
            'message' => $skipped > 0 ? fa_num((string) $skipped).' کلاسِ GPU به‌خاطرِ دادهٔ ناقص کنار گذاشته شد.' : '',
            'locations' => $locations,
            'plans'     => $plans,
            'images'    => [],
        ];
    }

    /**
     * یک کلاسِ GPU → یک ردیفِ پلن. `null` یعنی ناقص و باید کنار برود.
     *
     * ⚠️ مشخصاتِ ناقص **رد** می‌شود نه ذخیره با صفر — قاعدهٔ ثبت‌شدهٔ همین حوزه.
     */
    private function planFromGpuClass(array $g, string $priority): ?array
    {
        $name = trim((string) ($g['name'] ?? ''));
        $ref = trim((string) ($g['id'] ?? ''));

        if ($name === '' || $ref === '') {
            return null;
        }

        $gpuUsd = $this->priceFor($g, $priority);

        if ($gpuUsd === null) {
            return null;
        }

        // بیشترین پیکربندیِ مجازِ همان کلاس — همان چیزی که می‌فروشیم.
        // ⚠️ سقفِ خودِ API هم اعمال می‌شود (۱۶ هسته، ۶۱۴۴۰ مگ، ۲۵۰ گیگ)،
        //    وگرنه ساختِ سرور با «مقدارِ خارج از بازه» رد می‌شود.
        $vcpu = max(1, min(16, (int) ($g['max_vcpu'] ?? $g['min_vcpu'] ?? 0)));
        $ramMb = max(1024, min(61440, (int) ($g['max_ram'] ?? $g['min_ram'] ?? 0)));
        $storageBytes = (int) ($g['max_storage'] ?? $g['min_storage'] ?? 0);
        $diskGb = max(1, min(250, (int) round($storageBytes / 1_000_000_000)));

        if ($vcpu < 1 || $ramMb < 1024) {
            return null;
        }

        $totalUsdHour = $gpuUsd
            + ($vcpu * $this->vcpuUsdHour())
            + (($ramMb / 1024) * $this->ramGbUsdHour());

        $gpuCount = max(1, (int) ($g['gpu_count'] ?? 1));

        return [
            'provider_ref'      => $ref,
            'provider_location' => 'global',
            'location_code'     => self::LOCATION,
            'vcpu'              => $vcpu,
            'ram_mb'            => $ramMb,
            'disk_gb'           => $diskGb,
            'disk_type'         => 'ssd',
            'traffic_gb'        => 0,
            'cpu_kind'          => 'shared',
            'arch'              => 'x86',
            'cost_eur_cents'    => $this->monthlyEurCents($totalUsdHour),
            // ⚠️ «پرتقاضا» را همان‌طور که خودشان می‌گویند منتقل می‌کنیم؛
            //    فروشِ کارتی که موجود نیست یعنی پولِ گرفته‌شده و تحویلِ ناممکن.
            'in_stock'          => ! (bool) ($g['is_high_demand'] ?? false),
            'name'              => $name,
            // ── ستون‌های تازهٔ GPU ──
            'gpu_model'         => $name,
            'gpu_count'         => $gpuCount,
            'gpu_vram_mb'       => null,
            // 🔴 همیشه true: حتی بالاترین اولویت هم قطع می‌شود.
            'is_interruptible'  => true,
        ];
    }

    /** قیمتِ دلاری/ساعتِ GPU برای اولویتِ خواسته‌شده */
    private function priceFor(array $g, string $priority): ?float
    {
        $prices = $g['prices'] ?? null;

        if (! is_array($prices)) {
            return null;
        }

        foreach ($prices as $p) {
            if (! is_array($p)) {
                continue;
            }

            if (strtolower((string) ($p['priority'] ?? '')) !== $priority) {
                continue;
            }

            // ⚠️ رشته است، نه عدد. `(float)` روی رشتهٔ خالی صفر می‌دهد و صفر
            //    یعنی «رایگان» — پس صریح سنجیده می‌شود.
            $raw = (string) ($p['price'] ?? '');

            if ($raw === '' || ! is_numeric($raw)) {
                return null;
            }

            $v = (float) $raw;

            return $v > 0 ? $v : null;
        }

        return null;
    }

    /**
     * ساختِ «سرور» — این‌جا یعنی گروهِ کانتینر با یک نمونه.
     *
     * 🔴 نامِ قطعی همان محافظِ «دو بار نخر» است که در بقیهٔ درایورها هم هست:
     * `sn-svc-{id}` از بالا می‌آید و این API نام را **یکتا** می‌گیرد، پس تلاشِ
     * دوباره خطای تکراری می‌گیرد نه سرورِ دوم.
     */
    public function createServer(array $spec): array
    {
        $fail = fn (string $m) => ['ok' => false, 'message' => $m, 'ref' => null,
            'ipv4' => null, 'ipv6' => null, 'root_password' => null, 'status' => 'error'];

        if (! $this->isConfigured()) {
            return $fail('این زیرساخت تنظیم نشده است.');
        }

        $image = $this->image();

        if ($image === '') {
            // 🔴 بهتر است تحویل نشود تا اینکه کانتینری بالا بیاید که مشتری
            //    راهی به داخلش ندارد. مدیر در تنظیمات ایمیج را می‌گذارد.
            return $fail('ایمیجِ کانتینر برای این زیرساخت تنظیم نشده است.');
        }

        $name = (string) ($spec['name'] ?? '');

        if ($name === '') {
            return $fail('نامِ سرور خالی است.');
        }

        $planRef = (string) ($spec['plan_ref'] ?? '');

        if ($planRef === '') {
            return $fail('کلاسِ GPU مشخص نشده است.');
        }

        $plan = \App\Models\CloudPlan::query()
            ->where('provider', $this->slug())
            ->where('provider_ref', $planRef)
            ->first();

        if ($plan === null) {
            return $fail('پلنِ خواسته‌شده در کاتالوگ نیست.');
        }

        $env = [];
        $keys = array_values(array_filter((array) ($spec['ssh_keys'] ?? [])));

        if ($keys !== []) {
            // کلید در لحظهٔ ساخت تزریق می‌شود؛ ایمیجِ انتخابی باید آن را
            // بخوانَد و در `authorized_keys` بگذارد.
            $env['SSH_PUBLIC_KEY'] = implode("\n", $keys);
        }

        $body = [
            'name'             => $name,
            'display_name'     => $name,
            'replicas'         => 1,
            'autostart_policy' => true,
            'restart_policy'   => 'always',
            'container'        => [
                'image'     => $image,
                'priority'  => $this->priority(),
                'resources' => [
                    'cpu'            => (int) $plan->vcpu,
                    'memory'         => (int) $plan->ram_mb,
                    'gpu_classes'    => [$planRef],
                    'storage_amount' => (int) $plan->disk_gb * 1_000_000_000,
                ],
            ],
        ];

        if ($env !== []) {
            $body['container']['environment_variables'] = $env;
        }

        $r = $this->req('POST', $this->proj(), $body);

        if (! $r['ok']) {
            return $fail($r['message']);
        }

        $ref = (string) (data_get($r['body'], 'name') ?: $name);

        return [
            'ok'      => true,
            'message' => '',
            // ⚠️ `ref` نامِ گروه است نه شناسهٔ عددی: همهٔ مسیرهای بعدی
            //    (start/stop/delete/instances) با **نام** کار می‌کنند.
            'ref'           => $ref,
            // IP در لحظهٔ ساخت وجود ندارد؛ بعد از تخصیصِ نمونه می‌آید و
            // `serverStatus()` آن را می‌نشاند.
            'ipv4'          => null,
            'ipv6'          => null,
            'root_password' => null,
            'status'        => 'provisioning',
            'raw'           => is_array($r['body']) ? $r['body'] : [],
        ];
    }

    /**
     * وضعیتِ زنده + IP و پورتِ SSH از **نمونه**، نه از گروه.
     *
     * ⚠️ گروه فقط وضعیتِ کلی دارد؛ نشانیِ واقعی روی نمونه است و تا وقتی گره
     * تخصیص نخورده اصلاً وجود ندارد.
     */
    public function serverStatus(string $ref): array
    {
        $r = $this->req('GET', $this->proj('/'.rawurlencode($ref)));

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'status' => 'unknown',
                'ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
        }

        $status = $this->mapStatus((string) data_get($r['body'], 'current_state.status', ''));

        $inst = $this->req('GET', $this->proj('/'.rawurlencode($ref).'/instances'));
        $ip = null;

        if ($inst['ok']) {
            foreach ($this->items($inst['body']) as $i) {
                if (filled($i['ssh_ip'] ?? null)) {
                    $ip = (string) $i['ssh_ip'];

                    break;
                }
            }
        }

        return [
            'ok'      => true,
            'message' => '',
            'status'  => $status,
            'ipv4'    => $ip,
            'ipv6'    => null,
            'traffic_used_gb' => null,
            'raw'     => is_array($r['body']) ? $r['body'] : [],
        ];
    }

    /** وضعیتِ آنها → واژگانِ ما */
    private function mapStatus(string $s): string
    {
        return match (strtolower($s)) {
            'running'             => 'running',
            'stopped', 'succeeded', 'failed' => 'stopped',
            'pending', 'deploying' => 'provisioning',
            default               => 'unknown',
        };
    }

    /**
     * فهرستِ همهٔ گروه‌ها — پایهٔ گزارشِ «سرورِ یتیم».
     *
     * ⚠️ شکستِ خواندن **صریح** برمی‌گردد، نه فهرستِ خالی: فهرستِ خالیِ موفق
     * یعنی گزارش بگوید همهٔ سرورهای مشتریان ناپدید شده‌اند.
     */
    public function listServers(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'تنظیم نشده است.', 'servers' => []];
        }

        $r = $this->req('GET', $this->proj());

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'servers' => []];
        }

        $servers = [];

        foreach ($this->items($r['body']) as $g) {
            $name = (string) ($g['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $servers[] = [
                'ref'      => $name,
                'name'     => (string) ($g['display_name'] ?? $name),
                'status'   => $this->mapStatus((string) data_get($g, 'current_state.status', '')),
                'ipv4'     => null,
                'ipv6'     => null,
                'plan'     => null,
                'location' => self::LOCATION,
                'created'  => $g['create_time'] ?? null,
            ];
        }

        return ['ok' => true, 'message' => '', 'servers' => $servers];
    }

    /**
     * روشن/خاموش. «راه‌اندازیِ دوباره» این‌جا stop سپس start نیست — خودشان
     * مسیرِ restart روی **نمونه** دارند، ولی نمونه ممکن است اصلاً وجود نداشته
     * باشد. پس reboot را صریح رد می‌کنیم تا دکمه‌ای که کاری نمی‌کند نماند.
     */
    public function power(string $ref, string $action): array
    {
        $path = match ($action) {
            'on'  => '/start',
            'off', 'shutdown' => '/stop',
            default => null,
        };

        if ($path === null) {
            return ['ok' => false, 'message' => 'این عملیات برای سرورِ GPU در دسترس نیست.'];
        }

        $r = $this->req('POST', $this->proj('/'.rawurlencode($ref).$path));

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }

    /**
     * حذفِ واقعی.
     *
     * ⚠️ ۴۰۴ = «از قبل نبود» = **موفق** — همان قاعدهٔ هر درایورِ دیگر. بی‌این،
     * یک سرویسِ خاتمه‌یافته تا ابد در صفِ آزادسازی می‌مانْد.
     */
    public function deleteServer(string $ref): array
    {
        $r = $this->req('DELETE', $this->proj('/'.rawurlencode($ref)));

        if (! $r['ok'] && $r['status'] === 404) {
            return ['ok' => true, 'message' => 'سرور از قبل حذف شده بود.'];
        }

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }
}
