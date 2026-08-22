<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CalendarLayerPreference;
use App\Models\User;
use App\Services\Notify\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * یادآوریِ روزانهٔ تقویم به مدیر — `calendar:remind`.
 *
 * تقویم فقط وقتی کار می‌کند که کسی بازش کند؛ سررسیدی که در صفحهٔ باز‌نشده
 * نشسته عملاً وجود ندارد. این فرمان همان چند خط را می‌بَرد جایی که مدیر هر
 * روز نگاه می‌کند (بله + ایمیل).
 */
class CalendarRemindTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{title:string, rows:array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        // AdminNotifier را با یک جاسوس جایگزین می‌کنیم — نه بله واقعی، نه ایمیل
        $spy = new class($this) extends AdminNotifier
        {
            public function __construct(private $test)
            {
                // عمداً parent::__construct صدا زده نمی‌شود: وابستگیِ بله لازم نیست
            }

            /* ⚠️ امضا باید دقیقاً با والد بخواند؛ `$buttons` وقتی اضافه شد که
               اعلان‌ها دکمهٔ شیشه‌ای گرفتند. */
            public function event(string $title, array $rows = [], ?string $url = null, string $emoji = '🔔', array $buttons = []): void
            {
                $this->test->record($title, $rows, $url);
            }
        };

