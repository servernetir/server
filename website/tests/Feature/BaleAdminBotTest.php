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
        $this->say('تأیید '.$this->code());

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
        $this->say('تأیید '.$this->code());

        $this->assertSame(['ticket_reply', 'ticket_closed', 'ticket_survey'], (array) $box);
    }

    /** بستنِ بدونِ متن: هیچ «پاسخی» گفته نشده، پس `ticket_reply` نباید برود */
    public function test_closing_without_a_reply_does_not_fire_ticket_reply(): void
    {
        $box = $this->spyOnNotifications();
        $this->bind();
        $t = $this->ticket();

        $this->say('بستن '.$t->number);
        $this->say('تأیید '.$this->code());

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

        $this->assertStringContainsString('تأیید', $this->outbox());

        $this->say('تأیید '.$this->code());

        $this->assertSame(1, TicketMessage::count());
        $this->assertSame('answered', $t->fresh()->status);
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
        $this->say('تأیید '.$this->code());

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
}
