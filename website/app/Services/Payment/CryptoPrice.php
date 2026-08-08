<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * قیمتِ دلاریِ دارایی‌های نوسانی (فعلاً فقط TRX).
 *
 * ═══ چرا این کلاس اصلاً وجود دارد ═══
 *
 * تتر را ۱ دلار می‌گیریم چون استیبل‌کوین است و لنگرش تعریفِ خودش است. ولی TRX
 * قیمتِ بازار دارد، و بدونِ قیمتِ درست دو حالت پیش می‌آید و هر دو پول است:
 * قیمتِ زیادی بالا ⇒ مقدارِ خواسته‌شده کم می‌شود و سرویس را ارزان فروخته‌ایم؛
 * قیمتِ زیادی پایین ⇒ مشتری بیشتر می‌دهد و تیکت و بی‌اعتمادی می‌آید.
 *
 * ═══ قواعدی که از این‌جا کوتاه نمی‌آییم ═══
 *
 * ۱. **بی‌قیمت ⇒ عرضه نمی‌کنیم.** هیچ عددِ پیش‌فرض، هیچ حدس. همان قاعدهٔ
 *    `cloud_plans` که قیمتِ صفر را به فروشِ کورکورانه ترجیح می‌دهد.
 * ۲. **دو منبعِ مستقل باید هم‌داستان باشند.** یک منبع می‌تواند خراب باشد،
 *    فرمتش عوض شده باشد، یا صفحهٔ خطا با کدِ ۲۰۰ بدهد. اختلافِ بیش از
 *    {@see MAX_SPREAD_PCT} درصد یعنی «نمی‌دانیم» ⇒ عرضه نمی‌کنیم.
 * ۳. **بازهٔ عاقلانه.** پاسخِ درست‌شکل ولی بی‌معنا (۰ یا ۱۰۰۰ دلار برای TRX)
 *    رد می‌شود. این همان محافظی است که `ExchangeRate` برای دلار دارد.
 * ۴. **مسیرِ وب هرگز HTTP نمی‌زند.** صفحهٔ فاکتور فقط کش را می‌خواند
 *    ({@see cachedUsd}); تازه‌کردن کارِ کرونِ `crypto:watch` است. وگرنه یک
 *    منبعِ کُند، صفحهٔ پرداختِ مشتری را ده‌ها ثانیه معلق نگه می‌داشت.
 */
class CryptoPrice
{
    /** تا این مدت قیمتِ ذخیره‌شده معتبر است */
    public const TTL_MINUTES = 20;

    /** بعد از یک تلاشِ ناموفق، این‌قدر دست نگه می‌داریم */
    public const RETRY_MINUTES = 5;

    /** بیشترین اختلافِ قابل‌قبولِ دو منبع */
    public const MAX_SPREAD_PCT = 3.0;

    /**
     * بازهٔ عاقلانهٔ قیمت به دلار.
     *
     * ⚠️ این عددها سلیقه نیستند: بیرونشان یعنی یا پاسخ را غلط خوانده‌ایم یا
     * بازار آن‌قدر عوض شده که باید آدم نگاه کند. در هر دو حالت، سکوت بهتر از
     * صدور است.
     */
    private const BAND = [
        'TRX' => [0.01, 3.0],
    ];

    /**
     * منابعِ عمومی و بی‌کلید.
     *
     * ⚠️ عمداً سه تا، از سه سازمانِ متفاوت: از ایران دسترسیِ هیچ‌کدام تضمین
     * نیست، و قاعدهٔ «دو منبعِ هم‌داستان» فقط وقتی معنا دارد که واقعاً بیش از
     * دو تا در دسترس باشد.
     */
    private const SOURCES = [
        'TRX' => [
            'https://api.coinbase.com/v2/prices/TRX-USD/spot',
            'https://api.kraken.com/0/public/Ticker?pair=TRXUSD',
            'https://api.coingecko.com/api/v3/simple/price?ids=tron&vs_currencies=usd',
        ],
    ];

    /** آیا اصلاً برای این دارایی منبعی می‌شناسیم؟ */
    public function isPriceable(string $asset): bool
    {
        return isset(self::SOURCES[$asset]);
    }

    /**
     * قیمتِ ذخیره‌شده — **بدونِ هیچ تماسِ شبکه‌ای**.
     *
     * مسیرِ رندرِ صفحه فقط این را صدا می‌زند.
     */
    public function cachedUsd(string $asset): ?float
    {
        /*
        | ⚠️ حتی خواندنِ کش هم می‌تواند بترکد (کشِ این پروژه روی دیتابیس بوده و
        | یک قطعیِ گذرا یک بار کلِ دقیقهٔ کرون را کشت). این متد از مسیرِ رندرِ
        | صفحهٔ فاکتور صدا زده می‌شود، پس شکستش باید «قیمتی نداریم» باشد، نه
        | ۵۰۰ برای مشتریِ وسطِ پرداخت.
        */
        try {
            $v = Cache::get($this->key($asset));
        } catch (\Throwable) {
            return null;
        }

        return is_float($v) && $v > 0 ? $v : null;
    }

