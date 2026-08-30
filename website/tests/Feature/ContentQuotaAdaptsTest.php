<?php

namespace Tests\Feature;

use App\Services\ContentCalendar;
use Tests\TestCase;

/**
 * 🔴 سهمیهٔ روزانه باید خودش را با صف تنظیم کند — نه با دست.
 *
 * ═══ خرابیِ واقعی ═══
 *
 * سهمیه یک عددِ سخت‌کد بود که با دست «کالیبره» شده بود، و داکبلاکش می‌گفت اگر
 * موضوع‌ها را کم یا زیاد کردی این‌جا را هم تنظیم کن. ولی چیزی که کالیبراسیون
 * را می‌شکست تغییرِ موضوع‌ها نبود — **گذرِ زمان** بود: پایانِ برنامه ثابت است،
 * پس هر روز پنجره یک روز کوتاه‌تر و ظرفیت ~۳٫۵ کمتر می‌شود، در حالی که صف فقط
 * به‌اندازهٔ تولیدِ واقعی آب می‌رود. شهریور ۱۴۰۵ ظرفیت ۶۴۵ بود و صف ۶۸۸.
 *
 * ⚠️ عددی که هر چند هفته دستی تنظیم شود، تستی می‌سازد که یا نادیده گرفته
 * می‌شود یا خاموش — و آن بدتر از نداشتنش است.
 *
 * ═══ 🔴 چرا این پرونده مستقیم `planQuota()` را می‌سنجد ═══
 *
 * جهش‌سنجی نشان داد دو شاخهٔ حساب — بخشِ صحیح و سقفِ باند — با دادهٔ **امروز**
 * اصلاً اجرا نمی‌شوند (کمبودِ ۴۳ در ۲۰۲ روز یعنی زیر یکی در روز). یعنی
 * حذفشان از چشمِ کلِ سوئیت می‌گریخت. تا وقتی حساب داخلِ متدی بود که صف را از
 * پرونده و پُست‌ها می‌خواند، هیچ تستی نمی‌توانست کمبودِ بزرگ بسازد.
 */
class ContentQuotaAdaptsTest extends TestCase
{
    /** @return array<string,int> شکلِ پایه: n روز با سهمیهٔ داده‌شده */
    private function shape(int $days, int $base = 3): array
    {
        $out = [];

        for ($i = 1; $i <= $days; $i++) {
            $out[sprintf('2026-%02d-%02d', 1 + intdiv($i, 28), 1 + ($i % 28))] = $base;
        }

        return $out;
    }

    /**
     * ظرفیتِ کل = جمعِ خروجی.
     *
     * 🔴 عمداً هیچ کلامپی این‌جا **نیست**. نسخهٔ اولِ همین کمکی باند را خودش
     * دوباره اعمال می‌کرد، پس حذفِ سقف از خودِ کلاس از هر پنج تست سالم رد
     * می‌شد — فیکسچری ساخته از فهمِ نویسنده، نه از کد.
     */
    private function capacity(array $quota): int
    {
        return array_sum($quota);
    }

    /**
     * 🔴 کمبودِ **کوچک** — همان حالتی که واقعاً پیش می‌آید.
     *
     * زیر یکی در روز؛ گِردکردنِ ساده این را صفر می‌خواند و همهٔ کمبود سرِ جایش
     * به سالِ بعد می‌افتد.
     */
    public function test_a_sub_daily_shortfall_is_still_covered(): void
    {
        $shape = $this->shape(200);
        $base = array_sum($shape);          // ۶۰۰

        $q = ContentCalendar::planQuota(43, $shape);

        $this->assertSame($base + 43, $this->capacity($q),
            'کمبودِ زیرِ یکی در روز جبران نشد — همان چیزی که گِردکردن می‌بلعید');
    }

    /**
     * 🔴 کمبودِ **بزرگ** — شاخه‌ای که با دادهٔ امروز هرگز اجرا نمی‌شود.
     *
     * بی‌بخشِ صحیح، فقط باقیمانده پخش می‌شد و ظرفیت هرگز به صف نمی‌رسید.
     */
    public function test_a_multi_per_day_shortfall_raises_the_base_quota(): void
    {
        $shape = $this->shape(100);         // پایه ۳۰۰
        $q = ContentCalendar::planQuota(150, $shape);

        // پایه ۳ بود؛ کمبودِ ۱٫۵ در روز یعنی همهٔ روزها باید بالا بروند،
        // نه فقط تعدادی که باقیمانده می‌گیرند
        $this->assertGreaterThanOrEqual(4, min($q),
            'افزایهٔ همگانی اعمال نشد — کمبودِ بزرگ فقط با باقیمانده جبران نمی‌شود');

        $this->assertSame(450, $this->capacity($q));
    }

