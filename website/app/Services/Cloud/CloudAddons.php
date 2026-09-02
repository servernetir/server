<?php

namespace App\Services\Cloud;

use App\Models\CloudPlan;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;

/**
 * افزودنی‌های سرورِ ابری — **تنها منبعِ حقیقتِ** قیمتشان.
 *
 * چرا سرویسِ جدا و نه چند عدد پخش‌شده در کنترلر و ویو: قیمتِ افزودنی در چهار جا
 * لازم می‌شود (کارتِ سرورساز، خلاصهٔ سفارش، فاکتور، فاکتورِ تمدید). قبلاً در این
 * پروژه همین پخش‌شدگی باعث شد سایت ۲۰٪ تخفیف تبلیغ کند و تسویه ۱۵٪ بگیرد. پس
 * قیمت **یک جا** حساب می‌شود و بقیه صدایش می‌زنند.
 *
 * ═══ کلیدِ SSH پول نمی‌گیرد ═══
 * یک کلیدِ عمومی هیچ هزینه‌ای برای ما ندارد — نه اجاره، نه سهمیه. رایگان است و
 * عمداً هم رایگان می‌مانَد، چون ورودِ کلیدی از ورودِ رمزی امن‌تر است و نباید
 * برای امنیت از مشتری پول گرفت.
 *
 * ═══ IP اضافه پول می‌گیرد ═══
 * هر IPv4 اضافه یک اجارهٔ ماهانهٔ واقعی نزدِ زیرساخت است. قیمتِ فروشش با همان
 * زنجیرهٔ پلن‌ها ساخته می‌شود (بها → حاشیهٔ سود → نرخِ روزِ یورو → تومان، گردشده
 * **رو به بالا**) تا هیچ‌وقت زیرِ بهای تمام‌شده نفروشیم.
 */
class CloudAddons
{
    /** بهایِ پیش‌فرضِ ماهانهٔ یک IPv4 اضافه به سنتِ یورو — محافظه‌کارانه */
    public const DEFAULT_EXTRA_IP_COST = 120;

    /** سقفِ IP اضافه در هر سفارش — بیشتر از این، درخواستِ غیرعادی است */
    public const MAX_EXTRA_IP = 5;

    public function __construct(private CloudPricing $pricing) {}

    /** بهایِ تمام‌شدهٔ ماهانهٔ یک IP اضافه (سنتِ یورو) */
    public function extraIpCostCents(): int
    {
        $v = Setting::get('cloud_extra_ip_eur_cents');

        return $v === null || $v === '' ? self::DEFAULT_EXTRA_IP_COST : max(0, (int) $v);
    }

    /** قیمتِ فروشِ ماهانهٔ یک IP اضافه (تومان، گردشده رو به بالا) */
    public function extraIpMonthlyToman(): int
    {
        return $this->pricing->toman($this->pricing->sellEurCents($this->extraIpCostCents()));
    }

    /**
     * افزودنی‌های پاک‌سازی‌شده از ورودیِ کاربر.
     *
     * ⚠️ هرگز مقدارِ خامِ ورودی ذخیره نمی‌شود: عددِ منفی، اعشاری، رشته یا آرایه
     * همه به عددِ صحیحِ کران‌دار تبدیل می‌شوند. یک `extra_ipv4 = -3` می‌توانست
     * قیمتِ کل را **کم** کند.
     *
     * @return array{extra_ipv4:int}
     */
    public function sanitize(mixed $raw): array
    {
        $in = is_array($raw) ? $raw : [];
        $ip = $in['extra_ipv4'] ?? 0;

        $ip = is_numeric($ip) ? (int) $ip : 0;

        return ['extra_ipv4' => max(0, min(self::MAX_EXTRA_IP, $ip))];
    }

    /** آیا هیچ افزودنیِ پولی‌ای انتخاب شده؟ */
    public function isEmpty(array $addons): bool
    {
        return ($addons['extra_ipv4'] ?? 0) < 1;
    }

