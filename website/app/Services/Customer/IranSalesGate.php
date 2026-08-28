<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\Setting;

/**
 * دروازهٔ فروشِ محصولاتِ مستقر در ایران به مشتریِ احرازنشده.
 *
 * ═══ چرا (تصمیمِ کارفرما — ۶ شهریور ۱۴۰۵) ═══
 *
 * «فعلاً هاست/سرورِ ایران فقط به ایرانی‌ها فروخته شود.» مشتریِ ایرانی از
 * شاهکار و کدِ ملی رد شده (پروفایلِ verified دارد)؛ مشتریِ خارجی فقط ایمیل
 * و موبایلِ تأییدشده دارد و هنوز KYCِ سندی (پاسپورت/قبض) برایش نساخته‌ایم.
 * زیرساختِ داخلِ ایران نباید بی‌احراز دستِ ناشناس بیفتد.
 *
 * ═══ قواعد ═══
 *
 * - معیار «احراز» است نه «زبان»: `Customer::isVerified()` — ایرانیِ KYCشده
 *   از /en هم بخرد آزاد است؛ خارجیِ بی‌احراز از /fa هم بسته. زبان جعل‌شدنی
 *   است، احراز نه. (وقتی KYCِ خارجی ساخته شد، تأییدِ دستیِ مدارک همین پرچم
 *   را روشن می‌کند و دروازه خودبه‌خود برایش باز می‌شود.)
 * - پیش‌فرض **بسته** است: نبودِ ردیفِ تنظیم = بسته. کلیدِ اضطراری که فراموش
 *   شود نباید بازار را باز بگذارد. بازکردن فقط با مقدارِ صریحِ '1'
 *   (تنظیمات → عمومی → «فروشِ محصولاتِ ایران به مشتریِ احرازنشده»).
 * - هدفِ ایرانی: کشورِ IR (هاستِ اشتراکی) یا کدِ مکانِ `ir-*` (سرورِ ابری).
 *   دامنه‌های ir. از قبل برای **همه** بسته‌اند (DomainSearch::UNSOLD_TLDS) و
 *   به این دروازه نیازی ندارند.
 * - دو لایه: فهرستِ مکان‌ها برای مشتریِ بسته اصلاً «ایران» را نشان نمی‌دهد
 *   (UX)، و لحظهٔ ثبتِ سفارش هم سخت‌گیرانه رد می‌شود (امنیت) — نمایش قابلِ
 *   دورزدن است، سفارش نه.
 */
class IranSalesGate
{
    public const SETTING = 'iran_sales_open_to_unverified';

    /** آیا مدیر صریحاً فروش به احرازنشده را باز کرده؟ */
    public static function openToUnverified(): bool
    {
        try {
            return (string) Setting::get(self::SETTING) === '1';
        } catch (\Throwable) {
            return false;      // دیتابیسِ لنگ = بسته، نه باز
        }
    }

    /** آیا برای این مشتری، کلِ محصولاتِ ایران بسته است؟ */
    public static function blocksIranFor(?Customer $customer): bool
    {
        if (self::openToUnverified()) {
            return false;
        }

        return $customer === null || ! $customer->isVerified();
    }

    /** آیا این سفارشِ مشخص (با این مقصد) باید رد شود؟ */
    public static function blocks(?Customer $customer, ?string $target): bool
    {
        return self::isIranTarget($target) && self::blocksIranFor($customer);
    }

    /** «IR» (کشورِ هاست) یا «ir-…» (مکانِ ابری) */
    public static function isIranTarget(?string $target): bool
    {
        $t = strtolower(trim((string) $target));

        return $t === 'ir' || str_starts_with($t, 'ir-');
    }

    public static function message(): string
    {
        return __('ui.iran_gate_blocked');
    }
}
