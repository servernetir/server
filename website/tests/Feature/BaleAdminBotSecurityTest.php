<?php

namespace Tests\Feature;

use App\Models\BaleContact;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 «فقط من باید این دسترسی را داشته باشم» — همین یک جمله، به‌صورتِ تست.
 *
 * ═══ مدلِ تهدید، صریح ═══
 *
 * مسیرِ وب‌هوکِ بله `substr(sha256(BALE_BOT_TOKEN), 0, 32)` است و در لاگِ
 * دسترسیِ cPanel و Cloudflare و در `ErrorTracker` (که `fullUrl()` را ثبت
 * می‌کند) می‌نشیند. و `setWebhook`ِ بله — برخلافِ تلگرام — پارامترِ
 * `secret_token` **ندارد**، پس هیچ هدرِ امضاداری هم در کار نیست.
 *
 * پس فرضِ این فایل: **مهاجم آدرسِ وب‌هوک را دارد** و می‌تواند هر آپدیتِ دلخواهی
 * جعل کند، با هر `chat_id` و هر `from.id`. ادعا این است که با آن هم مدیر
 * نمی‌شود.
 */
class BaleAdminBotSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = 'bot-token-123';

    private const OWNER_CHAT = '700700';

    private const ATTACKER_CHAT = '13337';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bale.token', self::BOT);
        config()->set('services.bale_safir.key', 'safir-key-for-test');
        config()->set('services.bale_safir.bot_id', 2017652664);
        config()->set('servernet.contact.email', '');
        config()->set('servernet.contact.notify_phone', '09121110000');

        Http::swap(new Factory);
        Http::fake([
            '*safir*'      => Http::response(['message_id' => 'x']),
            '*sendMessage' => Http::response(['ok' => true]),
            '*'            => Http::response(['ok' => true]),
        ]);
    }

    private function hookUrl(): string
    {
        return '/bale/webhook/'.substr(hash('sha256', self::BOT), 0, 32);
    }

    private function admin(string $role = 'admin'): User
    {
        return User::create([
            'name' => 'کارفرما', 'email' => 'own'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('x'), 'role' => $role,
        ]);
    }

    private function bind(?User $user = null, string $chat = self::OWNER_CHAT): User
    {
        $user ??= $this->admin();

        Setting::putSecret(AdminBaleGate::KEY_BIND, json_encode([
            'chat_id' => $chat, 'user_id' => $user->id, 'at' => now()->toIso8601String(),
        ]));
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');

        return $user;
    }

    private function ticket(): Ticket
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'c'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        return $c->tickets()->create([
            'subject' => 'مشکلِ اتصال', 'department' => 'technical',
            'priority' => 'normal', 'status' => 'open',
            'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);
    }

    private function say(string $text, string $chat, array $extra = []): void
    {
        $this->postJson($this->hookUrl(), [
            'update_id' => random_int(1, 10_000_000),
            'message' => array_merge([
                'chat' => ['id' => $chat, 'type' => 'private'],
                'from' => ['id' => $chat, 'is_bot' => false],
                'text' => $text,
            ], $extra),
        ]);
    }

    /** متنِ همهٔ پیام‌هایی که به یک چت رفته */
    private function textsSentTo(string $chat): string
    {
        $out = '';

        foreach (Http::recorded() as [$req, ]) {
            $d = $req->data();

            if (str_contains($req->url(), '/sendMessage') && (string) ($d['chat_id'] ?? '') === $chat) {
                $out .= "\n".(string) ($d['text'] ?? '');
            }
        }

        return $out;
    }

    // ═══════════════ ۱) چتِ نامتصل هیچ‌کاری نمی‌تواند ═══════════════

    public function test_a_forged_update_from_an_unbound_chat_changes_nothing(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->say('پاسخ '.$t->number.' سلام', self::ATTACKER_CHAT);
        $this->say('بستن '.$t->number, self::ATTACKER_CHAT);

        $this->assertSame(0, TicketMessage::count());
        $this->assertSame('open', $t->fresh()->status);
        $this->assertStringNotContainsString('تأیید', $this->textsSentTo(self::ATTACKER_CHAT));
    }

    /**
     * 🔴 قلبِ استدلالِ «لو رفتنِ آدرسِ وب‌هوک کافی نیست» — نسخهٔ تازه.
     *
     * ═══ چه چیزی عوض شد و چرا ═══
     *
     * تا امروز هر پاسخِ دیده‌شدنیِ مشتری کدِ تأیید می‌خواست، و آن کد فقط به چتِ
     * متصل می‌رفت — پس دارندهٔ آدرسِ وب‌هوک نمی‌توانست پیامی به مشتری بفرستد.
     *
     * کارفرما آن کد را برداشت («وقتی خودم دکمهٔ ارسال را می‌زنم تأییدِ دوباره
     * لزومی ندارد») و حق داشت: اصطکاک روی گوشی یعنی برگشتن به پنل، یعنی مرگِ
     * خودِ قابلیت.
     *
     * ⚠️ ولی معامله باید **صریح** بماند: جعل دیگر ناممکن نیست، فقط **پرصدا**
     * است. هر ارسال بلافاصله به چتِ متصل گزارش می‌شود — با متنِ فرستاده‌شده —
     * پس پیامی که کارفرما نفرستاده روی گوشیِ خودش ظاهر می‌شود.
     *
     * این تست همان گزارش را قفل می‌کند. اگر روزی کسی «برای تمیزی» حذفش کند،
     * جعلِ خاموش برمی‌گردد.
     */
    public function test_every_send_is_reported_back_to_the_bound_chat(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->say('پاسخ '.$t->number.' متنِ آزمایشی', self::OWNER_CHAT);

        $owner = $this->textsSentTo(self::OWNER_CHAT);

        $this->assertStringContainsString('✅', $owner, 'ارسال گزارش نشد — جعل خاموش می‌مانَد');
        $this->assertStringContainsString($t->number, $owner);
        $this->assertStringContainsString('متنِ آزمایشی', $owner,
            'متنِ فرستاده‌شده در گزارش نیست — کارفرما نمی‌فهمد چه چیزی به مشتری رفته');
    }

    /** پاسخ همان لحظه می‌رود؛ دیگر مرحلهٔ دومی در کار نیست */
    public function test_a_reply_is_sent_immediately_without_a_confirm_step(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->say('پاسخ '.$t->number.' سرور را ری‌استارت کنید', self::OWNER_CHAT);

        $this->assertSame(1, TicketMessage::count(), 'پاسخ نرفت');
        $this->assertSame('answered', $t->fresh()->status);
    }

    /**
     * ⚠️ ماشینِ کدِ تأیید **حذف نشد** و این‌جا مستقیم سنجیده می‌شود.
     *
     * پاسخِ تیکت دیگر ازش رد نمی‌شود، ولی کارهای پولی و برگشت‌ناپذیرِ بعدی
     * لازمش دارند. تستِ بی‌فراخوان یعنی ماشینی که روزی بی‌صدا خراب می‌شود و
     * اولین باری که واقعاً لازم شد، کار نمی‌کند.
     */
    public function test_the_confirm_machinery_still_works_for_future_money_actions(): void
    {
        $gate = app(AdminBaleGate::class);

        $code = $gate->armConfirm('demo', ['x' => 1], 'کارِ آزمایشی');

        $this->assertSame(6, strlen($code), 'کدِ تأیید ۶ رقمی نیست');

        // کدِ خام هرگز در دیتابیس نمی‌نشیند
        $raw = (string) Setting::where('key', AdminBaleGate::KEY_STATE)->value('value');
        $this->assertStringNotContainsString($code, $raw);

        // سه اشتباه ⇒ کار لغو می‌شود
        foreach (['000001', '000002', '000003'] as $wrong) {
            $this->assertNull($gate->takeConfirm($wrong));
        }

        $this->assertNull($gate->takeConfirm($code), 'پس از ۳ اشتباه، کار هنوز زنده بود');

        // و در حالتِ عادی یک‌بارمصرف است
        $code2 = $gate->armConfirm('demo', ['x' => 2], 'کارِ دوم');
        $this->assertNotNull($gate->takeConfirm($code2));
        $this->assertNull($gate->takeConfirm($code2), 'کد دوباره مصرف شد');
    }

    // ═══════════════ ۳) کلید، نقش، و اتصالِ خوانده‌نشده ═══════════════

    public function test_the_kill_switch_stops_everything_on_the_next_message(): void
    {
        $this->bind();
        $t = $this->ticket();

        Setting::put(AdminBaleGate::KEY_ENABLED, '0');

        $this->say('تیکت '.$t->number, self::OWNER_CHAT);

        $this->assertStringNotContainsString('پروندهٔ', $this->textsSentTo(self::OWNER_CHAT));
    }

    /** کاربری که دیگر مدیر نیست، همان پیامِ بعدی را هم نمی‌تواند بزند */
    public function test_a_demoted_user_loses_the_bot(): void
    {
        $u = $this->bind();
        $t = $this->ticket();

        $u->forceFill(['role' => 'author'])->save();

        $this->say('پاسخ '.$t->number.' متن', self::OWNER_CHAT);

        $this->assertSame(0, TicketMessage::count());
        $this->assertStringNotContainsString('تأیید', $this->textsSentTo(self::OWNER_CHAT));
    }

    /**
     * 🔴 «اتصال رمزگشایی نشد» نباید مثلِ «هرگز متصل نشده» رفتار کند.
     *
     * `Setting::getSecret()` روی شکستِ رمزگشایی بی‌صدا `null` می‌دهد. اگر آن را
     * «هنوز متصل نشده» بخوانیم، چرخاندنِ `APP_KEY` یا یک بکاپِ قدیمی پنجرهٔ
     * `/pair` را دوباره برای کلِ اینترنت **باز** می‌کند — و کلیدِ روشن/خاموش که
     * رمزنگاری‌شده نیست، دست‌نخورده روی «روشن» می‌مانَد. یعنی خرابی باید
     * fail-closed باشد و به‌طورِ طبیعی fail-**open** بود.
     */
    public function test_an_unreadable_binding_closes_the_console_instead_of_reopening_pairing(): void
    {
        $this->bind();

        // ciphertext خراب — دقیقاً همان چیزی که چرخاندنِ APP_KEY می‌سازد
        Setting::put(AdminBaleGate::KEY_BIND, 'not-a-valid-ciphertext');

        $this->assertNull(app(AdminBaleGate::class)->binding());
        $this->assertSame('0', Setting::get(AdminBaleGate::KEY_ENABLED),
            'کنسول باز ماند — چرخاندنِ APP_KEY پنجرهٔ اتصال را به روی همه باز می‌کند');
    }

    // ═══════════════ ۴) هویت هرگز از bale_contacts نمی‌آید ═══════════════

    /**
     * 🔴 حفرهٔ ترفیعِ دسترسی که این تست جلویش را می‌گیرد.
     *
     * ردیفِ `bale_contacts` را خودِ همین وب‌هوکِ بی‌احراز می‌نویسد: `link()` یک
     * `updateOrCreate` روی `mobile` است، بی‌هیچ بررسیِ مالکیت. پس یک آپدیتِ
     * جعلیِ `contact` می‌تواند شمارهٔ پشتیبانی را به چتِ مهاجم ببندد. برای
     * **تحویلِ** پیام اشکالی ندارد؛ به‌عنوانِ منبعِ **هویت** یعنی یک POST تا
     * دسترسیِ کاملِ مدیر.
     */
    public function test_bale_contacts_can_never_grant_admin(): void
    {
        $this->admin();                        // مدیر هست، ولی هیچ اتصالی ثبت نشده
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');

        BaleContact::create([
            'mobile' => '09121110000',         // همان notify_phone
            'chat_id' => self::ATTACKER_CHAT,
            'linked_at' => now(),
        ]);

        $t = $this->ticket();
        $this->say('بستن '.$t->number, self::ATTACKER_CHAT);

        $this->assertSame('open', $t->fresh()->status);
        $this->assertSame(0, TicketMessage::count());
    }

    // ═══════════════ ۵) شکلِ آپدیت ═══════════════

    /** گروه/کانال: `chat.id` با `from.id` نمی‌خوانَد ⇒ کنسول خاموش است */
    public function test_a_group_update_is_refused(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->postJson($this->hookUrl(), [
            'message' => [
                'chat' => ['id' => '-1001234', 'type' => 'group'],
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
                'text' => 'بستن '.$t->number,
            ],
        ]);

        $this->assertSame('open', $t->fresh()->status);
    }

    /**
     * 🔴 پیامِ **فوروارد‌شده** فرمان نیست.
     *
     * در فوروارد `from` همان کسی است که فوروارد کرده (کارفرما) ولی متن را
     * شخصِ دیگری نوشته. کارِ کاملاً طبیعیِ «این را ببین مشتری چه نوشته»
     * می‌توانست متنِ ساختگیِ یک نفرِ دیگر را به‌عنوانِ فرمانِ کارفرما اجرا کند —
     * و «یادداشت» تأیید هم نمی‌خواهد.
     */
    public function test_a_forwarded_message_is_never_a_command(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->say('یادداشت متنِ کاشته‌شده', self::OWNER_CHAT, [
            'forward_date' => now()->getTimestamp(),
            'forward_from' => ['id' => 4242, 'is_bot' => false],
        ]);

        $this->assertSame(0, TicketMessage::count(), 'متنِ فوروارد‌شده به‌عنوانِ فرمان اجرا شد');
    }

    /** ربات‌ها فرمان نمی‌دهند */
    public function test_a_bot_sender_is_refused(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->postJson($this->hookUrl(), [
            'message' => [
                'chat' => ['id' => self::OWNER_CHAT, 'type' => 'private'],
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => true],
                'text' => 'بستن '.$t->number,
            ],
        ]);

        $this->assertSame('open', $t->fresh()->status);
    }

    // ═══════════════ ۶) رازها و کانالِ پولی ═══════════════

    /**
     * ⚠️ نیمهٔ منفی به‌تنهایی بی‌ارزش است — با یک `return` زودهنگام هم سبز
     * می‌شود. پس نیمهٔ **مثبت** هم هست: پیام واقعاً از API رباتِ خودمان رفت.
     */
    public function test_no_admin_message_ever_touches_the_paid_safir_channel(): void
    {
        $this->bind();
        $this->say('راهنما', self::OWNER_CHAT);

        $viaBot = false;

        foreach (Http::recorded() as [$req, ]) {
            $host = parse_url($req->url(), PHP_URL_HOST) ?: '';

            $this->assertStringNotContainsString('safir', $host,
                'پیامِ کنسولِ مدیر از کانالِ پولیِ سفیر رفت — سفیر فقط برای مشتریان است');

            if (str_contains($req->url(), '/sendMessage')) {
                $viaBot = true;
            }
        }

        $this->assertTrue($viaBot, 'اصلاً پیامی نرفت — نیمهٔ منفی بی‌معنی می‌شود');
    }

    /** پروندهٔ تیکت نباید ایمیل و موبایلِ مشتری را در چت بریزد */
    public function test_the_card_never_prints_customer_contact_details(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->say('تیکت '.$t->number, self::OWNER_CHAT);

        $out = $this->textsSentTo(self::OWNER_CHAT);

        $this->assertStringNotContainsString((string) $t->customer->email, $out);
        $this->assertStringNotContainsString((string) $t->customer->phone, $out);
        $this->assertStringContainsString((string) $t->customer->code, $out, 'کدِ عمومیِ مشتری باید باشد');
    }

    // ═══════════════ ۷) صفحهٔ پنل ═══════════════

    public function test_the_pairing_route_requires_an_admin_session(): void
    {
        $this->post('/admin/bale/pair')->assertRedirect();

        $author = $this->admin('author');

        $res = $this->actingAs($author, 'web')->post('/admin/bale/pair');

        $this->assertContains($res->status(), [403, 404, 302],
            'کاربرِ غیرمدیر توانست کدِ اتصال بسازد');

        $this->assertNull(app(AdminBaleGate::class)->binding());
    }

    // ═══════════════ ۸) خودِ اتصال ═══════════════

    /**
     * جریانِ کاملِ اتصال: پنل → ایمیل → بله.
     *
     * 🔴 کد **فقط** به ایمیل می‌رود و هرگز روی صفحه چاپ نمی‌شود. یعنی برای
     * تصاحبِ کنسول باید هم نشستِ پنل را داشت (که خودش رمز + کدِ دومرحله‌ای
     * می‌خواهد) هم صندوقِ ایمیل را — و دارندهٔ آدرسِ وب‌هوک هیچ‌کدام را ندارد.
     */
    public function test_pairing_needs_a_code_that_only_reaches_the_admin_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $admin = $this->admin();

        $res = $this->actingAs($admin, 'web')->post('/admin/bale/pair');
        $res->assertRedirect();

        $code = null;

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OtpMail::class, function ($mail) use (&$code, $admin) {
            $code = $mail->code;

            return $mail->hasTo($admin->email);
        });

        $this->assertNotNull($code);

        // کد نباید هیچ‌جای صفحه باشد
        $page = $this->actingAs($admin, 'web')->get('/admin/bale')->getContent();
        $this->assertStringNotContainsString((string) $code, $page, 'کدِ اتصال روی صفحه چاپ شد');

        // کدِ غلط از یک چتِ دلخواه هیچ اتصالی نمی‌سازد
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');
        $this->say('/pair 000000', self::ATTACKER_CHAT);
        $this->assertNull(app(AdminBaleGate::class)->binding());

        // کدِ درست، متصل می‌کند
        $this->say('/pair '.$code, self::OWNER_CHAT);

        $bind = app(AdminBaleGate::class)->binding();
        $this->assertNotNull($bind, 'اتصال با کدِ درست هم برقرار نشد');
        $this->assertSame(self::OWNER_CHAT, (string) $bind['chat_id']);
        $this->assertSame($admin->id, (int) $bind['user_id']);
    }

    /**
     * 🔴 بن‌بستی که کارفرما زنده گزارش داد: «کد /pair را زدم، ربات می‌گوید
     * شماره‌ات را اشتراک بگذار.»
     *
     * علت: کلیدِ روشن/خاموش پیش‌فرضش خاموش بود و `matches()` آن را **پیش از**
     * شاخهٔ اتصال می‌سنجید، در حالی که پنل روشن‌کردن را به «اول متصل شو» مشروط
     * می‌کرد. پس `/pair` هرگز به کنسول نمی‌رسید و به دکمهٔ اشتراکِ شماره
     * می‌افتاد — بی‌هیچ خطایی، در حالی که هر دو سرِ حلقه «درست» به‌نظر می‌رسیدند.
     *
     * این تست کلید را صریح روی خاموش می‌گذارد و ادعا می‌کند اتصال باز هم
     * برقرار می‌شود.
     */
    public function test_pairing_works_even_though_the_console_starts_switched_off(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $admin = $this->admin();
        $this->actingAs($admin, 'web')->post('/admin/bale/pair');

        $code = null;
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OtpMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });

        // 🔴 صریحاً خاموش — همان وضعیتی که کارفرما در آن گیر کرد
        Setting::put(AdminBaleGate::KEY_ENABLED, '0');

        $this->say('/pair '.$code, self::OWNER_CHAT);

        $gate = app(AdminBaleGate::class);

        $this->assertNotNull($gate->binding(), 'با کلیدِ خاموش، /pair به دکمهٔ اشتراکِ شماره افتاد');
        $this->assertTrue($gate->enabled(), 'اتصال برقرار شد ولی کنسول خاموش ماند — بن‌بستِ بعدی');

        // و بلافاصله باید فرمان بگیرد
        $this->say('راهنما', self::OWNER_CHAT);
        $this->assertStringContainsString('کنسولِ مدیر', $this->textsSentTo(self::OWNER_CHAT));
    }

    /**
     * ⚠️ نیمهٔ دومِ همان ادعا: بی‌«اتصالِ در انتظار»، هیچ چتی حق ندارد /pair بزند.
     *
     * وگرنه رفعِ بن‌بست بالا، پنجرهٔ اتصال را برای کلِ اینترنت باز می‌کرد.
     */
    public function test_pair_is_refused_when_no_pairing_was_started_from_the_panel(): void
    {
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');

        $this->say('/pair 123456', self::ATTACKER_CHAT);

        $this->assertNull(app(AdminBaleGate::class)->binding());
        $this->assertFalse(app(AdminBaleGate::class)->pairingPending());
    }

    /** و همان کد بارِ دوم کار نمی‌کند */
    public function test_a_pairing_code_is_single_use(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $admin = $this->admin();
        $this->actingAs($admin, 'web')->post('/admin/bale/pair');

        $code = null;
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OtpMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });

        Setting::put(AdminBaleGate::KEY_ENABLED, '1');
        $this->say('/pair '.$code, self::OWNER_CHAT);

        app(AdminBaleGate::class)->revoke();
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');

        $this->say('/pair '.$code, self::ATTACKER_CHAT);

        $this->assertNull(app(AdminBaleGate::class)->binding(),
            'کدِ اتصال دوباره مصرف شد — یعنی یک کدِ لو رفته برای همیشه معتبر است');
    }

    // ───────────────────────────── کمکی ─────────────────────────────

    /** کدِ ۶ رقمیِ تأیید را از متنِ پیامِ رفته بیرون بکش */
    private function grabCode(): ?string
    {
        if (preg_match('/تأیید (\d{6})/u', $this->textsSentTo(self::OWNER_CHAT), $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