        $this->app->instance(AdminNotifier::class, $spy);
    }

    public function record(string $title, array $rows, ?string $url = null): void
    {
        $this->sent[] = ['title' => $title, 'rows' => $rows, 'url' => $url];
    }

    private function tz(): string
    {
        return (string) config('calendar.display_timezone');
    }

    private function reminder(string $title, int $daysFromNow, string $status = 'pending'): CalendarEvent
    {
        return CalendarEvent::create([
            'type' => 'task', 'title' => $title, 'status' => $status,
            'event_date' => Carbon::now($this->tz())->addDays($daysFromNow)->toDateString(),
        ]);
    }

    /**
     * 🔴 «چیزی نیست» فرستاده نمی‌شود.
     *
     * درسِ ثبت‌شدهٔ همین پروژه: مدیری که هر روز پیامِ بی‌محتوا بگیرد از هفتهٔ
     * دوم همه را نادیده می‌گیرد — و آن‌وقت پیامِ روزی که واقعاً مهم است هم گم
     * می‌شود. سکوت یعنی «کاری نیست».
     */
    public function test_an_empty_day_sends_nothing(): void
    {
        $this->artisan('calendar:remind')->assertOk();

        $this->assertSame([], $this->sent);
    }

    public function test_upcoming_reminders_are_sent_with_a_human_when(): void
    {
        $this->reminder('تماس با تأمین‌کننده', 0);
        $this->reminder('پرداخت اجاره', 1);

        $this->artisan('calendar:remind')->assertOk();

        $this->assertCount(1, $this->sent, 'یک پیامِ جمع‌شده، نه یکی به ازای هر رویداد');

        $body = implode("\n", $this->sent[0]['rows']);
        $this->assertStringContainsString('تماس با تأمین‌کننده', $body);
        $this->assertStringContainsString('امروز', $body);
        $this->assertStringContainsString('پرداخت اجاره', $body);
        $this->assertStringContainsString('فردا', $body);
    }

    /**
     * 🔴 تاریخ **یک بار** به‌عنوان سرگروه می‌آید، نه دنبالِ هر خط.
     *
     * نسخهٔ اول به هر ردیف «۲۵ مرداد (امروز)» می‌چسباند؛ در پیامی با چهار
     * موردِ امروز، همان عبارت چهار بار تکرار می‌شد و عنوانِ خودِ کار — تنها
     * چیزِ مهم — لای تکرار گم می‌شد.
     */
    public function test_the_date_appears_once_per_day_not_on_every_line(): void
    {
        $this->reminder('کارِ اول', 0);
        $this->reminder('کارِ دوم', 0);
        $this->reminder('کارِ سوم', 0);

        $this->artisan('calendar:remind')->assertOk();

        $body = implode("\n", $this->sent[0]['rows']);

        $this->assertSame(1, substr_count($body, 'امروز'),
            'سرگروهِ «امروز» باید دقیقاً یک بار بیاید');
        $this->assertSame(3, substr_count($body, '•'), 'سه کار، سه ردیف');
    }

    /**
     * هر روز سرگروهِ خودش را دارد.
     *
     * ⚠️ عنوان‌ها عمداً «الف» و «ب» اند: نسخهٔ اول «امروزی» و «فردایی» بود و
     * چون خودِ عنوان رشتهٔ «امروز» را در خود داشت، `substr_count` دو بار
     * می‌شمرد و تست دربارهٔ چیزی شکست می‌خورد که سالم بود.
     */
    public function test_each_day_gets_its_own_heading(): void
    {
        $this->reminder('کارِ الف', 0);
        $this->reminder('کارِ ب', 1);

        $this->artisan('calendar:remind')->assertOk();

        $body = implode("\n", $this->sent[0]['rows']);

        $this->assertSame(1, substr_count($body, 'امروز'));
        $this->assertSame(1, substr_count($body, 'فردا'));
        // گروه‌ها به ترتیبِ زمانی‌اند: امروز پیش از فردا
        $this->assertLessThan(strpos($body, 'فردا'), strpos($body, 'امروز'));
    }

    /**
     * مبلغ در خطِ خودش می‌آید — در بله رنگ و ستون نداریم، پس عددی که تصمیمِ
     * صبح را عوض می‌کند باید در متن باشد.
     */
    public function test_an_amount_is_shown_on_the_line(): void
    {
        CalendarEvent::create([
            'type' => 'payment_due', 'title' => 'اجارهٔ دفتر', 'status' => 'pending',
            'event_date' => Carbon::now($this->tz())->toDateString(),
            'amount' => 50000000, 'currency_code' => 'IRT',
        ]);

        $this->artisan('calendar:remind')->assertOk();

        $body = implode("\n", $this->sent[0]['rows']);

        $this->assertStringContainsString('اجارهٔ دفتر', $body);
        // مبلغ با همان قالبِ بقیهٔ پنل، نه عددِ خام
        $this->assertStringContainsString(invoice_money(50000000, 'IRT'), $body);
    }

    /**
     * لینکِ پنل در پیام نمی‌آید — تصمیمِ صریحِ کارفرما.
     *
     * پیام روی گوشی خوانده می‌شود و آدرسِ پنل آن لحظه به کار نمی‌آید؛ فقط یک
     * خطِ اضافه در پیامی است که باید در یک نگاه خوانده شود.
     *
     * ⚠️ این تست هست چون `AdminNotifier::event()` پارامترِ `$url` دارد و
     * پرکردنش خیلی طبیعی به‌نظر می‌رسد — بی‌این نگهبان، اولین کسی که این فرمان
     * را دست بزند لینک را برمی‌گرداند.
     */
    public function test_no_panel_link_is_attached(): void
    {
        $this->reminder('یک کار', 0);

        $this->artisan('calendar:remind')->assertOk();

        $this->assertNull($this->sent[0]['url'], 'لینکِ پنل نباید فرستاده شود');

        $body = implode("\n", $this->sent[0]['rows']);
        $this->assertStringNotContainsString('/admin/calendar', $body);
        // و ته پیام نباید فاصلهٔ بی‌دلیل داشته باشد
        $this->assertSame(rtrim($body), $body, 'ته پیام نباید خطِ خالی داشته باشد');
    }

    /**
     * 🔴 لایه‌ای که پیش‌فرض خاموش است، در پیامِ بله هم نمی‌آید.
     *
     * «انتشار محتوا» از تقویم برداشته شد چون ۹۷٪ رویدادها را می‌ساخت. اگر
     * پیامِ بله همان را می‌فرستاد، همان شلوغی از درِ دیگر برمی‌گشت — و این‌بار
     * در جایی که حتی نمی‌شود خاموشش کرد.
     */
    public function test_a_default_off_layer_is_not_included_in_the_digest(): void
    {
        $today = Carbon::now($this->tz())->toDateString();

        CalendarEvent::create([
            'type' => 'social_post', 'title' => 'مقالهٔ زمان‌بندی‌شده',
            'event_date' => $today, 'status' => 'pending',
        ]);
        CalendarEvent::create([
            'type' => 'task', 'title' => 'کارِ واقعی',
            'event_date' => $today, 'status' => 'pending',
        ]);

        $this->artisan('calendar:remind')->assertOk();

        $body = implode("\n", $this->sent[0]['rows']);
        $this->assertStringContainsString('کارِ واقعی', $body);
        $this->assertStringNotContainsString('مقالهٔ زمان‌بندی‌شده', $body);
    }

    /**
     * ⚠️ ترجیحِ **کاربر** روی پیام اثر ندارد و نباید داشته باشد.
     *
     * کرون کاربری ندارد؛ و اگر ترجیح خوانده می‌شد، یک نفر با خاموش‌کردنِ یک
     * چیپ یادآوریِ کلِ تیم را قطع می‌کرد. پیش‌فرضِ config تصمیمِ سازمانی است،
     * چیپ تصمیمِ شخصی.
     */
    public function test_a_users_chip_preference_does_not_silence_the_digest(): void
    {
        $user = User::create([
            'name' => 'مدیر', 'email' => 'r'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);

        CalendarLayerPreference::store($user->id, ['task' => false]);
        $this->reminder('کارِ مهم', 0);

        $this->artisan('calendar:remind')->assertOk();

        $this->assertStringContainsString('کارِ مهم', implode("\n", $this->sent[0]['rows']));
    }

    /** رویدادِ بی‌مبلغ نباید جداکنندهٔ خالی بگیرد */
    public function test_an_event_without_an_amount_has_no_trailing_separator(): void
    {
        $this->reminder('کارِ بی‌پول', 0);

        $this->artisan('calendar:remind')->assertOk();

        $body = implode("\n", $this->sent[0]['rows']);
        $line = collect(explode("\n", $body))->first(fn ($l) => str_contains($l, 'کارِ بی‌پول'));

        $this->assertStringEndsWith('یادآوری و کار', trim((string) $line));
    }

    /** رویدادِ انجام‌شده کاری برای انجام ندارد و نباید یادآوری شود */
    public function test_a_done_reminder_is_left_out(): void
    {
        $this->reminder('کارِ تمام‌شده', 0, 'done');

        $this->artisan('calendar:remind')->assertOk();

        $this->assertSame([], $this->sent);
    }

    /** بیرونِ پنجره نباید بیاید — پیامِ بلند خوانده نمی‌شود */
    public function test_events_beyond_the_window_are_not_included(): void
    {
        $this->reminder('خیلی دور', 20);

        $this->artisan('calendar:remind')->assertOk();

        $this->assertSame([], $this->sent);
    }

    /**
     * 🔴 همان فهرست دو بار در روز فرستاده نمی‌شود.
     *
     * کرون روزانه است، ولی اجرای دستی یا یک retry نباید پیامِ تکراری بسازد.
     */
    public function test_the_same_digest_is_not_sent_twice_in_a_day(): void
    {
        $this->reminder('یک کار', 0);

        $this->artisan('calendar:remind')->assertOk();
        $this->artisan('calendar:remind')->assertOk();

        $this->assertCount(1, $this->sent);
    }

    /**
     * ⚠️ ولی موردِ **تازه** باید بگذرد.
     *
     * امضای گلوگاه شاملِ فهرستِ رویدادهاست، نه فقط تاریخ. بی‌آن، کاری که ظهر
     * اضافه می‌شد تا فردا هیچ یادآوری‌ای نمی‌گرفت.
     */
    public function test_a_newly_added_item_breaks_through_the_same_day(): void
    {
        $this->reminder('کارِ اول', 0);
        $this->artisan('calendar:remind')->assertOk();

        $this->reminder('کارِ دوم', 0);
        $this->artisan('calendar:remind')->assertOk();

        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('کارِ دوم', implode("\n", $this->sent[1]['rows']));
    }

    public function test_force_resends_the_same_digest(): void
    {
        $this->reminder('یک کار', 0);

        $this->artisan('calendar:remind')->assertOk();
        $this->artisan('calendar:remind --force')->assertOk();

        $this->assertCount(2, $this->sent);
    }
}
