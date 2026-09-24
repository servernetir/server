<?php

namespace App\Services\Ai;

use App\Models\Setting;
use App\Services\ExchangeRate;
use App\Support\ErrorTracker;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * نرخِ ارزِ فروشِ AI — تومان به ازای یک واحدِ ارزِ بها (D2).
 *
 * ═══ چرا جدا از `ExchangeRate::toToman()` ═══
 *
 * `toToman()` روی کشِ سرد **خودش می‌رود اینترنت** (اسکرپِ ۳۰ ثانیه‌ای با دو
 * تلاشِ دوباره). روی مسیرِ پولیِ /v1 این یعنی درخواستِ مشتری پشتِ یک سایتِ
 * ایرانی گیر کند. پس این کلاس **فقط کش و تنظیمات را می‌خواند**؛ «نرخ نداریم»
 * یک پاسخِ مشروع است و فروش را می‌بندد (۵۰۳ `fx_unavailable`).
 *
 * ═══ قاعدهٔ انتخاب ═══
 *
 *   R = max(نرخِ دستیِ مالک، نرخِ اسکرپِ ≤۲۴ ساعت)
 *
 * `max` عمدی است: نرخِ دستیِ فراموش‌شده که از بازار عقب افتاده هرگز نمی‌تواند
 * ارزان‌فروشی کند — فقط وقتی بالاتر از بازار است اثر دارد. نرخِ اسکرپِ ۶ تا ۲۴
 * ساعته ۲٪ سربار می‌گیرد (دلار در ۲۴ ساعت می‌تواند بپرد و ما با نرخِ دیروز
 * می‌فروشیم).
 *
 * ═══ ضامنِ افت (ratchet) ═══
 *
 * نرخ فقط در جهتِ **افت** کُند می‌شود، چون فقط افت است که قیمت را زیرِ بها
 * می‌برد: R هر ۲۴ ساعت حداکثر ۳٪ پایین‌تر از بالاترین نرخِ دیده‌شده می‌رود. یک
 * اسکرپِ اشتباه‌خوانده یا دستکاری‌شده (مثلاً عددِ «۱۰٬۰۰۰» به‌جای «۱۰۰٬۰۰۰»
 * که هنوز در بازهٔ معتبرِ ۲۰هزار–۵میلیون است) در بدترین حالت ۳٪ در روز ارزان‌تر
 * می‌کند، نه ده برابر. افزایش بی‌درنگ است — گران‌فروشیِ موقت برگشت‌پذیر است،
 * ارزان‌فروشی نه.
 *
 * نشانِ بالاترین نرخ در `Setting ai_fx_hw_{cur}` است، نه کش: پاک‌شدنِ کش نباید
 * ضامن را هم پاک کند. وقتی نشان ۲۴ ساعت کهنه شد، به نرخِ **مؤثرِ** همان لحظه
 * (که خودش حداکثر ۳٪ زیرِ نشانِ قبلی است) بازنشانی می‌شود — پس افتِ واقعیِ
 * بازار هم روزی ۳٪ دنبال می‌شود، نه یک‌باره. اگر نشان به‌خطا بالا رفته باشد
 * (اسکرپِ اشتباهِ رو به بالا)، `ai:price-preview --reset-fx-hw=USD` پاکش می‌کند.
 *
 * نوشتنِ نشان فقط در بالارفتن یا یک بار در روز است؛ هر نوشتنِ Setting کشِ
 * تنظیمات را خالی می‌کند و نباید در هر درخواست رخ دهد.
 */
final class AiFx
{
    /** بازهٔ معتبرِ نرخ به تومان — همان بازهٔ اسکرپر، این‌بار برای نرخِ دستی هم */
    public const MIN_RATE = 20_000;

    public const MAX_RATE = 5_000_000;

    /** کلیدِ نرخِ دستیِ هر ارز — همان کلیدهایی که ابری و رمزارز می‌خوانند */
    private const OVERRIDE_KEYS = [
        'USD' => 'pricing_usd_rate_override',
        'EUR' => 'pricing_rate_override',
    ];

    public function __construct(private readonly ExchangeRate $rates) {}

    public static function supports(string $currency): bool
    {
        return array_key_exists(strtoupper($currency), self::OVERRIDE_KEYS);
    }

    /**
     * نرخِ قابلِ فروش، یا null یعنی «امروز نمی‌فروشیم».
     */
    public function quote(string $currency): ?AiFxQuote
    {
        $currency = strtoupper($currency);

        if (! self::supports($currency)) {
            return null;
        }

        $candidates = [];

        $override = $this->override($currency);
        if ($override !== null) {
            $candidates[] = ['rate' => $override, 'source' => 'override', 'at' => null];
        }

        $scraped = $this->scraped($currency);
        if ($scraped !== null) {
            $candidates[] = $scraped;
        }

        if ($candidates === []) {
            return null;
        }

        // بزرگ‌ترین برنده است؛ در تساوی، نرخِ دستی (تصمیمِ آگاهانهٔ مالک) نامیده می‌شود
        usort($candidates, fn ($a, $b) => $b['rate'] <=> $a['rate'] ?: ($a['source'] === 'override' ? -1 : 1));
        $best = $candidates[0];

        $hw = $this->highWater($currency);
        $rate = $best['rate'];
        $source = $best['source'];
        $floor = $hw === null ? 0 : self::ceilBp($hw['rate'], 10_000 - (int) config('ai.fx_max_daily_drop_bp', 300));

        if ($rate < $floor) {
            $rate = $floor;
            $source = 'ratchet';
        }

        $this->advanceHighWater($currency, $hw, $rate);

        return new AiFxQuote(
            currency: $currency,
            rate: $rate,
            source: $source,
            at: $best['at'],
            highWater: $hw['rate'] ?? null,
        );
    }

