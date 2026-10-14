<?php

namespace App\Services\Provisioning;

use App\Models\Setting;
use App\Models\Product;
use App\Services\Cloud\CloudPricing;
use App\Support\ErrorTracker;
use Carbon\Carbon;

/**
 * بهای تمام‌شدهٔ فضای Storage Box، به تومان — منبعِ کفِ قیمت.
 *
 * 🔴 چرا لازم شد: پلن‌های بکاپ روی دورهٔ **سالانه** زیرِ قیمتِ خرید فروخته
 * می‌شدند. تخفیفِ ۲۰٪ دوره از کلِ حاشیه بزرگ‌تر بود، و چون تحویل موفق می‌شد
 * هیچ خطایی هیچ‌جا ثبت نمی‌شد: ضرر فقط در صورت‌حسابِ ماهانهٔ هتزنر پیدا می‌شد.
 *
 * اصلاحِ سه عدد کافی نبود — نرخِ ارز حرکت می‌کند، درصدِ تخفیف قابلِ ویرایش
 * است، و پلنِ تازه هم اضافه می‌شود. پس کف باید **محاسبه** شود نه نوشته.
 *
 * زنجیره:
 *   plan (sn_backup_3) → نگاشتِ config → نوعِ هتزنر (bx11)
 *     → بهای خامِ یورویی (کشِ کاتالوگ) → +سربارِ ارزی → × نرخِ روز → تومان
 *
 * ⚠️ کش را `hetzner:storage-catalog` پر می‌کند. عمداً کش است و نه تماسِ زنده:
 * این متد در مسیرِ نمایشِ قیمت صدا زده می‌شود و یک تماسِ API به‌ازای هر بازدید
 * هم کند است هم سهمیه‌سوز.
 */
class HetznerStorageCosts
{
    public const SETTING = 'hetzner_storage_costs';
    public const CATALOG_SETTING = 'hetzner_storage_catalog';

