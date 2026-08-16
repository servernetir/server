<?php

namespace App\Services\Domain\Reseller;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Services\Domain\DomainSearch;

/**
 * قیمتِ نماینده از روی یک استعلامِ خرده‌فروشی.
 *
 * ═══ 🔴 چرا این کلاس بیش از یک ضرب‌وتقسیم است ═══
 *
 * حاشیهٔ سودِ دامنه در این پروژه **per-TLD** است (`DomainSearch::marginFor()`).
 * یعنی یک تخفیفِ ثابتِ ۱۵٪ روی پسوندی که حاشیه‌اش ۱۰٪ است، فروش زیرِ بهای
 * تمام‌شده است — روی **هر** تراکنش، بی‌هیچ خطایی، بی‌هیچ لاگی. و بدترین
 * بخشش این است که دقیقاً روی پرفروش‌ترین پسوندها (`.com`) رخ می‌دهد، چون
 * همان‌ها رقابتی‌ترین و کم‌حاشیه‌ترین‌اند.
 *
 * پس هر قیمتِ نمایندگی از یک **کفِ سخت** رد می‌شود، و وقتی کف فعال شد،
 * پرچمِ `floored` روی نتیجه می‌نشیند تا هم API و هم پنل بتوانند صریح
 * بگویند «تخفیفِ سطحِ شما این‌جا کامل اعمال نشد و چرا».
 *
 * ⚠️ سکوت این‌جا از خودِ کف خطرناک‌تر است: تخفیفی که وعده‌اش ۱۵٪ بوده و در
 * فاکتور ۴٪ نشسته، اگر بی‌توضیح بماند برنامه‌ای را که برای وفاداری ساختیم به
 * سندِ بی‌اعتمادی تبدیل می‌کند — و اول از همه سطح‌بالاترها می‌فهمند، یعنی
 * باارزش‌ترین نماینده‌ها.
 */
class ResellerPricing
{
    public function __construct(
        private ResellerProgram $program,
        private DomainSearch $search,
    ) {}

    /**
     * @return array{
     *   retail:int, price:int, discount_pct:float, applied_pct:float,
     *   floor:int, floored:bool, reason:?string
     * }
     */
    public function forQuote(DomainQuote $quote, ?Customer $customer, string $kind = 'register'): array
    {
        $retail = (int) ($kind === 'renew'
            ? ($quote->renew_toman ?: $quote->sell_toman)
            : $quote->sell_toman);

        return $this->price($retail, $quote, $customer, (string) $quote->domain);
    }

    /**
     * هستهٔ محاسبه — از هر دو در (ثبت و تمدید) صدا زده می‌شود.
     *
     * @param  int  $retail  قیمتِ خرده‌فروشیِ همین کالا (تومان)
     */
    public function price(int $retail, ?DomainQuote $quote, ?Customer $customer, string $fqdn = ''): array
    {
        $out = [
            'retail'       => $retail,
            'price'        => $retail,
            'discount_pct' => 0.0,
            'applied_pct'  => 0.0,
            'floor'        => 0,
            'floored'      => false,
            'reason'       => null,
        ];

        if (! $this->program->isReseller($customer) || $retail <= 0) {
            return $out;
        }

        $pct = $this->program->discountPct($customer);
        $out['discount_pct'] = $pct;

        if ($pct <= 0) {
            return $out;
        }

        /*
        |----------------------------------------------------------------------
        | 🔴 بندِ ضدِ شکارِ مشتریِ مستقیم
        |----------------------------------------------------------------------
        |
        | تخفیفِ نمایندگی به دامنه‌ای که تازگی مشتریِ مستقیمِ ما بوده تعلق
        | نمی‌گیرد. بی‌این، ساده‌ترین راهِ سودِ یک نماینده این است که مشتریانِ
        | خودِ ما را با تخفیفی که **ما** داده‌ایم از ما بگیرد.
        |
        | ⚠️ فروش رد نمی‌شود، فقط تخفیف نمی‌خورد. ردکردن یعنی مشتریِ نهایی
        | دامنه‌اش را از رقیب می‌گیرد و ما هم مشتری را از دست می‌دهیم هم فروش
        | را — یعنی محافظی که به ضررِ خودمان تمام می‌شود.
        */
        if ($fqdn !== '' && $this->wasDirectCustomer($fqdn, $customer)) {
            $out['reason'] = 'direct_customer_domain';

            return $out;
        }

        $discounted = (int) floor($retail * (1 - $pct / 100));

        // ── کفِ سخت ──
        $floor = $this->floorFor($quote);
        $out['floor'] = $floor;

        $final = $floor > 0 ? max($discounted, $floor) : $discounted;

        /*
        | گردکردن رو به **بالا** روی همان پلهٔ ارزِ سایت.
        |
        | ⚠️ رو به پایین گردکردن، کف را یک پله می‌شکند — یعنی محافظی که خودش
        | نقضش می‌کند. اختلافش ناچیز است، ولی «ناچیز» ضربدرِ هر تراکنشِ
        | نماینده می‌شود.
        */
        $step = Currency::find('IRT')?->rounding_step ?: 1000;
        $final = (int) (ceil($final / $step) * $step);

        // پس از گردکردن ممکن است از خرده‌فروشی بالاتر بزند؛ آن‌وقت تخفیف بی‌معناست
        $final = min($final, $retail);

        $out['price'] = $final;
        $out['applied_pct'] = $retail > 0 ? round((1 - $final / $retail) * 100, 2) : 0.0;
        $out['floored'] = $final > $discounted;

        if ($out['floored']) {
            $out['reason'] = 'min_margin_floor';
        }

        return $out;
    }

