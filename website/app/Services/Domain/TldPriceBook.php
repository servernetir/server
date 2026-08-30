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
    private const NEVER_QUOTE = DomainSearch::UNSOLD_TLDS;

    public function __construct(private DomainSearch $search) {}

    /**
     * قیمتِ پایهٔ چند پسوند.
     *
     * @param  array<int,string>  $tlds
     * @return array<string,int>  پسوند → تومان (فقط آن‌هایی که قیمت دارند)
     */
    public function forTlds(array $tlds): array
    {
        /*
        | ⚠️ نرمال‌سازی **مشترک** با `cachedForTlds()` است و باید بمانَد.
        |
        | اگر این دو مسیر فهرست را جور دیگری تمیز کنند، کلیدِ کششان فرق می‌کند
        | و خواننده هرگز به چیزی که کرون گرم کرده نمی‌رسد — یعنی بی‌صدا به
        | استعلامِ زنده می‌افتد. همان چیزی که همهٔ این کلاس برای جلوگیری از آن
        | نوشته شده.
        |
        | ⚠️ پسوندِ ایرانی همان‌جا حذف می‌شود: نه استعلام، نه کش، نه بازگشت.
        */
        $tlds = $this->normalise($tlds);

        if ($tlds === []) {
            return [];
        }

        return Cache::remember($this->cacheKey($tlds), now()->addHours(self::TTL_HOURS),
            fn () => $this->quote($tlds));
    }

    /**
     * همان دفترچه، ولی **فقط از کش** — هرگز به رجیسترار تماس نمی‌گیرد.
     *
     * 🔴 برای مصرف‌کننده‌هایی که وسطِ رندرِ یک صفحهٔ عمومی‌اند.
     *
     * `forTlds()` روی کشِ سرد استعلامِ زنده می‌زند. آن رفتار برای کرون درست
     * است ولی روی یک درخواستِ وب دو فاجعه دارد: صفحه پشتِ شبکهٔ رجیسترار
     * می‌مانَد (همان TTFB که رویش کار کردیم)، و مهم‌تر — **بازدیدکننده** به
     * تماسِ API تبدیل می‌شود. حسابِ ما یک بار به‌خاطرِ تماسِ زیاد از آی‌پیِ
     * ایران علامت خورده؛ صفحهٔ اولی که خزنده هم می‌بیند نباید آن را تکرار کند.
     *
     * ⚠️ آرایهٔ خالی یعنی «هنوز نمی‌دانم»، نه «رایگان». فراخوان باید خودش
     * تصمیم بگیرد چه نشان دهد — این‌جا عمداً هیچ حدسی زده نمی‌شود.
     *
     * @param  array<int,string>  $tlds  باید **دقیقاً** همان فهرستی باشد که کرون گرم می‌کند
     * @return array<string,int>
     */
    public function cachedForTlds(array $tlds): array
    {
        $tlds = $this->normalise($tlds);

        if ($tlds === []) {
            return [];
        }

        return (array) Cache::get($this->cacheKey($tlds), []);
    }

    /**
     * 🔴 حاشیهٔ سود و نرخِ ارز در **کلیدِ کش**اند.
     *
     * بی‌این، مدیر حاشیه را در تنظیمات صفر می‌کند و صفحه تا شش ساعت همان
     * قیمتِ قدیم را نشان می‌دهد — و او فکر می‌کند تنظیمات کار نمی‌کند.
     * همان‌طور برای جهشِ نرخِ ارز: قیمتِ کهنه یعنی فروش زیرِ قیمتِ خرید.
     *
     * ⚠️ کلید از **فهرستِ پسوندها** هم ساخته می‌شود، پس دو فراخوان با دو
     * فهرستِ متفاوت هرگز به کشِ هم نمی‌رسند. `cachedForTlds()` بی‌فایده است
     * مگر با همان فهرستی صدا زده شود که کرون گرم می‌کند.
     *
     * @param  array<int,string>  $tlds  نرمال‌شده
     */
    private function cacheKey(array $tlds): string
    {
        return 'tld.prices.'.md5(implode('|', [
            (string) Setting::get('domain_margin_pct'),
            (string) Setting::get('pricing_rate_override'),
            implode(',', $tlds),
        ]));
    }

    /** پاک‌سازیِ مشترکِ فهرستِ پسوندها — نقطه‌ها، تکراری‌ها، و پسوندهای نافروشی. */
    private function normalise(array $tlds): array
    {
        $tlds = array_values(array_unique(array_filter(array_map(
            fn ($t) => strtolower(ltrim(trim((string) $t), '.')),
            $tlds
        ))));

        return array_values(array_diff($tlds, self::NEVER_QUOTE));
    }

    /**
     * همان دفترچه، ولی با **هر سه** قیمت: ثبت، تمدید، انتقال.
     *
     * ═══ 🔴 چرا این متد لازم شد و چرا «تمدید = ثبت» یک باگِ مالی است ═══
     *
     * APIِ نمایندگی و `GetTldPricing` ماژولِ WHMCS هر سه عدد را می‌خواهند.
     * ساده‌ترین کار این بود که همان `forTlds()` را بدهیم و هر سه را برابر
     * بگذاریم — و دقیقاً همان اشتباهی است که `Account\DomainController::order()`
     * یک بار خورده و مستندش کرده: قیمتِ سالِ اولِ بیشترِ پسوندها **تبلیغاتی**
     * است و رجیسترار برای سال‌های بعد نرخِ تمدید می‌گیرد.
     *
     * نمونهٔ واقعیِ همین کاتالوگ (`.shop`): ثبت ۱۹۰٬۰۰۰ و تمدید ۱٬۴۹۰٬۰۰۰
     * تومان — تقریباً هشت برابر. یعنی نماینده‌ای که با «تمدید = ثبت» قیمت
     * می‌گیرد، هر تمدید را با ~۱٫۳ میلیون تومان ضرر از ما می‌خرد، و چون
     * تمدید سالانه تکرار می‌شود، ضرر **انباشته** است نه یک‌باره.
     *
     * ⚠️ منطقِ دسته‌بندی، کش و ردِ پرمیوم عیناً همان `quote()` است و عمداً
     * تکرار نشده — دو پیاده‌سازیِ موازی یعنی روزی یکی‌شان اصلاح می‌شود.
     *
     * @param  array<int,string>  $tlds
     * @return array<string,array{register:int, renew:int, transfer:int}>
     */
    public function fullForTlds(array $tlds): array
    {
        $tlds = array_values(array_diff(
            array_values(array_unique(array_filter(array_map(
                fn ($t) => strtolower(ltrim(trim((string) $t), '.')),
                $tlds
            )))),
            self::NEVER_QUOTE
        ));

        if ($tlds === []) {
            return [];
        }

        $stamp = md5(implode('|', [
            'full',
            (string) Setting::get('domain_margin_pct'),
            (string) Setting::get('pricing_rate_override'),
            implode(',', $tlds),
        ]));

        return Cache::remember('tld.prices.full.'.$stamp, now()->addHours(self::TTL_HOURS),
            fn () => $this->quote($tlds, full: true));
    }

    /**
     * @return array<string,mixed>  در حالتِ عادی tld→int، در حالتِ full ساختارِ سه‌تایی
     */
    private function quote(array $tlds, bool $full = false): array
    {
        $out = [];

        // دسته‌دسته، چون رسیلری درخواستِ بی‌اندازه را رد می‌کند
        foreach (array_chunk($tlds, DomainSearch::BATCH) as $chunk) {
            try {
                foreach ($this->search->search(self::PROBE, $chunk) as $r) {
                    // فقط قیمتِ **پایه**: اگر همین نامِ بلند هم پرمیوم درآمد،
                    // عددش نمایندهٔ پسوند نیست و باید کنار گذاشته شود.
                    if (($r['orderable'] ?? false) && ! ($r['is_premium'] ?? false) && ($r['price_toman'] ?? 0) > 0) {
                        if (! $full) {
                            $out[$r['tld']] = (int) $r['price_toman'];

                            continue;
                        }

                        $register = (int) $r['price_toman'];

                        /*
                        | ⚠️ بازگشت به قیمتِ ثبت فقط وقتی رجیسترار عددی نداده.
                        | این‌جا «نمی‌دانیم» است نه «برابر است» — ولی نمایشِ
                        | هیچ قیمتی روی صفحهٔ فروش بدترین گزینه است، و همان
                        | انتخابی است که `DomainSearch::shape()` هم می‌کند.
                        | پس رفتار در هر دو یکی می‌مانَد.
                        */
                        $out[$r['tld']] = [
                            'register' => $register,
                            'renew'    => (int) ($r['renew_toman'] ?: $register),
                            'transfer' => (int) ($r['transfer_toman'] ?: $register),
                        ];
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
