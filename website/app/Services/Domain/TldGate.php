<?php

namespace App\Services\Domain;

use App\Models\Setting;

/**
 * دروازهٔ پسوند — «پسوندی که می‌دانیم ثبت نمی‌شود، فروخته نشود».
 *
 * ═══ چرا این کلاس هست ═══
 *
 * قراردادِ رجیستریِ امضانشده یک شکستِ **ساختاری** است: تا یک انسان در پنلِ
 * رجیسترار امضا نکند، پاسخ عوض نمی‌شود. پس بی‌این دروازه، زنجیره این‌طور بود:
 *
 *   مشتریِ اول  → پول داد → ثبت نشد → پارک → (مهلت) → لغو و بازگشتِ وجه
 *   مشتریِ دوم  → همان مسیر، دقیقاً همان پایان
 *   مشتریِ سوم  → …
 *
 * یعنی سامانه چیزی را که **از قبل می‌دانست شکست می‌خورد** دوباره می‌فروخت.
 * پولِ مشتری برمی‌گشت و کسی ضرر نقدی نمی‌کرد، ولی هر بار یک مشتری تجربهٔ
 * «پول دادم، چیزی نگرفتم، چند روز بعد پس گرفتم» می‌گرفت — و آن از نفروختن
 * بدتر است.
 *
 * ⚠️ همان الگوی `CloudProvisioner::quarantineProvider()` است («یا حتماً تحویل
 * شود، یا اصلاً برای فروش موجود نباشد») ولی با یک تفاوتِ عمدی: آن‌جا بازکردن
 * **دستی** است چون «دیگر شکست نمی‌خورد» را فقط یک سفارشِ واقعیِ موفق ثابت
 * می‌کند و آن سفارش پولِ واقعی خرج می‌کند. این‌جا برعکس: دامنهٔ پارک‌شده از
 * قبل **پرداخت شده** است، پس تلاشِ دوباره‌اش هیچ پولِ تازه‌ای خرج نمی‌کند و
 * می‌تواند خودش مدرکِ رفعِ مشکل باشد ⇒ باز شدن **خودکار** است. (`clear()` از
 * `DomainRegistrar::succeed()` صدا زده می‌شود.)
 *
 * 🔴 فقط با خطای **ساختاری** بسته می‌شود، نه با هر شکستی. یک قطعیِ گذرای
 * شبکه نباید فروشِ `.com` را بخواباند؛ برای همین تنها فراخوانش در
 * `DomainRegistrar` زیرِ شرطِ `isUnsignedContract()` است.
 */
class TldGate
{
    private const KEY = 'domain_blocked_tlds';

    /** نرمال‌سازی: «.COM» و «com» یک چیزند */
    private static function norm(string $tld): string
    {
        return strtolower(ltrim(trim($tld), '.'));
    }

    /** @return array<string,array{reason:string,at:string}> */
    public static function all(): array
    {
        $raw = Setting::get(self::KEY);

        if (blank($raw)) {
            return [];
        }

        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * علتِ بسته‌بودنِ یک پسوند، یا `null` اگر باز است.
     *
     * ⚠️ هرگز throw نمی‌کند: این متد روی مسیرِ **جستجو و تسویه** صدا زده
     * می‌شود و یک تنظیمِ خرابِ JSON نباید فروشگاه را بخواباند. جدولِ
     * `settings` هم ممکن است روی نصبی که هنوز مهاجرت نخورده نباشد.
     */
    public static function reasonFor(string $tld): ?string
    {
        try {
            $row = self::all()[self::norm($tld)] ?? null;

            return is_array($row) ? ($row['reason'] ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isBlocked(string $tld): bool
    {
        return self::reasonFor($tld) !== null;
    }

    /**
     * بستنِ یک پسوند.
     *
     * ⚠️ تاریخِ اولین بسته‌شدن حفظ می‌شود: اگر هر بار بازنویسی شود، «چند روز
     * است این پسوند نمی‌فروشد» — تنها عددی که فوریتِ کار را نشان می‌دهد — از
     * بین می‌رود.
     */
    public static function block(string $tld, string $reason): void
    {
        $tld = self::norm($tld);

        if ($tld === '') {
            return;
        }

        try {
            $all = self::all();

            $all[$tld] = [
                'reason' => mb_substr($reason, 0, 400),
                'at'     => $all[$tld]['at'] ?? now()->toIso8601String(),
            ];

            Setting::put(self::KEY, json_encode($all, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // بستنِ دروازه یک محافظِ **اضافه** است؛ اگر خودش خطا داد نباید
            // مسیرِ ثبت را بشکند. شکستِ اصلی از قبل ثبت و اعلان شده.
        }
    }

    /** بازکردن — از موفقیتِ واقعیِ یک ثبت، یا دستِ مدیر */
    public static function clear(string $tld): void
    {
        $tld = self::norm($tld);

        try {
            $all = self::all();

            if (! array_key_exists($tld, $all)) {
                return;     // نوشتنِ بی‌دلیل در تنظیمات، روی هر ثبتِ موفق
            }

            unset($all[$tld]);

            Setting::put(self::KEY, $all === [] ? null : json_encode($all, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            //
        }
    }
}
