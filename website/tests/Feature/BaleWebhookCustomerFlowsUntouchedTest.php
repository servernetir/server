<?php

namespace Tests\Feature;

use App\Models\BaleContact;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\Admin\AdminBaleRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 تورِ ایمنیِ کنسولِ مدیر — «هیچ اختلالی در کارِ مشتریان».
 *
 * ═══ چرا این فایل مهم‌تر از تستِ خودِ قابلیت است ═══
 *
 * رباتِ بله از قبل سه کارِ مشتری را انجام می‌دهد و هر سه **پول** یا **ورود**
 * هستند:
 *
 *   • `pre_checkout_query`  — تأییدِ پرداختِ کیفِ پول، با مهلتِ سختِ ۱۰ ثانیه
 *   • `successful_payment`  — تسویهٔ نهایی
 *   • اشتراکِ شماره         — تنها راهی که مشتری به بله وصل می‌شود (کدِ ورود)
 *
 * کنسولِ مدیر یک شاخهٔ تازه در همان وب‌هوک است. اگر جایش را عوض کنند، یا اگر
 * استثنایی از آن بیرون بزند، هیچ‌کدام از بالایی‌ها خطا نمی‌دهند — فقط **ساکت**
 * می‌شوند. پس این تست‌ها ترتیبِ شاخه‌ها را قفل می‌کنند، نه صرفاً رفتار را.
 *
 * ⚠️ هیچ‌کدام به `assertOk()` تکیه نمی‌کنند: این وب‌هوک روی **هر** مسیرِ خروج
 * ۲۰۰ می‌دهد، پس کدِ وضعیت این‌جا دقیقاً هیچ‌چیز اثبات نمی‌کند.
 */