    /**
     * کفِ قیمت = بهای تمام‌شده × (۱ + حداقلِ حاشیه).
     *
     * 🔴 اگر بهای تمام‌شده را **ندانیم**، کف صفر برمی‌گردد و تخفیف کامل اعمال
     * می‌شود. این عمدی است ولی خطرناک، پس صریح نوشته می‌شود: نبودِ نرخِ ارز یا
     * نبودِ استعلام یعنی ما هم نمی‌دانیم زیرِ قیمت می‌فروشیم یا نه.
     *
     * دلیلِ انتخاب: `DomainSearch::shape()` وقتی نرخِ ارز نباشد اصلاً استعلام
     * نمی‌سازد (`fx_unavailable`)، پس در مسیرِ واقعیِ فروش این حالت نمی‌رسد —
     * و اگر روزی رسید، بستنِ فروش به‌خاطرِ کفِ نامعلوم، ضررِ قطعی است در برابرِ
     * ضررِ محتمل.
     *
     * ⚠️ هر تغییری در این تصمیم باید `min_margin_pct` را هم بازبینی کند.
     */
    private function floorFor(?DomainQuote $quote): int
    {
        if ($quote === null) {
            return 0;
        }

        $minor = (int) $quote->cost_amount;      // واحدِ فرعیِ ارزِ مبدأ (×۱۰۰)
        $currency = (string) $quote->cost_currency;

        if ($minor <= 0 || $currency === '') {
            return 0;
        }

        $rate = $this->search->rateFor($currency);

        if ($rate === null || $rate <= 0) {
            return 0;
        }

        $costToman = ($minor / 100) * $rate;
        $minMargin = max(0.0, (float) config('domain_reseller.min_margin_pct', 8));

        return (int) ceil($costToman * (1 + $minMargin / 100));
    }

    /**
     * آیا این دامنه تازگی مالِ مشتریِ **مستقیمِ** ما بوده؟
     *
     * ⚠️ دامنهٔ خودِ نماینده استثناست — تمدیدِ پرتفویِ خودش نباید شکار حساب
     * شود، وگرنه هر نماینده روی دامنه‌های خودش تخفیف را از دست می‌داد.
     */
    private function wasDirectCustomer(string $fqdn, Customer $reseller): bool
    {
        $months = (int) config('domain_reseller.poaching_window_months', 6);

        if ($months <= 0) {
            return false;
        }

        return Domain::where('domain', strtolower(trim($fqdn)))
            ->where('customer_id', '!=', $reseller->id)
            ->where('updated_at', '>=', now()->subMonths($months))
            ->exists();
    }
}
