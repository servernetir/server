<?php

namespace App\Services\Domain;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * قیمتِ **نمونهٔ زندهٔ** هر پسوند — برای صفحاتِ بازاریابی.
 *
 * صفحهٔ `/domain/popular-tlds` باید عددِ واقعی نشان دهد، نه «استعلام لحظه‌ای».
 * ولی قیمتِ دامنه به نامِ خودِ دامنه هم بستگی دارد (پرمیوم)، پس «قیمتِ .com»
 * به‌تنهایی وجود ندارد. راهِ درست: یک نامِ **بلند و قطعاً آزاد** استعلام
 * می‌شود و قیمتش به‌عنوانِ «قیمتِ پایهٔ این پسوند» نشان داده می‌شود.
 *
 * ⚠️ نامِ نمونه عمداً بلند و بی‌معناست: نامِ کوتاه یا معنادار ممکن است پرمیوم
 * باشد و قیمتِ پرمیوم را به‌جای قیمتِ پایه روی صفحه بنشاند — یعنی دقیقاً
 * همان «قیمتی که مشتری نمی‌تواند بخرد».
 *
 * 🔴 کش اجباری است. بی‌آن، هر بازدیدِ صفحهٔ بازاریابی یک تماسِ واقعی به
 * رجیسترار می‌شود؛ یک ربات یا یک موجِ ترافیک، حسابِ ما را نرخ‌محدود می‌کند —
 * همان اتفاقی که یک بار افتاد.
 */
class TldPriceBook
{
    private const TTL_HOURS = 6;

    /** نامِ نمونه: بلند، بی‌معنا، و قطعاً ثبت‌نشده */
    private const PROBE = 'sn7price9check4base';

    /**
     * 🔴 پسوندهای ایرانی هرگز از رسیلرِ اروپایی قیمت نمی‌گیرند.
     *
     * `DomainSearch::SUGGEST_TLDS` این را از اول می‌دانست و `.ir` را عمداً از
     * پیشنهادها بیرون گذاشته بود — ولی آن محافظ فقط در **جستجو** بود، نه در
     * صفحهٔ کاتالوگ. نتیجه‌اش روی سایتِ زنده این بود:
     *
     *     /domain/ir  →  «.ir سالانه ۱۳٬۴۵۰٬۰۰۰ تومان»
     *
     * در برابرِ قیمتِ واقعیِ ایرنیک که حدودِ ۱۷۰٬۰۰۰ تومان است — تقریباً ۸۰
     * برابر. و بدتر از خودِ عدد: صفحه‌ای که این را نشان می‌دهد، به بازدیدکننده
     * می‌گوید «قیمت‌های این سایت بی‌ربط است» و اعتبارِ قیمتیِ کلِ کاتالوگ را
     * می‌بَرد. `.ir` هم ارزان‌ترین محصولِ سبد است، یعنی معمولاً **اولین**
     * تراکنشِ یک کسب‌وکارِ کوچک — همان دری که بعداً هاست و سرور از آن می‌آید.
     *
     * ⚠️ تا وقتی اتصالِ مستقیمِ ایرنیک ساخته نشده، این پسوندها باید به شاخهٔ
     * «استعلام» بیفتند، نه اینکه عددی نشان دهند که نه واقعی است نه فروختنی.
     *
     * @var array<int,string>
     */
    private const NEVER_QUOTE = ['ir', 'co.ir', 'ac.ir', 'org.ir', 'net.ir', 'gov.ir', 'id.ir', 'sch.ir', 'ایران'];

    public function __construct(private DomainSearch $search) {}

    /**
     * قیمتِ پایهٔ چند پسوند.
     *
     * @param  array<int,string>  $tlds
     * @return array<string,int>  پسوند → تومان (فقط آن‌هایی که قیمت دارند)
     */
    public function forTlds(array $tlds): array
    {
        $tlds = array_values(array_unique(array_filter(array_map(
            fn ($t) => strtolower(ltrim(trim((string) $t), '.')),
            $tlds
        ))));

        // ⚠️ پیش از هر چیز: پسوندِ ایرانی حذف می‌شود. نه استعلام می‌شود، نه
        //    کش، نه برگردانده — تا فراخوان شاخهٔ «استعلام» را نشان دهد.
        $tlds = array_values(array_diff($tlds, self::NEVER_QUOTE));

        if ($tlds === []) {
            return [];
        }

        /*
        | 🔴 حاشیهٔ سود و نرخِ ارز در **کلیدِ کش**اند.
        |
        | بی‌این، مدیر حاشیه را در تنظیمات صفر می‌کند و صفحه تا شش ساعت همان
        | قیمتِ قدیم را نشان می‌دهد — و او فکر می‌کند تنظیمات کار نمی‌کند.
        | همان‌طور برای جهشِ نرخِ ارز: قیمتِ کهنه یعنی فروش زیرِ قیمتِ خرید.
        */
        $stamp = md5(implode('|', [
            (string) Setting::get('domain_margin_pct'),
            (string) Setting::get('pricing_rate_override'),
            implode(',', $tlds),
        ]));

        return Cache::remember('tld.prices.'.$stamp, now()->addHours(self::TTL_HOURS),
            fn () => $this->quote($tlds));
    }

    /** @return array<string,int> */
    private function quote(array $tlds): array
    {
        $out = [];

        // دسته‌دسته، چون رسیلری درخواستِ بی‌اندازه را رد می‌کند
        foreach (array_chunk($tlds, DomainSearch::BATCH) as $chunk) {
            try {
                foreach ($this->search->search(self::PROBE, $chunk) as $r) {
                    // فقط قیمتِ **پایه**: اگر همین نامِ بلند هم پرمیوم درآمد،
                    // عددش نمایندهٔ پسوند نیست و باید کنار گذاشته شود.
                    if (($r['orderable'] ?? false) && ! ($r['is_premium'] ?? false) && ($r['price_toman'] ?? 0) > 0) {
                        $out[$r['tld']] = (int) $r['price_toman'];
                    }
                }
            } catch (\Throwable $e) {
                // صفحهٔ بازاریابی نباید به‌خاطر رجیسترار بخوابد؛ عددِ نبود
                // یعنی همان دکمهٔ «بررسی و ثبت» نشان داده می‌شود.
                \Illuminate\Support\Facades\Log::info('tld price probe failed', ['err' => $e->getMessage()]);
            }
        }

        return $out;
    }
}
