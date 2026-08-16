<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Notify\Notifier;
use App\Services\Ticket\TicketReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رفتارِ کنسولِ مدیر — «همان کاری که پنل می‌کند، از داخلِ بله».
 *
 * ⚠️ ادعای مرکزیِ این فایل **برابری** است، نه صرفاً «کار می‌کند»: پاسخِ ربات
 * باید دقیقاً همان اثری را بگذارد که پاسخِ پنل. اگر روزی یکی از دو مسیر عوض
 * شود و دیگری نه، این تست‌ها می‌شکنند — که تنها راهِ جلوگیری از دو پیاده‌سازیِ
 * درزدار است.
 */
class BaleAdminBotTest extends TestCase
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

        Http::swap(new Factory);
        Http::fake([
            '*sendMessage' => Http::response(['ok' => true]),
            '*'            => Http::response(['ok' => true]),
        ]);
    }

    private function hookUrl(): string
    {
        return '/bale/webhook/'.substr(hash('sha256', self::BOT), 0, 32);
    }

    private function bind(): User
    {
        $u = User::create([
            'name' => 'کارفرما', 'email' => 'o'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        Setting::putSecret(AdminBaleGate::KEY_BIND, json_encode([
            'chat_id' => self::OWNER_CHAT, 'user_id' => $u->id, 'at' => now()->toIso8601String(),
        ]));
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');

        return $u;
    }

    private function customer(string $locale = 'fa'): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'c'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => $locale,
        ]);
    }

    private function ticket(?Customer $c = null, array $over = []): Ticket
    {
        return ($c ?? $this->customer())->tickets()->create(array_merge([
            'subject' => 'سرورم بالا نمی‌آید', 'department' => 'technical',
            'priority' => 'high', 'status' => 'open',
            'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ], $over));
    }

    private function say(string $text, array $extra = [], ?int $updateId = null): void
    {
        $this->postJson($this->hookUrl(), [
            'update_id' => $updateId ?? random_int(1, 10_000_000),
            'message' => array_merge([
                'chat' => ['id' => self::OWNER_CHAT, 'type' => 'private'],
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
                'text' => $text,
            ], $extra),
        ]);
    }

    private function outbox(): string
    {
        $out = '';

        foreach (Http::recorded() as [$req, ]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $out .= "\n".(string) ($req->data()['text'] ?? '');
            }
        }

        return $out;
    }

    private function code(): ?string
    {
        return preg_match('/تأیید (\d{6})/u', $this->outbox(), $m) === 1 ? $m[1] : null;
    }

    /**
     * جاسوسِ کلیدهای اعلان.
     *
     * ⚠️ ظرف یک `ArrayObject` است نه آرایه: آرایهٔ PHP با مقدار پاس می‌شود و
     * جاسوس در نسخهٔ خودش می‌نوشت، پس تست همیشه صفر می‌دید — سبزی که هیچ‌چیز
     * نمی‌سنجد.
     */
    private function spyOnNotifications(): \ArrayObject
    {
        $seen = new \ArrayObject;

        $this->app->instance(Notifier::class, new class($seen) extends Notifier
        {
            public function __construct(private \ArrayObject $box) {}

            public function fire(string $key, ?Customer $customer, array $vars, string $text,
                array $adminRows = [], ?string $url = null, string $emoji = '🔔'): void
            {
                $this->box[] = $key;
            }
        });

        return $seen;
    }

    // ═══════════════ برابری با پنل ═══════════════

    /**
     * 🔴 قفلِ ضدِ واگرایی: ربات و پنل باید دقیقاً یک اثر بگذارند.
     *
     * اگر روزی یکی از دو مسیر عوض شود و دیگری نه، همین‌جا قرمز می‌شود.
     */
    public function test_a_reply_from_bale_has_the_same_side_effects_as_the_panel(): void
    {
        $admin = $this->bind();

        $viaPanel = $this->ticket();
        $viaBot   = $this->ticket();

        $this->actingAs($admin, 'web')
            ->post('/admin/tickets/'.$viaPanel->id.'/reply', ['body' => 'سلامِ من', 'close' => 1]);

        $this->say('بستن '.$viaBot->number.' سلامِ من');

        $a = $viaPanel->fresh();
        $b = $viaBot->fresh();

        $this->assertSame($a->status, $b->status, 'وضعیتِ تیکت بینِ پنل و ربات فرق کرد');
        $this->assertSame($a->last_reply_role, $b->last_reply_role);
        $this->assertNotNull($b->closed_at, 'ربات تیکت را نبست');

        $ma = TicketMessage::where('ticket_id', $a->id)->first();
        $mb = TicketMessage::where('ticket_id', $b->id)->first();

        $this->assertSame('staff', $mb->author_role);
        $this->assertSame($ma->body, $mb->body);
        $this->assertFalse((bool) $mb->is_internal);
    }

    /**
     * 🔴 ترتیبِ سه اعلان معنادار است: مشتری اول باید بداند تیکت بسته شد، بعد
     * ازش نظر بخواهیم. برعکسش یعنی نظرسنجی برای کسی می‌رود که هنوز فکر می‌کند
     * تیکتش باز است.
     */
    public function test_reply_and_close_fire_the_three_events_in_the_documented_order(): void
    {
        $box = $this->spyOnNotifications();
        $this->bind();
        $t = $this->ticket();

        $this->say('بستن '.$t->number.' حل شد');

        $this->assertSame(['ticket_reply', 'ticket_closed', 'ticket_survey'], (array) $box);
    }

    /** بستنِ بدونِ متن: هیچ «پاسخی» گفته نشده، پس `ticket_reply` نباید برود */
    public function test_closing_without_a_reply_does_not_fire_ticket_reply(): void
    {
        $box = $this->spyOnNotifications();
        $this->bind();
        $t = $this->ticket();

        $this->say('بستن '.$t->number);

        $this->assertSame(['ticket_closed', 'ticket_survey'], (array) $box);
        $this->assertSame(0, TicketMessage::count(), 'بستنِ خالی یک پیامِ الکی ساخت');
        $this->assertSame('closed', $t->fresh()->status);
    }

    // ═══════════════ یادداشتِ داخلی ═══════════════

    public function test_an_internal_note_notifies_nobody_and_does_not_move_the_ticket(): void
    {
        $box = $this->spyOnNotifications();
        $this->bind();
        $t = $this->ticket();

        $before = $t->fresh();

        $this->say('یادداشت مشتری قبلاً هم همین را پرسیده بود');

        // بی‌ریپلای هیچ تیکتی مشخص نیست ⇒ نباید چیزی بنویسد
        $this->assertSame(0, TicketMessage::count());

        // حالا با ریپلای روی کارتِ همان تیکت
        $this->replyingTo('https://x/admin/tickets/'.$t->id, 'یادداشت بررسی شد');

        $this->assertSame([], (array) $box, 'یادداشتِ داخلی اعلان فرستاد');
        $this->assertSame(1, TicketMessage::where('is_internal', true)->count());

        $after = $t->fresh();
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->last_reply_role, $after->last_reply_role);
    }

    /** ⚠️ ترنسکریپتِ چت قابلِ فوروارد است — یادداشتِ داخلی هرگز چاپ نمی‌شود */
    public function test_the_bot_never_prints_an_internal_note(): void
    {
        $this->bind();
        $t = $this->ticket();

        app(TicketReplyService::class)->post($t, null, 'پشتیبانی', 'رازِ داخلیِ ما', internal: true);

        $this->say('تیکت '.$t->number);

        $out = $this->outbox();

        $this->assertStringNotContainsString('رازِ داخلیِ ما', $out);
        $this->assertStringContainsString('یادداشتِ داخلی', $out, 'حتی شمارشش هم نیامد');
    }

    // ═══════════════ لنگرِ ریپلای و رقمِ فارسی ═══════════════

    /** هستهٔ ارگونومی: لینکِ `/admin/tickets/{id}` از قبل در هر اعلان هست */
    public function test_a_reply_to_an_alert_resolves_the_ticket_from_the_quoted_url(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->replyingTo('🎫 تیکتِ تازه'."\n".'https://console.servernet.cloud/admin/tickets/'.$t->id,
            'همین الان بررسی می‌کنم');

        // ⚠️ دیگر مرحلهٔ تأیید نیست: ریپلای همان لحظه می‌رود
        $this->assertSame(1, TicketMessage::count());
        $this->assertSame('answered', $t->fresh()->status);
        $this->assertStringContainsString('✅', $this->outbox(), 'ارسال گزارش نشد');
    }

    public function test_a_reply_to_a_non_ticket_alert_writes_nothing_and_says_which_ticket(): void
    {
        $this->bind();
        $this->ticket();

        $this->replyingTo('💳 پرداختِ موفق — ۱۲۰٬۰۰۰ تومان', 'دستت درد نکنه');

        $this->assertSame(0, TicketMessage::count());
        $this->assertStringContainsString('نمی‌شناسم', $this->outbox());
    }

    /**
     * 🔴 شماره‌ای که کارفرما کپی می‌کند از `fa_num()` رد شده، پس رقمش فارسی
     * است. بی‌تاشدنِ رقم، طبیعی‌ترین کارِ ممکن هرگز جواب نمی‌داد.
     */
    public function test_a_ticket_number_with_persian_digits_resolves(): void
    {
        $this->bind();
        $t = $this->ticket();
        $t->forceFill(['number' => 'TK-260815-0007'])->save();

        $this->say('تیکت TK-۲۶۰۸۱۵-۰۰۰۷');

        $this->assertStringContainsString('TK-260815-0007', $this->outbox());
    }

    // ═══════════════ صف ═══════════════

    /**
     * صف فقط `open` است، نه `isOpen()`.
     *
     * ⚠️ `isOpen()` یعنی `status !== 'closed'` و `answered` را هم می‌گیرد —
     * یعنی تیکتی که همین الان جوابش را داده‌ایم دوباره در صف می‌نشیند.
     */
    public function test_the_queue_lists_only_open_tickets_oldest_first(): void
    {
        $this->bind();

        $old = $this->ticket(null, ['subject' => 'قدیمی‌ترین', 'last_reply_at' => now()->subDays(3)]);
        $new = $this->ticket(null, ['subject' => 'تازه‌ترین', 'last_reply_at' => now()]);
        $this->ticket(null, ['subject' => 'پاسخ‌داده‌شده', 'status' => 'answered']);
        $this->ticket(null, ['subject' => 'بسته‌شده', 'status' => 'closed']);

        $this->say('کارها');

        $out = $this->outbox();

        $this->assertStringContainsString('قدیمی‌ترین', $out);
        $this->assertStringContainsString('تازه‌ترین', $out);
        $this->assertStringNotContainsString('پاسخ‌داده‌شده', $out);
        $this->assertStringNotContainsString('بسته‌شده', $out);

        $this->assertLessThan(mb_strpos($out, 'تازه‌ترین'), mb_strpos($out, 'قدیمی‌ترین'),
            'قدیمی‌ترینِ صف اول نیامد — کسی که بیشتر منتظر مانده باید اول دیده شود');
    }

    // ═══════════════ تکرار ═══════════════

    /** بله تحویلِ ناموفق را دوباره می‌فرستد */
    public function test_a_repeated_update_id_does_not_act_twice(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->say('پاسخ '.$t->number.' سلام');
        $code = $this->code();

        $this->say('تأیید '.$code, updateId: 555);
        $this->say('تأیید '.$code, updateId: 555);

        $this->assertSame(1, TicketMessage::count());
    }

    /**
     * 🔴 نیمهٔ دومِ همان ادعا، و مهم‌تر: **بدونِ** `update_id` هم نباید دو بار
     * اجرا شود.
     *
     * ⚠️ اگر فقط نیمهٔ اول را تست کنیم، آن تست به‌جای محافظ، **بادیگاردِ یک
     * فرض** می‌شود: فرضِ اینکه بله همیشه `update_id` می‌فرستد. اگر روزی
     * نفرستد، محافظِ واقعی باید یک‌بارمصرف‌بودنِ کدِ تأیید باشد — و همین تست
     * آن را جدا می‌سنجد.
     */
    public function test_dedupe_survives_a_missing_update_id(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->say('پاسخ '.$t->number.' سلام');
        $code = $this->code();

        foreach ([1, 2] as $_) {
            $this->postJson($this->hookUrl(), [
                'message' => [
                    'chat' => ['id' => self::OWNER_CHAT, 'type' => 'private'],
                    'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
                    'text' => 'تأیید '.$code,
                ],
            ]);
        }

        $this->assertSame(1, TicketMessage::count(), 'بی‌update_id، کار دو بار اجرا شد');
    }

    // ═══════════════ زبانِ مشتری ═══════════════

    /**
     * 🔴 این یک نقصِ **از قبل موجود** را هم می‌بندد و به پنل هم مربوط است.
     *
     * `config/app.php` مقدارِ `APP_LOCALE` را می‌خواند و روت‌های `/admin/*`
     * بیرونِ closureِ `$site`اند، پس هیچ middlewareِ `locale` رویشان نمی‌دود.
     * یعنی پاسخ به مشتریِ فارسی با رقمِ انگلیسی و لینکِ `/en/...` می‌رفت.
     */
    public function test_the_customer_notification_uses_the_customer_locale_not_the_app_locale(): void
    {
        config()->set('app.locale', 'en');

        $this->bind();
        $t = $this->ticket($this->customer('fa'));
        $t->forceFill(['number' => 'TK-260815-0042'])->save();

        $seen = new \ArrayObject;

        $this->app->instance(Notifier::class, new class($seen) extends Notifier
        {
            public function __construct(private \ArrayObject $box) {}

            public function fire(string $key, ?Customer $customer, array $vars, string $text,
                array $adminRows = [], ?string $url = null, string $emoji = '🔔'): void
            {
                $this->box[] = $text;
            }
        });

        $this->say('پاسخ '.$t->number.' بررسی شد');

        $body = (string) ($seen[0] ?? '');

        $this->assertStringContainsString('۰۰۴۲', $body,
            'شمارهٔ تیکت با رقمِ انگلیسی به مشتریِ فارسی رفت');
        $this->assertStringNotContainsString('/en/', $body,
            'لینکِ انگلیسی به مشتریِ فارسی رفت');
    }

    // ═══════════════ خرابیِ دیتابیس ═══════════════

    /**
     * ⚠️ از `ErrorTracker::clear()` استفاده می‌شود و نه پاک‌کردنِ دستیِ
     * `storage_path()`: هر پروسهٔ تست پوشهٔ خودش را دارد، پس مسیرِ دستی یک
     * no-opِ گمراه‌کننده است.
     */
    public function test_a_database_failure_never_500s_the_webhook_and_is_recorded(): void
    {
        $this->bind();
        \App\Support\ErrorTracker::clear();

        \Illuminate\Support\Facades\Schema::drop('tickets');

        $this->say('کارها');

        // ⚠️ خودِ ۲۰۰ چیزی ثابت نمی‌کند (این وب‌هوک همیشه ۲۰۰ می‌دهد)؛ ادعا
        // این است که خرابی **ثبت** شده، نه اینکه بی‌صدا بلعیده شده.
        $this->assertStringContainsString('bale-admin',
            json_encode(\App\Support\ErrorTracker::recent(50), JSON_UNESCAPED_UNICODE));
    }

    // ───────────────────────────── کمکی ─────────────────────────────

    private function replyingTo(string $quoted, string $text): void
    {
        $this->say($text, ['reply_to_message' => ['message_id' => 9, 'text' => $quoted]]);
    }
    // ═══════════════ دکمه‌های شیشه‌ای ═══════════════

    /**
     * فرمانِ آزمون باید پیامی با `inline_keyboard` بفرستد.
     *
     * ⚠️ ادعا روی **شکلِ بدنهٔ خروجی** است نه صرفاً «پیامی رفت»: اگر روزی
     * `reply_markup` جا بیفتد، کارفرما یک متنِ ساده می‌بیند و هیچ خطایی
     * هیچ‌جا نیست.
     */
    public function test_the_probe_command_sends_a_real_inline_keyboard(): void
    {
        $this->bind();

        $this->say('دکمه');

        $seen = null;

        foreach (Http::recorded() as [$req, ]) {
            $d = $req->data();

            if (str_contains($req->url(), '/sendMessage') && isset($d['reply_markup']['inline_keyboard'])) {
                $seen = $d['reply_markup']['inline_keyboard'];
            }
        }

        $this->assertNotNull($seen, 'هیچ دکمهٔ شیشه‌ای فرستاده نشد');
        $this->assertSame('v1:ping:1', $seen[0][0]['callback_data'] ?? null);
    }

    /**
     * 🔴 کلیکِ دکمه باید **هم** به خودِ کلیک جواب بدهد **هم** کارش را بکند.
     *
     * بی‌`answerCallbackQuery`، دکمه در کلاینتِ کارفرما تا ابد «در حالِ
     * بارگذاری» می‌مانَد و از «ربات هنگ کرده» قابلِ تشخیص نیست — حتی وقتی کار
     * درست انجام شده.
     */
    public function test_a_button_click_is_acknowledged_and_acted_on(): void
    {
        $this->bind();

        $this->postJson($this->hookUrl(), [
            'update_id' => 4242,
            'callback_query' => [
                'id'   => 'cbq-1',
                'data' => 'v1:ping:2',
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
            ],
        ])->assertOk();

        $answered = false;

        foreach (Http::recorded() as [$req, ]) {
            if (str_contains($req->url(), '/answerCallbackQuery')
                && ($req->data()['callback_query_id'] ?? '') === 'cbq-1') {
                $answered = true;
            }
        }

        $this->assertTrue($answered, 'به کلیک جواب داده نشد — دکمه تا ابد در حالِ بارگذاری می‌مانَد');
        $this->assertStringContainsString('کار می‌کنند', $this->outbox());
    }

    /** کلیکِ یک چتِ غریبه هیچ‌کاری نمی‌کند */
    public function test_a_button_click_from_a_stranger_does_nothing(): void
    {
        $this->bind();

        $this->postJson($this->hookUrl(), [
            'callback_query' => [
                'id' => 'cbq-2', 'data' => 'v1:ping:1',
                'from' => ['id' => '55599', 'is_bot' => false],
            ],
        ])->assertOk();

        $this->assertStringNotContainsString('کار می‌کنند', $this->outbox());
    }

    /**
     * ⚠️ آپدیتِ کلیک نباید مسیرِ مشتری را لمس کند — نه دکمهٔ اشتراکِ شماره
     * بفرستد نه چیزی بنویسد.
     */
    public function test_a_callback_update_never_falls_through_to_the_contact_prompt(): void
    {
        $this->bind();

        $this->postJson($this->hookUrl(), [
            'callback_query' => [
                'id' => 'cbq-3', 'data' => 'v1:ping:1',
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
            ],
        ]);

        foreach (Http::recorded() as [$req, ]) {
            $this->assertArrayNotHasKey('keyboard',
                (array) ($req->data()['reply_markup'] ?? []),
                'کلیکِ دکمه به دکمهٔ اشتراکِ شمارهٔ مشتری افتاد');
        }
    }
    // ═══════════════ فاز ۲: صفحه‌های خواندنی ═══════════════

    private function buttonsSent(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, ]) {
            $d = $req->data();

            foreach (($d['reply_markup']['inline_keyboard'] ?? []) as $row) {
                foreach ($row as $b) {
                    $out[] = (string) ($b['callback_data'] ?? '');
                }
            }
        }

        return $out;
    }

    private function click(string $data): void
    {
        $this->postJson($this->hookUrl(), [
            'update_id' => random_int(1, 10_000_000),
            'callback_query' => [
                'id' => 'cb'.random_int(1, 9999), 'data' => $data,
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
            ],
        ]);
    }

    /** 🔴 هیچ صفحه‌ای نباید بن‌بست باشد — همیشه راهِ برگشت هست */
    public function test_every_read_screen_offers_a_way_home(): void
    {
        $this->bind();
        $c = $this->customer();

        foreach (['v1:cm', 'v1:cl', 'v1:c:'.$c->id, 'v1:rl', 'v1:dl', 'v1:sq'] as $verb) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['ok' => true])]);

            $this->click($verb);

            $this->assertContains('v1:x', $this->buttonsSent(),
                $verb.' بن‌بست است — دکمهٔ منو ندارد');
        }
    }

    /** پروندهٔ مشتری هرگز ایمیل و موبایل چاپ نمی‌کند */
    public function test_the_customer_card_never_prints_contact_details(): void
    {
        $this->bind();
        $c = $this->customer();

        $this->click('v1:c:'.$c->id);

        $out = $this->outbox();

        $this->assertStringNotContainsString((string) $c->email, $out);
        $this->assertStringNotContainsString((string) $c->phone, $out);
        $this->assertStringContainsString((string) $c->code, $out);
    }

    /** جستجو با کدِ SN مشتری را پیدا می‌کند */
    public function test_search_finds_a_customer_by_code(): void
    {
        $this->bind();
        $c = $this->customer();

        $this->click('v1:cf');
        $this->say((string) $c->code);

        $this->assertContains('v1:c:'.$c->id, $this->buttonsSent(),
            'نتیجهٔ جستجو دکمهٔ پروندهٔ مشتری را نداد');
    }

    /**
     * 🔴 خطرناک‌ترین حالتِ فاز ۲، که منتقد پیدایش کرد.
     *
     * کارفرما «جستجو» را می‌زند، بعد روی یک کارتِ تیکتِ قدیمی سوایپ می‌کند —
     * روی گوشی ریپلای کارِ کاملاً طبیعی است — و نامِ مشتری را می‌نویسد.
     *
     * بی‌این محافظ، آن متن به‌عنوانِ **پاسخ به مشتری** می‌رفت: پیامکِ پولی و
     * ایمیل و بلهٔ برگشت‌ناپذیر، با متنی که اصلاً پاسخ نبود.
     */
    public function test_a_search_flow_plus_a_reply_anchor_executes_neither(): void
    {
        $this->bind();
        $t = $this->ticket();

        $this->click('v1:cf');

        // ریپلای روی کارتِ تیکت، در حالی که جریانِ جستجو باز است
        $this->replyingTo('https://x/admin/tickets/'.$t->id, 'رضا محمدی');

        $this->assertSame(0, TicketMessage::count(),
            'متنِ جستجو به‌عنوانِ پاسخ برای مشتری رفت');
        $this->assertStringContainsString('هیچ‌کدام اجرا نشد', $this->outbox());
    }

    /** کارتِ رسید باید هر دو عدد را نشان دهد — ادعای مشتری و بدهیِ فاکتور */
    public function test_the_receipt_card_shows_both_amounts(): void
    {
        $this->bind();
        $c = $this->customer();

        $inv = \App\Models\Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 500000, 'tax' => 0, 'total' => 500000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $r = \App\Models\BankTransferReceipt::create([
            'customer_id' => $c->id, 'invoice_id' => $inv->id,
            'amount' => 400000, 'reference' => 'REF-7', 'status' => 'pending',
        ]);

        $this->click('v1:r:'.$r->id);

        $out = $this->outbox();

        // ⚠️ جداکنندهٔ هزارگان را خودِ `number_format` می‌گذارد؛ ادعا باید از
        // همان تابع بیاید وگرنه تست سرِ یک کاراکترِ نامرئی قرمز می‌شود.
        $this->assertStringContainsString(fa_num(number_format(400000)), $out, 'مبلغِ ادعاشده نیامد');
        $this->assertStringContainsString(fa_num(number_format(500000)), $out, 'بدهیِ فاکتور نیامد');
        $this->assertStringContainsString('یکی نیستند', $out,
            'اختلافِ دو عدد هشدار نداد — تأیید بدهیِ فاکتور را تسویه می‌کند نه ادعا را');
    }

    /**
     * ⚠️ فاز ۲ فقط می‌خوانَد. هیچ دکمه‌ای نباید چیزی بنویسد.
     *
     * این تست کلِ فاز را قفل می‌کند: اگر روزی کسی دکمهٔ «تأیید رسید» را زودتر
     * از محافظش اضافه کند، همین‌جا قرمز می‌شود.
     */
    public function test_no_read_screen_writes_anything(): void
    {
        $this->bind();
        $c = $this->customer();
        $t = $this->ticket($c);

        $before = [
            'tickets'  => \App\Models\Ticket::sum('id'),
            'messages' => TicketMessage::count(),
            'invoices' => \App\Models\Invoice::count(),
        ];

        foreach (['v1:cm', 'v1:cl', 'v1:c:'.$c->id, 'v1:sl:'.$c->id, 'v1:il:'.$c->id,
                  'v1:rl', 'v1:dl', 'v1:sq', 'v1:t:'.$t->id] as $verb) {
            $this->click($verb);
        }

        $this->assertSame($before['messages'], TicketMessage::count());
        $this->assertSame($before['invoices'], \App\Models\Invoice::count());
        $this->assertSame('open', $t->fresh()->status, 'یک صفحهٔ خواندنی وضعیتِ تیکت را عوض کرد');
    }
    // ═══════════════ فاز ۳: کارهای برگشت‌پذیر ═══════════════

    private function service(?Customer $c = null, array $over = []): \App\Models\Service
    {
        return \App\Models\Service::create(array_merge([
            'customer_id' => ($c ?? $this->customer())->id,
            'name' => 'هاستِ لینوکسی', 'currency_code' => 'IRT', 'price' => 500000,
            'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'active',
            'activated_at' => now(), 'next_due_at' => now()->addMonth(),
        ], $over));
    }

    /** مهرِ تازه‌ای که کارت روی دکمه می‌گذارد */
    private function stamp(string $verb, int $id): string
    {
        return app(AdminBaleGate::class)->stamp($verb.':'.$id);
    }

    /**
     * 🔴 هستهٔ محافظِ فاز ۳.
     *
     * دکمه‌های بله در تاریخچهٔ چت **تا ابد** کلیک‌شدنی می‌مانند. یک «⏸ تعلیق»
     * که سه هفته پیش فرستاده شده، امروز با یک کلیکِ اتفاقی سرویسِ زندهٔ مشتری
     * را می‌خواباند و مشتری پیامکِ «سرویس شما غیرفعال شد» می‌گیرد.
     *
     * ⚠️ محافظ عمداً یک مرحلهٔ تأییدِ اضافه **نیست** — کارفرما آن را برداشت و
     * حق داشت. مهر در جریانِ عادی نامرئی است و فقط دکمهٔ کهنه را می‌گیرد.
     */
    public function test_a_stale_button_never_fires(): void
    {
        $this->bind();
        $s = $this->service();

        // مهرِ متعلق به ۲۴ ساعت پیش
        $old = $this->travelTo(now()->subDay(), fn () => $this->stamp('su', $s->id));

        $this->click('v1:su:'.$s->id.':'.$old);

        $this->assertSame('active', $s->fresh()->status, 'دکمهٔ کهنه سرویسِ زنده را معلق کرد');
        $this->assertStringContainsString('کهنه', $this->outbox());
    }

    /** و دکمهٔ جعلی هم رد می‌شود — مهر با APP_KEY امضا شده */
    public function test_a_forged_stamp_never_fires(): void
    {
        $this->bind();
        $s = $this->service();

        $this->click('v1:su:'.$s->id.':dead');

        $this->assertSame('active', $s->fresh()->status);
    }

    /** مهرِ تازه: کار انجام می‌شود و دکمهٔ برگشت همان‌جاست */
    public function test_a_fresh_suspend_works_and_offers_an_undo(): void
    {
        $this->bind();
        $s = $this->service();

        $this->click('v1:su:'.$s->id.':'.$this->stamp('su', $s->id));

        $this->assertSame('suspended', $s->fresh()->status);

        // ⚠️ کارِ برگشت‌پذیر باید برگشتش هم یک تپ باشد
        $undo = array_filter($this->buttonsSent(), fn ($d) => str_starts_with($d, 'v1:sr:'.$s->id));
        $this->assertNotEmpty($undo, 'دکمهٔ برگشت نیامد');
    }

    /** مهرِ یک فعل روی فعلِ دیگر کار نمی‌کند */
    public function test_a_stamp_is_bound_to_its_verb(): void
    {
        $this->bind();
        $s = $this->service();

        // مهرِ «رفعِ تعلیق» را روی «تعلیق» می‌زنیم
        $this->click('v1:su:'.$s->id.':'.$this->stamp('sr', $s->id));

        $this->assertSame('active', $s->fresh()->status, 'مهر بینِ دو فعل جابه‌جا پذیرفته شد');
    }

    /** ⚠️ و به شناسه هم بند است — مهرِ سرویسِ دیگری نباید این یکی را بخواباند */
    public function test_a_stamp_is_bound_to_its_row(): void
    {
        $this->bind();
        $a = $this->service();
        $b = $this->service();

        $this->click('v1:su:'.$a->id.':'.$this->stamp('su', $b->id));

        $this->assertSame('active', $a->fresh()->status, 'مهرِ یک ردیف روی ردیفِ دیگر کار کرد');
    }

    /** وضعیت و اولویتِ تیکت مهر نمی‌خواهد: هیچ پیامی به مشتری نمی‌رود */
    public function test_ticket_priority_changes_without_a_stamp_and_notifies_nobody(): void
    {
        $box = $this->spyOnNotifications();
        $this->bind();
        $t = $this->ticket();

        $this->click('v1:tps:'.$t->id.':p_urgent');

        $this->assertSame('urgent', $t->fresh()->priority);
        $this->assertSame([], (array) $box, 'تغییرِ اولویت به مشتری اعلان فرستاد');
    }

    /** صدورِ فاکتورِ تمدید از همان متدِ پنل می‌رود، نه نسخهٔ دوم */
    public function test_renewal_invoice_is_issued_from_the_bot(): void
    {
        $this->bind();
        $s = $this->service();

        $before = \App\Models\Invoice::count();

        $this->click('v1:sv:'.$s->id.':'.$this->stamp('sv', $s->id));

        $this->assertSame($before + 1, \App\Models\Invoice::count(), 'فاکتورِ تمدید صادر نشد');
        $this->assertSame('active', $s->fresh()->status, 'صدورِ فاکتور نباید وضعیتِ سرویس را عوض کند');
    }

    /**
     * ⚠️ تلاشِ دوبارهٔ تحویل فقط **پرچم را برمی‌گرداند** و خودش تماسی نمی‌گیرد.
     *
     * `createacct` تا ۱۸۰ ثانیه طول می‌کشد و مهلتِ وب‌هوکِ بله را رد می‌کند؛
     * آن‌وقت بله آپدیت را دوباره می‌فرستد و آن ترافیک در همان سطلِ throttleای
     * می‌نشیند که پرداختِ مشتری هم در آن است.
     */
    public function test_provision_retry_only_flips_the_flag_and_makes_no_http_call(): void
    {
        $this->bind();
        $s = $this->service(null, ['provision_status' => 'failed', 'status' => 'provision_failed']);

        $this->click('v1:sp:'.$s->id.':'.$this->stamp('sp', $s->id));

        $this->assertSame('pending', $s->fresh()->provision_status);

        foreach (Http::recorded() as [$req, ]) {
            $this->assertStringNotContainsString('createacct', $req->url(),
                'دکمهٔ تلاشِ دوباره خودش به سرور زنگ زد — مهلتِ وب‌هوک رد می‌شود');
        }
    }

    /** لغوِ فاکتور از همان تعریفِ مشترک می‌رود و فاکتورِ پرداخت‌شده را نمی‌گیرد */
    public function test_cancelling_a_paid_invoice_from_the_bot_is_refused(): void
    {
        $this->bind();
        $c = $this->customer();

        $paid = \App\Models\Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 100000, 'tax' => 0, 'total' => 100000, 'paid' => 100000,
            'status' => 'paid', 'issued_at' => now(), 'paid_at' => now(),
        ]);

        $this->click('v1:ic:'.$paid->id.':'.$this->stamp('ic', $paid->id));

        $this->assertSame('paid', $paid->fresh()->status, 'فاکتورِ پرداخت‌شده لغو شد');
    }
    /**
     * 🔴 خواستهٔ کارفرما: «وقتی روی دکمه‌ها کلیک کردم پاک بشه تا دچار اشتباه
     * نشیم.»
     *
     * و از مهرِ تازگی هم بهتر است، چون **دیدنی** است: دکمه‌ای که نیست، اشتباه
     * کلیک نمی‌شود. مهر لایهٔ دوم می‌مانَد برای کارتی که بله دیگر اجازهٔ
     * ویرایشش را نمی‌دهد (سقفِ ۴۸ ساعت).
     */
    public function test_a_write_button_is_removed_after_it_is_used(): void
    {
        $this->bind();
        $s = $this->service();

        $this->postJson($this->hookUrl(), [
            'update_id' => 991,
            'callback_query' => [
                'id' => 'cbx', 'data' => 'v1:su:'.$s->id.':'.$this->stamp('su', $s->id),
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
                'message' => ['message_id' => 4242, 'text' => 'کارتِ سرویس'],
            ],
        ])->assertOk();

        $edited = false;

        foreach (Http::recorded() as [$req, ]) {
            $d = $req->data();

            if (str_contains($req->url(), '/editMessageText')
                && (int) ($d['message_id'] ?? 0) === 4242) {
                $edited = true;

                $this->assertArrayNotHasKey('reply_markup', $d,
                    'ویرایش کیبورد را برنداشت — دکمه هنوز کلیک‌شدنی است');
                $this->assertStringContainsString('انجام شد', (string) ($d['text'] ?? ''));
            }
        }

        $this->assertTrue($edited, 'دکمه‌های کارت پس از کلیک برداشته نشدند');
        $this->assertSame('suspended', $s->fresh()->status);
    }

    /** ⚠️ ولی صفحهٔ **خواندنی** نباید دکمه‌هایش پاک شود — ناوبری می‌شکند */
    public function test_a_read_button_keeps_its_keyboard(): void
    {
        $this->bind();
        $c = $this->customer();

        $this->postJson($this->hookUrl(), [
            'callback_query' => [
                'id' => 'cby', 'data' => 'v1:c:'.$c->id,
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
                'message' => ['message_id' => 77, 'text' => 'فهرست'],
            ],
        ]);

        foreach (Http::recorded() as [$req, ]) {
            $this->assertStringNotContainsString('/editMessageText', $req->url(),
                'صفحهٔ خواندنی دکمه‌هایش را پاک کرد — ناوبری می‌شکند');
        }
    }
    // ═══════════════ فاز ۴: کارهای پولی ═══════════════

    private function receipt(array $over = []): \App\Models\BankTransferReceipt
    {
        $c = $this->customer();

        $inv = \App\Models\Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 500000, 'tax' => 0, 'total' => 500000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        return \App\Models\BankTransferReceipt::create(array_merge([
            'customer_id' => $c->id, 'invoice_id' => $inv->id,
            'amount' => 500000, 'reference' => 'R'.random_int(10000, 99999), 'status' => 'pending',
        ], $over));
    }

    private function work(): void
    {
        $this->artisan('bale:work')->assertSuccessful();
    }

    /**
     * 🔴 کارِ پولی **داخلِ وب‌هوک اجرا نمی‌شود** — فقط در صف می‌رود.
     *
     * تأییدِ رسید زنجیرهٔ `applyPaid` را راه می‌اندازد که ممکن است سرورِ واقعی
     * بخرد. اگر داخلِ وب‌هوک بماند، مهلتِ بله رد می‌شود، بله همان آپدیت را
     * دوباره می‌فرستد، و کارِ پولی **دو بار** انجام می‌شود.
     */
    public function test_a_money_action_is_queued_not_executed_in_the_webhook(): void
    {
        $this->bind();
        $r = $this->receipt();

        $this->click('v1:ray:'.$r->id.':'.$this->stamp('ray', $r->id));

        $this->assertSame('pending', $r->fresh()->status, 'رسید داخلِ وب‌هوک تأیید شد');
        $this->assertNotNull(app(AdminBaleGate::class)->pendingJob(), 'کار در صف نرفت');
        $this->assertStringContainsString('در صف', $this->outbox());
    }

    /** و کارگر همان کار را انجام می‌دهد و نتیجه را گزارش می‌کند */
    public function test_the_worker_executes_the_queued_approval_and_reports_back(): void
    {
        $this->bind();
        $r = $this->receipt();

        $this->click('v1:ray:'.$r->id.':'.$this->stamp('ray', $r->id));
        $this->work();

        $this->assertSame('approved', $r->fresh()->status);
        $this->assertStringContainsString('✅', $this->outbox());
        $this->assertNull(app(AdminBaleGate::class)->pendingJob(), 'کار از صف برداشته نشد');
    }

    /**
     * 🔴 دو کلیک نباید دو کار بسازد.
     *
     * روی گوشی، تپِ دوبل عادی است و بله هم آپدیتِ ناموفق را دوباره می‌فرستد.
     */
    public function test_a_double_click_never_queues_two_money_jobs(): void
    {
        $this->bind();
        $r = $this->receipt();
        $stamp = $this->stamp('ray', $r->id);

        $this->click('v1:ray:'.$r->id.':'.$stamp);
        $this->click('v1:ray:'.$r->id.':'.$stamp);

        $this->work();
        $this->work();

        $this->assertSame(1, \App\Models\Payment::where('invoice_id', $r->invoice_id)->count(),
            'دو پرداخت برای یک رسید ساخته شد');
    }

    /** دکمهٔ کهنه هیچ کارِ پولی‌ای در صف نمی‌گذارد */
    public function test_a_stale_money_button_queues_nothing(): void
    {
        $this->bind();
        $r = $this->receipt();

        $old = $this->travelTo(now()->subDay(), fn () => $this->stamp('ray', $r->id));

        $this->click('v1:ray:'.$r->id.':'.$old);

        $this->assertNull(app(AdminBaleGate::class)->pendingJob());
        $this->assertSame('pending', $r->fresh()->status);
    }

    /**
     * 🔴 دروغی که در کدِ پنل بود و در سرویسِ مشترک بسته شد.
     *
     * اگر فاکتور دیگر قابلِ پرداخت نباشد، تأیید **رد** می‌شود و رسید دست‌نخورده
     * می‌مانَد. نسخهٔ قبلی رسید را `approved` مهر می‌زد و پیامِ «فاکتور تسویه
     * شد» می‌داد — در حالی که پولِ واقعیِ رسیده به هیچ فاکتوری ننشسته بود.
     */
    public function test_approving_against_an_unpayable_invoice_is_refused_and_leaves_the_receipt_pending(): void
    {
        $this->bind();
        $r = $this->receipt();

        $r->invoice->forceFill(['status' => 'canceled'])->save();

        $this->click('v1:ray:'.$r->id.':'.$this->stamp('ray', $r->id));
        $this->work();

        $this->assertSame('pending', $r->fresh()->status,
            'رسید بسته شد در حالی که پول به هیچ فاکتوری ننشست');
        $this->assertStringContainsString('قابلِ پرداخت نیست', $this->outbox());
    }

    /** صفحهٔ تأیید باید نام مشتری و مبلغ را نشان دهد — تنها جای دیدنش روی گوشی */
    public function test_the_money_confirm_screen_names_the_customer_and_the_amount(): void
    {
        $this->bind();
        $r = $this->receipt();

        $this->click('v1:ra:'.$r->id);

        $out = $this->outbox();

        $this->assertStringContainsString((string) $r->customer->code, $out);
        $this->assertStringContainsString(fa_num(number_format(500000)), $out);
        $this->assertSame('pending', $r->fresh()->status, 'صفحهٔ تأیید خودش کار را انجام داد');
    }

    /** ردِ رسید دلیل می‌خواهد و مشتری همان متن را می‌بیند */
    public function test_rejecting_a_receipt_asks_for_a_reason_then_queues_it(): void
    {
        $this->bind();
        $r = $this->receipt();

        $this->click('v1:rj:'.$r->id);
        $this->say('شمارهٔ پیگیری با بانک نمی‌خواند');
        $this->work();

        $r->refresh();

        $this->assertSame('rejected', $r->status);
        $this->assertStringContainsString('نمی‌خواند', (string) $r->reject_reason);
    }

    /**
     * ⚠️ کارِ کهنه در صف نباید ساعت‌ها بعد اجرا شود.
     *
     * اگر کرون نمی‌دویده، تأییدِ رسیدی که کارفرما دیگر یادش نیست نباید ناگهان
     * انجام شود.
     */
    public function test_a_stale_queued_job_is_dropped_instead_of_run(): void
    {
        $this->bind();
        $r = $this->receipt();

        $this->click('v1:ray:'.$r->id.':'.$this->stamp('ray', $r->id));

        $this->travel(30)->minutes();
        $this->work();

        $this->assertSame('pending', $r->fresh()->status, 'کارِ کهنه اجرا شد');
    }

    /** خاتمهٔ سرویس هم از همان صف می‌رود و کارتش نام سرویس را تکرار می‌کند */
    public function test_terminate_asks_first_then_queues(): void
    {
        $this->bind();
        $s = $this->service();

        $this->click('v1:sx:'.$s->id);

        $this->assertStringContainsString($s->name, $this->outbox());
        $this->assertStringContainsString('برگشت ندارد', $this->outbox());
        $this->assertNotSame('terminated', $s->fresh()->status);

        $this->click('v1:sxy:'.$s->id.':'.$this->stamp('sxy', $s->id));

        $this->assertNotNull(app(AdminBaleGate::class)->pendingJob());
    }
}
