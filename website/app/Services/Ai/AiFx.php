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
 * ═══ پنجرهٔ واقعیِ ۲۴ ساعته، نه یک نشانِ تکی ═══
 *
 * نسخهٔ اول یک نشانِ تکی `{rate, at}` داشت که ۲۴ ساعت پس از **نوشته‌شدن** منقضی
 * می‌شد. بازبینیِ پیش از انتشار نشان داد این در دو قدمِ پشتِ‌سرِهم ۵٫۹٪ افت را در
 * چند دقیقه راه می‌دهد: بازارِ صاف نشان را بازنویسی نمی‌کند، پس نزدیکِ ۲۴ ساعتگی
 * یک اسکرپِ خراب اول ۳٪ می‌بَرد و انقضا بلافاصله ۳٪ دیگر. «تازه‌کردنِ نشان وقتی
 * نرخ به آن می‌رسد» هم کافی نبود: نرخِ ۹۹٬۹۹۹ زیرِ نشانِ ۱۰۰٬۰۰۰ هرگز تازه‌اش
 * نمی‌کند و همان دو قدم برمی‌گردد.
 *
 * حالا `Setting ai_fx_hw_{cur}` بیشینهٔ نرخِ **مؤثرِ** هر ساعتِ UTC را نگه می‌دارد
 * و کف = ⌈۰٫۹۷ × بیشینهٔ سطل‌هایی که در ۲۴ ساعتِ اخیر شروع شده‌اند⌉. هر نرخِ مؤثری
 * که در ۲۴ ساعتِ گذشته فروخته شده در سطلِ ساعتِ خودش هست، پس R در **هر** بازهٔ
 * ۲۴ ساعته حداکثر ۳٪ می‌افتد — اثبات‌پذیر، نه تقریبی. سطل‌بندیِ ساعتی فقط
 * محافظه‌کارتر است (تا ۲۵ ساعت عقب را می‌بیند).
 *
 * سامانهٔ بیکار (هیچ سطلی در ۲۴ ساعت) آخرین سطل را مرجع می‌گیرد: مکثِ طولانی نباید
 * افتِ بزرگ را یک‌جا بپذیرد. بهایش گران‌فروشیِ چندروزه پس از افتِ واقعیِ بازار است،
 * که برگشت‌پذیر است؛ اگر ضامن به‌خطا بالا مانده: `ai:price-preview --reset-fx-hw=USD`.
 *
 * ردیفِ **خراب** (دست‌کاری‌شده یا نیمه‌نوشته) فروش را می‌بندد و خبر می‌دهد. نسخهٔ
 * اول ردیفِ خراب را «نبودن» می‌خواند و در همان تماس با نرخِ پایین بازنویسی‌اش
 * می‌کرد — محافظت برای همیشه و بی‌صدا از دست می‌رفت.
 *
 * نوشتن: یک بار در هر ساعت (سطلِ تازه) و هر بار که نرخ در همان ساعت بالا برود.
 * هر نوشتنِ Setting کشِ تنظیمات را خالی می‌کند، پس نباید در هر درخواست رخ دهد.
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

        $buckets = $this->buckets($currency);

        if ($buckets === null) {
            ErrorTracker::noteOnce('pricing',
                "ضامنِ افتِ نرخِ {$currency} (Setting {$this->hwKey($currency)}) خراب است؛ فروشِ AI بسته شد. "
                ."پس از بررسی: php artisan ai:price-preview --reset-fx-hw={$currency}", 3600);

            return null;
        }

        $reference = self::reference($buckets);
        $rate = $best['rate'];
        $source = $best['source'];
        $floor = $reference === null ? 0 : self::ceilBp($reference, 10_000 - (int) config('ai.fx_max_daily_drop_bp', 300));

        if ($rate < $floor) {
            $rate = $floor;
            $source = 'ratchet';
        }

        $this->record($currency, $buckets, $rate);

        return new AiFxQuote(
            currency: $currency,
            rate: $rate,
            source: $source,
            at: $best['at'],
            highWater: $reference,
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

    /**
     * مرجعِ ضامن برای نمایش: بیشینهٔ ۲۴ ساعتِ اخیر (یا آخرین سطل اگر بیکار بوده).
     * `malformed` یعنی ردیف خراب است و فروش بسته.
     *
     * @return array{rate:?int, hours:int, malformed:bool}
     */
    public function highWater(string $currency): array
    {
        $b = $this->buckets(strtoupper($currency));

        return $b === null
            ? ['rate' => null, 'hours' => 0, 'malformed' => true]
            : ['rate' => self::reference($b), 'hours' => count($b), 'malformed' => false];
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

    /**
     * سطل‌های ساعتی: کلید `YmdH` به UTC، مقدار بیشینهٔ نرخِ مؤثرِ آن ساعت.
     * [] = هنوز هیچ؛ null = ردیف هست ولی خراب است (⇒ بستنِ فروش).
     *
     * @return array<string,int>|null
     */
    private function buckets(string $currency): ?array
    {
        $raw = Setting::get($this->hwKey($currency));

        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $row = json_decode($raw, true);
        if (! is_array($row) || ($row['v'] ?? null) !== 2 || ! is_array($row['h'] ?? null)) {
            return null;
        }

        // سقف = بالاترین نرخِ مؤثرِ ممکن (۵ میلیون + سربارِ کهنگی)؛ سقفِ خامِ ۵ میلیون
        // نرخِ کهنهٔ سربارخورده را رد می‌کرد و ضامن را خاموش
        $max = self::ceilBp(self::MAX_RATE, 10_000 + (int) config('ai.fx_stale_buffer_bp', 200));
        $out = [];
        foreach ($row['h'] as $hour => $rate) {
            if (preg_match('/^\d{10}$/', (string) $hour) !== 1 || ! is_int($rate) || $rate < self::MIN_RATE || $rate > $max) {
                return null;
            }
            $out[(string) $hour] = $rate;
        }

        return $out;
    }

    /** بیشینهٔ سطل‌هایی که در ۲۴ ساعتِ اخیر شروع شده‌اند؛ اگر هیچ، آخرین سطل */
    private static function reference(array $buckets): ?int
    {
        if ($buckets === []) {
            return null;
        }

        $since = now()->utc()->subHours(24)->format('YmdH');
        $window = array_filter($buckets, fn ($hour) => (string) $hour >= $since, ARRAY_FILTER_USE_KEY);

        if ($window !== []) {
            return max($window);
        }

        ksort($buckets, SORT_STRING);

        return end($buckets);
    }

    /** @param array<string,int> $buckets */
    private function record(string $currency, array $buckets, int $rate): void
    {
        $hour = now()->utc()->format('YmdH');

        if (($buckets[$hour] ?? 0) >= $rate) {
            return;                        // همین ساعت با همین نرخ یا بالاتر ثبت شده
        }

        $buckets[$hour] = $rate;

        // فقط ۲۶ ساعتِ اخیر لازم است؛ سطلِ همین ساعت همیشه می‌مانَد
        $keep = now()->utc()->subHours(26)->format('YmdH');
        $buckets = array_filter($buckets, fn ($h) => (string) $h >= $keep, ARRAY_FILTER_USE_KEY);
        ksort($buckets, SORT_STRING);

        try {
            Setting::put($this->hwKey($currency), json_encode(['v' => 2, 'h' => $buckets]));
        } catch (\Throwable) {
            // نوشتنِ ضامن نباید قیمت‌دادن را بشکند؛ سطل‌های قبلی سرِ جایشان می‌مانند
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
