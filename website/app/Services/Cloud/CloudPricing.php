<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use App\Services\ExchangeRate;

/**
 * قیمت‌گذاریِ سرورِ ابری — از بهایِ تمام‌شدهٔ یورویی به قیمتِ فروشِ تومانی.
 *
 * ⚠️ چرا این کلاس با `price_toman()` سایت فرق دارد:
 * قیمتِ هاست‌های اشتراکی «لنگرِ تومانی» دارد و ضریبِ یورو فقط بالا/پایینش می‌برد.
 * ولی سرورِ ابری را ما **به یورو می‌خریم**؛ پس زنجیره برعکس است:
 *
 *     بهایِ تمام‌شده (یورو) → + حاشیهٔ سود → قیمتِ فروشِ یورویی
 *                                          → × نرخِ روزِ یورو → تومان
 *
 * اگر لنگرِ تومانی می‌گذاشتیم، با هر جهشِ نرخِ ارز **زیر قیمتِ خرید** می‌فروختیم.
 *
 * قاعدهٔ گردکردن: همیشه **رو به بالا**. گردکردنِ پایین یعنی تخفیفِ ناخواسته و
 * روی سرورِ ابری که حاشیهٔ سودش نازک است، می‌تواند سود را صفر کند.
 */
class CloudPricing
{
    public const DEFAULT_MARGIN_PCT = 45;

    /**
     * 🔴 کفِ سختِ حاشیه — خطِ قرمزِ کارفرما: «هیچ‌چیز مفت/زیرِ بها نفروشیم».
     *
     * روی پروداکشن `cloud_margin_pct` مقدارِ ۲ داشت و کلِ خطِ ابری عملاً به
     * بهایِ خرید فروخته می‌شد (sn-svc-72: فروش €0.0100/h روی بهایِ €0.0095/h)
     * — بی‌هیچ خطا و هشداری. حاشیهٔ زیرِ این کف تقریباً همیشه غلطِ تایپی یا
     * آزمایشِ فراموش‌شده است، نه تصمیم؛ و غلطش مستقیم پول می‌سوزاند (همان
     * قاعدهٔ ثبت‌شدهٔ aeza_price_divisor: تنظیمی که فقط بازهٔ معقول دارد،
     * بیرونِ بازه باید مهار شود). فرمِ تنظیمات هم min=10 گرفت.
     */
    public const MIN_MARGIN_PCT = 10;

    /** حاشیهٔ سودِ سرورِ ابری (درصد) — جدا از `price_margin_pct` هاست */
    public function marginPct(): float
    {
        $v = Setting::get('cloud_margin_pct');

        $pct = $v === null || $v === '' ? (float) self::DEFAULT_MARGIN_PCT : max(0, (float) $v);

        return max($pct, (float) self::MIN_MARGIN_PCT);
    }

    /** قیمتِ فروشِ یورویی به سنت — گردشده رو به بالا به ۱۰ سنت */
    public function sellEurCents(int $costEurCents): int
    {
        if ($costEurCents <= 0) {
            return 0;
        }

        $withMargin = $costEurCents * (1 + $this->marginPct() / 100);

        return (int) (ceil($withMargin / 10) * 10);
    }

    /**
     * نرخِ یک یورو به تومان.
     *
     * اولویت: نرخِ دستیِ مدیر (`pricing_rate_override`) ← نرخِ زنده ← ۰.
     * صفر یعنی «نمی‌دانیم»؛ در آن حالت قیمتِ تومانی **ساخته نمی‌شود** تا عددِ
     * غلط روی سایت نرود.
     */
    public function eurToToman(): int
    {
        $override = (int) Setting::get('pricing_rate_override', '0');

        if ($override > 0) {
            return $override;
        }

        try {
            return (int) (app(ExchangeRate::class)->toToman('EUR') ?: 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** قیمتِ تومانی از سنتِ یورو — گردشده رو به بالا به ۱۰٬۰۰۰ تومان */
    public function toman(int $sellEurCents, int $step = 10000): int
    {
        $rate = $this->eurToToman();

        if ($rate <= 0 || $sellEurCents <= 0) {
            return 0;
        }

        $toman = ($sellEurCents / 100) * $rate;

        return (int) (ceil($toman / $step) * $step);
    }

    /**
     * هر دو قیمت با هم.
     *
     * @return array{eur_cents:int,irt:int}
     */
    public function priceFor(int $costEurCents): array
    {
        $eur = $this->sellEurCents($costEurCents);

        return ['eur_cents' => $eur, 'irt' => $this->toman($eur)];
    }
}
