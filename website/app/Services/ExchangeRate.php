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

    /** آخرین نرخ ذخیره‌شده، یا null */
    public function current(string $currency = 'USD'): ?array
    {
        return Cache::get($this->key($currency));
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

        return $row;
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
