<?php

namespace App\Services\Cloud;

use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * همگام‌سازیِ کاتالوگِ ابری از همهٔ ارائه‌دهنده‌ها.
 *
 * سه قاعدهٔ سخت که از تجربهٔ همین پروژه آمده‌اند:
 *
 * ۱) **هیچ‌وقت حذف نکن، غیرفعال کن.** اگر ارائه‌دهنده پلنی را برداشت و ما ردیف
 *    را DELETE کنیم، سرویسِ فعالِ مشتری به پلنِ ناموجود اشاره می‌کند و صفحهٔ
 *    پنلش می‌شکند. `is_active=false` هم فروش را می‌بندد هم تاریخ را نگه می‌دارد.
 *
 * ۲) **برچسبِ دستیِ مدیر را بازنویسی نکن.** اگر مدیر برای «آلمان — فرانکفورت»
 *    نامِ فارسیِ دلخواه گذاشت، سینکِ بعدی نباید پاکش کند.
 *
 * ۳) **قیمتِ صفر ذخیره نکن.** اگر نرخِ یورو در دسترس نبود، `price_irt` صفر
 *    می‌ماند و `scopeSellable` خودش پلن را از فروشگاه بیرون می‌گذارد — بهتر از
 *    فروختنِ سرورِ ۵۰ یورویی به قیمتِ صفر.
 */
class CloudCatalogSync
{
    public function __construct(
        private CloudManager $manager,
        private CloudPricing $pricing,
    ) {}

    /**
     * @return array{ok:bool,providers:array<string,array{ok:bool,message:string,locations:int,plans:int,images:int}>,rate:int}
     */
    public function sync(?string $only = null): array
    {
        if (! Schema::hasTable('cloud_plans')) {
            return ['ok' => false, 'providers' => [], 'rate' => 0, 'message' => 'جدول‌های ابری ساخته نشده‌اند — اول مهاجرت را بزنید.'];
        }

        $rate = $this->pricing->eurToToman();
        $report = [];

        foreach ($this->manager->configured() as $slug => $driver) {
            if ($only !== null && $only !== $slug) {
                continue;
            }

            try {
                $report[$slug] = $this->syncOne($slug, $driver);
            } catch (\Throwable $e) {
                // یک ارائه‌دهندهٔ خراب نباید سینکِ دیگری را بخواباند
                Log::error('cloud.sync.failed', ['provider' => $slug, 'err' => $e->getMessage()]);

                $report[$slug] = [
                    'ok' => false, 'message' => 'خطای غیرمنتظره: '.$e->getMessage(),
                    'locations' => 0, 'plans' => 0, 'images' => 0,
                ];
            }
        }

        // منوی سایت از همین کاتالوگ ساخته می‌شود؛ بی‌این، مکانِ تازه تا ۱۰ دقیقه
        // در منو دیده نمی‌شد و مدیر فکر می‌کرد همگام‌سازی کار نکرده.
        \App\Services\SiteMenu::forget();
        \App\Services\Cloud\CloudCountry::forget();

        $costAlert = $this->alertOnCostIncrease();

        if ($costAlert !== null) {
            $report['__cost'] = $costAlert;
        }

        $warning = $this->crossProviderSanity();

        if ($warning !== null) {
            $report['__sanity'] = $warning;
        }

        return [
            'ok'        => $report !== [] && collect($report)->contains('ok', true),
            'providers' => $report,
            'rate'      => $rate,
        ];
    }

