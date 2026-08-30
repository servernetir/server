<?php

namespace Tests\Feature;

use App\Models\SmsOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 شمارندهٔ صفِ پیامک باید «الان» را بگوید، نه جمعِ همیشه.
 *
 * ═══ خرابی‌ای که این می‌بندد ═══
 *
 * `/system/sms-status` جمعِ کلِ تاریخِ جدول را می‌داد. آن عدد **خودم** را هم
 * گمراه کرد: «۲۹ منقضی» را نرخِ امروز خواندم و به کارفرما گزارش کردم بیش از
 * نیمی از پیام‌ها نمی‌رسد — در حالی که آن ردیف‌ها یادگارِ دورانی بودند که رله
 * خوابیده بود، و در همان لحظه `poller_alive` درست و `last_error` خالی بود.
 *
 * ⚠️ عددی که هرگز صفر نمی‌شود دو جور خراب است، و هر دو در این پروژه ثبت‌شده‌اند:
 * یا برای همیشه نگران‌کننده می‌مانَد و آن‌وقت نادیده گرفته می‌شود، یا یک خرابیِ
 * **تازه** را در انبوهِ اعدادِ قدیمی پنهان می‌کند.
 *
 * ⚠️ و دو مسیرِ متفاوت در یک عدد جمع می‌شدند: ردیف‌های `bale_only` اعلانِ بله
 * برای مشتری‌اند، نه پیامک. خرابیِ یکی در عددِ دیگری گم می‌شد.
 */
class SmsQueueCounterIsRecentTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $status, string $event, string $when): void
    {
        $at = \Illuminate\Support\Carbon::parse($when);

        $row = SmsOutbox::create([
            'destination' => '09120000000',
            'event'       => $event,
            'body'        => 'x',
            'status'      => $status,
            'expires_at'  => $at->copy()->addMinutes(10),
        ]);

        /*
        | ⚠️ `created_at` در `$fillable` نیست، پس `create()` نادیده‌اش می‌گیرد و
        | لاراول `now()` می‌نشانَد. نسخهٔ اولِ این فیکسچر همان اشتباه را کرد و
        | ردیفِ «یک‌ماهه» در واقع تازه بود — یعنی تست سناریو را اصلاً نمی‌ساخت
        | و قرمزی‌اش دربارهٔ چیزِ دیگری حرف می‌زد.
        */
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    private function queue(): array
    {
        return $this->get('/system/sms-status')->assertOk()->json('bridge.queue');
    }

    /** 🔴 ردیفِ کهنه نباید در عددِ زنده بیاید. */
    public function test_an_old_failure_no_longer_counts_as_a_live_one(): void
    {
        $this->row('expired', 'otp', now()->subMonth()->toDateTimeString());
        $this->row('expired', 'otp', now()->subDays(3)->toDateTimeString());

        $q = $this->queue();

        $this->assertSame('24h', $q['window']);
        $this->assertSame([], $q['sms'],
            'ردیفِ کهنه هنوز در پنجرهٔ زنده شمرده می‌شود — همان عددی که مرا گمراه کرد');
    }

    /** و ردیفِ تازه **باید** بیاید، وگرنه سنجه فقط ساکت شده نه درست. */
    public function test_a_fresh_failure_does_count(): void
    {
        $this->row('expired', 'otp', now()->subHours(2)->toDateTimeString());
        $this->row('sent', 'otp', now()->subHour()->toDateTimeString());

        $q = $this->queue();

        $this->assertSame(1, $q['sms']['expired'] ?? null);
        $this->assertSame(1, $q['sms']['sent'] ?? null);
    }

    /** ⚠️ اعلانِ بله از پیامک جدا شمرده شود — دو مسیرِ متفاوت‌اند. */
    public function test_bale_notifications_are_counted_apart_from_sms(): void
    {
        $this->row('expired', 'bale_only', now()->subHours(2)->toDateTimeString());
        $this->row('sent', 'otp', now()->subHours(2)->toDateTimeString());

        $q = $this->queue();

        $this->assertSame(1, $q['bale']['expired'] ?? null);
        $this->assertSame(1, $q['sms']['sent'] ?? null);
        $this->assertArrayNotHasKey('expired', $q['sms'],
            'خرابیِ اعلانِ بله در عددِ پیامک گم شد');
    }

    /**
     * تاریخچه می‌مانَد، ولی **جدا**.
     *
     * برای زمینه مفید است؛ فقط نباید جای سنجهٔ زنده را بگیرد.
     */
    public function test_the_all_time_total_is_still_available_but_separate(): void
    {
        $this->row('expired', 'otp', now()->subMonth()->toDateTimeString());

        $q = $this->queue();

        $this->assertSame([], $q['sms']);
        $this->assertSame(1, (int) ($q['total_all_time']['expired'] ?? 0),
            'تاریخچه هم باید در دسترس بماند');
    }

    /** ⚠️ و صفِ خالی باید واقعاً خالی گزارش شود، نه غایب. */
    public function test_an_empty_queue_reports_empty_not_missing(): void
    {
        $q = $this->queue();

        $this->assertSame('24h', $q['window']);
        $this->assertSame([], $q['sms']);
        $this->assertSame([], $q['bale']);
    }
}
