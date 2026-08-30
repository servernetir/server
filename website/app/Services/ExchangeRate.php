<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * نرخ ارز آزاد از alanchand.com — مبنای تبدیل قیمت رسیلری به تومان.
 *
 * سایت React است ولی صفحهٔ هر ارز سرور-رندر است و قیمت ده‌ها بار در آن
 * تکرار می‌شود (سربرگ، جدول، نمودار). پس به‌جای سلکتور شکننده،
 * «پرتکرارترین عدد کامادار در بازهٔ منطقی» را برمی‌داریم — این به تغییر
 * قالب سایت مقاوم است.
 *
 * اعتبارسنجی حیاتی است: نرخ غلط، کل کاتالوگ را غلط قیمت‌گذاری می‌کند.
 * اگر استخراج مطمئن نبود، به‌جای ذخیرهٔ زباله مقدار قبلی حفظ و خطا ثبت می‌شود.
 */
class ExchangeRate
{
    /** ارزهایی که می‌توانیم نرخشان را بگیریم → مسیر صفحه در alanchand */
    private const SOURCES = [
        'USD' => 'usd',
        'EUR' => 'eur',
    ];

    /** بازهٔ عاقلانه به تومان — بیرون از این یعنی استخراج اشتباه */
    private const MIN_TOMAN = 20_000;
    private const MAX_TOMAN = 5_000_000;

    private function key(string $currency): string
    {
        return 'fx.'.strtolower($currency).'_irt';
    }

    /**
     * آخرین نرخ ذخیره‌شده، یا null.
     *
     * 🔴 خروجی **یا یک ردیفِ کاملِ معتبر است یا null** — هرگز چیزِ نیم‌بند.
     *
     * تاریخچه: فراخوان‌ها مستقیم `$row['rate_toman']` را می‌خواندند. کافی بود
     * شکلِ ذخیره‌شده عوض شود (یا ردیفِ کهنه‌ای از نسخهٔ قبلی در کش بماند) تا
     * `Undefined array key` بدهد و کرونِ ساعتی با کدِ ۱ بمیرد — که خودش
     * `schedule:run` را پر از خطا می‌کرد. نگهبانِ شکل این‌جا است تا هیچ
     * فراخوانی مجبور نباشد تکرارش کند.
     *
     * ⚠️ ردیفِ خراب **دور ریخته می‌شود، نه ترمیم**. نرخِ حدسی یعنی فروشِ سرور
     * زیرِ بهای خرید؛ `null` یعنی «نمی‌دانم»، و بقیهٔ سامانه از قبل می‌داند با
     * «نمی‌دانم» چه کند (پلن از فروشگاه بیرون می‌رود).
     */
    public function current(string $currency = 'USD'): ?array
    {
        try {
            $row = Cache::get($this->key($currency));
        } catch (\Throwable $e) {
            Log::warning('ExchangeRate cache read failed', ['currency' => $currency, 'err' => $e->getMessage()]);

            return null;
        }

        if (! is_array($row)) {
            /*
            | کشِ سرد ⇒ آخرین نرخِ پایدار (اگر خیلی کهنه نشده).
            |
            | 🔴 بی‌این شاخه، یک قطعیِ چندساعتهٔ منبعِ نرخ کلِ پرداختِ رمزارز را
            | خاموش می‌کرد — با استخرِ سالمِ آدرس و مشتریِ آمادهٔ پرداخت.
            */
            $last = $this->lastKnown($currency);

            return $last === null ? null : [
                'rate_toman' => $last['rate_toman'],
                'currency' => strtoupper($currency),
                'source' => 'last-known',
                'at' => $last['at'],
                'stale' => true,
            ];
        }

        $rate = $row['rate_toman'] ?? null;

        if (! is_numeric($rate) || (int) $rate < self::MIN_TOMAN || (int) $rate > self::MAX_TOMAN) {
            // بلند، ولی بی‌استثنا و **گلوگاه‌دار**: این متد در رندرِ هر صفحه صدا
            // زده می‌شود و بی‌گلوگاه، یک ردیفِ خرابِ ماندگار پنجرهٔ ۴۰۰ خطیِ
            // ردیاب را پر می‌کرد و خطاهای گران‌تر را بیرون می‌انداخت.
            \App\Support\ErrorTracker::noteOnce('pricing',
                'ردیفِ نرخِ ارزِ ذخیره‌شده معتبر نیست و نادیده گرفته شد ('.$currency.'). '
                .'تا گرفتنِ نرخِ تازه، قیمتِ ارزی ساخته نمی‌شود.', 900);

            return null;
        }

        return ['rate_toman' => (int) $rate] + $row + ['currency' => strtoupper($currency), 'at' => null];
    }

