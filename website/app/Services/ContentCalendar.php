<?php

namespace App\Services;

use App\Models\Post;
use App\Support\Jalali;
use Illuminate\Support\Carbon;

/**
 * تقویمِ انتشار محتوا — تصمیم می‌گیرد هر مقاله «کِی» منتشر شود.
 *
 * ═══ چرا این کلاس لازم شد ═══
 *
 * تا پیش از این، `content:generate` زمانِ انتشار را با `random_int` در بازهٔ
 * «N روزِ آینده» می‌ریخت. نتیجه در عمل دو حالتِ بد داشت:
 *
 *   ۱) چند مقاله روی یک روز می‌افتاد و روزهای دیگر خالی می‌ماند —
 *      یعنی سایت به‌جای جریانِ ثابت، پالسی منتشر می‌کرد. گوگل جریانِ ثابت را
 *      نشانهٔ سایتِ زنده می‌داند؛ پالسِ نامنظم را نه.
 *   ۲) `--daily` دقیقاً یک مقاله در روز می‌گذاشت و **بر اساسِ type** می‌شمرد،
 *      پس بلاگ و پایگاه دانش هرکدام جداگانه روزی یکی می‌گذاشتند و
 *      هیچ‌کس نمی‌دانست مجموعِ روز چند تا شد.
 *
 * این کلاس یک سهمیهٔ **روزانه و مشترک بین همهٔ نوع‌ها** می‌سازد: هر روز
 * ۲ تا ۵ مطلب، با آهنگی که به روزِ هفتهٔ ایرانی می‌خورد.
 *
 * ═══ چرا سهمیه ثابت نیست ═══
 *
 * انتشارِ دقیقاً ۴ مطلب در روز، هر روز، ساعتِ گِرد، الگویی می‌سازد که از
 * بیرون کاملاً ماشینی به‌نظر می‌رسد. سهمیه بر اساسِ **خودِ تاریخ** به‌شکلِ
 * قطعی (نه تصادفی) بین ۲ تا ۵ نوسان می‌کند: قطعی است تا دو بار اجرا کردنِ
 * فرمان همان جواب را بدهد، و متغیر است تا الگو نداشته باشد.
 *
 * پنج‌شنبه و جمعه سبک‌تر است چون ترافیکِ B2B ایران در آخرِ هفته می‌افتد و
 * مقالهٔ خوب را نباید در کم‌بازدیدترین روز سوزاند.
 */
class ContentCalendar
{
    /** آخرین روزِ سالِ ۱۴۰۵ — افقِ برنامه‌ریزی */
    public const PLAN_UNTIL_JYEAR = 1405;

    /** ساعاتِ مجازِ انتشار: صبحِ کاری تا شبِ زودهنگام */
    private const HOUR_MIN = 9;

    private const HOUR_MAX = 21;

    /** @var array<string,int> کشِ شمارشِ روزها در یک اجرا */
    private array $taken = [];

    /** @var array<string,list<int>> دقیقه‌های اشغال‌شدهٔ هر روز (به‌صورتِ دقیقه از نیمه‌شب) */
    private array $minutes = [];

    private ?Carbon $cursor = null;

    public function __construct()
    {
        $this->load();
    }

    /**
     * سهمیهٔ انتشارِ یک روز: عددی بین ۲ تا ۵، قطعی نسبت به تاریخ.
     *
     * ⚠️ عمداً از `random_int` استفاده نمی‌شود. اگر سهمیه تصادفی باشد، اجرای
     * دومِ همان فرمان می‌تواند سهمیهٔ دیروز را کمتر ببیند و مقاله‌ای را روی
     * روزی بگذارد که قبلاً پر شده بود.
     */
    public function quotaFor(Carbon $day): int
    {
        $weekday = Jalali::weekdayIndex($day);   // ۰=شنبه … ۶=جمعه

        // پنج‌شنبه و جمعه: سبک
        if ($weekday >= 5) {
            return 2;
        }

        /*
         * شنبه تا چهارشنبه: معمولاً ۳، گاهی ۴، به‌ندرت ۵.
         *
         * توزیع اتفاقی نیست — با اندازهٔ **صف** کالیبره شده. ظرفیتِ کل باید
         * کمی از تعدادِ موضوع‌های برنامه‌ریزی‌شده بیشتر باشد و نه خیلی بیشتر:
         *
         *   ظرفیتِ کم  ⇒ صف ته نمی‌کشد و موضوع‌ها به سالِ بعد می‌افتند
         *   ظرفیتِ زیاد ⇒ `nextSlot()` حریصانه روزهای اول را پر می‌کند، صف
         *                زودتر تمام می‌شود، و **هفته‌های آخرِ سال خالی می‌مانَد**
         *
         * حالتِ دوم بدتر است چون بی‌صدا رخ می‌دهد: همه‌چیز درست کار می‌کند و
         * فقط اسفند ساکت است.
         *
         * ⚠️ اگر روزی موضوع‌ها را زیاد یا کم کردی، این‌جا را هم تنظیم کن.
         * `ContentPipelineTest::test_calendar_capacity_covers_the_queue_without_leaving_a_dead_tail`
         * هر دو طرفِ این تعادل را می‌سنجد و اگر از آن خارج شوی قرمز می‌شود.
         */
        /*
         * ⚠️ این توزیع در ۸ شهریور ۱۴۰۵ یک پله بالا رفت و دلیلش را بنویس تا
         * کسی برش نگرداند: تنظیمِ قبلی (۳/۳/۲) ظرفیتِ ۶۹۱ می‌داد که همان
         * موقع فقط ۱ نوبت از صف بیشتر بود. پنجرهٔ سال هر روز کوتاه‌تر می‌شود
         * (~۳٫۵ نوبت در روز) ولی صف فقط با تولید کم می‌شود؛ دو روز بعد ظرفیت
         * از صف کمتر شد و تست قرمز شد.
         *
         * درس: حاشیهٔ «۱ نوبت» حاشیه نیست. ظرفیت باید صفِ **نصبِ خالی** را هم
         * بپوشاند (بدترین حالت)، نه فقط صفِ امروزِ پروداکشن را.
         */
        return match (crc32($day->toDateString()) % 8) {
            3, 4       => 4,
            5, 6, 7    => 5,
            default    => 3,
        };
    }