    /** @return array{ok:bool,message:string,locations:int,plans:int,images:int} */
    private function syncOne(string $provider, CloudProvider $driver): array
    {
        $cat = $driver->fetchCatalog();

        if (! ($cat['ok'] ?? false)) {
            return [
                'ok' => false, 'message' => (string) ($cat['message'] ?? 'کاتالوگ خوانده نشد.'),
                'locations' => 0, 'plans' => 0, 'images' => 0,
            ];
        }

        $locations = $this->syncLocations((array) $cat['locations']);
        $images    = $this->syncImages($provider, (array) $cat['images']);
        $plans     = $this->syncPlans($provider, (array) $cat['plans']);

        return [
            // پیامِ توضیحیِ درایور را نگه می‌داریم: «۴۰ محصول خوانده شد ولی هیچ
            // پلنی ساخته نشد چون…». بی‌این، گزارش فقط «۰ پلن» می‌گفت و هیچ
            // سرنخی از علت نمی‌داد.
            'ok' => true, 'message' => (string) ($cat['message'] ?? ''),
            'locations' => $locations, 'plans' => $plans, 'images' => $images,
        ];
    }

    // ───────────────────────── مکان‌ها ─────────────────────────

    private function syncLocations(array $rows): int
    {
        $n = 0;

        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');

            if ($code === '') {
                continue;
            }

            $loc = CloudLocation::firstOrNew(['code' => $code]);

            /*
            | گاردِ ریشه‌ای — ممیزی ۷ (CTO): «بدونِ این، هر ۳۰۱ در importِ بعدی
            | دوباره تولید می‌شود.» کدِ «گروهِ محصول به‌جای شهر» (de-de-dedicated،
            | ru-intel، ws-…) دیگر **هرگز ردیفِ تازه** نمی‌سازد — دو بار این باگ
            | از دو درایورِ مختلف وارد جدول شد و ۲۲+۲۱ صفحهٔ تکراری/خالی ساخت.
            | ردیفِ موجود به‌روز می‌ماند (پلن‌هایش زنده‌اند)؛ فقط تولد ممنوع است.
            */
            if (! $loc->exists && CloudLocation::isLegacyCode($code)) {
                \App\Support\ErrorTracker::noteOnce(
                    'cloud',
                    'سینکِ کاتالوگ کدِ مکانِ نامعتبر داد و رد شد: '.$code.' (گروهِ محصول به‌جای شهر — ممیزی ۷)',
                    86400,
                    ['code' => $code]
                );

                continue;
            }

            // ستون‌های واقعی همیشه تازه می‌شوند…
            $loc->country = (string) ($r['country'] ?? $loc->country ?? '');
            $loc->city = $r['city'] ?? $loc->city;
            $loc->latitude = $r['latitude'] ?? $loc->latitude;
            $loc->longitude = $r['longitude'] ?? $loc->longitude;

            // …ولی برچسبِ دستیِ مدیر و پرچم دست نمی‌خورند
            if (! $loc->exists) {
                $meta = CloudLocation::COUNTRIES[strtoupper((string) $loc->country)] ?? null;
                $loc->flag = $meta['flag'] ?? null;
                $loc->is_active = true;
                $loc->sort = 0;
            }

            $loc->save();
            $n++;
        }

