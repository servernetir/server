<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CalendarLayerPreference;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Calendar\CalendarEventProvider;
use App\Services\Calendar\CalendarService;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * تقویمِ کسب‌وکار — /admin/calendar
 *
 * محورها:
 *   • تبدیلِ شمسی↔میلادی با **مقدارِ مرجع**، نه فقط رفت‌وبرگشت
 *   • هر لایه از جدولِ درستش می‌خوانَد و در روزِ درست می‌نشیند
 *   • مرزِ دسترسی: نویسندهٔ محتوا این صفحه را نمی‌بیند
 *   • «هیچ لایه‌ای» با «همهٔ لایه‌ها» قاتی نمی‌شود
 *   • فقط رویدادِ دستی حذف/ویرایش می‌شود
 *   • یک providerِ خراب کلِ تقویم را نمی‌کُشد
 */
class AdminCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'admin'): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 's'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => $role,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('oldsecret'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /* ═════════════════════ تبدیلِ تاریخ ═════════════════════ */

    /**
     * 🔴 مقدارِ مرجع، نه رفت‌وبرگشت.
     *
     * رفت‌وبرگشتِ تنها یک تلهٔ کلاسیک است: اگر هر دو جهت با **همان** خطا نوشته
     * شوند، تست سبز می‌شود و تاریخ همچنان یک روز غلط است. پس نوروزِ ۱۴۰۵ و
     * چند نقطهٔ شناخته‌شدهٔ دیگر صریح سنجیده می‌شوند.
     */
    public function test_jalali_conversion_matches_known_reference_dates(): void
    {
        // نوروز
        $this->assertSame([1405, 1, 1], Jalali::fromGregorian(2026, 3, 21));
        $this->assertSame([2026, 3, 21], Jalali::toGregorian(1405, 1, 1));

        // اولِ مرداد ۱۴۰۵
        $this->assertSame([2026, 7, 23], Jalali::toGregorian(1405, 5, 1));
        $this->assertSame([1405, 5, 1], Jalali::fromGregorian(2026, 7, 23));

        // روزِ آخرِ سالِ غیرکبیسه (۲۹ اسفند)
        $this->assertSame([1405, 12, 29], Jalali::fromGregorian(2027, 3, 20));
    }

    public function test_jalali_round_trips_across_a_full_year(): void
    {
        for ($jm = 1; $jm <= 12; $jm++) {
            $days = Jalali::daysInMonth(1405, $jm);
            $this->assertGreaterThan(0, $days);

            for ($jd = 1; $jd <= $days; $jd++) {
                [$gy, $gm, $gd] = Jalali::toGregorian(1405, $jm, $jd);
                $this->assertSame(
                    [1405, $jm, $jd],
                    Jalali::fromGregorian($gy, $gm, $gd),
                    "شکست در $jm/$jd",
                );
            }
        }
    }

    public function test_month_lengths_follow_the_jalali_calendar(): void
    {
        foreach ([1, 2, 3, 4, 5, 6] as $m) {
            $this->assertSame(31, Jalali::daysInMonth(1405, $m));
        }
        foreach ([7, 8, 9, 10, 11] as $m) {
            $this->assertSame(30, Jalali::daysInMonth(1405, $m));
        }

        // اسفند: ۲۹ در سالِ عادی، ۳۰ در کبیسه. ۱۴۰۳ کبیسه است، ۱۴۰۵ نیست.
        $this->assertSame(30, Jalali::daysInMonth(1403, 12));
        $this->assertSame(29, Jalali::daysInMonth(1405, 12));
    }

    /**
     * 🔴 رگرسیون: روزِ ۳۶۶اُمِ سالِ کبیسه = **۳۰ اسفند**، نه «۱ ماهِ سیزدهم».
     *
     * `jalali_ymd()` اسفند را ثابت ۲۹ روز می‌گرفت، پس در سالِ کبیسه حلقه هر
     * ۱۲ ماه را مصرف می‌کرد و `[jy, 13, 1]` می‌داد. خرابی **خاموش** بود و
     * سالی یک روز: `blog_date()` نامِ ماه را از خانه‌ای می‌خواند که وجود ندارد
     * و رشتهٔ خالی چاپ می‌کرد، `sdate()` تاریخِ ناموجودِ «۱۴۰۳/۱۳/۰۱» می‌ساخت،
     * و رویدادِ ۳۰ اسفند در هیچ خانه‌ای از شبکهٔ ماه نمی‌نشست.
     *
     * ⚠️ این تست عمداً `blog_date()` و `sdate()` را هم می‌سنجد، نه فقط
     * `Jalali` را: تقویم تنها مصرف‌کنندهٔ این تابع نیست.
     */
    public function test_the_last_day_of_a_leap_year_is_esfand_thirty(): void
    {
        app()->setLocale('fa');

        // ۱۴۰۳ کبیسه است؛ ۳۶۶اُمین روزش = ۲۰ مارس ۲۰۲۵
        $this->assertSame([1403, 12, 30], Jalali::fromGregorian(2025, 3, 20));
        $this->assertTrue(Jalali::isLeap(1403));

        // و لایهٔ نمایشِ سایت هم باید نامِ ماه را داشته باشد، نه جای خالی
        $this->assertStringContainsString('اسفند', blog_date('2025-03-20'));
        $this->assertStringContainsString('۱۲/۳۰', sdate('2025-03-20 08:00:00'));

        // سه سالِ کبیسهٔ شناخته‌شده در برابرِ سه سالِ عادی
        foreach ([1399, 1403, 1408] as $leap) {
            $this->assertTrue(Jalali::isLeap($leap), "سال $leap باید کبیسه باشد");
        }
        foreach ([1400, 1401, 1405] as $plain) {
            $this->assertFalse(Jalali::isLeap($plain), "سال $plain نباید کبیسه باشد");
        }
    }

    public function test_parser_accepts_persian_digits_and_both_separators(): void
    {
        $this->assertSame([1405, 5, 12], Jalali::parse('1405-05-12'));
        $this->assertSame([1405, 5, 12], Jalali::parse('1405/5/12'));
        $this->assertSame([1405, 5, 12], Jalali::parse('۱۴۰۵-۰۵-۱۲'));

        // ۳۱ اسفند وجود ندارد — باید رد شود نه اینکه به فروردین سر برود
        $this->assertNull(Jalali::parse('1405-12-31'));
        $this->assertNull(Jalali::parse('چیزی نیست'));
        $this->assertNull(Jalali::parse(null));
    }

    /* ═════════════════════ دسترسی ═════════════════════ */

    public function test_the_page_loads_for_an_admin(): void
    {
        $this->actingAs($this->staff(), 'web')
            ->get('/admin/calendar')
            ->assertOk()
            ->assertSee('تقویم کسب‌وکار')
            ->assertSee('cal-boot', false);
    }

    /**
     * 🔴 نویسندهٔ محتوا این صفحه را نمی‌بیند.
     *
     * تقویم سررسیدِ فاکتور و پروندهٔ مشتری را کنار هم می‌گذارد. روتِ تازه باید
     * خودبه‌خود پشتِ `admin` باشد — همان قاعده‌ای که در routes/web.php نوشته شده
     * و سه بار جا افتادنش گران تمام شد.
     */
    public function test_a_content_author_cannot_open_the_calendar(): void
    {
        $this->actingAs($this->staff('author'), 'web')
            ->get('/admin/calendar')
            ->assertForbidden();

        $this->actingAs($this->staff('author'), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected(): void
    {
        $this->get('/admin/calendar')->assertRedirect();
    }

    public function test_the_sidebar_shows_the_calendar_link_in_the_business_group(): void
    {
        $html = $this->actingAs($this->staff(), 'web')->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('/admin/calendar', $html);
        // زیرِ گروهِ «کسب‌وکار»، نه شناور و نه در گروهِ دیگر
        $business = strpos($html, 'کسب‌وکار');
        $finance = strpos($html, 'مالی');
        $link = strpos($html, '/admin/calendar');
        $this->assertNotFalse($business);
        $this->assertGreaterThan($business, $link);
        $this->assertLessThan($finance, $link);
    }

    /**
     * پرش به هر ماه و سال — نه فقط یک ماه جلو و عقب.
     *
     * ⚠️ ناوبری تا امروز فقط ماه‌به‌ماه بود، پس «سالِ آینده را ببینم» دوازده
     * کلیک می‌شد. حالا انتخابگر هست و این تست ادعا می‌کند سرور هم ماهِ دور را
     * می‌پذیرد (نه فقط نزدیک).
     */
    public function test_any_month_of_any_reasonable_year_can_be_requested(): void
    {
        $staff = $this->staff();

        foreach ([[1406, 1], [1407, 12], [1404, 6], [1410, 7]] as [$y, $m]) {
            $res = $this->actingAs($staff, 'web')
                ->getJson("/admin/calendar/events?y={$y}&m={$m}")
                ->assertOk()->json();

            $this->assertSame($y, $res['grid']['year']);
            $this->assertSame($m, $res['grid']['month']);
            $this->assertSame(0, count($res['grid']['cells']) % 7);
        }
    }

    /** سالِ بی‌معنی رد می‌شود، نه اینکه صفحه را بشکند */
    public function test_an_absurd_year_is_refused(): void
    {
        $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=9999&m=1')
            ->assertStatus(422);
    }

    /**
     * نامِ ماه‌ها از سرور می‌آید تا مرورگر فهرستِ دومی نسازد.
     *
     * ⚠️ ادعا روی **payloadِ رمزگشایی‌شده** است، نه روی متنِ HTML: بلید در
     * `@json` فارسی را به `\uXXXX` تبدیل می‌کند، پس `assertStringContainsString`
     * روی نامِ ماه همیشه شکست می‌خورد — و آن شکست دربارهٔ چیزی است که اصلاً
     * خراب نیست.
     */
    public function test_the_boot_payload_carries_the_month_names(): void
    {
        $html = $this->actingAs($this->staff(), 'web')->get('/admin/calendar')->assertOk()->getContent();

        $this->assertStringContainsString('cal-jump', $html, 'انتخابگرِ ماه/سال باید در صفحه باشد');

        $this->assertSame(1, preg_match(
            '#<script type="application/json" id="cal-boot">(.*?)</script>#s', $html, $m,
        ));

        $boot = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        $this->assertIsArray($boot['monthNames'] ?? null);
        $this->assertCount(12, $boot['monthNames']);
        $this->assertSame('فروردین', $boot['monthNames'][0]);
        $this->assertSame('اسفند', $boot['monthNames'][11]);
    }

    /* ═════════════════════ لایه‌ها ═════════════════════ */

    public function test_each_layer_reads_from_its_own_table(): void
    {
        $c = $this->customer();

        // همه در مردادِ ۱۴۰۵ (۲۳ ژوئیه تا ۲۲ اوت ۲۰۲۶)
        Domain::create([
            'customer_id' => $c->id, 'domain' => 'servernet.test', 'sld' => 'servernet',
            'tld' => 'test', 'status' => 'active', 'expires_at' => '2026-07-25 10:00:00',
        ]);

        Service::create([
            'customer_id' => $c->id, 'name' => 'هاست ویژه', 'currency_code' => 'IRT',
            'price' => 500000, 'cycle' => 'monthly', 'status' => 'active',
            'next_due_at' => '2026-07-28',
        ]);

        Invoice::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'subtotal' => 100000,
            'tax' => 0, 'total' => 100000, 'status' => 'unpaid', 'due_at' => '2026-07-30 12:00:00',
        ]);

        CalendarEvent::create([
            'type' => 'task', 'title' => 'تماس با تأمین‌کننده',
            'event_date' => '2026-08-01', 'status' => 'pending',
        ]);

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')
            ->assertOk()
            ->json();

        $this->assertTrue($res['ok']);

        $byType = collect($res['events'])->keyBy('type');
        $this->assertTrue($byType->has('domain_renewal'));
        $this->assertTrue($byType->has('hosting_renewal'));
        $this->assertTrue($byType->has('payment_due'));
        $this->assertTrue($byType->has('task'));

        $this->assertSame('servernet.test', $byType['domain_renewal']['title']);
        // ۲۵ ژوئیه ۲۰۲۶ = ۳ مرداد ۱۴۰۵
        $this->assertSame('1405-05-03', $byType['domain_renewal']['date']);
        $this->assertSame('1405-05-06', $byType['hosting_renewal']['date']);
    }

    /**
     * 🔴 رویدادها باید **به ترتیبِ زمانی** برگردند.
     *
     * این تست نبود و یک باگِ واقعی از کنارش رد شد: `sortBy()` با آرایه‌ای از
     * دسترس‌گرهای تک‌آرگومانی صدا زده شده بود، ولی لاراول آن آرایه را
     * **مقایسه‌گرِ دوآرگومانی** می‌فهمد. PHP آرگومانِ اضافه را برای closure
     * بی‌صدا نادیده می‌گیرد، پس رشتهٔ تاریخ به‌جای نتیجهٔ مقایسه برمی‌گشت و
     * ترتیب عملاً تصادفی می‌شد.
     *
     * ⚠️ در نمای **ماه** دیده نمی‌شد — هر رویداد بر اساسِ تاریخش در خانهٔ درست
     * می‌نشیند. فقط نمای فهرست و ستونِ «پیش‌رو» غلط بودند، یعنی دقیقاً جاهایی
     * که ترتیب تنها معنایشان است. پس ادعای این تست روی **ترتیبِ خروجیِ API**
     * است، نه روی ظاهرِ شبکه.
     */
    public function test_events_come_back_in_chronological_order(): void
    {
        // عمداً بی‌ترتیب ساخته می‌شوند
        foreach ([
            ['2026-08-18', 'ششم'],
            ['2026-07-26', 'دوم'],
            ['2026-08-03', 'چهارم'],
            ['2026-07-23', 'اول'],
            ['2026-08-11', 'پنجم'],
            ['2026-07-30', 'سوم'],
        ] as [$date, $title]) {
            CalendarEvent::create([
                'type' => 'task', 'title' => $title, 'event_date' => $date, 'status' => 'pending',
            ]);
        }

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();

        $dates = array_column($res['events'], 'date');
        $sorted = $dates;
        sort($sorted);

        $this->assertSame($sorted, $dates, 'رویدادها باید به ترتیبِ تاریخ برگردند');
        $this->assertSame(
            ['اول', 'دوم', 'سوم', 'چهارم', 'پنجم', 'ششم'],
            array_column($res['events'], 'title'),
        );
    }

    /**
     * ستونِ «پیش‌رو» هم همان ترتیب را لازم دارد — و بازه‌اش از **امروز** است،
     * نه از ماهی که کاربر نگاه می‌کند.
     */
    public function test_the_upcoming_list_is_ordered_and_anchored_to_today(): void
    {
        $tz = (string) config('calendar.display_timezone');
        $today = Carbon::now($tz);

        foreach ([5, 1, 3] as $offset) {
            CalendarEvent::create([
                'type' => 'task', 'title' => 'روز '.$offset,
                'event_date' => $today->copy()->addDays($offset)->toDateString(),
                'status' => 'pending',
            ]);
        }

        // بیرونِ پنجرهٔ ۷ روزه — نباید بیاید
        CalendarEvent::create([
            'type' => 'task', 'title' => 'خیلی دور',
            'event_date' => $today->copy()->addDays(40)->toDateString(), 'status' => 'pending',
        ]);

        // لغوشده کاری برای انجام ندارد — «پیش‌رو» نیست
        CalendarEvent::create([
            'type' => 'task', 'title' => 'لغوشده',
            'event_date' => $today->copy()->addDays(2)->toDateString(), 'status' => 'cancelled',
        ]);

        $upcoming = app(CalendarService::class)->upcoming();

        $this->assertSame(
            ['روز 1', 'روز 3', 'روز 5'],
            $upcoming->map(fn ($i) => $i->title)->all(),
        );
        $this->assertSame([1, 3, 5], $upcoming->map(fn ($i) => $i->daysFromToday())->all());
    }

    /**
     * 🔴 رویدادِ **روزِ آخرِ بازه** باید دیده شود.
     *
     * ستون‌های `date` را لاراول `2026-08-22 00:00:00` می‌نویسد، پس مقایسهٔ
     * رشته‌ایِ `BETWEEN '…' AND '2026-08-22'` آن ردیف را بیرون می‌گذاشت — چون
     * `'2026-08-22 00:00:00'` از `'2026-08-22'` بزرگ‌تر است.
     *
     * پیامدش خاموش بود و دقیقاً روی لبه: یادآوریِ **آخرین روزِ هر ماه** در نمای
     * همان ماه غیب می‌شد، و پنجرهٔ «پیش‌رو» یک روز کوتاه بود. هیچ تستی نگرفتش
     * چون همه‌شان رویداد را وسطِ ماه می‌گذاشتند.
     */
    public function test_an_event_on_the_last_day_of_the_range_is_included(): void
    {
        $c = $this->customer();

        // ۳۱ مرداد ۱۴۰۵ = ۲۲ اوت ۲۰۲۶ — آخرین روزِ ماه
        CalendarEvent::create([
            'type' => 'task', 'title' => 'کارِ روزِ آخر',
            'event_date' => '2026-08-22', 'status' => 'pending',
        ]);
        Service::create([
            'customer_id' => $c->id, 'name' => 'سرویسِ روزِ آخر', 'currency_code' => 'IRT',
            'price' => 1000, 'cycle' => 'monthly', 'status' => 'active',
            'next_due_at' => '2026-08-22',
        ]);

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();

        $titles = array_column($res['events'], 'title');
        $this->assertContains('کارِ روزِ آخر', $titles, 'یادآوریِ روزِ آخرِ ماه باید دیده شود');
        $this->assertContains('سرویسِ روزِ آخر', $titles, 'سررسیدِ روزِ آخرِ ماه باید دیده شود');
    }

    /**
     * و همان لبه در پنجرهٔ «پیش‌رو»: `upcoming(1)` یعنی **امروز**، نه هیچ.
     */
    public function test_the_upcoming_window_includes_its_own_last_day(): void
    {
        $tz = (string) config('calendar.display_timezone');
        $today = Carbon::now($tz);

        CalendarEvent::create([
            'type' => 'task', 'title' => 'امروز',
            'event_date' => $today->toDateString(), 'status' => 'pending',
        ]);
        CalendarEvent::create([
            'type' => 'task', 'title' => 'فردا',
            'event_date' => $today->copy()->addDay()->toDateString(), 'status' => 'pending',
        ]);

        $svc = app(CalendarService::class);

        $this->assertSame(['امروز'], $svc->upcoming(['task'], 1)->map(fn ($i) => $i->title)->all());
        $this->assertSame(['امروز', 'فردا'], $svc->upcoming(['task'], 2)->map(fn ($i) => $i->title)->all());
    }

    public function test_dead_rows_never_reach_the_calendar(): void
    {
        $c = $this->customer();

        Domain::create([
            'customer_id' => $c->id, 'domain' => 'gone.test', 'sld' => 'gone', 'tld' => 'test',
            'status' => 'cancelled', 'expires_at' => '2026-07-25 10:00:00',
        ]);
        Service::create([
            'customer_id' => $c->id, 'name' => 'سرویسِ خاتمه‌یافته', 'currency_code' => 'IRT',
            'price' => 1, 'cycle' => 'monthly', 'status' => 'terminated', 'next_due_at' => '2026-07-28',
        ]);
        Invoice::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'subtotal' => 1, 'tax' => 0,
            'total' => 1, 'paid' => 1, 'status' => 'paid', 'due_at' => '2026-07-30 12:00:00',
        ]);

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();

        $this->assertSame([], $res['events']);
    }

    /**
     * 🔴 «هیچ لایه‌ای» ≠ «همهٔ لایه‌ها».
     *
     * اگر این دو یکی گرفته شوند، کاربری که همهٔ چیپ‌ها را خاموش می‌کند ناگهان
     * همه‌چیز را می‌بیند — دقیقاً برعکسِ چیزی که خواسته.
     */
    public function test_an_explicitly_empty_layer_list_returns_nothing(): void
    {
        CalendarEvent::create([
            'type' => 'task', 'title' => 'کاری', 'event_date' => '2026-08-01', 'status' => 'pending',
        ]);

        $staff = $this->staff();

        $all = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();
        $this->assertCount(1, $all['events']);

        $none = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&layers[]=')->assertOk()->json();
        $this->assertSame([], $none['events']);
    }

    public function test_layer_filter_narrows_the_result(): void
    {
        $c = $this->customer();
        Domain::create([
            'customer_id' => $c->id, 'domain' => 'a.test', 'sld' => 'a', 'tld' => 'test',
            'status' => 'active', 'expires_at' => '2026-07-25 10:00:00',
        ]);
        CalendarEvent::create([
            'type' => 'task', 'title' => 'کاری', 'event_date' => '2026-08-01', 'status' => 'pending',
        ]);

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&layers[]=task')->assertOk()->json();

        $this->assertCount(1, $res['events']);
        $this->assertSame('task', $res['events'][0]['type']);
    }

    /* ═════════════════════ رویدادِ دستی ═════════════════════ */

    public function test_a_reminder_is_stored_with_the_correct_gregorian_date(): void
    {
        $staff = $this->staff();

        $res = $this->actingAs($staff, 'web')->postJson('/admin/calendar/events', [
            'type' => 'task', 'title' => 'تماس با مشتری',
            'event_date' => '1405-05-12', 'description' => 'دربارهٔ تمدید',
        ])->assertCreated()->json();

        $this->assertTrue($res['ok']);

        $row = CalendarEvent::first();
        $this->assertNotNull($row);
        // ۱۲ مرداد ۱۴۰۵ = ۳ اوت ۲۰۲۶
        $this->assertSame('2026-08-03', $row->event_date->toDateString());
        $this->assertSame($staff->id, $row->user_id);
        // و در پاسخ، دوباره شمسی برمی‌گردد
        $this->assertSame('1405-05-12', $res['event']['date']);
    }

    public function test_an_unparsable_date_is_rejected(): void
    {
        $this->actingAs($this->staff(), 'web')->postJson('/admin/calendar/events', [
            'type' => 'task', 'title' => 'x', 'event_date' => '1405-13-40',
        ])->assertStatus(422);

        $this->assertSame(0, CalendarEvent::count());
    }

    public function test_status_can_be_marked_done_and_cancelled(): void
    {
        $event = CalendarEvent::create([
            'type' => 'task', 'title' => 'کاری', 'event_date' => '2026-08-03', 'status' => 'pending',
        ]);
        $staff = $this->staff();

        $this->actingAs($staff, 'web')
            ->patchJson("/admin/calendar/events/{$event->id}", ['status' => 'done'])
            ->assertOk()->assertJsonPath('event.status', 'done');

        $this->assertSame('done', $event->fresh()->status);

        $this->actingAs($staff, 'web')
            ->patchJson("/admin/calendar/events/{$event->id}", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame('cancelled', $event->fresh()->status);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $event = CalendarEvent::create([
            'type' => 'task', 'title' => 'کاری', 'event_date' => '2026-08-03', 'status' => 'pending',
        ]);

        $this->actingAs($this->staff(), 'web')
            ->patchJson("/admin/calendar/events/{$event->id}", ['status' => 'exploded'])
            ->assertStatus(422);

        $this->assertSame('pending', $event->fresh()->status);
    }

    public function test_a_manual_event_can_be_deleted(): void
    {
        $event = CalendarEvent::create([
            'type' => 'task', 'title' => 'کاری', 'event_date' => '2026-08-03', 'status' => 'pending',
        ]);

        $this->actingAs($this->staff(), 'web')
            ->deleteJson("/admin/calendar/events/{$event->id}")
            ->assertOk();

        $this->assertNull(CalendarEvent::find($event->id));
    }

    /**
     * 🔴 حذف فقط رویدادِ دستی را می‌گیرد.
     *
     * محافظش یک شرطِ `if` نیست بلکه **شکلِ داده** است: رویدادِ خودکار اصلاً
     * ردیفی در `calendar_events` ندارد، پس شناسه‌اش هرگز به مدل نمی‌رسد.
     * این تست همان تضمین را قفل می‌کند — اگر روزی کسی رویدادها را در جدول
     * کپی کند، همین‌جا می‌شکند.
     */
    public function test_a_provider_event_cannot_be_deleted(): void
    {
        $c = $this->customer();
        $domain = Domain::create([
            'customer_id' => $c->id, 'domain' => 'x.test', 'sld' => 'x', 'tld' => 'test',
            'status' => 'active', 'expires_at' => '2026-07-25 10:00:00',
        ]);

        // شناسهٔ دامنه به‌عنوان شناسهٔ رویدادِ تقویم — نباید چیزی حذف شود
        $this->actingAs($this->staff(), 'web')
            ->deleteJson("/admin/calendar/events/{$domain->id}")
            ->assertNotFound();

        $this->assertNotNull(Domain::find($domain->id));
    }

    /**
     * 🔴 چیپ = **نوعِ رویداد**، نه «کدام provider اجرا شود».
     *
     * یادآوریِ دستی با نوعِ «سررسید پرداخت» (اجارهٔ دفتر) رنگ و آیکونِ پرداخت
     * می‌گیرد، پس باید با همان چیپ خاموش و روشن شود. پیش از این زیرِ چیپِ
     * «یادآوری و کار» قایم بود و خاموش‌کردنِ چیپِ پرداخت هیچ اثری رویش نداشت —
     * یعنی کنترلی که کاربر می‌بیند به چیزی که می‌بیند وصل نبود.
     */
    public function test_a_manual_event_is_controlled_by_the_chip_of_its_own_type(): void
    {
        CalendarEvent::create([
            'type' => 'payment_due', 'title' => 'اجارهٔ دفتر',
            'event_date' => '2026-08-03', 'status' => 'pending',
        ]);
        CalendarEvent::create([
            'type' => 'task', 'title' => 'کارِ معمولی',
            'event_date' => '2026-08-04', 'status' => 'pending',
        ]);

        $staff = $this->staff();

        $payment = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&layers[]=payment_due')->assertOk()->json();
        $this->assertSame(['اجارهٔ دفتر'], array_column($payment['events'], 'title'));

        $task = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&layers[]=task')->assertOk()->json();
        $this->assertSame(['کارِ معمولی'], array_column($task['events'], 'title'));
    }

    /* ═════════════════════ تکرارشوندگی ═════════════════════ */

    /**
     * 🔴 «پنجمِ هر ماه» یعنی پنجمِ هر ماهِ **شمسی**.
     *
     * اگر با `Carbon::addMonths()` (ماهِ میلادی) گام برمی‌داشتیم، چون ماه‌های
     * شمسی ۳۱/۳۰/۲۹ روزه‌اند و با میلادی هم‌مرز نیستند، یادآوریِ اجاره بعد از
     * چند ماه یکی‌دو روز از روزش می‌افتاد — آرام، بی‌خطا، و دقیقاً روی چیزی که
     * باید سرِ وقت پرداخت شود.
     */
    public function test_a_monthly_series_lands_on_the_same_jalali_day_each_month(): void
    {
        // ۵ مرداد ۱۴۰۵ = ۲۷ ژوئیه ۲۰۲۶
        CalendarEvent::create([
            'type' => 'payment_due', 'title' => 'اجارهٔ دفتر',
            'event_date' => '2026-07-27', 'repeat' => 'monthly',
            'amount' => 50000000, 'currency_code' => 'IRT', 'status' => 'pending',
        ]);

        foreach ([[5, 5], [6, 5], [7, 5], [12, 5]] as [$month, $day]) {
            $res = $this->actingAs($this->staff(), 'web')
                ->getJson("/admin/calendar/events?y=1405&m={$month}&layers[]=payment_due")
                ->assertOk()->json();

            $this->assertCount(1, $res['events'], "ماه $month باید دقیقاً یک تکرار داشته باشد");
            $this->assertSame(
                Jalali::format(1405, $month, $day),
                $res['events'][0]['date'],
                "تکرارِ ماه $month باید روی روز $day بنشیند",
            );
        }
    }

    /**
     * روزی که در ماهِ مقصد نیست باید **کوتاه** شود، نه به ماهِ بعد سر برود.
     */
    public function test_a_day_that_does_not_exist_in_the_target_month_is_clamped(): void
    {
        // ۳۱ فروردین ۱۴۰۵ — مهر ۳۰ روز دارد و اسفند ۲۹
        $this->assertSame([1405, 7, 30], Jalali::addMonths(1405, 1, 31, 6));
        $this->assertSame([1405, 12, 29], Jalali::addMonths(1405, 1, 31, 11));

        // و از سالِ کبیسه به سالِ عادی
        $this->assertSame([1404, 12, 29], Jalali::addYears(1403, 12, 30, 1));
    }

    /**
     * 🔴 سریِ تکرارشونده‌ای که **قبلاً** شروع شده باید دیده شود.
     *
     * فیلترِ سادهٔ `whereBetween('event_date')` تاریخِ شروع را می‌سنجد، پس
     * «اجاره از سالِ پیش» هیچ‌وقت در تقویمِ امسال نمی‌آمد — بی‌هیچ خطایی.
     */
    public function test_a_series_that_started_before_the_window_still_shows(): void
    {
        CalendarEvent::create([
            'type' => 'task', 'title' => 'اجارهٔ قدیمی',
            'event_date' => '2024-07-27', 'repeat' => 'monthly', 'status' => 'pending',
        ]);

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();

        $this->assertCount(1, $res['events']);
        $this->assertSame('اجارهٔ قدیمی', $res['events'][0]['title']);
    }

    public function test_a_series_stops_at_its_end_date(): void
    {
        CalendarEvent::create([
            'type' => 'task', 'title' => 'موقت',
            'event_date' => '2026-07-27', 'repeat' => 'monthly',
            'repeat_until' => '2026-08-27', 'status' => 'pending',
        ]);

        $inRange = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=6')->assertOk()->json();
        $this->assertCount(1, $inRange['events']);

        $after = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=8')->assertOk()->json();
        $this->assertSame([], $after['events']);
    }

    /**
     * 🔴 «انجام شد» به‌ازای **هر تکرار** است، نه کلِ سری.
     *
     * اگر روی `status`ِ ردیف نوشته می‌شد، تیک‌زدنِ اجارهٔ مرداد همهٔ ماه‌های
     * بعد را هم انجام‌شده می‌کرد — یعنی یادآوری بعد از اولین پرداخت برای همیشه
     * خاموش می‌شد.
     */
    public function test_marking_one_occurrence_done_leaves_the_others_pending(): void
    {
        $event = CalendarEvent::create([
            'type' => 'payment_due', 'title' => 'اجاره',
            'event_date' => '2026-07-27', 'repeat' => 'monthly', 'status' => 'pending',
        ]);
        $staff = $this->staff();

        // تکرارِ مرداد را انجام‌شده کن
        $this->actingAs($staff, 'web')
            ->patchJson("/admin/calendar/events/{$event->id}", [
                'status' => 'done', 'occurrence' => '2026-07-27',
            ])->assertOk()->assertJsonPath('event.status', 'done');

        $mordad = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();
        $shahrivar = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=6')->assertOk()->json();

        $this->assertSame('done', $mordad['events'][0]['status']);
        $this->assertSame('pending', $shahrivar['events'][0]['status'], 'شهریور نباید تیک بخورد');

        // و ردیفِ اصلی دست‌نخورده مانده
        $this->assertSame('pending', $event->fresh()->status);
    }

    public function test_each_occurrence_has_its_own_addressable_id(): void
    {
        $event = CalendarEvent::create([
            'type' => 'task', 'title' => 'تکراری',
            'event_date' => '2026-07-27', 'repeat' => 'monthly', 'status' => 'pending',
        ]);

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=6')->assertOk()->json();

        $this->assertSame('manual:'.$event->id.'@2026-08-27', $res['events'][0]['id']);
        $this->assertSame('2026-08-27', $res['events'][0]['meta']['occurrence']);
    }

    public function test_the_amount_is_shown_in_the_description(): void
    {
        CalendarEvent::create([
            'type' => 'payment_due', 'title' => 'اجاره', 'event_date' => '2026-08-03',
            'repeat' => 'monthly', 'amount' => 50000000, 'currency_code' => 'IRT', 'status' => 'pending',
        ]);

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();

        $this->assertStringContainsString('ماهانه', $res['events'][0]['description']);
        $this->assertNotEmpty($res['events'][0]['description']);
    }

    public function test_a_reminder_can_be_created_with_a_repeat_rule(): void
    {
        $res = $this->actingAs($this->staff(), 'web')->postJson('/admin/calendar/events', [
            'type' => 'payment_due', 'title' => 'اجارهٔ دفتر',
            'event_date' => '1405-05-05', 'repeat' => 'monthly',
            'repeat_until' => '1406-05-05', 'amount' => 50000000,
        ])->assertCreated()->json();

        $this->assertTrue($res['ok']);

        $row = CalendarEvent::first();
        $this->assertSame('monthly', $row->repeat);
        $this->assertSame('2026-07-27', $row->event_date->toDateString());
        $this->assertSame('2027-07-27', $row->repeat_until->toDateString());
        $this->assertSame(50000000, $row->amount);
        $this->assertSame('IRT', $row->currency_code);
    }

    public function test_an_end_date_before_the_start_is_refused(): void
    {
        $this->actingAs($this->staff(), 'web')->postJson('/admin/calendar/events', [
            'type' => 'task', 'title' => 'x',
            'event_date' => '1405-05-05', 'repeat' => 'monthly', 'repeat_until' => '1404-05-05',
        ])->assertStatus(422)->assertJsonPath('error', 'until_before_start');

        $this->assertSame(0, CalendarEvent::count());
    }

    /* ═════════════════════ ترجیحِ لایه ═════════════════════ */

    public function test_layer_preferences_are_saved_per_user_and_applied(): void
    {
        $a = $this->staff();
        $b = $this->staff();

        CalendarEvent::create([
            'type' => 'task', 'title' => 'کاری', 'event_date' => '2026-08-01', 'status' => 'pending',
        ]);

        $this->actingAs($a, 'web')->postJson('/admin/calendar/preferences', [
            'layers' => ['task' => false, 'payment_due' => true],
        ])->assertOk()->assertJsonPath('layers.task', false);

        $this->assertFalse(CalendarLayerPreference::forUser($a->id)['task']);
        // ترجیحِ شخصی است — کاربرِ دیگر دست‌نخورده می‌مانَد
        $this->assertTrue(CalendarLayerPreference::forUser($b->id)['task']);

        // و بی‌فرستادنِ layers، همان ترجیح اعمال می‌شود
        $res = $this->actingAs($a, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();
        $this->assertSame([], $res['events']);
    }

    /**
     * لایه‌ای که بعداً به config اضافه شود باید برای کاربرِ قدیمی هم **روشن**
     * باشد. بی‌این، هر قابلیتِ تازه برای همهٔ کاربرانِ موجود نامرئی می‌شد.
     */
    public function test_an_unsaved_layer_defaults_to_visible(): void
    {
        $a = $this->staff();

        CalendarLayerPreference::store($a->id, ['task' => false]);

        $prefs = CalendarLayerPreference::forUser($a->id);
        $this->assertFalse($prefs['task']);
        $this->assertTrue($prefs['domain_renewal']);
        $this->assertTrue($prefs['payment_due']);
    }

    /* ═════════════════════ مقاومت ═════════════════════ */

    /**
     * 🔴 یک providerِ خراب کلِ تقویم را نمی‌کُشد.
     *
     * تقویم پنج منبع را کنار هم می‌گذارد و روی سروری اجرا می‌شود که ممکن است
     * هنوز همهٔ مهاجرت‌ها را نخورده باشد. صفحه‌ای که به‌خاطرِ یک جدولِ نبود ۵۰۰
     * شود، دقیقاً روزی از کار می‌افتد که بیشترین لازم را دارد.
     */
    public function test_a_throwing_provider_does_not_kill_the_calendar(): void
    {
        CalendarEvent::create([
            'type' => 'task', 'title' => 'سالم', 'event_date' => '2026-08-01', 'status' => 'pending',
        ]);

        $service = app(CalendarService::class);

        $service->register('domain_renewal', new class implements CalendarEventProvider
        {
            public function getEvents(Carbon $from, Carbon $to): Collection
            {
                throw new \RuntimeException('جدول نیست');
            }
        });

        $events = $service->events(
            Carbon::parse('2026-07-23', 'Asia/Tehran'),
            Carbon::parse('2026-08-22 23:59:59', 'Asia/Tehran'),
        );

        // لایهٔ سالم رسیده…
        $this->assertCount(1, $events);
        $this->assertSame('سالم', $events->first()->title);
        // …و خرابی **گزارش** شده، نه بلعیده
        $this->assertArrayHasKey('domain_renewal', $service->failures());
    }

    public function test_an_over_wide_range_is_refused(): void
    {
        $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?from=1400-01-01&to=1410-01-01')
            ->assertStatus(422)
            ->assertJsonPath('error', 'range_too_wide');
    }

    /**
     * 🔴 روزِ شمسی با ساعتِ **تهران** تعیین می‌شود، نه UTC.
     *
     * `config/app.timezone` عمداً UTC است. رویدادِ ساعت ۲۱:۳۰ UTC یعنی ۰۱:۰۰
     * فردا به وقتِ تهران — و اگر تقویم UTC حساب کند، آن رویداد یک روز زودتر
     * نشان داده می‌شود.
     */
    public function test_a_late_night_event_lands_on_the_tehran_day(): void
    {
        $c = $this->customer();

        // ۲۱:۳۰ UTC روی ۲۵ ژوئیه = ۰۱:۰۰ بامدادِ ۲۶ ژوئیه به وقتِ تهران
        Domain::create([
            'customer_id' => $c->id, 'domain' => 'late.test', 'sld' => 'late', 'tld' => 'test',
            'status' => 'active', 'expires_at' => '2026-07-25 21:30:00',
        ]);

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();

        // ۲۶ ژوئیه ۲۰۲۶ = ۴ مرداد ۱۴۰۵ (نه ۳ مرداد)
        $this->assertSame('1405-05-04', $res['events'][0]['date']);
    }

    /**
     * `with_upcoming=1` — تنها مسیری که فقط جاوااسکریپت صدایش می‌زند.
     *
     * ⚠️ ادعای مهم این تست ترتیبِ **دو** فراخوانِ داخلی است: `upcoming()` عمداً
     * بعد از برداشتنِ `failures()`ِ بازهٔ اصلی صدا زده می‌شود، چون خودش آن را
     * ریست می‌کند. برعکسش یعنی گزارشِ خرابی همیشه مالِ هفتهٔ پیشِ‌رو بود نه
     * ماهی که کاربر می‌بیند — یک اشتباهِ کاملاً خاموش.
     */
    public function test_the_upcoming_payload_is_only_sent_when_asked(): void
    {
        $tz = (string) config('calendar.display_timezone');

        CalendarEvent::create([
            'type' => 'task', 'title' => 'به‌زودی',
            'event_date' => Carbon::now($tz)->addDay()->toDateString(), 'status' => 'pending',
        ]);

        $staff = $this->staff();

        $without = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();
        $this->assertArrayNotHasKey('upcoming', $without);

        $with = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&with_upcoming=1')->assertOk()->json();
        $this->assertArrayHasKey('upcoming', $with);
        $this->assertSame('به‌زودی', $with['upcoming'][0]['title']);
        $this->assertSame(1, $with['upcoming'][0]['days_away']);
    }

    public function test_the_response_carries_a_screen_reader_label(): void
    {
        CalendarEvent::create([
            'type' => 'task', 'title' => 'تماس با مشتری', 'event_date' => '2026-08-03', 'status' => 'pending',
        ]);

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();

        $label = $res['events'][0]['sr_label'];
        // برچسب باید **زمینه** بدهد نه فقط عنوان: نوعِ لایه و تاریخِ خوانا
        $this->assertStringContainsString('تماس با مشتری', $label);
        $this->assertStringContainsString('مرداد', $label);
    }

    /**
     * داربستِ شبکه از سرور می‌آید — تنها راهی که مرورگر بی‌ریاضیِ جلالی
     * می‌تواند ماه را رسم کند.
     */
    public function test_the_month_grid_scaffold_comes_from_the_server(): void
    {
        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();

        $this->assertSame(1405, $res['grid']['year']);
        $this->assertSame(5, $res['grid']['month']);
        $this->assertSame('مرداد', $res['grid']['month_name']);
        $this->assertSame(31, $res['grid']['days']);

        // خانه‌ها همیشه مضربِ ۷ اند، وگرنه آخرین ردیفِ شبکه کش می‌آید
        $this->assertSame(0, count($res['grid']['cells']) % 7);

        $days = array_values(array_filter(
            array_column($res['grid']['cells'], 'day'),
            static fn ($d) => $d !== null,
        ));
        $this->assertSame(31, count($days));
        $this->assertSame(1, $days[0]);
        $this->assertSame(31, $days[30]);
    }
}