    /**
     * زمانِ انتشارِ بعدی. روزها را از فردا جلو می‌رود تا روزی پیدا کند که
     * سهمیه‌اش پر نشده باشد، سپس ساعتی می‌دهد که با بقیهٔ همان روز فاصله دارد.
     *
     * اگر تقویمِ سال پر شده باشد `null` برمی‌گرداند — فراخوان باید این را
     * جدی بگیرد و تولید را متوقف کند، نه اینکه مقاله را روی سالِ بعد بریزد.
     */
    public function nextSlot(?string $preferredDate = null): ?Carbon
    {
        $day = $this->startDay($preferredDate);
        $end = $this->endOfPlan();
        $pinned = $preferredDate !== null;

        while ($day->lte($end)) {
            $key = $day->toDateString();
            if (($this->taken[$key] ?? 0) < $this->quotaFor($day)) {
                // مطلبِ تاریخ‌دار نباید مکان‌نمای عمومی را جلو ببرد، وگرنه یک
                // مقالهٔ نوروزی کلِ صف را به اسفند پرت می‌کند و پنج ماه سکوت
                // می‌سازد
                if (! $pinned) {
                    $this->cursor = $day->copy();
                }

                return $this->reserve($day);
            }
            $day = $day->copy()->addDay();
        }

        return null;
    }

    /**
     * نقطهٔ شروعِ جستجو. تاریخِ درخواستی فقط وقتی پذیرفته می‌شود که در آینده و
     * داخلِ سالِ برنامه باشد؛ تاریخِ گذشته یعنی مطلبی که هرگز در فید دیده
     * نمی‌شود، پس به صفِ عادی برمی‌گردد.
     */
    private function startDay(?string $preferredDate): Carbon
    {
        $default = $this->cursor ?? now()->addDay()->startOfDay();

        if ($preferredDate === null || trim($preferredDate) === '') {
            return $default;
        }

        try {
            $wanted = Carbon::parse($preferredDate)->startOfDay();
        } catch (\Throwable $e) {
            return $default;
        }

        $floor = now()->addDay()->startOfDay();

        return ($wanted->lt($floor) || $wanted->gt($this->endOfPlan())) ? $default : $wanted;
    }

    /** ظرفیتِ باقی‌ماندهٔ تقویم تا پایانِ سال — برای گزارش و برنامه‌ریزی */
    public function remainingCapacity(): int
    {
        $free = 0;
        $day = now()->addDay()->startOfDay();
        $end = $this->endOfPlan();

        while ($day->lte($end)) {
            $free += max(0, $this->quotaFor($day) - ($this->taken[$day->toDateString()] ?? 0));
            $day = $day->addDay();
        }

        return $free;
    }

    /** آخرین لحظهٔ قابلِ برنامه‌ریزی: پایانِ ۲۹ اسفند ۱۴۰۵ */
    public function endOfPlan(): Carbon
    {
        $last = Jalali::daysInMonth(self::PLAN_UNTIL_JYEAR, 12);
        [$gy, $gm, $gd] = Jalali::toGregorian(self::PLAN_UNTIL_JYEAR, 12, $last);

        return Carbon::create($gy, $gm, $gd)->endOfDay();
    }

    /**
     * ثبتِ یک نوبت روی روزِ داده‌شده و برگرداندنِ لحظهٔ دقیق.
     *
     * ساعت‌ها را عمداً پخش می‌کند: نوبتِ n اُمِ روز حولِ بازهٔ n اُم می‌افتد،
     * با چند دقیقه جابه‌جاییِ نامنظم. بدونِ این، دو مقالهٔ یک روز می‌توانستند
     * در یک دقیقه منتشر شوند و در فیدِ RSS پشتِ هم بچسبند.
     */
    private function reserve(Carbon $day): Carbon
    {
        $key = $day->toDateString();
        $index = $this->taken[$key] ?? 0;
        $quota = max(1, $this->quotaFor($day));

        $span = self::HOUR_MAX - self::HOUR_MIN;
        $hour = self::HOUR_MIN + (int) floor($span * $index / $quota);
        $minute = (crc32($key.'#'.$index) % 60);

        // اگر همان دقیقه قبلاً گرفته شده، جلو برو تا آزاد شود
        $stamp = $hour * 60 + $minute;
        while (in_array($stamp, $this->minutes[$key] ?? [], true)) {
            $stamp++;
        }

        $this->minutes[$key][] = $stamp;
        $this->taken[$key] = $index + 1;

        return $day->copy()->setTime(intdiv($stamp, 60), $stamp % 60);
    }

    /** شمارشِ آنچه از قبل روی تقویم نشسته — همهٔ نوع‌ها با هم */
    private function load(): void
    {
        try {
            $rows = Post::query()
                ->whereNotNull('published_at')
                ->where('published_at', '>=', now()->startOfDay())
                ->pluck('published_at');
        } catch (\Throwable $e) {
            return; // جدول هنوز مهاجرت نشده — تقویمِ خالی
        }

        foreach ($rows as $at) {
            $key = $at->toDateString();
            $this->taken[$key] = ($this->taken[$key] ?? 0) + 1;
            $this->minutes[$key][] = $at->hour * 60 + $at->minute;
        }
    }
}
