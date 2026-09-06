<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Support\CardRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🔴 شمارهٔ کاملِ کارت در متنِ تیکت نمی‌مانَد.
 *
 * ═══ رخداد (شهریور ۱۴۰۵) ═══
 * مشتری برای عودتِ وجه شمارهٔ کاملِ کارتش را در تیکت نوشت. پول برگشت، تیکت
 * بسته شد، و شماره ماند. هیچ خطایی هیچ‌جا نبود — چون هیچ چیزی خراب نشده بود.
 *
 * ⚠️ شماره‌های این فایل **ساختگی**‌اند و عمداً هیچ‌کدام کارتِ واقعی نیست:
 * گذاشتنِ شمارهٔ آن مشتری در تست یعنی همان نشت، این‌بار در تاریخچهٔ گیت و
 * روی GitHub — جایی که دیگر پاک‌کردنی نیست.
 */
class CardRedactionTest extends TestCase
{
    use RefreshDatabase;

    /** ساختگی: BINِ واقعیِ ایرانی + رقم‌های ساختگی، ولی لومن-معتبر */
    private const CARD = '6219861111111000';
    private const MASKED = '621986******1000';

    private function ticket(): Ticket
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 't'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        return Ticket::create([
            'customer_id' => $c->id, 'number' => 'T-'.random_int(1000, 9999),
            'subject' => 'عودت وجه', 'department' => 'billing',
            'priority' => 'normal', 'status' => 'open',
        ]);
    }

    /** همان چیزی که واقعاً رخ داد */
    public function test_a_card_number_never_reaches_the_database(): void
    {
        $t = $this->ticket();

        $t->addMessage('customer', null, 'مشتری',
            'لطفاً به کارت '.self::CARD.' واریز کنید. ممنون.');

        $stored = (string) DB::table('ticket_messages')->value('body');

        $this->assertStringNotContainsString(self::CARD, $stored,
            'شمارهٔ کاملِ کارت هنوز در دیتابیس ذخیره می‌شود.');
        $this->assertStringContainsString(self::MASKED, $stored);
        $this->assertStringContainsString('ممنون', $stored,
            'بقیهٔ متنِ مشتری باید دست‌نخورده بماند.');
    }

    /**
     * 🔴 مهم‌ترین ادعای این فایل: ماسک در لحظهٔ **ست‌کردن** می‌افتد، نه موقعِ
     * نوشتن در دیتابیس. یعنی هر کدی که از همین شیء متن برمی‌دارد — ایمیلِ
     * اعلان، پیامک، پیشِ‌نویسِ رباتِ بله — شمارهٔ کامل را به بیرون نمی‌برد.
     * پاک‌سازیِ سطحِ دیتابیس این را نمی‌داد.
     */
    public function test_the_in_memory_value_is_masked_too(): void
    {
        $m = $this->ticket()->addMessage('customer', null, 'م', 'کارت: '.self::CARD);

        $this->assertStringNotContainsString(self::CARD, (string) $m->body);
    }

    /**
     * محافظ روی **مدل** است نه روی `Ticket::addMessage()` — پس مسیرهای دیگرِ
     * نوشتن (`LicenseOrderTicket` امروز، هرچه فردا اضافه شود) هم پوشش دارند.
     */
    public function test_a_direct_create_is_covered_as_well(): void
    {
        $t = $this->ticket();

        TicketMessage::create([
            'ticket_id' => $t->id, 'author_role' => 'system',
            'author_name' => 'سیستم', 'body' => self::CARD, 'is_internal' => true,
        ]);

        $this->assertSame(self::MASKED, (string) DB::table('ticket_messages')->value('body'));
    }

    public function test_an_update_is_covered_as_well(): void
    {
        $m = $this->ticket()->addMessage('staff', null, 'پشتیبانی', 'سلام');

        $m->update(['body' => 'کارت '.self::CARD]);

        $this->assertStringNotContainsString(self::CARD, (string) DB::table('ticket_messages')->value('body'));
    }

    /** مشتریِ فارسی‌زبان شماره را با ارقامِ فارسی می‌نویسد */
    public function test_persian_digits_are_caught(): void
    {
        $fa = strtr(self::CARD, ['0' => '۰', '1' => '۱', '2' => '۲', '6' => '۶', '9' => '۹', '8' => '۸']);

        $this->assertSame(self::MASKED, CardRedactor::mask($fa));
    }

    /** کسی شماره را چهارتاچهارتا می‌نویسد */
    public function test_grouped_forms_are_caught(): void
    {
        foreach ([' ', '-', '‌'] as $sep) {
            $grouped = implode($sep, str_split(self::CARD, 4));

            $this->assertSame(self::MASKED, CardRedactor::mask($grouped),
                'قالبِ گروه‌بندی‌شده با جداکنندهٔ «'.$sep.'» رد شد.');
        }
    }

    /**
     * ⚠️ نیمهٔ دیگرِ کار. محافظی که هر عددِ ۱۶ رقمی را خراب کند، شمارهٔ پیگیریِ
     * بانکی و شناسهٔ سفارش را هم می‌بلعد — و آن هم یک خرابیِ خاموش است، چون
     * کسی متوجهِ عددِ عوض‌شده در تیکتِ کهنه نمی‌شود.
     */
    public function test_an_ordinary_sixteen_digit_number_is_left_alone(): void
    {
        $innocent = 'کدِ پیگیری: 1000000000000000';

        $this->assertSame($innocent, CardRedactor::mask($innocent));
    }

    /**
     * 🔴 عددِ بلندتر **اصلاً** نامزد نیست — نه اینکه پنجرهٔ ۱۶ رقمی‌اش را از
     * تویش دربیاوریم. الگویی که مستقیم دنبالِ ۱۶ رقم بگردد، از وسطِ یک شناسهٔ
     * ۲۰ رقمی شروع می‌کند و نصفه‌اش را ماسک می‌زند.
     */
    public function test_a_longer_digit_run_is_never_partially_masked(): void
    {
        $long = 'شناسه 1000000000000000123456 پایان';

        $this->assertSame($long, CardRedactor::mask($long));
    }

    /** مبلغ و تاریخ و شمارهٔ تیکت نباید دست بخورند */
    public function test_everyday_numbers_survive(): void
    {
        $text = 'فاکتور ۵۹۴٬۰۰۰ تومان، تاریخ 1405/06/07، تیکت T-2941، موبایل 09121234567.';

        $this->assertSame($text, CardRedactor::mask($text));
    }

    /**
     * موضوعِ تیکت را هم **مشتری** می‌نویسد.
     *
     * ⚠️ بهایش فقط دیتابیس نیست: `TicketDraftWriter` موضوع و متنِ تیکت را برای
     * ساختِ پیش‌نویس به ارائه‌دهندهٔ هوشِ مصنوعیِ بیرون از کشور می‌فرستد.
     */
    public function test_the_ticket_subject_is_masked_too(): void
    {
        $t = $this->ticket();
        $t->update(['subject' => 'عودت وجه به کارت '.self::CARD]);

        $this->assertStringNotContainsString(self::CARD, (string) DB::table('tickets')->value('subject'));
        $this->assertStringContainsString(self::MASKED, (string) DB::table('tickets')->value('subject'));
    }

    /** موضوعِ تیکت را هم مشتری می‌نویسد */
    public function test_the_sweeper_covers_old_rows_and_only_writes_with_force(): void
    {
        $t = $this->ticket();

        // دور زدنِ mutator تا ردیفِ «کهنه» بسازیم — دقیقاً همان چیزی که
        // امروز روی پروداکشن نشسته است.
        DB::table('ticket_messages')->insert([
            'ticket_id' => $t->id, 'author_role' => 'customer', 'author_name' => 'م',
            'body' => 'کارت من '.self::CARD, 'is_internal' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('security:redact-cards')->assertSuccessful();

        $this->assertStringContainsString(self::CARD, (string) DB::table('ticket_messages')->value('body'),
            'اجرای بدونِ --force نباید چیزی بنویسد.');

        $this->artisan('security:redact-cards --force')->assertSuccessful();

        $this->assertStringNotContainsString(self::CARD, (string) DB::table('ticket_messages')->value('body'));
    }
}
