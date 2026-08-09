<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
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

            public function event(string $title, array $rows = [], ?string $url = null, string $emoji = '🔔'): void
            {
                $this->test->record($title, $rows);
            }
        };

        $this->app->instance(AdminNotifier::class, $spy);
    }

    public function record(string $title, array $rows): void
    {
        $this->sent[] = ['title' => $title, 'rows' => $rows];
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
