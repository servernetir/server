<?php

namespace App\Services\Cloud;

use App\Models\Customer;
use App\Models\Service;

/**
 * سقفِ سفارشِ سرورِ ابری — محافظِ «کارتِ دزدیده‌شده».
 *
 * 🔴 سناریوی واقعی که این کلاس جلویش را می‌گیرد:
 * تحویلِ سرورِ ابری کاملاً خودکار است، پس هر حسابِ تازه‌ثبت‌شده یک دکمهٔ مستقیم
 * به APIِ زیرساختِ خارجی دارد. مهاجم ثبت‌نام می‌کند، با کارتِ دزدیده‌شده یا
 * کریپتو پول می‌دهد، و در چند دقیقه ده‌ها سرور می‌گیرد — برای botnet یا استخراج.
 * بعد chargeback می‌خورد. آن‌وقت **هم صورتحسابِ زیرساخت پای ماست، هم گزارشِ
 * abuse**، و بدتر: حسابِ مادرِ ما نزدِ آن زیرساخت به‌خاطر سوءاستفاده تعلیق
 * می‌شود — که یعنی سرورِ **همهٔ** مشتریانِ خارج هم‌زمان از دست می‌رود.
 *
 * پس شعاعِ انفجار تک‌مشتری نیست. برای همین سقف روی «حسابِ نوپا» سخت‌گیرانه است
 * و با سابقهٔ پرداختِ موفق باز می‌شود.
 *
 * ⚠️ این محافظ **رد نمی‌کند**، فقط به صفِ بازبینیِ دستی می‌فرستد. مسدودکردنِ
 * مشتریِ واقعی گران‌تر از یک تأخیرِ کوتاه است.
 */
class CloudFraudGuard
{
    /** حسابِ جوان‌تر از این (ساعت) «نوپا» است */
    private const NEW_ACCOUNT_HOURS = 48;

    /** سقفِ سرورِ فعالِ هم‌زمان برای حسابِ نوپا */
    private const NEW_ACCOUNT_MAX = 2;

    /** سقفِ سرورِ ساخته‌شده در ۲۴ ساعت، برای هر حساب */
    private const DAILY_MAX = 5;

    /**
     * آیا این سفارش باید پیش از تحویل، دستی تأیید شود؟
     *
     * @return array{hold:bool, reason:?string}
     */
    public function check(Customer $customer): array
    {
        $cloud = Service::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('cloud_plan_id');

        $live = (clone $cloud)->whereNotIn('status', Service::DEAD_STATUSES)->count();
        $today = (clone $cloud)->where('created_at', '>=', now()->subDay())->count();

        if ($today >= self::DAILY_MAX) {
            return ['hold' => true, 'reason' => 'بیش از '.self::DAILY_MAX.' سرور در ۲۴ ساعت'];
        }

        // حسابِ نوپا: هم سنِ حساب کم است هم هیچ پرداختِ تأییدشده‌ای ندارد.
        // اگر مشتری قبلاً پولِ سالم داده، دیگر نوپا نیست.
        $isNew = $customer->created_at !== null
            && $customer->created_at->gt(now()->subHours(self::NEW_ACCOUNT_HOURS))
            && ! $this->hasSettledPayment($customer);

        if ($isNew && $live >= self::NEW_ACCOUNT_MAX) {
            return ['hold' => true, 'reason' => 'حسابِ تازه با بیش از '.self::NEW_ACCOUNT_MAX.' سرور'];
        }

        return ['hold' => false, 'reason' => null];
    }

    /**
     * آیا این مشتری تا حالا پرداختِ تسویه‌شده داشته؟
     *
     * ⚠️ فاکتورِ **پرداخت‌شده** ملاک است نه صرفاً وجودِ تراکنش: تراکنشِ ناموفق
     * یا در انتظار، سابقهٔ اعتماد نمی‌سازد.
     */
    private function hasSettledPayment(Customer $customer): bool
    {
        return \App\Models\Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'paid')
            ->exists();
    }
}
