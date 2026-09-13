<?php

namespace App\Services\Provisioning;

use App\Models\Setting;
use App\Services\Cloud\CloudPricing;
use App\Support\ErrorTracker;

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

        $net = $this->types()[$type] ?? null;

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
