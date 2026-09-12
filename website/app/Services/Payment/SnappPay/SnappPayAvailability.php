<?php

namespace App\Services\Payment\SnappPay;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\Cache;

/**
 * آیا این فاکتور، برای این مشتری، همین الان اقساطی هست؟
 *
 * ═══ چرا یک کلاسِ جدا و نه چند `if` در ویو ═══
 *
 * این تصمیم شش شرط دارد که پنج‌تایش محلی است و ششمی فقط از خودِ اسنپ‌پی
 * می‌آید. پخش‌کردنشان در Blade یعنی روزی یکی از آن‌ها در یک صفحه اعمال شود
 * و در صفحهٔ دیگر نه — و آن صفحه همان‌جایی است که مشتری دکمه را می‌بیند.
 *
 * ═══ قاعدهٔ اسنپ‌پی که قابلِ مذاکره نیست ═══
 *
 * 🔴 «از هر گونه پیاده‌سازی دستی سمتِ خود خودداری فرمایید و حتماً سرویس
 * eligible را درست پیاده‌سازی کنید.» و: «title و description همیشه داینامیک
 * از سمتِ اسنپ‌پی بوده و به هیچ عنوان نباید به صورت ثابت نمایش پیدا کند.»
 *
 * پس عنوان و توضیحِ روی دکمه **ساختهٔ ما نیست**؛ عیناً همان چیزی است که
 * برمی‌گردد. و اگر `eligible=false` بود، دکمه اصلاً رندر نمی‌شود — نه
 * غیرفعال، نه با پیامِ «در دسترس نیست».
 *
 * ═══ کش ═══
 *
 * ⚠️ پاسخِ eligible فقط به **مبلغ** بستگی دارد (تنها پارامترِ ورودی‌اش همان
 * است). مستندات می‌گوید «با هر تغییرِ مبلغ دوباره صدا زده شود» — که دقیقاً
 * یعنی کش بر اساسِ مبلغ. بدونِ کش، هر بار باز کردنِ صفحهٔ فاکتور یک تماسِ
 * شبکه‌ای به اسنپ‌پی می‌زند و صفحه را کند می‌کند.
 */
class SnappPayAvailability
{
    /** کوتاه است تا تغییرِ سیاستِ اسنپ‌پی زود به ما برسد */
    private const CACHE_TTL = 300;

    public function __construct(private SnappPayClient $client) {}

    /**
     * @return array{show:bool,title:?string,description:?string,reason:?string}
     */
    public function forInvoice(Invoice $invoice, ?Customer $customer): array
    {
        $local = $this->localReason($invoice, $customer);

        if ($local !== null) {
            return $this->no($local);
        }

        $offer = $this->offer((int) $invoice->total);

        if (! $offer['eligible']) {
            return $this->no('not-eligible');
        }

        return [
            'show'        => true,
            // عیناً از اسنپ‌پی؛ هیچ متنِ ثابتی از سمتِ ما
            'title'       => $offer['title'],
            'description' => $offer['description'],
            'reason'      => null,
        ];
    }

    /**
     * پاسخِ eligible برای یک مبلغ.
     *
     * ⚠️ تماسِ ناموفق «نمی‌دانیم» است و «نمی‌دانیم» مثلِ «نه» رفتار می‌کند:
     * دکمه‌ای که بعداً سرِ گرفتنِ توکن رد شود، از نبودنش بدتر است. و نتیجهٔ
     * منفی کش **نمی‌شود** تا یک قطعیِ گذرا درگاه را پنج دقیقه نخواباند.
     *
     * @return array{eligible:bool,title:?string,description:?string}
     */
    public function offer(int $amountToman): array
    {
        $key = 'snapppay:eligible:'.$amountToman;

        $hit = Cache::get($key);

        if (is_array($hit)) {
            return $hit;
        }

        $res = $this->client->eligible($amountToman);

        if ($res['eligible']) {
            Cache::put($key, $res, self::CACHE_TTL);
        }

        return $res;
    }

    /**
     * شرط‌هایی که بدونِ تماسِ شبکه‌ای معلوم‌اند — و همیشه **اول** سنجیده
     * می‌شوند، تا برای فاکتوری که اصلاً واجد شرایط نیست به اسنپ‌پی زنگ نزنیم.
     */
    private function localReason(Invoice $invoice, ?Customer $customer): ?string
    {
        if (! (bool) config('snapppay.enabled') || ! $this->client->configured()) {
            return 'disabled';
        }

        // تصمیمِ کارفرما: «تمام خریدهایی که با زبان فارسی انجام می‌شود»
        if (! in_array(app()->getLocale(), (array) config('snapppay.locales', ['fa']), true)) {
            return 'locale';
        }

        if (! in_array((string) $invoice->currency_code, (array) config('snapppay.currencies', ['IRT']), true)) {
            return 'currency';
        }

        /*
        | ⚠️ شارژِ کیفِ پول پیش‌فرض بیرون است: خریدِ اعتبار با اقساط عملاً
        | وام‌دادنِ نقد است و مشتری می‌تواند اعتبار بخرد، خرج نکند و پس بخواهد.
        */
        if ((string) $invoice->kind === 'topup' && ! (bool) config('snapppay.allow_topup', false)) {
            return 'topup';
        }

        if (! $invoice->isPayable() || (int) $invoice->due() !== (int) $invoice->total) {
            return 'not-payable';
        }

        /*
        | 🔴 تنها چیزی که بین تستِ ما و مشتریِ واقعی می‌ایستد.
        |
        | کارفرما تصمیم گرفت استیجینگ روی **همان سرورِ پروداکشن** باشد. تا
        | وقتی این فهرست پر است، درگاه فقط برای همان حساب‌ها دیده می‌شود.
        | خالی‌کردنش = بازکردن برای همه، و باید تصمیمِ آگاهانه باشد نه
        | فراموشی؛ برای همین صریح در .env است، نه پیش‌فرضِ کد.
        */
        $testers = (array) config('snapppay.test_emails', []);

        if ($testers !== [] && ! in_array((string) $customer?->email, $testers, true)) {
            return 'not-tester';
        }

        return null;
    }

    /** @return array{show:bool,title:null,description:null,reason:string} */
    private function no(string $reason): array
    {
        return ['show' => false, 'title' => null, 'description' => null, 'reason' => $reason];
    }
}