    /** جمعِ ماهانهٔ افزودنی‌ها به تومان */
    public function monthlyToman(array $addons): int
    {
        $count = max(0, (int) ($addons['extra_ipv4'] ?? 0));

        return $count * $this->extraIpMonthlyToman();
    }

    /**
     * جمعِ افزودنی‌ها برای یک دورهٔ کامل — با **همان** تخفیفِ دورهٔ پلن.
     *
     * چرا همان تخفیف: اگر پلن سالانه ۱۵٪ تخفیف بخورد و افزودنی نخورد، مشتری در
     * صورت‌حساب دو نرخِ متفاوت می‌بیند و حق دارد بپرسد چرا. یکسان‌بودن هم
     * توضیحش ساده‌تر است هم محاسبه‌اش.
     */
    public function forCycle(array $addons, string $cycle): int
    {
        $months = Service::monthsIn($cycle);

        if ($months <= 0) {
            return 0;
        }

        $discount = (int) (config('billing.cycles.'.$cycle.'.discount_pct') ?? 0);
        $discount = max(0, min(90, $discount));

        $raw = $this->monthlyToman($addons) * $months * (100 - $discount) / 100;

        return $raw > 0 ? Product::roundUpToman($raw) : 0;
    }

    /**
     * سطرهای خوانا برای خلاصهٔ سفارش و فاکتور.
     *
     * @return array<int, array{label:string,qty:int,monthly:int,total:int}>
     */
    public function lines(array $addons, string $cycle): array
    {
        $count = max(0, (int) ($addons['extra_ipv4'] ?? 0));

        if ($count < 1) {
            return [];
        }

        $months = max(1, Service::monthsIn($cycle));
        $discount = max(0, min(90, (int) (config('billing.cycles.'.$cycle.'.discount_pct') ?? 0)));
        $unit = $this->extraIpMonthlyToman();

        return [[
            'label'   => 'IP اضافه (IPv4)',
            'qty'     => $count,
            'monthly' => $unit,
            'total'   => Product::roundUpToman($unit * $count * $months * (100 - $discount) / 100),
        ]];
    }

    /**
     * آیا این پلن می‌تواند افزودنی‌های خواسته‌شده را تحویل دهد؟
     *
     * ⚠️ ضروری است: عرضه‌ها روی چند زیرساخت گروه می‌شوند، ولی همهٔ زیرساخت‌ها
     * IP اضافه نمی‌دهند. اگر نسنجیم، مشتری IP می‌خرد و تحویل روی زیرساختی
     * می‌افتد که نمی‌تواند بدهد — پولِ گرفته‌شده و وعدهٔ انجام‌نشده.
     */
    public function planSupports(CloudPlan $plan, array $addons, CloudManager $manager): bool
    {
        if ($this->isEmpty($addons)) {
            return true;
        }

        return (bool) ($manager->capabilitiesForPlan($plan)['extra_ip'] ?? false);
    }

    /**
     * ارزان‌ترین پلنِ هم‌اسلاگ که افزودنی‌ها را هم می‌تواند تحویل دهد.
     *
     * همان الگوی «پلنی که سیستم‌عاملِ انتخابی را دارد» — انتخابِ دیرهنگام تا
     * مشتری هیچ تفاوتی نبیند.
     */
    public function bestPlanFor(string $slug, array $addons, CloudManager $manager, bool $hourly = false): ?CloudPlan
    {
        // ⚠️ `$hourly` این‌جا هم لازم است، نه فقط در `bestForSlug`: این متد
        //    مسیرِ **اول** انتخابِ دیرهنگام است و اگر قید را نداند، سرویسِ
        //    ساعتی را روی ردیفی می‌نشاند که زیرساخت ساعتی نمی‌فروشدش.
        $rows = CloudPlan::query()->sellable()
            ->when($hourly, fn ($q) => $q->hourlyCapable())
            ->where('slug', $slug)->orderBy('cost_eur_cents')->get();

        foreach ($rows as $plan) {
            if ($this->planSupports($plan, $addons, $manager)) {
                return $plan;
            }
        }

        return null;
    }
}