    /** A complete, successful upstream snapshot only; never customer credentials. */
    public static function rememberCatalog(array $plans, int $serverId): void
    {
        Setting::put(self::CATALOG_SETTING, json_encode([
            'at' => now()->toIso8601String(), 'server_id' => $serverId,
            'currency' => 'EUR', 'plans' => $plans,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function metadataForPlan(string $plan): ?array
    {
        $data = json_decode((string) Setting::get(self::CATALOG_SETTING), true);
        $entry = $data['plans'][$plan] ?? null;
        if (! is_array($entry)) return null;
        return $entry + [
            'server_id' => $data['server_id'] ?? null, 'at' => $data['at'] ?? null,
            'currency' => $data['currency'] ?? null,
        ];
    }

    public function isCatalogPlan(string $plan): bool
    {
        return str_starts_with($plan, 'sn_storage_box_');
    }

    public function configurationError(Product $product): ?string
    {
        if (! $this->isCatalogPlan((string) $product->plan) && $product->group !== 'storage-box') return null;
        $meta = $this->metadataForPlan((string) $product->plan);
        if (! $meta || ! $this->isFresh($meta)) return 'کاتالوگ فضای ذخیره‌سازی به‌روز نیست؛ فروش موقتاً متوقف است.';
        if (($meta['currency'] ?? '') !== 'EUR' || (int) ($meta['net_monthly_cents'] ?? 0) <= 0
            || ! isset($meta['net_setup_cents']) || (int) $meta['net_setup_cents'] < 0
            || (int) ($meta['size'] ?? 0) <= 0 || empty($meta['type']) || empty($meta['location'])) {
            return 'مشخصات یا بهای تأییدشده این پلن در دسترس نیست.';
        }
        $server = $product->server;
        if (! $server || $server->type !== 'hetzner_storage' || $server->status !== 'active'
            || (int) $server->id !== (int) ($meta['server_id'] ?? 0)
            || ! (new HetznerStorageClient($server))->isConfigured()) {
            return 'اتصال پروژه تحویل این پلن آماده نیست.';
        }
        if (app(CloudPricing::class)->eurToToman() <= 0) return 'نرخ تبدیل معتبر برای قیمت‌گذاری موجود نیست.';
        return null;
    }

    public function isFresh(array $meta): bool
    {
        try {
            $at = Carbon::parse($meta['at'] ?? '');
            if (! empty($meta['unavailable_after']) && Carbon::parse($meta['unavailable_after'])->lte(now())) return false;
            return ! empty($meta['at']) && $at->lte(now()->addMinutes(5))
                && $at->gte(now()->subSeconds((int) config('provisioning.hetzner_storage.catalog_ttl', 86400)));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Current exchange rate and configured margin, with upstream setup kept separate. */
    public function quoteForPlan(string $plan): ?array
    {
        $meta = $this->metadataForPlan($plan);
        if (! $meta || ! $this->isFresh($meta) || ($meta['currency'] ?? null) !== 'EUR'
            || (int) ($meta['net_monthly_cents'] ?? 0) <= 0 || ! isset($meta['net_setup_cents'])
            || (int) $meta['net_setup_cents'] < 0) return null;
        $monthly = $this->quoteNetCents((int) $meta['net_monthly_cents']);
        $setup = $this->quoteNetCents((int) $meta['net_setup_cents']);
        if (! $monthly || ! $setup) return null;
        return $monthly + ['setup_irt' => $setup['irt'], 'setup_eur_cents' => $setup['eur_cents']];
    }

    public function quoteNetCents(int $net): ?array
    {
        $pricing = app(CloudPricing::class);
        $rate = $pricing->eurToToman();
        if ($rate <= 0 || $net < 0) return null;
        // Remove machine epsilon at integer boundaries before rounding real charges.
        $landed = (int) ceil($pricing->costWithFee($net, 'hetzner') - 0.0000001);
        $margin = max($pricing->storageMarginPct(), $this->minMarginPct());
        $eur = (int) (ceil($landed * (1 + $margin / 100) / 10 - 0.0000001) * 10);
        if ($eur < 0 || $eur > intdiv(PHP_INT_MAX - 999999, $rate)) return null;
        // EUR cents -> IRT, ceil to 10,000, entirely in integers. Float arithmetic
        // otherwise turns exact 110,000 into 120,000 at some exchange rates.
        return ['eur_cents' => $eur, 'irt' => intdiv($eur * $rate + 999999, 1000000) * 10000];
    }

    /**
     * ذخیرهٔ بهای خامِ هر نوع (سنتِ یورو، **بدون** سربار و مالیات).
     *
     * سربار عمداً ذخیره نمی‌شود: درصدش در تنظیمات قابلِ تغییر است و اگر این‌جا
     * پخته شود، تغییرش تا اجرای بعدیِ کاتالوگ بی‌اثر می‌مانَد.
     */
    public static function remember(array $netCentsByType, string $location): void
    {
        Setting::put(self::SETTING, json_encode([
            'location' => $location,
            'at'       => now()->toIso8601String(),
            'types'    => $netCentsByType,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,int> نوع → سنتِ یورو */
    public function types(): array
    {
        $raw = Setting::get(self::SETTING);

        if (blank($raw)) {
            return [];
        }

        $data = json_decode((string) $raw, true);

        return is_array($data['types'] ?? null) ? $data['types'] : [];
    }

    /** نوعِ هتزنرِ متناظر با پلنِ ما — `null` یعنی این پلن اصلاً از هتزنر نیست */
    public function typeFor(string $plan): ?string
    {
        if ($this->isCatalogPlan($plan)) return $this->metadataForPlan($plan)['type'] ?? null;
        $map = (array) config('provisioning.hetzner_storage.plans', []);

        return filled($map[$plan] ?? null) ? (string) $map[$plan] : null;
    }

    /**
     * بهای تمام‌شدهٔ **ماهانه** به تومان.
     *
     * `null` یعنی «نمی‌دانیم» و هرگز نباید «رایگان» خوانده شود — فراخوان در آن
     * حالت کف نمی‌گذارد ولی فریاد ثبت می‌شود. بستنِ فروش هم جواب نبود: یک کشِ
     * خالی نباید محصولِ سالم را بی‌صدا از فروشگاه بیرون بگذارد.
     */
    public function monthlyToman(string $plan): ?int
    {
        $type = $this->typeFor($plan);

        if ($type === null) {
            return null;   // پلنِ غیرِ هتزنر — به این کف ربطی ندارد
        }

        $meta = $this->metadataForPlan($plan);
        if ($this->isCatalogPlan($plan) && (! $meta || ! $this->isFresh($meta))) return null;
        $net = $meta ? ($meta['net_monthly_cents'] ?? null) : ($this->types()[$type] ?? null);

        if ($net === null || (int) $net <= 0) {
            ErrorTracker::noteOnce('pricing',
                'بهای تمام‌شدهٔ نوعِ «'.$type.'» در کشِ کاتالوگ نیست — کفِ قیمت برای پلنِ '
                .$plan.' اعمال نشد. یک بار `hetzner:storage-catalog` را بزنید.', 3600);

            return null;
        }

        $pricing = app(CloudPricing::class);
        $rate = $pricing->eurToToman();

        if ($rate <= 0) {
            return null;   // نرخِ ارز نداریم؛ همان قاعدهٔ scopeSellable سرورِ ابری
        }

        $landedEur = $pricing->costWithFee((int) $net, 'hetzner') / 100;

        return (int) ceil($landedEur * $rate);
    }

    /** حداقلِ حاشیهٔ سود (درصد) — خواستهٔ کارفرما: هرگز زیرِ این نفروش */
    public function minMarginPct(): float
    {
        $v = config('provisioning.hetzner_storage.min_margin_pct');

        return $v === null ? 5.0 : max(0.0, (float) $v);
    }

    /**
     * کفِ قیمتِ فروش برای یک دوره — صفر یعنی «کفی نداریم» نه «رایگان».
     *
     * ⚠️ گردکردن رو به **بالا** است. رو به پایین یعنی کف خودش یک تخفیفِ
     * کوچکِ ناخواسته بسازد، که دقیقاً همان چیزی است که قرار بود جلویش را بگیرد.
     */
    public function floorToman(string $plan, int $months): int
    {
        if ($months <= 0) {
            return 0;
        }

        $monthly = $this->monthlyToman($plan);

        if ($monthly === null) {
            return 0;
        }

        return (int) ceil($monthly * $months * (1 + $this->minMarginPct() / 100));
    }
}
