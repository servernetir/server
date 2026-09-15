<?php

namespace App\Support;

/**
 * حسابِ دقیقِ میکرو-واحد — فقط صحیح، هیچ float.
 *
 * قیمت‌های AI زیرِ یک سنتِ توکن‌اند؛ هر مسیرِ محاسبه که float ببیند،
 * گردِ فقط-به-بالا می‌شکند. این کلاس یکتای مسیرِ تقسیمِ گردیده در کلِ
 * دروازهٔ AI است.
 *
 * قراردادِ گرد: همیشه **بُر به بالا** (قرارِ سرورنت در قیمت‌گذاری) —
 * شرکتی هیچ سطری را زیرِ بها نمی‌فروشد؛ باقی‌ماندهٔ خردِ هر درخواست به
 * کفِ خودش گرد می‌شود نه به‌روزرسانیِ باقی‌مانده.
 */
final class MicroMath
{
    /**
     * تقسیمِ صحیحِ گرد به بالا. ورودی‌های مثبت — قراردادِ دروازه: مبالغِ
     * منفی مسیرِ دیگری ندارند (متری کسرشده) و عمداً بسته می‌شوند.
     */
    public static function ceilDiv(int $numerator, int $divisor): int
    {
        if ($divisor <= 0) {
            throw new \InvalidArgumentException('مقسوم‌علیه باید مثبت باشد.');
        }

        if ($numerator < 0) {
            throw new \InvalidArgumentException('مقسوم‌علیه‌ی تقسیمِ گردِ بالا باید نامنفی باشد؛ رقمِ منفی مسیرِ دیگری است.');
        }

        if ($numerator === 0) {
            return 0;
        }

        return intdiv($numerator - 1, $divisor) + 1;
    }

    /**
     * نرخ × مقدار ÷ تقسیم‌کننده، گرد **به بالا** — در یک گام، بدونِ مرحلهٔ
     * وسطی که خطای گردِ دوبل تولید کند.
     *
     * محدودهٔ امن: `rate × units` تا ~1e13 (نرخِ 1e7 میکرو-واحد × 1e6 واحد)
     * که در محدودهٔ int64 است.
     */
    public static function scaledCeil(int $rate, int $units, int $divisor): int
    {
        return self::ceilDiv($rate * $units, $divisor);
    }

    /** فرمِ نمایشیِ میکرو-واحد — بدونِ float، فقط صحیح و رشته. پسوندِ
     *  ارز را تماس‌دهنده می‌دهد (ارزِ API جایی سِد نمی‌شود). */
    public static function display(int $micros, string $suffix = 'µ'): string
    {
        $whole = intdiv($micros, 1_000_000);
        $micro = abs($micros % 1_000_000);

        return $whole
            . ($micro > 0 ? sprintf('.%06d', $micro) : '')
            . ($suffix !== '' ? ' '.$suffix : '');
    }
}
