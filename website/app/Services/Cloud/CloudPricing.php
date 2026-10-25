<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use App\Services\ExchangeRate;

/**
 * قیمت‌گذاریِ سرورِ ابری — از بهایِ تمام‌شدهٔ یورویی به قیمتِ فروشِ تومانی.
 *
 * محصولات ابری را به ارز خارجی می‌خریم و زنجیرهٔ قیمت چنین است:
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
    public function marginPct(?string $provider = null): float
    {
        if ($provider === 'ovh' && is_numeric(Setting::get('cloud_margin_pct_ovh'))) {
            return max(0, min(500, (float) Setting::get('cloud_margin_pct_ovh')));
        }
        if ($provider === 'salad') {
            $gpu = Setting::get('cloud_margin_pct_salad');
            if ($gpu !== null && $gpu !== '') {
                return max(0, min(500, (float) $gpu));
            }
        }
        $v = Setting::get('cloud_margin_pct');

        return $v === null || $v === '' ? (float) self::DEFAULT_MARGIN_PCT : max(0, (float) $v);
    }

    /** حاشیهٔ مستقل محصولات ذخیره‌سازی؛ خالی باشد از حاشیهٔ عمومی ابری پیروی می‌کند. */
    public function storageMarginPct(): float
    {
        $v = Setting::get('cloud_storage_margin_pct');

        return $v === null || trim((string) $v) === ''
            ? $this->marginPct('hetzner')
            : max(0, min(500, (float) $v));
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
            $v = Setting::get('pricing_fx_fee_pct_'.strtolower(trim($provider)));

            if ($v !== null && trim((string) $v) !== '' && is_numeric($v)) {
                return max(0.0, min(25.0, (float) $v));
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
    public function sellEurCents(int $costEurCents, ?string $provider = null): int
    {
        if ($costEurCents <= 0) {
            return 0;
        }

        $withMargin = $costEurCents * (1 + $this->marginPct($provider) / 100);

        return (int) (ceil($withMargin / 10) * 10);
    }

    /** نرخ یک یورو به تومان، فقط از رکورد معتبر و ذخیره‌شده نوسان. */
    public function eurToToman(): int
    {
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
    public function priceFor(int $costEurCents, ?string $provider = null): array
    {
        $eur = $this->sellEurCents($costEurCents, $provider);

        return ['eur_cents' => $eur, 'irt' => $this->toman($eur)];
    }
}
