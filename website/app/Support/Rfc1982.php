<?php

namespace App\Support;

/**
 * حسابِ سریالِ SOA طبقِ RFC 1982 (فضای ۳۲بیتی).
 *
 * 🔴 چرا مقایسهٔ معمولیِ `>=` کافی نیست: سریال پس از 4294967295 به صفر
 * برمی‌گردد. با مقایسهٔ عددی، نودی که سریالِ تازه‌ترِ «پیچیده» (مثلاً 5) دارد
 * «عقب‌مانده» دیده می‌شود و zone هرگز «منتشر روی همه» نمی‌شود — یا بدتر،
 * نودِ واقعاً عقب‌مانده «هم‌گام» خوانده می‌شود.
 *
 * ⚠️ فاصلهٔ دقیقاً 2^31 در RFC «تعریف‌نشده» است. این‌جا `UNDEFINED` (منفی)
 * برمی‌گردد تا هر فراخوانی که `compare() >= 0` می‌پرسد آن را «هم‌گام نیست»
 * بخواند — ادعای انتشارِ نادرست بدتر از یک بررسیِ دوباره است.
 */
final class Rfc1982
{
    /** سریالِ معتبر در [0, 2^32) */
    public const MAX = 0xFFFFFFFF;

    private const HALF = 0x80000000;

    /** نتیجهٔ مقایسهٔ تعریف‌نشده (فاصلهٔ دقیقاً 2^31) — عمداً منفی، بالا را بخوان */
    public const UNDEFINED = -2;

    /**
     * ‎-1 اگر a پیش از b باشد، 0 اگر برابر، 1 اگر a پس از b، و `UNDEFINED`
     * وقتی RFC پاسخی ندارد.
     */
    public static function compare(int $a, int $b): int
    {
        // ورودیِ بیرون از بازه (منفی یا بزرگ‌تر از ۳۲ بیت) به همان فضای سریال تا می‌شود
        $a &= self::MAX;
        $b &= self::MAX;

        if ($a === $b) {
            return 0;
        }

        $d = ($b - $a) & self::MAX;          // فاصلهٔ رو به جلو از a تا b

        if ($d === self::HALF) {
            return self::UNDEFINED;
        }

        return $d < self::HALF ? -1 : 1;
    }

    /** آیا a دست‌کم به تازگیِ b است؟ (تعریف‌نشده ⇒ نه) */
    public static function atLeast(int $a, int $b): bool
    {
        $c = self::compare($a, $b);

        return $c === 0 || $c === 1;
    }

    /** سریالِ بعدی با پیچش (برای تست و ابزار) */
    public static function add(int $serial, int $n): int
    {
        return ($serial + $n) & self::MAX;
    }
}