    /**
     * نرخِ **نمایشِ** یورو برای مشتریِ en/tr (D9) — نه برای قیمت‌گذاری.
     *
     * همان تقدمِ `CloudPricing::eurToToman()` (نرخِ دستی، بعد بازار) که شارژِ
     * یورویی با آن به تومان تبدیل می‌شود — ولی بی‌اسکرپِ زنده. null یعنی یورو
     * نشان داده نشود؛ حدسِ یورو هرگز.
     */
    public function displayRate(string $currency = 'EUR'): ?int
    {
        $currency = strtoupper($currency);

        // نرخِ خام، بی‌سربارِ کهنگی: سربار حاشیهٔ امنِ فروش است، نه نرخِ تبدیلِ شارژ
        return $this->override($currency) ?? $this->scraped($currency)['raw'] ?? null;
    }

    /** پاک‌کردنِ دستیِ نشانِ بالاترین نرخ — فقط از فرمانِ مدیر */
    public function resetHighWater(string $currency): void
    {
        Setting::put($this->hwKey($currency), null);
    }

    /** @return array{rate:int,at:string}|null */
    public function highWater(string $currency): ?array
    {
        $raw = Setting::get($this->hwKey($currency));
        $row = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($row) || ! is_int($row['rate'] ?? null) || ! is_string($row['at'] ?? null)) {
            return null;
        }

        if ($row['rate'] < self::MIN_RATE || $row['rate'] > self::MAX_RATE) {
            return null;
        }

        return $row;
    }

    /* ─────────────────────────────────────────────────────────── */

    private function override(string $currency): ?int
    {
        $v = trim((string) Setting::get(self::OVERRIDE_KEYS[$currency] ?? '', ''));

        if ($v === '' || preg_match('/^\d+$/', $v) !== 1) {
            return null;
        }

        $n = (int) $v;

        // بیرون از بازه = «خاموش»، نه «به نزدیک‌ترین مرز بچسبان». عددِ دستیِ
        // با یک صفرِ کم یا زیاد نباید مبنای هیچ فروشی شود.
        return $n >= self::MIN_RATE && $n <= self::MAX_RATE ? $n : null;
    }

    /** @return array{rate:int,source:string,at:?string,raw:int}|null */
    private function scraped(string $currency): ?array
    {
        try {
            // current() فقط کش + پشتوانهٔ پایدار را می‌خواند؛ هرگز اسکرپ نمی‌کند
            $row = $this->rates->current($currency);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($row) || ! is_numeric($row['rate_toman'] ?? null) || blank($row['at'] ?? null)) {
            // بی‌زمان = سنِ نامعلوم = غیرقابلِ اتکا
            return null;
        }

        $rate = (int) $row['rate_toman'];
        if ($rate < self::MIN_RATE || $rate > self::MAX_RATE) {
            return null;
        }

        try {
            $at = Carbon::parse((string) $row['at']);
        } catch (\Throwable) {
            return null;
        }

        $ageMin = $at->diffInMinutes(now(), false);
        if ($ageMin < -5 || $ageMin > 60 * (int) config('ai.fx_max_age_h', 24)) {
            return null;             // از آینده یا کهنه‌تر از ۲۴ ساعت
        }

        if ($ageMin > 60 * (int) config('ai.fx_stale_after_h', 6)) {
            ErrorTracker::noteOnce('pricing',
                "نرخِ {$currency} برای فروشِ AI کهنه است (بیش از ".config('ai.fx_stale_after_h', 6)
                .' ساعت)؛ با سربارِ اطمینان فروخته می‌شود. fx:dollar را بررسی کنید.', 3600);

            return [
                'rate' => self::ceilBp($rate, 10_000 + (int) config('ai.fx_stale_buffer_bp', 200)),
                'source' => 'scraped+stale',
                'at' => $at->toIso8601String(),
                'raw' => $rate,
            ];
        }

        return ['rate' => $rate, 'source' => 'scraped', 'at' => $at->toIso8601String(), 'raw' => $rate];
    }

    /** @param array{rate:int,at:string}|null $hw */
    private function advanceHighWater(string $currency, ?array $hw, int $rate): void
    {
        $expired = $hw === null || Carbon::parse($hw['at'])->lt(now()->subHours(24));

        if (! $expired && $rate <= $hw['rate']) {
            return;
        }

        try {
            Setting::put($this->hwKey($currency), json_encode([
                'rate' => $rate,
                'at' => now()->toIso8601String(),
            ]));
        } catch (\Throwable) {
            // نشان‌نوشتن نباید قیمت‌دادن را بشکند؛ نشانِ قبلی سرِ جایش می‌ماند
        }
    }

    private function hwKey(string $currency): string
    {
        return 'ai_fx_hw_'.strtolower($currency);
    }

    /** ⌈x · bp / 10⁴⌉ — عددِ صحیح، گرد به بالا */
    private static function ceilBp(int $x, int $bp): int
    {
        return BigInteger::of($x)->multipliedBy($bp)->dividedBy(10_000, RoundingMode::Up)->toInt();
    }
}
