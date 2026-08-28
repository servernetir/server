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
     * حاشیهٔ سودِ سرورِ ابری (درصد) — جدا از `price_margin_pct` هاست.
     *
     * ⚠️ عمداً **هیچ کفی ندارد** (تصمیمِ صریحِ کارفرما، ۶ شهریور: «حاشیهٔ سود
     * من همان عددی است که در تنظیمات می‌نویسم»). یک کفِ سختِ ۱۰٪ یک روز این‌جا
     * گذاشته شد و همان روز برداشته شد — قیمت‌گذاری ابزارِ رقابت اوست. محافظِ
     * ضدِ ضرر جای دیگری است: `fxFeePct()` سربارِ واقعیِ رساندنِ پول را واردِ
     * بها می‌کند تا حاشیهٔ کوچک روی بهایِ «واقعی» بنشیند، نه بهایِ خوش‌بینانه.
     */
    public function marginPct(): float
    {
        $v = Setting::get('cloud_margin_pct');

        return $v === null || $v === '' ? (float) self::DEFAULT_MARGIN_PCT : max(0, (float) $v);
    }

    /**
     * 🔴 سربارِ رساندنِ پول به زیرساخت (٪) — `pricing_fx_fee_pct`.
     *
     * ریشهٔ «۲٪ سود ولی در عمل ضرر» (sn-svc-72): بهایِ ذخیره‌شده، نرخِ اسمیِ
     * زیرساخت بود؛ ولی یورویی که واقعاً به حسابشان می‌رسد گران‌تر تمام می‌شود
     * (کارمزدِ حواله، اسپردِ صرافی، و VAT اگر روی صورت‌حساب باشد). این سربار
     * تا امروز فقط در درایورِ GPU حساب می‌شد — حالا منبعِ یگانه این‌جاست و هر
     * سه درایور آن را روی بهای ماهانه **و ساعتی** می‌نشانند، پس حاشیهٔ کوچکِ
     * مدیر هم سودِ واقعی است. پیش‌فرض صفر — عددِ حدسی ممنوع (قاعدهٔ ثبت‌شده).
     */
    public function fxFeePct(): float
    {
        $v = (float) Setting::get('pricing_fx_fee_pct', '');

        return $v > 0 ? min(25.0, $v) : 0.0;
    }

    /**
     * سربار به تفکیکِ **زیرساخت** — چون واقعیتِ مالی‌شان یکی نیست (تصمیمِ
     * کارفرما، ۶ شهریور): هتزنر روی صورت‌حساب VAT آلمان (۱۹٪) می‌زند ولی
     * قیمتِ aeza نهایی است؛ یک عددِ مشترک یا هتزنر را ضررده می‌کرد یا aeza
     * را گران و غیررقابتی. کلید: `pricing_fx_fee_pct_{slug}`؛ خالی = عددِ
     * عمومیِ `pricing_fx_fee_pct` (پشتیبان، نه جمع).
     */
    public function fxFeePctFor(?string $provider): float
    {
        if (filled($provider)) {
            $v = (float) Setting::get('pricing_fx_fee_pct_'.strtolower(trim($provider)), '');

            if ($v > 0) {
                return min(25.0, $v);
            }
        }

        return $this->fxFeePct();
    }

    /** ضریبِ سربار — برای درایورها که بها را با آن ذخیره می‌کنند */
    public function costWithFee(float $eur, ?string $provider = null): float
    {
        return $eur * (1 + $this->fxFeePctFor($provider) / 100);
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