    /**
     * 🔴 و باندِ ۲ تا ۵ شکسته نشود، حتی وقتی صف عظیم است.
     *
     * باند یک تصمیمِ طراحی است: بیشتر از ۵ در روز، سایت را به فیدِ هرزنامه‌ای
     * شبیه می‌کند. اگر صف جا نشود، آن تصمیمِ انسانی است (موضوع کم کن یا افق را
     * جلو ببر) — و تقویم نباید خودش را از باند بیرون بکشد.
     */
    public function test_an_impossible_queue_never_breaks_the_design_band(): void
    {
        $shape = $this->shape(50);
        $q = ContentCalendar::planQuota(100000, $shape);

        $this->assertLessThanOrEqual(5, max($q), 'سهمیه از سقفِ باند بیرون زد');
        $this->assertGreaterThanOrEqual(2, min($q));

        $this->assertSame(250, $this->capacity($q),
            'ظرفیت باید روی سقفِ باند بایستد، نه بیشتر');
    }

    /**
     * ⚠️ و جهتِ عکس: ظرفیتِ **زیاد** هم خرابی است.
     *
     * `nextSlot()` حریص است و روزهای اول را پر می‌کند، پس ظرفیتِ اضافی یعنی صف
     * زودتر تمام می‌شود و **هفته‌های آخرِ سال خالی می‌مانَد** — بی‌هیچ خطایی.
     * این بدترین نوع خرابی است چون همه‌چیز درست کار می‌کند و فقط اسفند ساکت است.
     */
    public function test_a_surplus_shrinks_the_quota_instead_of_leaving_a_dead_tail(): void
    {
        $shape = $this->shape(100, 4);      // پایه ۴۰۰
        $q = ContentCalendar::planQuota(-150, $shape);

        $this->assertSame(250, $this->capacity($q));
    }

    /** ⚠️ ولی هرگز زیر کفِ باند: فیدِ تُنُک هم خرابی است. */
    public function test_an_empty_queue_still_leaves_the_floor(): void
    {
        $shape = $this->shape(30);
        $q = ContentCalendar::planQuota(-99999, $shape);

        $this->assertSame(60, $this->capacity($q),
            'سهمیه زیر کفِ ۲ رفت — سکوت در فیدِ یک سایتِ زنده هم خرابی است');
    }

    /**
     * ⚠️ قطعی، نه تصادفی.
     *
     * دو بار اجرا کردنِ همان فرمان باید همان تقویم را بدهد، وگرنه مقاله‌ای روی
     * روزی می‌نشیند که اجرای قبلی پرش کرده بود.
     */
    public function test_the_same_input_always_gives_the_same_plan(): void
    {
        $shape = $this->shape(120);

        $this->assertSame(
            ContentCalendar::planQuota(77, $shape),
            ContentCalendar::planQuota(77, $shape)
        );
    }

    /**
     * ⚠️ و روزهای اضافه نباید همه به ابتدای دوره بچسبند.
     *
     * با ترتیبِ تقویمی، همان «پالسِ اول، سکوتِ بعد»ی ساخته می‌شد که این کلاس
     * اصلاً برای رفعش نوشته شد.
     */
    public function test_the_extra_days_are_spread_not_front_loaded(): void
    {
        $shape = $this->shape(200);
        $q = ContentCalendar::planQuota(40, $shape);

        $keys = array_keys($shape);
        $half = array_slice($keys, 0, 100);

        // روزهایی که باقیمانده گرفتند = آن‌هایی که از شکلِ پایه بالاترند
        $lifted = array_keys(array_filter($q, fn ($v, $k) => $v > $shape[$k], ARRAY_FILTER_USE_BOTH));

        $inFirstHalf = count(array_intersect($lifted, $half));

        $this->assertGreaterThan(5, $inFirstHalf);
        $this->assertLessThan(35, $inFirstHalf,
            'روزهای اضافه به یک نیمه چسبیدند — همان پالسی که قرار بود نباشد');
    }

    /** و ورودیِ خالی نباید بترکاند. */
    public function test_an_empty_shape_is_harmless(): void
    {
        $this->assertSame([], ContentCalendar::planQuota(500, []));
    }
}