        return $n;
    }

    // ───────────────────────── ایمیج‌ها ─────────────────────────

    private function syncImages(string $provider, array $rows): int
    {
        $seen = [];
        $n = 0;

        foreach ($rows as $r) {
            $ref = (string) ($r['provider_ref'] ?? '');
            $key = (string) ($r['key'] ?? '');

            if ($ref === '' || $key === '') {
                continue;
            }

            CloudImage::updateOrCreate(
                ['provider' => $provider, 'provider_ref' => $ref],
                [
                    'key'         => $key,
                    'kind'        => (string) ($r['kind'] ?? 'os'),
                    'family'      => $r['family'] ?? null,
                    'version'     => $r['version'] ?? null,
                    'label'       => (string) ($r['label'] ?? $key),
                    'arch'        => (string) ($r['arch'] ?? 'x86'),
                    'min_disk_gb' => (int) ($r['min_disk_gb'] ?? 0),
                    'is_active'   => true,
                ]
            );

            $seen[] = $ref;
            $n++;
        }

        // ایمیجِ برداشته‌شده: غیرفعال، نه حذف (ممکن است سرورِ فعالی رویش باشد)
        if ($seen !== []) {
            CloudImage::where('provider', $provider)
                ->whereNotIn('provider_ref', $seen)
                ->update(['is_active' => false]);
        }

        return $n;
    }

    // ───────────────────────── پلن‌ها ─────────────────────────

    private function syncPlans(string $provider, array $rows): int
    {
        $seen = [];
        $n = 0;

        foreach ($rows as $r) {
            $ref = (string) ($r['provider_ref'] ?? '');
            $code = (string) ($r['location_code'] ?? '');
            $cost = (int) ($r['cost_eur_cents'] ?? 0);

            if ($ref === '' || $code === '' || $cost <= 0) {
                continue;
            }

            $vcpu = max(1, (int) ($r['vcpu'] ?? 1));
            $ram = max(128, (int) ($r['ram_mb'] ?? 1024));
            $disk = max(1, (int) ($r['disk_gb'] ?? 10));
            $cpuKind = ($r['cpu_kind'] ?? 'shared') === 'dedicated' ? 'dedicated' : 'shared';

            $price = $this->pricing->priceFor($cost);

            // ── ردگیریِ تغییرِ بها ──
            // ⚠️ چرا لازم است: قیمتِ فروشِ سرویس‌های فعال سرِ سفارش قفل شده و
            // خودکار تمدید می‌شود. اگر زیرساخت بها را بالا ببرد و ما نفهمیم،
            // هر تمدید ضررِ خالص است و هیچ‌جا صدا در نمی‌آورد.
            $existing = CloudPlan::query()
                ->where('provider', $provider)
                ->where('provider_ref', $ref)
                ->where('location_code', $code)
                ->first(['id', 'cost_eur_cents']);

            $costTrack = [];

            if ($existing !== null && (int) $existing->cost_eur_cents !== $cost
                && (int) $existing->cost_eur_cents > 0) {
                $costTrack = [
                    'previous_cost_eur_cents' => (int) $existing->cost_eur_cents,
                    'cost_changed_at'         => now(),
                ];
            }

            $gpuModel = filled($r['gpu_model'] ?? null) ? (string) $r['gpu_model'] : null;
            $gpuCount = $gpuModel !== null ? max(1, (int) ($r['gpu_count'] ?? 1)) : null;
            // برمتال (سرورِ فیزیکی) — باید به نام و اسلاگ برسد وگرنه با VPSِ
            // هسته‌اختصاصیِ هم‌مشخصات یک گروه می‌شود (توضیح در CloudNaming).
            $metal = (bool) ($r['metal'] ?? false);

            CloudPlan::updateOrCreate(
                ['provider' => $provider, 'provider_ref' => $ref, 'location_code' => $code],
                [
                    'provider_location' => $r['provider_location'] ?? null,
                    'public_name'       => CloudNaming::planName($vcpu, $ram, $cpuKind, $metal),
                    /*
                    | 🔴 GPU **باید** به اسلاگ برسد.
                    |
                    | اسلاگ کلیدِ گروه‌بندیِ `offers()` است و `bestForSlug()`
                    | ارزان‌ترینِ گروه را برمی‌دارد. بی‌این آرگومان، یک RTX 3060
                    | و یک H100 با vCPU/رم/دیسکِ یکسان اسلاگِ یکسان می‌گیرند و
                    | مشتری پولِ گران را می‌دهد و ارزان را تحویل می‌گیرد.
                    |
                    | ⚠️ افزودنِ پارامتر به `planSlug()` به‌تنهایی کافی نبود —
                    | تا وقتی **این فراخوان** آن را پاس ندهد، گارد هرگز اجرا
                    | نمی‌شود. همان تلهٔ ثبت‌شده: تستی که خودش تابع را مستقیم
                    | صدا می‌زند، سیم‌کشی را نمی‌سنجد.
                    */
                    'slug'              => CloudNaming::planSlug($vcpu, $ram, $disk, $code, $cpuKind, $gpuModel, $gpuCount, $metal),
                    'vcpu'              => $vcpu,
                    'ram_mb'            => $ram,
                    'disk_gb'           => $disk,
                    'disk_type'         => (string) ($r['disk_type'] ?? 'nvme'),
                    'traffic_gb'        => max(0, (int) ($r['traffic_gb'] ?? 0)),
                    'cpu_kind'          => $cpuKind,
                    'arch'              => (string) ($r['arch'] ?? 'x86'),
                    'cost_eur_cents'    => $cost,
                    'price_eur_cents'   => $price['eur_cents'],
                    'price_irt'         => $price['irt'],
                    'is_active'         => true,
                    'in_stock'          => (bool) ($r['in_stock'] ?? true),
                    // مرتب‌سازیِ طبیعی: کوچک به بزرگ. ۱۰۰۰ ضربِ ثابت است تا
                    // پلنِ ۸هسته/۱۶گیگ بعد از ۸هسته/۸گیگ بیاید.
                    'sort'              => min(65535, $vcpu * 1000 + (int) ($ram / 1024)),
                    'synced_at'         => now(),
                    // ستون‌های GPU — برای پلنِ بی‌کارت `null` می‌مانند، و
                    // `null` این‌جا یعنی «کارت ندارد» نه «نمی‌دانیم».
                    'gpu_model'         => $gpuModel,
                    'gpu_count'         => $gpuCount,
                    'gpu_vram_mb'       => ($v = (int) ($r['gpu_vram_mb'] ?? 0)) > 0 ? $v : null,
                    'is_interruptible'  => (bool) ($r['is_interruptible'] ?? false),
                    // ⚠️ `admin_disabled` و `admin_note` عمداً این‌جا **نیستند**.
                    // اگر بودند، هر اجرای کرون تصمیمِ مدیر را پاک می‌کرد و پکیجِ
                    // عمداً بسته، دو روز بعد خودش باز می‌شد.
                ] + $costTrack
                // بهایِ ساعتیِ واقعیِ زیرساخت (درسِ sn-svc-76) — فقط اگر ستونش
                // ساخته شده باشد، تا syncِ پیش از مهاجرت نشکند.
                + (\Illuminate\Support\Facades\Schema::hasColumn('cloud_plans', 'cost_hour_eur_micro')
                    ? ['cost_hour_eur_micro' => (int) ($r['cost_hour_eur_micro'] ?? 0) > 0
                        ? (int) $r['cost_hour_eur_micro'] : null]
                    : [])
            );

            $seen[] = $ref.'@'.$code;
            $n++;
        }

        // پلنی که این بار نیامد = برداشته شده. غیرفعال می‌شود تا از فروشگاه
        // بیرون برود، ولی ردیفش می‌ماند چون سرویسِ فعالِ مشتری به آن اشاره دارد.
        if ($seen !== []) {
            CloudPlan::where('provider', $provider)
                ->get(['id', 'provider_ref', 'location_code', 'is_active'])
                ->each(function (CloudPlan $p) use ($seen) {
                    if (! in_array($p->provider_ref.'@'.$p->location_code, $seen, true) && $p->is_active) {
                        $p->update(['is_active' => false]);
                    }
                });
        }

        return $n;
    }

    /**
     * هشدارِ گران‌شدنِ بهایِ تمام‌شده — محافظِ قیمتِ تمدید.
     *
     * ═══ چرا این مهم‌ترین هشدارِ این حوزه است ═══
     *
     * قیمتِ فروشِ مشتری سرِ سفارش **قفل** می‌شود و سرویس خودکار تمدید می‌شود.
     * اگر زیرساخت بها را بالا ببرد، ما همان قیمتِ قدیم را فاکتور می‌کنیم و از آن
     * لحظه هر تمدید **ضررِ خالص** است — ماه‌به‌ماه، بی‌صدا، چون سرور کار می‌کند و
     * مشتری راضی است و هیچ خطایی تولید نمی‌شود.
     *
     * پس دو چیز گزارش می‌شود: کدام پلن‌ها گران شدند، و **چند سرویسِ فعال** روی
     * آنها نشسته است. عددِ دوم است که فوریت را می‌سازد: «۳ پلن گران شد» یعنی
     * چیزی؛ «۳ پلن گران شد و ۴۱ سرویسِ فعال رویشان است» یعنی همین امروز.
     *
     * عمداً قیمتِ فروش را **خودکار بالا نمی‌بریم**: بالا بردنِ قیمتِ سرویسِ فعالِ
     * مشتری بی‌اطلاعِ او، تصمیمی تجاری و حقوقی است نه فنی. مدیر تصمیم می‌گیرد.
     */
    private function alertOnCostIncrease(): ?string
    {
        if (! Schema::hasTable('cloud_plans')) {
            return null;
        }

        $risen = CloudPlan::query()
            ->whereNotNull('previous_cost_eur_cents')
            ->whereColumn('cost_eur_cents', '>', 'previous_cost_eur_cents')
            ->where('cost_changed_at', '>=', now()->subMinutes(30))
            ->get();

        if ($risen->isEmpty()) {
            return null;
        }

        // چند سرویسِ فعال روی این پلن‌ها نشسته؟ همین عدد فوریت را می‌سازد.
        $exposed = 0;

        if (Schema::hasTable('services') && Schema::hasColumn('services', 'cloud_plan_id')) {
            $exposed = \App\Models\Service::query()
                ->whereIn('cloud_plan_id', $risen->pluck('id'))
                ->whereIn('status', ['active', 'awaiting_provision'])
                ->count();
        }

        $worst = $risen->sortByDesc(fn (CloudPlan $p) => $p->costChangePct())->first();

        $message = sprintf(
            '🔴 بهایِ %s پلن گران شد (بیشترین: %s٪ روی %s). %s سرویسِ فعال روی این پلن‌هاست '
            .'و با قیمتِ قفل‌شدهٔ قدیم تمدید می‌شود — یعنی از تمدیدِ بعد ضرر. '
            .'در /admin/cloud ببینید و تصمیم بگیرید.',
            fa_num((string) $risen->count()),
            fa_num((string) $worst->costChangePct()),
            (string) $worst->public_name,
            fa_num((string) $exposed)
        );

        try {
            app(\App\Services\Notify\AdminNotifier::class)->event(
                'بهایِ زیرساخت گران شد',
                [
                    'پلن‌های گران‌شده' => fa_num((string) $risen->count()),
                    'سرویسِ در معرض'   => fa_num((string) $exposed),
                    'بیشترین افزایش'   => fa_num((string) $worst->costChangePct()).'٪ · '.$worst->public_name,
                ],
                url('/admin/cloud'),
                '🔴'
            );
        } catch (\Throwable) {
            // اعلان نباید همگام‌سازی را بشکند
        }

        return $message;
    }

    /**
     * راستی‌آزماییِ متقابلِ زیرساخت‌ها — دامِ خودکارِ «واحدِ قیمت اشتباه».
     *
     * ═══ چرا لازم شد ═══
     * قیمت‌های زیرساختِ ۲ یک بار **۱۰۰ برابر ارزان** خوانده شدند (عددِ روبل
     * به‌جای کوپک تفسیر شد) و هیچ‌چیز در سیستم صدا در نیاورد. کارفرما با چشم
     * دید که «خیلی ارزان افتاده‌اند». یک اشتباهِ واحد که فقط با چشمِ انسان دیده
     * شود، دیر یا زود از دست می‌رود.
     *
     * منطق: بهایِ تمام‌شدهٔ هر گیگابایتِ رم را در دو زیرساخت مقایسه کن. سرورِ
     * مجازی کالای کم‌وبیش یکسانی است؛ اختلافِ ۲۰–۳۰ درصد طبیعی است، اختلافِ
     * چند برابر یعنی چیزی در نگاشتِ واحد شکسته.
     *
     * عمداً **هشدار** است و نه توقف: ممکن است واقعاً ارزان‌تر باشند. ولی مدیر
     * باید ببیندش و یک‌بار با فاکتورِ خودش بسنجد.
     */
    private function crossProviderSanity(): ?string
    {
        if (! Schema::hasTable('cloud_plans')) {
            return null;
        }

        $perGb = [];

        foreach (array_keys(CloudManager::DRIVERS) as $provider) {
            $rows = CloudPlan::query()
                ->where('provider', $provider)
                ->where('is_active', true)
                ->where('cost_eur_cents', '>', 0)
                ->where('ram_mb', '>', 0)
                ->get(['cost_eur_cents', 'ram_mb']);

            if ($rows->count() < 3) {
                continue;                  // نمونهٔ کم، مقایسه بی‌معنا
            }

            // میانه و نه میانگین: یک پلنِ عجیب میانگین را می‌برد
            $values = $rows->map(fn ($r) => $r->cost_eur_cents / ($r->ram_mb / 1024))
                ->sort()->values();

            $perGb[$provider] = (float) $values[intdiv($values->count(), 2)];
        }

        if (count($perGb) < 2) {
            return null;
        }

        $min = min($perGb);
        $max = max($perGb);

        if ($min <= 0 || $max / $min < 4) {
            return null;                   // اختلافِ طبیعیِ بازار
        }

        $cheapest = array_search($min, $perGb, true);
        $label = app(CloudManager::class)->label((string) $cheapest);

        return sprintf(
            '⚠️ %s حدودِ %s برابر ارزان‌تر از دیگری درآمده (بهایِ هر گیگ رم). '
            .'احتمالِ قوی: واحدِ عددِ قیمت اشتباه خوانده شده. در تنظیمات «واحدِ '
            .'عددِ قیمت» را عوض کنید و قیمتِ یک پلن را با فاکتورِ خودتان بسنجید.',
            $label,
            fa_num((string) round($max / $min))
        );
    }

    /**
     * بازمحاسبهٔ قیمت بی‌تماس با API — برای وقتی نرخِ یورو عوض شده ولی
     * بهایِ تمام‌شده همان است. سریع و بی‌هزینه.
     */
    public function reprice(): int
    {
        if (! Schema::hasTable('cloud_plans')) {
            return 0;
        }

        /*
        | 🔴 بدونِ نرخِ ارز، **هیچ ردیفی نوشته نمی‌شود**.
        |
        | `CloudPricing::toman()` وقتی نرخ در دسترس نباشد `0` می‌دهد، و این
        | حلقه بی‌هیچ سنجشی همان صفر را روی `price_irt`ِ **همهٔ** پلن‌ها
        | می‌نوشت. `scopeSellable` شرطِ `price_irt > 0` دارد ⇒ کلِ کاتالوگِ ابری
        | از فروشگاه و از صفحاتِ کشور غیب می‌شد، با کدِ ۲۰۰ و بی‌هیچ استثنایی،
        | تا اجرای موفقِ بعدی — یعنی تا ۲۴ ساعت.
        |
        | یک تایم‌اوتِ ساده به سرویسِ نرخ کافی بود. حالا قیمتِ **کهنه** می‌مانَد،
        | که بی‌نهایت بهتر از قیمتِ صفر است: کهنه یعنی چند درصد خطا، صفر یعنی
        | محصول اصلاً وجود ندارد.
        */
        if ($this->pricing->eurToToman() <= 0) {
            \App\Support\ErrorTracker::note('pricing',
                'بازمحاسبهٔ قیمت لغو شد: نرخِ یورو در دسترس نیست. قیمتِ قبلی دست‌نخورده ماند.');

            return 0;
        }

        $n = 0;

        CloudPlan::query()->where('cost_eur_cents', '>', 0)->chunkById(200, function ($plans) use (&$n) {
            foreach ($plans as $plan) {
                $price = $this->pricing->priceFor((int) $plan->cost_eur_cents);

                if ($price['eur_cents'] !== (int) $plan->price_eur_cents || $price['irt'] !== (int) $plan->price_irt) {
                    $plan->update([
                        'price_eur_cents' => $price['eur_cents'],
                        'price_irt'       => $price['irt'],
                    ]);
                    $n++;
                }
            }
        });

        return $n;
    }
}
