<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * داشبوردِ مدیریت باید «آخرین اتفاقات» را نشان دهد.
 *
 * کارفرما خواست با یک نگاه بفهمد چه گذشته: آخرین پرداخت‌ها، سرویس‌های تازه، و
 * تیکت‌ها — با تاریخِ شمسی.
 *
 * 🔴 هیچ‌چیز کپی نمی‌شود؛ هر سه فهرست زنده از منبعِ خودشان خوانده می‌شوند.
 * جدولِ خلاصهٔ جدا روزی با واقعیت drift می‌کند و داشبورد چیزی نشان می‌دهد که
 * دیگر درست نیست.
 */
class AdminDashboardLatestFeedTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * ⚠️ نامِ نمایشی از **احرازِ هویت** می‌آید، نه از ستونِ `name`.
     *
     * `Customer::displayName()` اول `identityVerification` را می‌خواند و اگر
     * نبود بخشِ اولِ ایمیل را برمی‌گرداند. نسخهٔ اولِ این تست `name` را ست
     * می‌کرد و دنبالش می‌گشت — و شکست، در حالی که خودِ ویژگی سالم بود.
     *
     * ساختنِ `IdentityVerification` این‌جا ارزشش را ندارد: فیلدِ رمزنگاری‌شدهٔ
     * اجباری دارد و به چیزی که می‌سنجیم ربطی ندارد. ایمیلِ قابلِ پیش‌بینی
     * کافی است.
     */
    private function customer(string $handle = 'nemune'): Customer
    {
        return Customer::create([
            'code'     => 'SN-'.random_int(100000, 999999),
            'email'    => $handle.'@example.com',
            'password' => bcrypt('secret-pass-123'),
            'status'   => 'active',
        ]);
    }

    private function invoice(Customer $c, string $status = 'paid'): Invoice
    {
        return Invoice::create([
            'customer_id' => $c->id, 'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => 'IRT', 'subtotal' => 900000, 'tax' => 0,
            'total' => 900000, 'paid' => $status === 'paid' ? 900000 : 0,
            'status' => $status, 'issued_at' => now(), 'due_at' => now()->addDays(7),
        ]);
    }

    /**
     * فقط بدنهٔ یک پنلِ «آخرین …».
     *
     * ⚠️ ادعا باید به همان پنل محدود شود. نسخهٔ اول کلِ صفحه را می‌گشت و
     * شکست — چون همان مشتری در پنلِ «تازه‌ترین مشتریان» هم هست. یعنی تست
     * چیزی را متهم می‌کرد که جای درستِ خودش بود.
     */
    private function panel(string $html, string $heading): string
    {
        $start = mb_strpos($html, $heading);

        if ($start === false) {
            return '';
        }

        $rest = mb_substr($html, $start);
        $end = mb_strpos($rest, '<div class="ad-panel"', 10);

        return $end === false ? $rest : mb_substr($rest, 0, $end);
    }

    private function dashboard(): string
    {
        return $this->actingAs($this->admin())->get('/admin')->assertOk()->getContent();
    }

    /** 🔴 پرداختِ موفق با مشتری و مبلغ دیده می‌شود. */
    public function test_a_recent_payment_shows_up(): void
    {
        $c = $this->customer('alefba');

        Payment::create([
            'invoice_id' => $this->invoice($c)->id, 'customer_id' => $c->id,
            'currency_code' => 'IRT', 'gateway' => 'zarinpal', 'status' => 'paid',
            'amount' => 900000, 'paid_at' => now(),
        ]);

        $html = $this->dashboard();

        $this->assertStringContainsString('آخرین پرداخت‌ها', $html);
        $this->assertStringContainsString('alefba', $this->panel($html, 'آخرین پرداخت‌ها'),
            'مشتریِ پرداخت در پنلِ پرداخت‌ها نیامد');
        $this->assertStringNotContainsString('هنوز پرداختی ثبت نشده', $html);
    }

    /**
     * ⚠️ تلاشِ **ناموفق** نباید در فهرست بیاید.
     *
     * 🔴 مدیری که پرداختِ شکست‌خورده را در «آخرین پرداخت‌ها» ببیند، درآمدی
     * می‌بیند که وجود ندارد. بدترین نوعِ خطا در داشبوردِ مالی: عددی که
     * به‌نظر درست می‌آید.
     */
    public function test_a_failed_payment_never_appears(): void
    {
        $c = $this->customer('shekast');

        Payment::create([
            'invoice_id' => $this->invoice($c, 'unpaid')->id, 'customer_id' => $c->id,
            'currency_code' => 'IRT', 'gateway' => 'zarinpal', 'status' => 'failed',
            'amount' => 500000,
        ]);

        $payments = $this->panel($this->dashboard(), 'آخرین پرداخت‌ها');

        $this->assertStringNotContainsString('shekast', $payments,
            'پرداختِ ناموفق در فهرستِ پرداخت‌ها آمد');
    }

    /** سرویسِ تازه و تیکتِ تازه هم می‌آیند. */
    public function test_recent_services_and_tickets_show_up(): void
    {
        $c = $this->customer('kharidar');

        Service::create([
            'customer_id' => $c->id, 'name' => 'هاست لینوکس طلایی',
            'currency_code' => 'IRT', 'price' => 1200000, 'cycle' => 'monthly',
            'status' => 'active', 'next_due_at' => now()->addMonth(),
        ]);

        Ticket::create([
            'customer_id' => $c->id, 'number' => 'TK-'.random_int(1000, 9999),
            'subject' => 'مشکل در اتصال به سرور', 'department' => 'support',
            'priority' => 'normal', 'status' => 'open',
            'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);

        $html = $this->dashboard();

        $this->assertStringContainsString('هاست لینوکس طلایی', $html);
        $this->assertStringContainsString('مشکل در اتصال به سرور', $html);
        $this->assertStringContainsString('منتظر پاسخ', $html, 'برچسبِ «منتظر پاسخ» نیامد');
    }

    /**
     * ⚠️ «منتظر پاسخ» از `last_reply_role` می‌آید نه از وضعیت.
     *
     * تیکتِ بازی که آخرین پاسخش را **خودمان** داده‌ایم منتظرِ ما نیست؛ برچسب
     * زدنش یعنی مدیر کاری را که انجام داده دوباره در صف ببیند.
     */
    public function test_a_ticket_we_already_answered_is_not_flagged_as_waiting(): void
    {
        $c = $this->customer();

        Ticket::create([
            'customer_id' => $c->id, 'number' => 'TK-'.random_int(1000, 9999),
            'subject' => 'تیکتی که جواب داده‌ایم', 'department' => 'support',
            'priority' => 'normal', 'status' => 'open',
            'last_reply_role' => 'staff', 'last_reply_at' => now(),
        ]);

        $html = $this->dashboard();

        $this->assertStringContainsString('تیکتی که جواب داده‌ایم', $html);
        $this->assertStringNotContainsString('منتظر پاسخ', $html);
    }

    /** ⚠️ نصبِ خالی باید «هنوز چیزی نیست» بگوید، نه ردیفِ ساختگی. */
    public function test_an_empty_install_says_so_instead_of_inventing_rows(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('هنوز پرداختی ثبت نشده', $html);
        $this->assertStringContainsString('هنوز سرویسی ثبت نشده', $html);
    }
}