    /** قیمت، و اگر کش خالی بود یک بار تلاشِ زنده (فقط مسیرِ صدورِ پرداخت) */
    public function usd(string $asset): ?float
    {
        return $this->cachedUsd($asset) ?? $this->refresh($asset);
    }

    /**
     * تازه‌کردنِ همهٔ دارایی‌های قیمت‌دار — از کرون صدا زده می‌شود.
     *
     * فقط وقتی کار می‌کند که کش خالی باشد؛ پس در حالتِ عادی هر ۲۰ دقیقه یک بار
     * و در حالتِ قطعی هر ۵ دقیقه یک بار به بیرون وصل می‌شود، نه هر دقیقه.
     */
    public function warm(): void
    {
        foreach (array_keys(self::SOURCES) as $asset) {
            if ($this->cachedUsd($asset) !== null || Cache::get($this->backoffKey($asset))) {
                continue;
            }

            $this->refresh($asset);
        }
    }

    /**
     * دریافتِ زنده، اعتبارسنجی، ذخیره.
     *
     * 🔴 در هر شکِ کوچک `null` برمی‌گرداند و **چیزی ذخیره نمی‌کند**.
     */
    public function refresh(string $asset): ?float
    {
        $urls = self::SOURCES[$asset] ?? null;

        if ($urls === null) {
            return null;
        }

        [$min, $max] = self::BAND[$asset];
        $quotes = [];

        foreach ($urls as $url) {
            $p = $this->quote($url);

            if ($p === null || $p < $min || $p > $max) {
                continue;
            }

            $quotes[] = $p;

            // دو تا کافی است — منبعِ سوم فقط پشتیبانِ قطعی است
            if (count($quotes) === 2) {
                break;
            }
        }

        if (count($quotes) < 2) {
            $this->backoff($asset, 'کمتر از دو منبعِ سالم پاسخ داد');

            return null;
        }

        $spread = abs($quotes[0] - $quotes[1]) / min($quotes[0], $quotes[1]) * 100;

        if ($spread > self::MAX_SPREAD_PCT) {
            $this->backoff($asset, sprintf('اختلافِ منابع %.1f%% بود', $spread));

            return null;
        }

        // ⚠️ کمینه، نه میانگین: اگر باید یک طرف خطا کنیم، طرفی که مشتری
        //    **بیشتر** می‌فرستد نه کمتر.
        $price = min($quotes);

        Cache::put($this->key($asset), $price, now()->addMinutes(self::TTL_MINUTES));
        Cache::forget($this->backoffKey($asset));

        return $price;
    }

    /** یک منبع → قیمت، یا null */
    private function quote(string $url): ?float
    {
        $json = $this->getJson($url);

        if ($json === null) {
            return null;
        }

        if (str_contains($url, 'coinbase.com')) {
            return $this->number($json['data']['amount'] ?? null);
        }

        if (str_contains($url, 'kraken.com')) {
            $result = $json['result'] ?? null;

            if (! is_array($result) || $result === []) {
                return null;
            }

            // ⚠️ نامِ جفت‌ارز نزدِ کراکن ثابت نیست (TRXUSD یا XTRXZUSD) —
            //    پس کلیدِ اول برداشته می‌شود، نه یک نامِ سخت‌کد.
            $first = $result[array_key_first($result)] ?? null;

            return $this->number($first['c'][0] ?? null);
        }

        if (str_contains($url, 'coingecko.com')) {
            return $this->number($json['tron']['usd'] ?? null);
        }

        return null;
    }

    /** ⚠️ هرگز استثنا بیرون نمی‌دهد — این مسیر از داخلِ کرون هم صدا زده می‌شود */
    private function getJson(string $url): ?array
    {
        try {
            $res = Http::timeout(5)->acceptJson()
                ->withHeaders(['User-Agent' => 'ServerNetBot/1.0'])
                ->get($url);

            if (! $res->successful()) {
                return null;
            }

            $json = $res->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** رشته/عدد → float مثبتِ متناهی، وگرنه null */
    private function number(mixed $raw): ?float
    {
        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return null;
        }

        if (is_string($raw) && ! is_numeric($raw)) {
            return null;
        }

        $v = (float) $raw;

        return is_finite($v) && $v > 0 ? $v : null;
    }

    private function backoff(string $asset, string $why): void
    {
        Log::warning('CryptoPrice: قیمت پذیرفته نشد — دارایی عرضه نمی‌شود', [
            'asset' => $asset, 'reason' => $why,
        ]);

        Cache::put($this->backoffKey($asset), true, now()->addMinutes(self::RETRY_MINUTES));
    }

    private function key(string $asset): string
    {
        return 'cyprice.'.strtolower($asset).'_usd';
    }

    private function backoffKey(string $asset): string
    {
        return 'cyprice.'.strtolower($asset).'_backoff';
    }
}
