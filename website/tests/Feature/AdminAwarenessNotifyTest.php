<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Ticket;
use App\Services\Notify\AdminNotifier;
use App\Services\Notify\NotifyEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «مدیر باید در جریان باشد» — رویدادهایی که منتظرِ **اقدامِ او**اند.
 *
 * ═══ گزارشی که این فایل از آن آمد ═══
 *
 * کارفرما: «وقتی کاربر جوابِ تیکتی که من دادم رو دوباره جواب می‌ده، هیچ
 * اعلانی به من نمی‌آد.»
 *
 * دو علت داشت و هر دو لازم بودند:
 *   ۱) `Account\TicketController::reply()` **هیچ‌چیز** شلیک نمی‌کرد
 *   ۲) و رویدادِ موجودِ `ticket_reply` مخاطبش **مشتری** است — یعنی
 *      «کارمند جواب داد، به مشتری خبر بده»؛ جهتش برعکس بود.
 *
 * پیامدش از یک اعلانِ جاافتاده بزرگ‌تر بود: مکالمهٔ پشتیبانی **متوقف** می‌شد.
 * مشتری جواب می‌داد و منتظر می‌مانْد، مدیر خبر نداشت، و تیکت روزها باز
 * می‌مانْد بی‌آنکه کسی مقصر باشد.
 *
 * ⚠️ همین ممیزی یک جای دیگر را هم پیدا کرد: **رسیدِ واریز**. مشتری پول
 * می‌فرستاد و تا مدیر خودش `/admin/bank-transfers` را باز نمی‌کرد، هیچ‌کس
 * نمی‌فهمید. پولِ رسیده می‌توانست روزها منتظر بمانَد.
 */
class AdminAwarenessNotifyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * اعلان‌های مدیر را می‌گیرد بی‌آنکه بله/SMTP واقعی صدا شود.
     *
     * ⚠️ ظرف یک `ArrayObject` است نه آرایه: آرایهٔ PHP با مقدار پاس می‌شود و
     * جاسوس در نسخهٔ خودش می‌نوشت، پس تست همیشه صفر می‌دید — یعنی سبز‌شدنی که
     * هیچ‌چیز نمی‌سنجید.
     */
    private function spyOnAdminNotices(): \ArrayObject
    {
        $seen = new \ArrayObject;

        $this->app->instance(AdminNotifier::class, new class($seen) extends AdminNotifier
        {
            public function __construct(private \ArrayObject $box)
            {
                // عمداً parent::__construct صدا نمی‌شود: وابستگیِ بله لازم نیست
            }

            /*
            | ⚠️ امضا باید **دقیقاً** با والد بخوانَد وگرنه PHP خطای مرگبار
            | می‌دهد و کلِ فایلِ تست نصفه می‌مانَد.
            |
            | `$buttons` وقتی اضافه شد که اعلانِ «پاسخِ مشتری» دکمهٔ شیشه‌ای
            | گرفت. این جایگزین دکمه‌ها را هم ثبت می‌کند تا تست بتواند
            | بسنجدشان — نه اینکه بی‌صدا دورشان بریزد.
            */
            public function event(string $title, array $rows = [], ?string $url = null, string $emoji = '🔔', array $buttons = [], ?string $key = null): void
            {
                $this->box[] = ['title' => $title, 'rows' => $rows, 'url' => $url, 'buttons' => $buttons];
            }
        });

        return $seen;
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 't'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    // ═══════════════ ۱) پاسخِ مشتری در تیکت ═══════════════

    /** 🔴 خودِ گزارش: پاسخِ تازهٔ مشتری باید به مدیر برسد */
    public function test_a_customer_reply_notifies_the_admin(): void
    {
        $box = $this->spyOnAdminNotices();

        $c = $this->customer();

        $ticket = $c->tickets()->create([
            'subject' => 'سرورم بالا نمی‌آید', 'department' => 'technical',
            'priority' => 'high', 'status' => 'open',
            'last_reply_role' => 'staff', 'last_reply_at' => now(),
        ]);

        $this->actingAs($c, 'customer')
            ->post(route('account.ticket.reply', $ticket), ['body' => 'هنوز درست نشده'])
            ->assertRedirect();

        $this->assertCount(1, (array) $box, 'پاسخِ مشتری هیچ اعلانی به مدیر نساخت — همان گزارشِ کارفرما');

        $notice = $box[0];

        $this->assertStringContainsString('پاسخ', $notice['title']);

        /*
        | ⚠️ اعلانی که نگوید «کدام تیکت و کدام مشتری»، مدیر را مجبور می‌کند
        | پنل را باز کند تا بفهمد اصلاً مهم هست یا نه — و اعلانی که باید بازش
        | کنی، از هفتهٔ دوم خوانده نمی‌شود.
        */
        $flat = json_encode($notice, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString((string) $ticket->number, $flat, 'شمارهٔ تیکت در اعلان نیست');
        $this->assertStringContainsString('سرورم بالا نمی‌آید', $flat, 'موضوعِ تیکت در اعلان نیست');
        $this->assertStringContainsString($c->code, $flat, 'مشتری در اعلان نام برده نشده');
        $this->assertStringContainsString('/admin/tickets/'.$ticket->id, (string) $notice['url']);
    }

    /**
     * 🔴 نیمهٔ دومِ ادعا — و مهم‌تر از نیمهٔ اول:
     * **پاسخِ خودِ کارمند نباید به مدیر اعلان بفرستد.**
     *
     * بی‌این، ساده‌ترین «رفع» (بردنِ `ticket_reply` به `both`) هر پاسخِ مدیر را
     * هم به خودش اعلان می‌کرد. اعلانی که خودت ساخته‌ای نه‌تنها بی‌فایده است،
     * بلکه بقیهٔ اعلان‌ها را هم بی‌ارزش می‌کند — همان قاعدهٔ ثبت‌شدهٔ CLAUDE.md
     * دربارهٔ ۹۶ پیامِ تکراریِ روزانه.
     */
    public function test_a_staff_reply_never_notifies_the_admin_about_itself(): void
    {
        $box = $this->spyOnAdminNotices();

        $c = $this->customer();

        $ticket = $c->tickets()->create([
            'subject' => 'پرسشِ مالی', 'department' => 'billing',
            'priority' => 'normal', 'status' => 'open',
            'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);

        // پاسخِ کارمند از همان مسیری که پنلِ مدیریت می‌زند
        $ticket->addMessage('staff', null, 'پشتیبانی', 'در حال بررسی هستیم');

        $this->assertSame([], (array) $box,
            'پاسخِ کارمند به خودِ مدیر اعلان فرستاد — سیلِ اعلانِ بی‌فایده');
    }

    /** رویدادِ تازه در کاتالوگ **فقط** مخاطبِ مدیر داشته باشد */
    public function test_the_new_event_is_admin_only(): void
    {
        $this->assertTrue(NotifyEvent::has('ticket_customer_reply'));
        $this->assertTrue(NotifyEvent::notifiesAdmin('ticket_customer_reply'));
        $this->assertFalse(NotifyEvent::notifiesCustomer('ticket_customer_reply'),
            'مشتری هم خبر می‌گیرد — یعنی برای پاسخِ خودش پیام دریافت می‌کند');
    }

    // ═══════════════ ۲) رسیدِ واریز ═══════════════

    /**
     * 🔴 پولِ رسیده نباید بی‌صدا منتظر بمانَد.
     *
     * فاکتور تا تأییدِ **دستیِ** مدیر تسویه نمی‌شود و سرویس راه نمی‌افتد.
     */
    public function test_a_bank_receipt_notifies_the_admin(): void
    {
        $box = $this->spyOnAdminNotices();

        $c = $this->customer();

        $acc = PaymentAccount::create([
            'kind' => 'bank', 'currency_code' => 'IRT', 'label' => 'ملت — شعبهٔ مرکزی',
            'holder' => 'سرورنت', 'account_no' => '1234567890',
            'is_active' => true, 'sort' => 0,
        ]);

        $invoice = Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 1_000_000, 'tax' => 0, 'total' => 1_000_000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(), 'due_at' => now()->addDays(3),
        ]);

        // ⚠️ `payment_account_id` لازم است: بی‌آن کنترلر به شاخهٔ «حسابِ ریالیِ
        // تنظیمات» می‌رود که در تست پیکربندی نشده و پیش از ساختِ رسید برمی‌گردد.
        $this->actingAs($c, 'customer')
            ->post(route('account.invoice.bank', $invoice), [
                'reference' => 'REF-99001', 'payment_account_id' => $acc->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, (array) $box, 'رسیدِ واریز هیچ اعلانی به مدیر نساخت');

        $flat = json_encode($box[0], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('REF-99001', $flat, 'شناسهٔ پرداخت در اعلان نیست');
        $this->assertStringContainsString((string) $invoice->number, $flat);
        $this->assertStringContainsString('/admin/bank-transfers', (string) $box[0]['url']);
    }

    /**
     * ⚠️ ارسالِ دوبارهٔ فرم نباید اعلانِ دوم بسازد.
     *
     * رسیدِ تکراری ساخته نمی‌شود؛ اگر اعلان بیرونِ همان شرط می‌نشست، مشتری‌ای
     * که صفحه را رفرش کند برای مدیر چند پیامِ یکسان می‌ساخت.
     */
    public function test_a_duplicate_receipt_submission_does_not_notify_twice(): void
    {
        $box = $this->spyOnAdminNotices();

        $c = $this->customer();

        $acc = PaymentAccount::create([
            'kind' => 'bank', 'currency_code' => 'IRT', 'label' => 'ملت',
            'holder' => 'سرورنت', 'account_no' => '1234567890',
            'is_active' => true, 'sort' => 0,
        ]);

        $invoice = Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 500_000, 'tax' => 0, 'total' => 500_000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(), 'due_at' => now()->addDays(3),
        ]);

        foreach (['REF-1', 'REF-2'] as $ref) {
            $this->actingAs($c, 'customer')
                ->post(route('account.invoice.bank', $invoice), [
                    'reference' => $ref, 'payment_account_id' => $acc->id,
                ]);
        }

        $this->assertCount(1, (array) $box, 'ارسالِ دوباره اعلانِ تکراری ساخت');
    }
}