    /**
     * نرخ فعلی به تومان. اگر کش خالی بود یک بار می‌گیرد.
     * null یعنی واقعاً در دسترس نیست — فراخوان باید تصمیم بگیرد چه کند.
     */
    public function toToman(string $currency = 'USD'): ?int
    {
        $currency = strtoupper($currency);

        // تومان به تومان
        if (in_array($currency, ['IRT', 'IRR'], true)) {
            return $currency === 'IRR' ? 10 : 1;
        }

        $row = $this->current($currency) ?? $this->refresh($currency);

        return $row['rate_toman'] ?? null;
    }

    /** دریافت زنده، اعتبارسنجی و ذخیره. در صورت شکست مقدار قبلی دست‌نخورده می‌ماند. */
    public function refresh(string $currency = 'USD'): ?array
    {
        /*
        | ═══ 🔴 کلیدِ خاموشیِ نرخِ زنده — و چرا نبودش یک باگِ واقعی بود ═══
        |
        | `toToman()` روی کشِ سرد **خودش می‌رود اینترنت**. یعنی «نرخ نداریم» در
        | این سیستم اصلاً قابلِ رخ‌دادن نبود: هر بار که کش خالی بود، یک اسکرپِ
        | زنده جایش را پر می‌کرد.
        |
        | دو پیامد داشت، هر دو واقعی:
        |
        |  ۱) **در تست**: `DomainPricingRateTest` و `CryptoCheckoutVisibilityTest`
        |     سناریوی «نرخ سرد» را می‌ساختند و بعد به alanchand.com وصل می‌شدند
        |     و نرخِ واقعی می‌گرفتند. آن تست‌ها سال‌ها سبز بودند **فقط چون سایت
        |     در دسترس نبود** — یعنی سبزیِ سوئیت به اینترنت گره خورده بود.
        |     `DomainPricingRateTest` حتی `config('services.exchange.enabled')`
        |     را false می‌کرد؛ پرچمی که **هیچ‌جای کد خوانده نمی‌شد**. همان تلهٔ
        |     «مسیرِ غلطِ config = درایورِ خاموش»، این‌بار در جهتِ عکس: تست فکر
        |     می‌کرد چیزی را خاموش کرده و نکرده بود.
        |
        |  ۲) **در پروداکشن**: هیچ راهی برای فریزکردنِ نرخ نبود. اگر منبع خراب
        |     یا دستکاری شود، قیمتِ همهٔ دامنه‌ها و سرورها با آن تکان می‌خورد و
        |     تنها راهِ توقف، ویرایشِ کد است.
        |
        | حالا پرچم واقعی است. خاموش که باشد، `current()` هنوز کار می‌کند (نرخِ
        | ذخیره‌شده معتبر است) ولی هیچ تماسِ تازه‌ای نمی‌رود — یعنی «نمی‌دانم»
        | واقعاً «نمی‌دانم» می‌مانَد و لایه‌های بالاتر (که همه `null` را به
        | «قیمت را نشان نده» ترجمه می‌کنند) کارِ درست را می‌کنند.
        */
        if (! config('services.exchange.enabled', true)) {
            return null;
        }

        $currency = strtoupper($currency);
        $slug = self::SOURCES[$currency] ?? null;

        if ($slug === null) {
            Log::warning('ExchangeRate: currency not supported', ['currency' => $currency]);
            return null;
        }

        try {
            $html = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (compatible; ServerNetBot/1.0)',
                'Accept-Language' => 'fa,en;q=0.8',
            ])->timeout(12)->retry(2, 500)
              ->get("https://alanchand.com/currencies-price/{$slug}")
              ->body();
        } catch (\Throwable $e) {
            Log::warning('ExchangeRate fetch failed', ['currency' => $currency, 'err' => $e->getMessage()]);
            return null;
        }

        $toman = $this->extract($html);
        if ($toman === null) {
            Log::warning('ExchangeRate parse failed — keeping last value', ['currency' => $currency]);
            return null;
        }

        $row = [
            'currency'   => $currency,
            'rate_toman' => $toman,
            'source'     => 'alanchand.com',
            'at'         => now()->toIso8601String(),
        ];

        Cache::put($this->key($currency), $row, now()->addHours(6));
        $this->remember($currency, $row);

        return $row;
    }

    /**
     * ═══ 🔴 پشتوانهٔ پایدارِ نرخ — چرا لازم شد ═══
     *
     * نرخ فقط در **کش** (۶ ساعت) زندگی می‌کرد و تنها منبعش اسکرپِ یک سایتِ
     * ایرانی بود. یعنی یک نقطهٔ شکستِ تکی: تغییرِ ساختارِ آن صفحه، قطعیِ چند
     * ساعته، یا پاک‌شدنِ کش ⇒  نال ⇒ **کلِ پرداختِ رمزارز خاموش**،
     * با استخرِ سالمِ آدرس. کارفرما دقیقاً همین را دید: «۵ ولت آزاد است ولی
     * برای مشتری غیرفعال».
     *
     * حالا هر نرخِ موفق در  هم می‌نشیند (که برخلافِ کش پاک نمی‌شود)
     * و اگر کش سرد باشد از همان خوانده می‌شود.
     *
     * ⚠️ **با سقفِ سن.** نرخِ کهنه یعنی ارزان‌فروشی؛ پس بعد از این مدت واقعاً
     * «نمی‌دانم» می‌شود و لایه‌های بالاتر قیمت نمی‌سازند. ۴۸ ساعت مرزِ معقولی
     * است: نوسانِ دو روزهٔ دلار قابلِ تحمل است، ولی یک هفته نه.
     */
    private const FALLBACK_MAX_AGE_HOURS = 48;

    private function rememberKey(string $currency): string
    {
        return 'fx_last_'.strtolower($currency);
    }

    /** آخرین نرخِ موفق را پایدار نگه دار — کش می‌رود، این می‌مانَد. */
    private function remember(string $currency, array $row): void
    {
        try {
            \App\Models\Setting::put($this->rememberKey($currency), json_encode([
                'rate_toman' => (int) $row['rate_toman'],
                'at' => now()->toIso8601String(),
                'source' => (string) ($row['source'] ?? ''),
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // ذخیرهٔ پشتوانه هرگز نباید مسیرِ اصلی را بشکند
            Log::warning('ExchangeRate remember failed', ['currency' => $currency, 'err' => $e->getMessage()]);
        }
    }

    /**
     * آخرین نرخِ پایدار، اگر هنوز آن‌قدر کهنه نشده که خطرناک باشد.
     *
     * @return array{rate_toman:int,at:string,stale:bool}|null
     */
    public function lastKnown(string $currency = 'USD'): ?array
    {
        try {
            $raw = \App\Models\Setting::get($this->rememberKey($currency));
        } catch (\Throwable) {
            return null;
        }

        if (blank($raw)) {
            return null;
        }

        $row = json_decode((string) $raw, true);

        if (! is_array($row) || ! is_numeric($row['rate_toman'] ?? null)) {
            return null;
        }

        $rate = (int) $row['rate_toman'];

        // همان بازهٔ اعتبارِ مسیرِ اصلی — پشتوانه نباید درِ پشتیِ عددِ بی‌معنی باشد
        if ($rate < self::MIN_TOMAN || $rate > self::MAX_TOMAN) {
            return null;
        }

        try {
            $at = \Illuminate\Support\Carbon::parse((string) ($row['at'] ?? ''));
        } catch (\Throwable) {
            return null;
        }

        if ($at->lt(now()->subHours(self::FALLBACK_MAX_AGE_HOURS))) {
            return null;          // آن‌قدر کهنه که فروختن با آن ضرر است
        }

        return ['rate_toman' => $rate, 'at' => $at->toIso8601String(), 'stale' => true];
    }

    /** استخراج قیمت از HTML — عمومی برای تست‌پذیری */
    public function extract(string $html): ?int
    {
        $html = strtr($html, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '٬'=>',',
        ]);

        if (! preg_match_all('/\b([0-9]{2,3}(?:,[0-9]{3})+)\b/', $html, $m)) {
            return null;
        }

        $counts = [];
        foreach ($m[1] as $raw) {
            $n = (int) str_replace(',', '', $raw);
            if ($n >= self::MIN_TOMAN && $n <= self::MAX_TOMAN) {
                $counts[$n] = ($counts[$n] ?? 0) + 1;
            }
        }
        if (! $counts) {
            return null;
        }

        arsort($counts);
        $top = array_key_first($counts);

        // باید چند بار تکرار شده باشد وگرنه احتمالاً عدد رندوم صفحه است
        return $counts[$top] >= 3 ? $top : null;
    }
}
