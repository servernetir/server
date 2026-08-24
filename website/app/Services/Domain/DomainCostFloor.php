<?php

namespace App\Services\Domain;

use App\Models\Domain;
use App\Support\ErrorTracker;

/**
 * کفِ ارزیِ تمدید — «هرگز زیرِ بهای تمام‌شده تمدید نفروش»، در یک جا.
 *
 * ═══ دو باگِ ممیزیِ شهریور ۱۴۰۵ که این کلاس می‌بندد ═══
 *
 * 🔴 (۱) مسیرِ **خرده‌فروشی** اصلاً کف نداشت: فاکتورِ تمدید از `renew_toman`ِ
 * فریزشده در روزِ خرید صادر می‌شد — یک سال بعد، با هر نرخِ دلاری، ما به
 * قیمتِ امروز از رجیسترار می‌خریدیم و به قیمتِ پارسال می‌فروختیم. ضررِ
 * تضمین‌شده روی هر تمدید، صادرشده به‌دستِ خودِ کرون.
 *
 * 🔴 (۲) کفِ نمایندگی بود ولی از `cost_amount` — بهای **تبلیغاتیِ سالِ اول** —
 * حساب می‌شد. برای `.shop` (ثبت €1.90 / تمدید €14.90) یعنی کفِ ~€2 در برابرِ
 * هزینهٔ واقعیِ €14.90. حالا بهای تمدید (`cost_renew_amount`) ذخیره می‌شود و
 * حرفِ اول را می‌زند؛ ردیف‌های قدیمی به `cost_amount` برمی‌گردند — محافظِ
 * ناقص بهتر از هیچ است.
 *
 * ⚠️ اگر داده یا نرخ نباشد **صفر** برمی‌گردد یعنی محافظ خاموش — عمدی است:
 * بستنِ تمدید به‌خاطرِ نبودِ نرخ یعنی دامنهٔ مشتری منقضی شود؛ ضررِ قطعی در
 * برابرِ ضررِ محتمل. ولی در ردیاب ثبت می‌شود تا نبودِ طولانی‌مدتِ نرخ دیده شود.
 *
 * ⚠️ قیمت فقط **بالا** می‌رود: کفِ پایین‌تر از قیمتِ ذخیره‌شده هیچ‌کاره است.
 * ارزان‌ترکردن تصمیمِ مالیِ کارفرماست، نه کارِ خودکارِ کد.
 */
class DomainCostFloor
{
    public function __construct(private DomainSearch $search) {}

    /** کمینهٔ قابلِ قبولِ فروشِ یک سال تمدید، به تومان. ۰ = محافظ خاموش. */
    public function renewPerYear(Domain $domain): int
    {
        $minor = (int) ($domain->cost_renew_amount ?: $domain->cost_amount);
        $currency = (string) $domain->cost_currency;

        if ($minor <= 0 || $currency === '') {
            return 0;
        }

        $rate = $this->search->rateFor($currency);

        if ($rate === null || $rate <= 0) {
            ErrorTracker::noteOnce('domain', 'renewal cost floor skipped — no FX rate', 3600, [
                'currency' => $currency,
            ]);

            return 0;
        }

        $costToman = ($minor / 100) * $rate;
        $minMargin = max(0.0, (float) config('domain_reseller.min_margin_pct', 8));

        $step = \App\Models\Currency::find('IRT')?->rounding_step ?: 1000;

        return (int) (ceil($costToman * (1 + $minMargin / 100) / $step) * $step);
    }
}
