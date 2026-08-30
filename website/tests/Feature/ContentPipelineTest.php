<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use App\Services\ContentCalendar;
use App\Services\InternalLinks;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * خطِ تولید محتوا — تقویمِ انتشار، لینکِ داخلی، و سلامتِ برنامه‌ها.
 *
 * 🔴 چرا این تست وجود دارد: بلاگ از ۲۵ مرداد ۱۴۰۵ تا ۶ شهریور **هیچ مطلبی
 * منتشر نکرد** و هیچ‌کس نفهمید. علتش خرابی نبود؛ `plan.php` هر ۱۰۲ موضوعش
 * مصرف شده بود و `content:generate` هر روز سرِ ساعت اجرا می‌شد، «همه ساخته
 * شده‌اند ✓» می‌گفت و با کدِ **موفق** برمی‌گشت.
 *
 * یعنی ضربانِ کرون سبز بود، `/admin/errors` خالی بود، و تنها نشانه‌اش
 * `lastmod`ِ ثابت در نقشهٔ سایت بود. **صفِ خالی شبیهِ کارِ تمام‌شده است، نه
 * شبیهِ خرابی** — و هیچ پایشی برای «کارِ تمام‌شده» هشدار نمی‌دهد.
 *
 * پس مهم‌ترین ادعای این فایل آن پایین است:
 * `test_every_scheduled_plan_file_exists_and_has_topics`.
 */
class ContentPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** برنامه‌هایی که کرون واقعاً مصرفشان می‌کند */
    private const PLANS = ['plan', 'docs-plan', 'blog-1405', 'kb-1405', 'docs-1405'];

    /* ═══════════════════ صفِ محتوا ═══════════════════ */

    /**
     * 🔴 گاردِ اصلی. اگر `routes/console.php` به برنامه‌ای اشاره کند که وجود
     * ندارد یا خالی است، کرون بی‌صدا هیچ‌چیز تولید نمی‌کند — دقیقاً همان
     * خرابیِ مرداد. این تست خودِ فایلِ زمان‌بندی را می‌خوانَد، نه یک فهرستِ
     * دستیِ موازی که روزی کهنه شود.
     */
    public function test_every_scheduled_plan_file_exists_and_has_topics(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));

        preg_match_all("~content:generate[^']*--plan=([a-z0-9\-_]+)~", $console, $m);

        $this->assertNotEmpty(
            $m[1],
            'هیچ برنامهٔ محتوایی در کرون ثبت نشده — یعنی تولیدِ محتوا کاملاً خاموش است.'
        );

        foreach (array_unique($m[1]) as $plan) {
            $file = resource_path('content/'.$plan.'.php');

            $this->assertFileExists($file, "کرون برنامهٔ «{$plan}» را صدا می‌زند ولی فایلش نیست.");

            $rows = require $file;
            $this->assertNotEmpty($rows, "برنامهٔ «{$plan}» خالی است — کرون هر روز بی‌صدا هیچ‌کاری نمی‌کند.");
        }
    }

    /**
     * اسلاگ کلیدِ یکتاسازیِ `content:generate` است: هر اسلاگی که در جدولِ
     * `posts` باشد از صف حذف می‌شود. پس یک اسلاگِ تکراری بینِ دو برنامه یعنی
     * موضوعِ دوم **هرگز نوشته نمی‌شود** و کسی خبردار نمی‌شود.
     */
    public function test_no_slug_is_repeated_across_any_plan(): void
    {
        $seen = [];

        foreach (self::PLANS as $plan) {
            foreach (require resource_path('content/'.$plan.'.php') as $row) {
                $slug = $row['slug'];

                $this->assertArrayNotHasKey(
                    $slug,
                    $seen,
                    "اسلاگ «{$slug}» در {$plan} تکراری است (قبلاً در ".($seen[$slug] ?? '?').")؛ دومی هرگز ساخته نمی‌شود."
                );

                $seen[$slug] = $plan;
            }
        }
    }

    /**
     * دستهٔ نامعتبر صفحه را ۵۰۰ نمی‌کند — بدتر: مطلب ساخته می‌شود و در
     * سایدبارِ `/docs` (که فقط بخش‌های `config/docs.php` را می‌سازد) یا در
     * فیلترِ دستهٔ بلاگ **نامرئی** می‌مانَد.
     */
    public function test_every_plan_row_uses_a_real_category(): void
    {
        $blogCats = array_keys(config('blog.categories'));
        $docSections = array_keys(config('docs.sections'));

        foreach (self::PLANS as $plan) {
            foreach (require resource_path('content/'.$plan.'.php') as $row) {
                $valid = ($row['type'] ?? 'blog') === 'kb' ? $docSections : $blogCats;

                $this->assertContains(
                    $row['category'],
                    $valid,
                    "دستهٔ «{$row['category']}» در {$plan} ({$row['slug']}) تعریف نشده — مطلب نامرئی می‌شود."
                );
            }
        }
    }

    /**
     * 🔴 ظرفیتِ تقویم باید از صف بیشتر باشد، ولی نه خیلی بیشتر.
     *
     * کمتر ⇒ ته صف به سالِ بعد می‌افتد.
     * خیلی بیشتر ⇒ `nextSlot()` **حریص** است و روزهای اول را پر می‌کند، پس صف
     * زودتر تمام می‌شود و **هفته‌های آخرِ سال خالی می‌مانَد** — بی‌هیچ خطایی.
     *
     * ⚠️ نسخهٔ اولِ همین تست فقط نسبتِ ظرفیت به صف را می‌سنجید (`< ۱٫۲۵`) و
     * **سبز شد** در حالی که شبیه‌سازی نشان داد اسفند فقط ۸ مطلب می‌گیرد و
     * دو هفتهٔ آخر کاملاً خالی است. نسبت یک **جانشین** برای خرابی بود، نه
     * خودش. حالا صف واقعاً روی تقویم چیده می‌شود و فاصلهٔ آخرین مطلب تا
     * پایانِ سال سنجیده می‌شود.
     */
    public function test_the_queue_actually_reaches_the_end_of_the_year(): void
    {
        $this->seedTheAlreadyPublishedBacklog();

        $calendar = new ContentCalendar;
        $end = $calendar->endOfPlan();
        $last = null;
        $placed = 0;

        foreach ($this->unbuiltQueue() as $row) {
            $slot = $calendar->nextSlot($row['date'] ?? null);

            $this->assertNotNull(
                $slot,
                "تقویم بعد از {$placed} مطلب پر شد ولی صف تمام نشده — بقیه به سالِ بعد می‌افتند."
            );

            $placed++;
            $last = $last === null || $slot->greaterThan($last) ? $slot : $last;
        }

        $gap = $last->diffInDays($end);

        /*
         * ⚠️ کرانِ ۲۱ روز است نه ۱۴، و این سست‌گیری عمدی است.
         *
         * این تست بدترین حالت را می‌سنجد: **نصبِ خالی**، یعنی کلِ صفِ فایل‌ها.
         * پروداکشن همیشه چند ده موضوع کمتر دارد (منتشرشده‌ها)، پس دنبالهٔ
         * واقعی‌اش بلندتر از این عدد است. اگر کران را روی پروداکشنِ امروز
         * تنظیم کنم، فردا که چند مطلبِ دیگر ساخته شود دوباره قرمز می‌شود —
         * تستی که هر روز کالیبره لازم داشته باشد، تستِ خوبی نیست.
         *
         * کاری که این کران واقعاً می‌کند: گرفتنِ **عدمِ تطابقِ فاحش**. ۲۱ روز
         * از ۲۰۲ روز هنوز آن را می‌گیرد. ادعای سختِ این تست بالاتر است:
         * هیچ موضوعی نباید از سال بیرون بیفتد.
         */
        $this->assertLessThanOrEqual(
            21,
            $gap,
            "آخرین مطلب {$gap} روز پیش از پایانِ سال منتشر می‌شود؛ انتهای سال خالی می‌مانَد. "
            .'یا موضوع اضافه کن یا سهمیهٔ روزانه را کم کن.'
        );
    }

    /**
     * دیتابیسِ تست خالی است، ولی پروداکشن نیست: ۱۰۲ مقالهٔ `plan.php` و ۴۰
     * ردیفِ اولِ `docs-plan` از قبل منتشر شده‌اند و **نوبتی از تقویمِ پیشِ رو
     * نمی‌گیرند**.
     *
     * ⚠️ بی‌این، تست صفی ۴۰ تایی بزرگ‌تر از واقعیت می‌چیند و دربارهٔ سایتِ
     * زنده حرفِ اشتباه می‌زند — یا الکی قرمز می‌شود، یا بدتر: تنظیمی را تأیید
     * می‌کند که روی پروداکشن دو هفتهٔ آخرِ سال را خالی می‌گذارد.
     *
     * عدد ۴۰ از خودِ سایت آمده (`/docs` در شهریور ۱۴۰۵). اگر روزی خیلی جابه‌جا
     * شد و این تست قرمز شد، **اول همین عدد را بررسی کن**، نه سهمیهٔ تقویم را.
     */
    private function seedTheAlreadyPublishedBacklog(): void
    {
        $rows = array_slice(require resource_path('content/docs-plan.php'), 0, 40);

        foreach ($rows as $row) {
            Post::create([
                'slug' => $row['slug'], 'type' => 'kb', 'category' => $row['category'],
                'status' => 'published', 'published_at' => now()->subMonths(2),
            ]);
        }
    }

    /**
     * صف به همان شکلی که `content:generate` می‌بیندش: ردیف‌هایی که اسلاگشان
     * هنوز در `posts` نیست. ترتیب هم همان ترتیبِ کرون است.
     *
     * @return list<array<string,mixed>>
     */
    private function unbuiltQueue(): array
    {
        $built = Post::pluck('slug')->flip();
        $out = [];

        foreach (['docs-plan', 'blog-1405', 'kb-1405', 'docs-1405'] as $plan) {
            foreach (require resource_path('content/'.$plan.'.php') as $row) {
                if (! $built->has($row['slug'])) {
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    /* ═══════════════════ تقویمِ انتشار ═══════════════════ */

    public function test_daily_quota_always_stays_between_two_and_five(): void
    {
        $calendar = new ContentCalendar;
        $day = now()->addDay()->startOfDay();
        $end = $calendar->endOfPlan();

        while ($day->lte($end)) {
            $quota = $calendar->quotaFor($day);

            $this->assertGreaterThanOrEqual(2, $quota, "سهمیهٔ {$day->toDateString()} کمتر از ۲ است.");
            $this->assertLessThanOrEqual(5, $quota, "سهمیهٔ {$day->toDateString()} بیشتر از ۵ است.");

            $day = $day->addDay();
        }
    }

    /**
     * ⚠️ سهمیه باید **قطعی** باشد. اگر تصادفی بود، اجرای دومِ فرمان می‌توانست
     * سهمیهٔ همان روز را کمتر ببیند و مقاله‌ای را روی روزی بگذارد که پر شده.
     */
    public function test_quota_is_deterministic_not_random(): void
    {
        $day = now()->addDays(30)->startOfDay();

        $first = (new ContentCalendar)->quotaFor($day);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, (new ContentCalendar)->quotaFor($day));
        }
    }

    public function test_thursday_and_friday_are_the_light_days(): void
    {
        $calendar = new ContentCalendar;
        $day = now()->addDay()->startOfDay();

        for ($i = 0; $i < 21; $i++) {
            if (Jalali::weekdayIndex($day) >= 5) {
                $this->assertSame(2, $calendar->quotaFor($day), 'آخرِ هفته باید سبک بماند.');
            }
            $day = $day->addDay();
        }
    }

    public function test_slots_never_exceed_the_daily_quota(): void
    {
        $calendar = new ContentCalendar;
        $perDay = [];

        for ($i = 0; $i < 60; $i++) {
            $slot = $calendar->nextSlot();
            $this->assertNotNull($slot);
            $perDay[$slot->toDateString()][] = $slot;
        }

        foreach ($perDay as $date => $slots) {
            $this->assertLessThanOrEqual(
                $calendar->quotaFor(Carbon::parse($date)),
                count($slots),
                "روز {$date} بیش از سهمیه‌اش مطلب گرفت."
            );
        }
    }

    /**
     * دو مقاله در یک دقیقه یعنی در فیدِ RSS و در فهرستِ بلاگ پشتِ هم می‌چسبند
     * و ترتیبشان به `id` می‌افتد — که ترتیبِ تولید است، نه ترتیبِ سرمقاله‌ای.
     */
    public function test_no_two_slots_land_on_the_same_minute(): void
    {
        $calendar = new ContentCalendar;
        $stamps = [];

        for ($i = 0; $i < 40; $i++) {
            $stamps[] = $calendar->nextSlot()->format('Y-m-d H:i');
        }

        $this->assertSame($stamps, array_unique($stamps), 'دو مطلب در یک دقیقه زمان‌بندی شدند.');
    }

    public function test_slots_stay_inside_working_hours(): void
    {
        $calendar = new ContentCalendar;

        for ($i = 0; $i < 40; $i++) {
            $hour = $calendar->nextSlot()->hour;

            $this->assertGreaterThanOrEqual(9, $hour);
            $this->assertLessThanOrEqual(21, $hour);
        }
    }

    public function test_the_plan_horizon_is_the_last_day_of_1405(): void
    {
        $end = (new ContentCalendar)->endOfPlan();

        [$jy, $jm, $jd] = Jalali::fromGregorian($end->year, $end->month, $end->day);

        $this->assertSame(1405, $jy);
        $this->assertSame(12, $jm);
        $this->assertSame(Jalali::daysInMonth(1405, 12), $jd);
    }

    /**
     * 🔴 مطلبِ فصلی نباید مکان‌نمای عمومی را جلو ببرد. بی‌این محافظ، یک مقالهٔ
     * نوروزی کلِ صف را به اسفند پرت می‌کرد و پنج ماه سکوت می‌ساخت.
     */
    public function test_a_pinned_seasonal_date_does_not_drag_the_queue_forward(): void
    {
        $calendar = new ContentCalendar;

        $before = $calendar->nextSlot();
        $calendar->nextSlot(now()->addDays(120)->toDateString());
        $after = $calendar->nextSlot();

        $this->assertTrue(
            $after->lessThan($before->copy()->addDays(3)),
            'بعد از یک مطلبِ تاریخ‌دار، صفِ عادی به آینده پرید.'
        );
    }

    public function test_a_pinned_date_is_honoured_when_it_is_valid(): void
    {
        $wanted = now()->addDays(90)->startOfDay();

        $slot = (new ContentCalendar)->nextSlot($wanted->toDateString());

        $this->assertSame($wanted->toDateString(), $slot->toDateString());
    }

    /** تاریخِ گذشته یعنی مطلبی که هرگز در فید دیده نمی‌شود — باید به صفِ عادی برگردد. */
    public function test_a_past_or_out_of_range_pin_falls_back_to_the_queue(): void
    {
        $calendar = new ContentCalendar;
        $floor = now()->addDay()->startOfDay();

        foreach (['2020-01-01', '2099-01-01', 'not-a-date', ''] as $bad) {
            $slot = $calendar->nextSlot($bad);

            $this->assertNotNull($slot);
            $this->assertTrue($slot->gte($floor), "تاریخ نامعتبر «{$bad}» پذیرفته شد.");
            $this->assertTrue($slot->lte($calendar->endOfPlan()));
        }
    }

    /** پیش‌نویس‌های از قبل زمان‌بندی‌شده باید در سهمیهٔ همان روز شمرده شوند. */
    public function test_existing_scheduled_posts_consume_the_days_quota(): void
    {
        $day = now()->addDay()->startOfDay();
        $quota = (new ContentCalendar)->quotaFor($day);

        for ($i = 0; $i < $quota; $i++) {
            Post::create([
                'slug' => 'taken-'.$i, 'type' => 'blog', 'category' => 'hosting',
                'status' => 'draft', 'published_at' => $day->copy()->setTime(10 + $i, 0),
            ]);
        }

        $slot = (new ContentCalendar)->nextSlot();

        $this->assertNotSame($day->toDateString(), $slot->toDateString(), 'روزِ پر دوباره انتخاب شد.');
    }

    /* ═══════════════════ لینکِ داخلی ═══════════════════ */

    /**
     * 🔴 مدل آدرس را حدس می‌زند و حدسش منطقی است — «/راهنمای-خرید-هاست»
     * دقیقاً چیزی است که یک سایتِ هاستینگ باید داشته باشد. ولی نداریم، و
     * نتیجه ۴۰۴ ای است که هیچ تستی نمی‌بیندش چون در دیتابیس است نه در کد.
     */
    public function test_a_link_to_a_nonexistent_path_is_unwrapped(): void
    {
        $html = '<p>برای اطلاعات بیشتر <a href="/راهنمای-خرید-هاست">این راهنما</a> را ببینید.</p>';

        $out = app(InternalLinks::class)->sanitize($html);

        $this->assertStringNotContainsString('<a', $out);
        $this->assertStringContainsString('این راهنما', $out, 'متنِ لینک نباید حذف شود، فقط خودِ لینک.');
    }

    public function test_a_link_to_a_real_page_survives(): void
    {
        $html = '<p>در <a href="/webtools">ابزارهای وب‌مستر</a> ببینید.</p>';

        $this->assertStringContainsString('href="/webtools"', app(InternalLinks::class)->sanitize($html));
    }

    public function test_external_links_get_nofollow_and_blank_target(): void
    {
        $out = app(InternalLinks::class)->sanitize('<a href="https://example.com/x">نمونه</a>');

        $this->assertStringContainsString('rel="nofollow noopener"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
    }

    public function test_javascript_urls_are_stripped(): void
    {
        $out = app(InternalLinks::class)->sanitize('<a href="javascript:alert(1)">کلیک</a>');

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('کلیک', $out);
    }

    /**
     * 🔴 بی‌این، مقالهٔ انگلیسی به `/blog/foo` لینک می‌دهد و خواننده وسطِ متنِ
     * انگلیسی به نسخهٔ **فارسی** پرتاب می‌شود — و سیگنالِ hreflang خنثی می‌شود.
     */
    public function test_translated_content_keeps_its_links_in_the_same_language(): void
    {
        $links = app(InternalLinks::class);

        $this->assertStringContainsString(
            'href="/en/webtools"',
            $links->localize('<a href="/webtools">tools</a>', 'en')
        );

        $this->assertStringContainsString(
            'href="/tr/docs"',
            $links->localize('<a href="/docs">belgeler</a>', 'tr')
        );
    }

    /**
     * 🔴 مقالهٔ واقعیِ اول آدرس‌ها را **کامل** نوشت، نه نسبی — چون قاعدهٔ لینکِ
     * محصول آدرسِ کاملِ `lroute()` را به پرامپت می‌دهد و مدل همان سبک را
     * تکرار کرد. نسخهٔ اولِ `localize()` فقط مسیرِ نسبی را می‌گرفت، پس
     * خوانندهٔ انگلیسی به صفحهٔ فارسی می‌افتاد.
     */
    public function test_absolute_urls_on_our_own_host_are_localized_too(): void
    {
        $links = app(InternalLinks::class);
        $host = rtrim((string) config('app.url'), '/');

        $out = $links->localize('<a href="'.$host.'/blog/x">t</a>', 'en');

        $this->assertStringContainsString($host.'/en/blog/x', $out);
    }

    public function test_an_absolute_url_already_localized_is_left_alone(): void
    {
        $links = app(InternalLinks::class);
        $host = rtrim((string) config('app.url'), '/');
        $html = '<a href="'.$host.'/en/blog/x">t</a>';

        $this->assertSame($html, $links->localize($html, 'en'));
    }

    /**
     * 🔴 `content:translate-missing` درِ **دومِ** ساختِ ترجمه است و محافظ
     * فقط روی درِ اول (`content:generate`) گذاشته شده بود.
     *
     * هر مقاله‌ای که ترجمه‌اش سرِ تولید شکست بخورد از این مسیر پر می‌شود —
     * یعنی دقیقاً همان مقاله‌هایی که یک بار قبلاً مشکل داشته‌اند، لینکِ
     * بین‌زبانی هم می‌گرفتند. (قاعدهٔ «نیمهٔ دیگرِ شاخه را بپرس».)
     */
    public function test_the_translate_missing_door_localizes_links_too(): void
    {
        $post = Post::create([
            'slug' => 'half-translated', 'type' => 'blog', 'category' => 'seo',
            'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        PostTranslation::create([
            'post_id' => $post->id, 'locale' => 'fa', 'auto' => true,
            'title' => 'مقالهٔ نیمه‌ترجمه',
            'content' => '<p>ابزارها در <a href="/webtools">ابزارهای وب‌مستر</a> هستند.</p>',
        ]);

        $this->app->bind(\App\Services\AiContent::class, fn () => new class extends \App\Services\AiContent
        {
            public function enabled(): bool
            {
                return true;
            }

            public function translate(array $fa, string $target): ?array
            {
                return [
                    'title' => 'Half translated', 'excerpt' => 'x', 'tags' => ['a'],
                    'content' => '<p>Tools live at <a href="/webtools">webmaster tools</a>.</p>',
                ];
            }
        });

        $this->artisan('content:translate-missing', ['--limit' => 1])->assertSuccessful();

        $en = $post->fresh()->translations->firstWhere('locale', 'en');

        $this->assertNotNull($en, 'ترجمهٔ en ساخته نشد.');
        $this->assertStringContainsString('href="/en/webtools"', $en->content);
    }

    public function test_localizing_never_double_prefixes(): void
    {
        $links = app(InternalLinks::class);

        $once = $links->localize('<a href="/webtools">t</a>', 'en');

        $this->assertSame($once, $links->localize($once, 'en'));
    }

    public function test_persian_content_is_left_alone(): void
    {
        $html = '<a href="/webtools">ابزارها</a>';

        $this->assertSame($html, app(InternalLinks::class)->localize($html, 'fa'));
    }

    public function test_external_urls_are_not_localized(): void
    {
        $html = '<a href="https://example.com/webtools">x</a>';

        $this->assertSame($html, app(InternalLinks::class)->localize($html, 'en'));
    }

    /** فهرستِ پیشنهادی به مدل باید فقط آدرسِ **واقعی** داشته باشد. */
    public function test_every_offered_link_target_actually_resolves(): void
    {
        $links = app(InternalLinks::class);

        foreach (array_keys(config('blog.categories')) as $category) {
            foreach ($links->inventory($category, 'blog') as $row) {
                $this->assertTrue(
                    $links->resolves($row['url']),
                    "آدرسِ «{$row['url']}» به مدل پیشنهاد می‌شود ولی حل نمی‌شود."
                );
            }
        }

        foreach (array_keys(config('docs.sections')) as $section) {
            foreach ($links->inventory($section, 'kb') as $row) {
                $this->assertTrue($links->resolves($row['url']), "آدرسِ «{$row['url']}» حل نمی‌شود.");
            }
        }
    }

    /**
     * لینک به پیش‌نویسی که هنوز منتشر نشده، تا روزِ انتشارش ۴۰۴ می‌دهد —
     * و آن دقیقاً بازه‌ای است که گوگل مقالهٔ تازه را می‌خزد.
     */
    public function test_unpublished_posts_are_never_offered_as_link_targets(): void
    {
        $draft = Post::create([
            'slug' => 'not-yet', 'type' => 'blog', 'category' => 'hosting',
            'status' => 'draft', 'published_at' => now()->addDays(10),
        ]);
        PostTranslation::create(['post_id' => $draft->id, 'locale' => 'fa', 'title' => 'هنوز نه']);

        $urls = array_column(app(InternalLinks::class)->inventory('hosting', 'blog'), 'url');

        $this->assertNotContains('/blog/not-yet', $urls);
    }

    /* ═══════════════════ دادهٔ ساختاریافتهٔ پرسش ═══════════════════ */

    public function test_faq_schema_is_built_from_the_article_body(): void
    {
        $html = '<h2>بخش</h2><p>متن</p>'
            .'<h2>پرسش‌های پرتکرار</h2>'
            .'<h3>چرا TTFB بالا می‌رود؟</h3><p>معمولاً کوئری کند یا نبودِ کش، که با پروفایل‌گیری پیدا می‌شود.</p>';

        $ld = json_decode(article_faq_ld($html), true);

        $this->assertSame('FAQPage', $ld['@type']);
        $this->assertCount(1, $ld['mainEntity']);
        $this->assertSame('چرا TTFB بالا می‌رود؟', $ld['mainEntity'][0]['name']);
    }

    public function test_an_article_without_questions_emits_no_schema(): void
    {
        $this->assertSame('', article_faq_ld('<h2>بخش</h2><p>متن معمولی بدون پرسش.</p>'));
    }

    /**
     * پرسشِ بی‌پاسخ یک آیتمِ نامعتبر است و گوگل کلِ صفحه را رد می‌کند — پس
     * باید کنار گذاشته شود، نه با پاسخِ خالی ساخته شود.
     */
    public function test_a_question_without_a_real_answer_is_dropped(): void
    {
        $html = '<h2>پرسش‌های پرتکرار</h2><h3>سؤال؟</h3><p>بله.</p>';

        $this->assertSame('', article_faq_ld($html));
    }

    public function test_english_faq_heading_is_recognised_too(): void
    {
        $html = '<h2>Frequently asked questions</h2>'
            .'<h3>Why is TTFB high?</h3><p>Usually a slow query or a missing cache layer somewhere.</p>';

        $this->assertStringContainsString('FAQPage', article_faq_ld($html));
    }

    /* ═══════════════════ برخوردِ اسلاگ ═══════════════════ */

    /**
     * 🔴 اسلاگی که پستِ بیرونی صاحبش باشد **هرگز** از برنامه در نمی‌آید.
     *
     * روی پروداکشن سه موضوع دقیقاً همین شدند و بی‌صدا افتادند، چون
     * اعتبارسنجی فقط فایل‌های برنامه را با هم می‌سنجید — و آن سه پست در هیچ
     * فایلی نبودند (بازماندهٔ ایمپورتِ وردپرس، فقط در جدولِ `posts`).
     */
    public function test_a_slug_owned_by_a_foreign_post_is_shouted_about(): void
    {
        $post = Post::create([
            'slug' => 'website-speed-complete-guide', 'type' => 'blog',
            'category' => 'seo', 'status' => 'published', 'published_at' => now()->subYear(),
        ]);
        // پستِ ایمپورت‌شده: ترجمهٔ fa دارد ولی auto نیست
        PostTranslation::create([
            'post_id' => $post->id, 'locale' => 'fa',
            'title' => 'مطلبِ قدیمیِ ایمپورت‌شده', 'auto' => false,
        ]);

        $this->artisan('content:generate', ['--plan' => 'blog-1405', '--dry' => true, '--limit' => 1])
            ->expectsOutputToContain('هرگز ساخته نمی‌شود')
            ->assertSuccessful();
    }

    /**
     * ⚠️ ولی مصرفِ عادیِ صف باید **ساکت** بماند.
     *
     * نسخهٔ اولِ این هشدار هر موضوعِ ردشده را چاپ می‌کرد، و همان روزِ اول یک
     * خط برای مقاله‌ای زد که خودِ همین فرمان دیشب ساخته بود. فردا دو تا،
     * ماهِ بعد صد تا — هشداری که به نویز تبدیل می‌شود و برخوردِ واقعی را در
     * خودش دفن می‌کند.
     */
    public function test_a_topic_this_pipeline_already_built_stays_silent(): void
    {
        $post = Post::create([
            'slug' => 'website-speed-complete-guide', 'type' => 'blog',
            'category' => 'seo', 'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        PostTranslation::create([
            'post_id' => $post->id, 'locale' => 'fa',
            'title' => 'راهنمای کامل افزایش سرعت سایت', 'auto' => true,
        ]);

        $this->artisan('content:generate', ['--plan' => 'blog-1405', '--dry' => true, '--limit' => 1])
            ->doesntExpectOutputToContain('هرگز ساخته نمی‌شود')
            ->assertSuccessful();
    }

    /** هیچ اسلاگی از برنامه‌ها نباید با پستِ بیرونیِ موجود برخورد کند. */
    public function test_a_clean_install_reports_no_foreign_clash(): void
    {
        $this->artisan('content:generate', ['--plan' => 'blog-1405', '--dry' => true, '--limit' => 1])
            ->doesntExpectOutputToContain('هرگز ساخته نمی‌شود')
            ->assertSuccessful();
    }

    /* ═══════════════════ پایشِ صف ═══════════════════ */

    /**
     * 🔴 خودِ خرابیِ مرداد ۱۴۰۵: مطلبِ منتشرشده هست، صفِ آینده خالی است.
     *
     * هیچ‌کدام از چک‌های موجود این را نمی‌دیدند، چون هیچ استثنایی پرتاب نشد و
     * کرون واقعاً می‌دوید.
     */
    public function test_an_exhausted_queue_on_a_live_site_is_reported_as_a_failure(): void
    {
        Post::create([
            'slug' => 'already-live', 'type' => 'blog', 'category' => 'hosting',
            'status' => 'published', 'published_at' => now()->subMonth(),
        ]);

        $content = $this->contentCheck();

        $this->assertSame('fail', $content['level'], 'صفِ خالی روی سایتِ زنده باید قرمز باشد.');
    }

    /**
     * ⚠️ ولی روی نصبِ تازه همان حالت **عادی** است. پایشگری که بی‌دلیل قرمز
     * باشد یاد می‌دهد قرمز را نادیده بگیرند — و آن بدتر از نداشتنش است.
     */
    public function test_a_fresh_install_with_no_content_at_all_is_not_an_alarm(): void
    {
        $this->assertSame('ok', $this->contentCheck()['level']);
    }

    public function test_a_healthy_queue_is_green(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            Post::create([
                'slug' => 'queued-'.$i, 'type' => 'blog', 'category' => 'hosting',
                'status' => 'draft', 'published_at' => now()->addDays($i),
            ]);
        }

        $this->assertSame('ok', $this->contentCheck()['level']);
    }

    /** کمتر از دو هفته ذخیره: هنوز کار می‌کند، ولی وقتِ افزودن موضوع است. */
    public function test_a_thinning_queue_warns_before_it_runs_dry(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            Post::create([
                'slug' => 'queued-'.$i, 'type' => 'blog', 'category' => 'hosting',
                'status' => 'draft', 'published_at' => now()->addDays($i),
            ]);
        }

        $this->assertSame('warn', $this->contentCheck()['level']);
    }

    /**
     * ⚠️ پیش‌نویسی که زمانش گذشته «صف» نیست — منتظرِ کرونِ انتشار است.
     * شمردنش به‌عنوان ذخیره یعنی چک دقیقاً وقتی سبز می‌مانَد که صف واقعاً
     * ته کشیده.
     */
    public function test_overdue_drafts_do_not_count_as_queue(): void
    {
        Post::create([
            'slug' => 'live-one', 'type' => 'blog', 'category' => 'hosting',
            'status' => 'published', 'published_at' => now()->subMonth(),
        ]);
        Post::create([
            'slug' => 'overdue', 'type' => 'blog', 'category' => 'hosting',
            'status' => 'draft', 'published_at' => now()->subHour(),
        ]);

        $this->assertSame('fail', $this->contentCheck()['level']);
    }

    private function contentCheck(): array
    {
        foreach (app(\App\Services\SystemHealth::class)->checks() as $row) {
            if ($row['key'] === 'content') {
                return $row;
            }
        }

        $this->fail('چکِ «content» در فهرستِ سلامت نیست — یعنی صفِ محتوا اصلاً پایش نمی‌شود.');
    }

    /* ═══════════════════ مسیرِ کامل ═══════════════════ */

    /**
     * از `content:generate` تا صفحهٔ رندرشده، با هوش مصنوعیِ ساختگی.
     *
     * ⚠️ هر حلقهٔ این زنجیره جداگانه تست دارد، ولی «کدِ ۲۰۰ یعنی هیچ» و
     * تستِ واحد هم همین‌طور: تنها چیزی که ثابت می‌کند خط تولید کار می‌کند،
     * یک مقالهٔ واقعی است که تا HTML می‌رسد. یک‌بار همین زنجیره جایی وسطش
     * پاره بود و هر تستِ واحدش سبز.
     */
    public function test_a_generated_article_survives_all_the_way_to_the_page(): void
    {
        $this->fakeTheWriter();

        $this->artisan('content:generate', ['--plan' => 'blog-1405', '--limit' => 1])
            ->assertSuccessful();

        $post = Post::where('slug', 'website-speed-complete-guide')->first();
        $this->assertNotNull($post, 'مقاله ساخته نشد.');

        // پیش‌نویسِ زمان‌بندی‌شده، نه منتشرشده
        $this->assertSame('draft', $post->status);
        $this->assertTrue($post->published_at->isFuture(), 'زمانِ انتشار در گذشته است.');
        $this->assertTrue($post->published_at->lte((new ContentCalendar)->endOfPlan()));

        // هر سه زبان
        $this->assertSame(['en', 'fa', 'tr'], $post->translations->pluck('locale')->sort()->values()->all());

        $fa = $post->translations->firstWhere('locale', 'fa');

        // لینکِ ساختگی حل نمی‌شود ⇒ باید باز شده باشد؛ لینکِ واقعی باید مانده باشد
        $this->assertStringNotContainsString('/راهنمای-جعلی', $fa->content);
        $this->assertStringContainsString('href="/webtools"', $fa->content);

        // نسخهٔ انگلیسی باید لینکش را در همان زبان نگه دارد
        $en = $post->translations->firstWhere('locale', 'en');
        $this->assertStringContainsString('href="/en/webtools"', $en->content);

        // انتشار سررسید ⇒ صفحه بالا می‌آید
        $post->update(['published_at' => now()->subMinute()]);
        $this->artisan('content:publish-due')->assertSuccessful();
        $this->assertSame('published', $post->fresh()->status);

        $html = $this->get('/blog/website-speed-complete-guide')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('FAQPage', $html, 'اسکیمای پرسش روی صفحه رندر نشد.');
        $this->assertStringContainsString('BlogPosting', $html);
    }

    /** نویسندهٔ ساختگی — هیچ تماسِ شبکه‌ای، خروجیِ قابلِ پیش‌بینی. */
    private function fakeTheWriter(): void
    {
        $this->app->bind(\App\Services\AiContent::class, fn () => new class extends \App\Services\AiContent
        {
            public function enabled(): bool
            {
                return true;
            }

            public function article(array $brief): ?array
            {
                // فهرستِ لینک باید واقعاً به پرامپت رسیده باشد
                if (! str_contains((string) ($brief['links'] ?? ''), '/webtools')) {
                    return null;
                }

                return [
                    'title'   => 'راهنمای کامل افزایش سرعت سایت',
                    'excerpt' => 'زنجیرهٔ کامل از کلیک تا رندر و اینکه هر حلقه چقدر وقت می‌برد.',
                    'tags'    => ['سرعت سایت', 'بهینه‌سازی'],
                    'content' => '<h2>از کجا شروع کنیم</h2><p>اول اندازه بگیر، بعد تغییر بده.</p>'
                        .'<p>ابزارش در <a href="/webtools">ابزارهای وب‌مستر</a> هست، '
                        .'و <a href="/راهنمای-جعلی">این راهنما</a> وجود ندارد.</p>'
                        .'<h2>پرسش‌های پرتکرار</h2>'
                        .'<h3>سرعت سایت چقدر باید باشد؟</h3>'
                        .'<p>هدفِ عملی زیر دو ثانیه برای نمایشِ محتوای اصلی است، روی اتصالِ موبایل.</p>',
                ];
            }

            public function translate(array $fa, string $target): ?array
            {
                return [
                    'title'   => 'Complete guide to website speed',
                    'excerpt' => 'The full chain from click to render.',
                    'tags'    => ['speed'],
                    'content' => '<h2>Where to start</h2><p>Measure first.</p>'
                        .'<p>Tools live at <a href="/webtools">webmaster tools</a>.</p>',
                ];
            }
        });
    }
}
