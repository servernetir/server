<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * نرخ دلار آزاد از alanchand.com — مبنای قیمت‌گذاری دامنه.
 *
 * سایت React است ولی صفحهٔ /currencies-price/usd سرور-رندر است و قیمت
 * ده‌ها بار در آن تکرار می‌شود (سربرگ، جدول، نمودار). پس به‌جای سلکتور
 * شکننده، «پرتکرارترین عدد کامادار در بازهٔ منطقی» را برمی‌داریم — این به
 * تغییر قالب سایت مقاوم است.
 *
 * اعتبارسنجی حیاتی است: قیمت دلار غلط، کل کاتالوگ را غلط قیمت‌گذاری می‌کند.
 * پس اگر استخراج مطمئن نبود، به‌جای ذخیرهٔ زباله، مقدار قبلی حفظ و خطا ثبت
 * می‌شود.
 */
class ExchangeRate
{
    private const URL = 'https://alanchand.com/currencies-price/usd';
    private const CACHE_KEY = 'fx.usd_irt';

    /** بازهٔ عاقلانه برای دلار به تومان — بیرون از این یعنی استخراج اشتباه */
    private const MIN_TOMAN = 20_000;
    private const MAX_TOMAN = 2_000_000;

    /** آخرین نرخ ذخیره‌شده + زمانش، یا null اگر هنوز چیزی نداریم */
    public function current(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /**
     * دریافت زنده، اعتبارسنجی و ذخیره. مقدار جدید را برمی‌گرداند یا در صورت
     * شکست، مقدار قبلی را دست‌نخورده نگه می‌دارد و null برمی‌گرداند.
     */
    public function refresh(): ?array
    {
        try {
            $html = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; ServerNetBot/1.0)',
                'Accept-Language' => 'fa,en;q=0.8',
            ])->timeout(12)->retry(2, 500)->get(self::URL)->body();
        } catch (\Throwable $e) {
            Log::warning('ExchangeRate fetch failed', ['err' => $e->getMessage()]);
            return null;
        }

        $toman = $this->extract($html);
        if ($toman === null) {
            Log::warning('ExchangeRate parse failed — keeping last value');
            return null;
        }

        $row = [
            'usd_irt' => $toman,          // تومان
            'source'  => 'alanchand.com',
            'at'      => now()->toIso8601String(),
        ];
        // ۲ ساعت TTL: اگر کران یک بار جا افتاد، مقدار قدیمی هنوز هست ولی «کهنه» پیداست
        Cache::put(self::CACHE_KEY, $row, now()->addHours(6));

        return $row;
    }

    /** استخراج قیمت از HTML — عمومی برای تست‌پذیری */
    public function extract(string $html): ?int
    {
        // ارقام فارسی/عربی به لاتین
        $html = strtr($html, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '٬'=>',',
        ]);

        if (! preg_match_all('/\b([0-9]{2,3}(?:,[0-9]{3})+)\b/', $html, $m)) {
            return null;
        }

        // شمارش تکرار هر عدد که در بازهٔ منطقی است
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

        // پرتکرارترین برنده است؛ اگر مساوی شد، بزرگ‌ترین (قیمت جاری معمولاً بالاتر)
        arsort($counts);
        $top = array_key_first($counts);
        $topCount = $counts[$top];

        // اطمینان: باید دست‌کم چند بار تکرار شده باشد، وگرنه احتمالاً عدد رندوم است
        if ($topCount < 3) {
            return null;
        }

        return $top;
    }
}