class BaleWebhookCustomerFlowsUntouchedTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = 'bot-token-123';

    private const OWNER_CHAT = '700700';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bale.token', self::BOT);
        config()->set('services.bale_safir.key', null);
        config()->set('servernet.contact.email', '');

        // ⚠️ کارخانهٔ نو + `'*'` در **آخر**: یک استابِ همه‌گیرِ زودتر، هر استابِ
        // بعدی را بی‌اثر می‌کند و تست بی‌صدا هیچ‌چیز نمی‌سنجد.
        Http::swap(new Factory);
        Http::fake([
            '*sendMessage'           => Http::response(['ok' => true]),
            '*answerPreCheckoutQuery' => Http::response(['ok' => true]),
            '*'                      => Http::response(['ok' => true]),
        ]);
    }

    private function hookUrl(): string
    {
        return '/bale/webhook/'.substr(hash('sha256', self::BOT), 0, 32);
    }

    /** کنسول را روشن و به چتِ کارفرما متصل کن */
    private function bind(): User
    {
        $admin = User::create([
            'name' => 'کارفرما', 'email' => 'owner'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        Setting::putSecret(AdminBaleGate::KEY_BIND, json_encode([
            'chat_id' => self::OWNER_CHAT, 'user_id' => $admin->id, 'at' => now()->toIso8601String(),
        ]));
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');

        return $admin;
    }

    private function ticket(): Ticket
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 't'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        return $c->tickets()->create([
            'subject' => 'سرور بالا نمی‌آید', 'department' => 'technical',
            'priority' => 'high', 'status' => 'open',
            'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);
    }

    // ═══════════════ B0 — استثنا نباید وب‌هوک را بکشد ═══════════════

    /**
     * 🔴 خطرناک‌ترین رگرسیونی که این تغییر می‌توانست بسازد.
     *
     * دپلوی این پروژه فایل‌به‌فایل است، پس «یک فایل جا ماند» حالتِ واقعی است.
     * اگر ساختِ سرویس یا `matches()` استثنا بدهد و کسی نگیردش، وب‌هوک ۵۰۰
     * می‌دهد؛ بله دوباره می‌فرستد؛ و `pre_checkout_query` مهلتِ ۱۰ ثانیه‌اش را
     * از دست می‌دهد ⇒ پرداختِ مشتری بی‌صدا لغو می‌شود.
     */
    public function test_a_pre_checkout_query_is_still_answered_when_the_admin_console_explodes(): void
    {
        $this->app->bind(AdminBaleRouter::class, fn () => throw new \RuntimeException('boom'));

        $this->postJson($this->hookUrl(), [
            'pre_checkout_query' => ['id' => 'q1', 'invoice_payload' => 'nope', 'total_amount' => 10],
        ])->assertOk();

        $answered = false;

        Http::assertSent(function ($r) use (&$answered) {
            if (str_contains($r->url(), '/answerPreCheckoutQuery')) {
                $answered = true;
            }

            return true;
        });

        $this->assertTrue($answered, 'پاسخِ pre-checkout نرفت — پرداختِ مشتری در همان ۱۰ ثانیه می‌سوزد');
    }

    /** و همان استثنا نباید `/start` مشتریِ تازه را هم بخورد */
    public function test_a_new_customer_still_gets_the_contact_keyboard_when_the_console_explodes(): void
    {
        $this->app->bind(AdminBaleRouter::class, fn () => throw new \RuntimeException('boom'));

        $this->postJson($this->hookUrl(), [
            'message' => ['chat' => ['id' => 55501], 'from' => ['id' => 55501], 'text' => '/start'],
        ])->assertOk();

        $this->assertContactKeyboardWentTo('55501');
    }

    // ═══════════════ B1..B4 — ترتیبِ شاخه‌ها ═══════════════

    public function test_start_still_gets_the_contact_keyboard(): void
    {
        $this->bind();

        $this->postJson($this->hookUrl(), [
            'message' => ['chat' => ['id' => 55502], 'from' => ['id' => 55502], 'text' => '/start'],
        ]);

        $this->assertContactKeyboardWentTo('55502');
    }

    /**
     * `/start` از **چتِ متصل** حالا منوی مدیر می‌آورد، نه دکمهٔ اشتراکِ شماره.
     *
     * کارفرما: «وقتی شروع را می‌زنم دکمه‌ها بیایند، نخواهم بنویسم.»
     *
     * ⚠️ این تست عمداً نگه داشته شد و فقط ادعایش برعکس شد — چون نیمهٔ دیگرش
     * (تستِ بالا: `/start` هر چتِ دیگری هنوز دکمهٔ شماره می‌گیرد) تنها چیزی
     * است که مسیرِ ورودِ بلهٔ **مشتری** را زنده نگه می‌دارد.
     */
    public function test_start_from_the_bound_chat_opens_the_admin_menu(): void
    {
        $this->bind();

        $this->postJson($this->hookUrl(), [
            'message' => [
                'chat' => ['id' => self::OWNER_CHAT, 'type' => 'private'],
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
                'text' => '/start',
            ],
        ])->assertOk();

        $menu = false;

        Http::assertSent(function ($r) use (&$menu) {
            $d = $r->data();

            if (str_contains($r->url(), '/sendMessage')
                && isset($d['reply_markup']['inline_keyboard'])) {
                $menu = true;
            }

            return true;
        });

        $this->assertTrue($menu, 'منوی دکمه‌ایِ مدیر نیامد');
    }

    /**
     * ⚠️ و راهِ پیوندِ شمارهٔ خودِ کارفرما بسته نشد.
     *
     * بی‌این، مدیری که `/start` می‌زند دیگر هرگز نمی‌تواند شماره‌اش را به ربات
     * وصل کند — یعنی کدِ ورود و اعلان‌های حسابِ خودش در بله نمی‌آید.
     */
    public function test_the_owner_can_still_get_the_contact_keyboard_on_demand(): void
    {
        $this->bind();

        $this->postJson($this->hookUrl(), [
            'message' => [
                'chat' => ['id' => self::OWNER_CHAT, 'type' => 'private'],
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
                'text' => 'پیوند شماره',
            ],
        ])->assertOk();

        $this->assertContactKeyboardWentTo(self::OWNER_CHAT);
    }

    public function test_an_admin_verb_from_a_stranger_falls_through_to_the_contact_prompt(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->postJson($this->hookUrl(), [
            'message' => ['chat' => ['id' => 55503], 'from' => ['id' => 55503], 'text' => 'بستن '.$t->number],
        ]);

        $this->assertContactKeyboardWentTo('55503');
        $this->assertSame('open', $t->fresh()->status, 'غریبه توانست تیکت را ببندد');
    }

    /**
     * 🔴 کارفرما ممکن است با کیفِ بلهٔ **خودش** پول بدهد.
     *
     * آن آپدیت `from.id`ِ برابرِ چتِ متصل دارد، پس اگر شاخهٔ کنسول بالاتر از
     * `successful_payment` بنشیند، یک پرداختِ واقعی به‌عنوانِ «فرمان» خوانده
     * می‌شود و اصلاً تسویه نمی‌شود.
     */
    public function test_a_successful_payment_from_the_bound_chat_is_never_treated_as_a_command(): void
    {
        $this->bind();
        $this->ticket();

        $this->postJson($this->hookUrl(), [
            'message' => [
                'chat' => ['id' => self::OWNER_CHAT], 'from' => ['id' => self::OWNER_CHAT],
                'text' => 'کارها',
                'successful_payment' => ['invoice_payload' => 'unknown-ref', 'total_amount' => 100],
            ],
        ])->assertOk();

        $this->assertSame(0, TicketMessage::count(), 'آپدیتِ پرداخت به‌عنوانِ فرمان اجرا شد');
        $this->assertNoConsoleReply();
    }

    public function test_a_contact_share_from_the_bound_chat_still_links(): void
    {
        $this->bind();

        $this->postJson($this->hookUrl(), [
            'message' => [
                'chat' => ['id' => self::OWNER_CHAT], 'from' => ['id' => self::OWNER_CHAT],
                'text' => 'کارها',
                'contact' => ['phone_number' => '989121110000', 'first_name' => 'کارفرما'],
            ],
        ])->assertOk();

        $this->assertSame(1, BaleContact::where('mobile', '09121110000')->count(),
            'اشتراکِ شماره توسطِ شاخهٔ کنسول بلعیده شد — زنجیرهٔ ورودِ بله می‌شکند');
    }

    // ═══════════════ B5 — هزینهٔ مسیرِ مشتری ═══════════════

    /**
     * آپدیتِ پرداخت نباید هیچ پرس‌وجوی تازه‌ای به `settings` بزند.
     *
     * ⚠️ نه به‌خاطرِ سرعت: هر پرس‌وجوی اضافه روی مسیرِ پرداخت یعنی یک نقطهٔ
     * شکستِ تازه زیرِ همان مهلتِ ۱۰ ثانیه، و کشِ پروداکشن روی همان دیتابیسی
     * است که تا امروز چند بار قطعیِ گذرا داشته.
     */
    public function test_the_admin_gate_costs_no_settings_query_on_a_payment_update(): void
    {
        $this->bind();

        $hits = 0;
        DB::listen(function ($q) use (&$hits) {
            if (str_contains($q->sql, 'settings')) {
                $hits++;
            }
        });

        $this->postJson($this->hookUrl(), [
            'pre_checkout_query' => ['id' => 'q9', 'invoice_payload' => 'x', 'total_amount' => 1],
        ]);

        $this->assertSame(0, $hits, 'مسیرِ پرداخت به جدولِ تنظیمات دست زد');
    }

    // ───────────────────────────── کمکی ─────────────────────────────

    private function assertContactKeyboardWentTo(string $chatId): void
    {
        $seen = false;

        Http::assertSent(function ($r) use (&$seen, $chatId) {
            $d = $r->data();

            if (str_contains($r->url(), '/sendMessage')
                && (string) ($d['chat_id'] ?? '') === $chatId
                && ($d['reply_markup']['keyboard'][0][0]['request_contact'] ?? false) === true) {
                $seen = true;
            }

            return true;
        });

        $this->assertTrue($seen, 'دکمهٔ «اشتراکِ شماره» نرفت — جریانِ ورودِ بلهٔ مشتری شکسته است');
    }

    /**
     * ⚠️ عمداً `Http::recorded()` و نه `Http::assertSent()`.
     *
     * `assertSent` وقتی **هیچ** درخواستی ثبت نشده باشد خودش شکست می‌خورد — و
     * «هیچ درخواستی نرفت» دقیقاً یکی از حالت‌های درستِ این ادعاست. با
     * `assertSent` این تست به‌جای سنجیدنِ محتوا، وجودِ ترافیک را می‌سنجید.
     */
    private function assertNoConsoleReply(): void
    {
        foreach (Http::recorded() as [$request, ]) {
            if (str_contains($request->url(), '/sendMessage')) {
                $this->assertStringNotContainsString('کنسولِ مدیر', (string) ($request->data()['text'] ?? ''));
            }
        }

        $this->addToAssertionCount(1);
    }
}
