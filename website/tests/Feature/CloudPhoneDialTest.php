<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * شماره‌گیریِ **دلخواه** — پنل و بله.
 *
 * ═══ خواستهٔ کارفرما ═══
 *
 * «مثلاً بتوانم شمارهٔ یک غریبه را بزنم و باهاش تماس بگیرم؛ مشتریم نبود هم
 * بتوانم تماس داشته باشم.»
 *
 * ═══ 🔴 چرا این فایل بزرگ‌تر از خودِ قابلیت است ═══
 *
 * تا امروز مقصدِ هر تماس از **دیتابیس** می‌آمد، و کامنتِ `PhoneCallController`
 * دلیلش را نوشته بود: «اگر شماره را از فرم بگیریم، پنل تبدیل می‌شود به یک
 * تلفنِ رایگانِ بین‌المللی». حالا شماره از فرم می‌آید، پس آن نگرانی باید با
 * چیزِ دیگری بسته شود — و همان «چیزِ دیگر» است که این‌جا سنجیده می‌شود، نه
 * اینکه «دکمه کار می‌کند».
 */
class CloudPhoneDialTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = 'bot-token-123';

    private const OWNER_CHAT = '700700';

    private const RELAY = 'https://flow.servernet.cloud/webhook/cloud-phone-outgoing';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bale.token', self::BOT);
        config()->set('services.bale_safir.key', null);

        config()->set('services.cloud_phone.relay_url', self::RELAY);
        config()->set('services.cloud_phone.relay_secret', 'shared-secret-for-tests');
        config()->set('services.cloud_phone.extension', '71057757');
        config()->set('services.cloud_phone.agent_number', '09142223343');

        Http::swap(new Factory);
        Http::fake([
            self::RELAY => Http::response(['status' => 'sent'], 200),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    // ───────────────────────────── داربست ─────────────────────────────

    private function admin(): User
    {
        return User::create([
            'name' => 'کارفرما', 'email' => 'o'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function relayCalls(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req]) {
            if (str_contains($req->url(), 'cloud-phone-outgoing')) {
                $out[] = $req;
            }
        }

        return $out;
    }

    /** شمارهٔ مقصدی که واقعاً در پاکت نشست. */
    private function dialledNumber(): ?string
    {
        $sent = $this->relayCalls();

        if ($sent === []) {
            return null;
        }

        $b64 = explode('.', substr($sent[0]['envelope'], strlen('CLOUD_PHONE_V1:')), 2)[0];

        return json_decode(base64_decode(strtr($b64, '-_', '+/')), true)['to_number'] ?? null;
    }

    // ══════════════════════════════════════════════════════════════════
    // پنل
    // ══════════════════════════════════════════════════════════════════

    public function test_an_admin_can_dial_a_number_that_belongs_to_nobody(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/calls/dial', ['number' => '09121234567'])
            ->assertRedirect();

        $this->assertSame('9121234567', $this->dialledNumber(),
            'شمارهٔ تایپ‌شده باید عیناً به رله برود');
    }

    public function test_a_landline_with_an_area_code_works_too(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/calls/dial', ['number' => '۰۲۱۷۱۰۵۷۷۵۷'])   // ⚠️ ارقامِ فارسی
            ->assertRedirect();

        $this->assertSame('2171057757', $this->dialledNumber());
    }

    /**
     * 🔴 مهم‌ترین ادعای این فایل.
     *
     * کامنتِ قدیمیِ کنترلر می‌ترسید پنل «تلفنِ رایگانِ بین‌المللی» شود. حالا که
     * مقصد از فرم می‌آید، تنها چیزی که جلویش را می‌گیرد نگهبانِ نوعِ شماره
     * است — پس همان باید تست شود، نه صرفاً اینکه شماره‌گیری کار می‌کند.
     */
    public function test_an_international_number_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/calls/dial', ['number' => '+442071838750'])
            ->assertRedirect();

        $this->assertCount(0, $this->relayCalls(), 'شمارهٔ خارجی نباید گرفته شود');
    }

    public function test_a_number_without_an_area_code_is_refused(): void
    {
        // 🔴 «۳۴۲۶۱۰۰۰» سه شهر، سه آدمِ متفاوت — حدسِ پیش‌شماره یعنی زنگ به غریبه
        $this->actingAs($this->admin())
            ->post('/admin/calls/dial', ['number' => '34261000'])
            ->assertRedirect();

        $this->assertCount(0, $this->relayCalls());
    }

    public function test_a_writer_cannot_dial(): void
    {
        /*
        | 🔴 تماس پول خرج می‌کند و از خطِ شرکت می‌رود. همان تفکیکی که روتِ
        | «تماس با مشتری» دارد: دیدنِ گزارش برای نقشِ نویسنده باز است، برقراریِ
        | تماس نه.
        */
        $writer = User::create([
            'name' => 'نویسنده', 'email' => 'w'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'writer',
        ]);

        $this->actingAs($writer)->post('/admin/calls/dial', ['number' => '09121234567']);

        $this->assertCount(0, $this->relayCalls());
    }

    public function test_a_visitor_cannot_dial(): void
    {
        $this->post('/admin/calls/dial', ['number' => '09121234567']);

        $this->assertCount(0, $this->relayCalls());
    }

    /**
     * ⚠️ تماس با غریبه به هیچ پرونده‌ای نمی‌چسبد، پس بی‌لاگ تنها ردش
     * صورت‌حسابِ تأمین‌کننده است.
     */
    public function test_every_manual_dial_leaves_a_trace(): void
    {
        $this->actingAs($this->admin())->post('/admin/calls/dial', ['number' => '09121234567']);

        $this->assertDatabaseHas('activity_logs', ['action' => 'call']);

        $this->assertStringContainsString('9121234567',
            (string) \App\Models\ActivityLog::where('action', 'call')->value('description'),
            'لاگ باید بگوید به چه شماره‌ای زنگ زده شد');
    }

    // ══════════════════════════════════════════════════════════════════
    // بله
    // ══════════════════════════════════════════════════════════════════

    private function hookUrl(): string
    {
        return '/bale/webhook/'.substr(hash('sha256', self::BOT), 0, 32);
    }

    private function bind(): void
    {
        $u = $this->admin();

        Setting::putSecret(AdminBaleGate::KEY_BIND, json_encode([
            'chat_id' => self::OWNER_CHAT, 'user_id' => $u->id, 'at' => now()->toIso8601String(),
        ]));
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');
    }

    private function say(string $text, string $from = self::OWNER_CHAT): void
    {
        $this->postJson($this->hookUrl(), [
            'update_id' => random_int(1, 10_000_000),
            'message' => ['text' => $text, 'from' => ['id' => $from, 'is_bot' => false],
                'chat' => ['id' => $from]],
        ]);
    }

    private function click(string $data, string $from = self::OWNER_CHAT): void
    {
        $this->postJson($this->hookUrl(), [
            'update_id' => random_int(1, 10_000_000),
            'callback_query' => [
                'id' => 'cb'.random_int(1, 9999), 'data' => $data,
                'from' => ['id' => $from, 'is_bot' => false],
            ],
        ]);
    }

    private function outbox(): string
    {
        $out = '';

        foreach (Http::recorded() as [$req]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $out .= "\n".(string) ($req->data()['text'] ?? '');
            }
        }

        return $out;
    }

    private function dialButton(): ?string
    {
        foreach (Http::recorded() as [$req]) {
            foreach (($req->data()['reply_markup']['inline_keyboard'] ?? []) as $row) {
                foreach ($row as $b) {
                    if (str_starts_with((string) ($b['callback_data'] ?? ''), 'v1:dn:')) {
                        return (string) $b['callback_data'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * 🔴 مرکزی‌ترین ادعای بخشِ بله: **خودِ فرمان زنگ نمی‌زند.**
     *
     * در بله متن با یک تپ می‌رود و اصلاح‌شدنی نیست. اگر «تماس ۰۹۱۲…» مستقیم
     * شماره می‌گرفت، یک رقمِ جاافتاده یعنی زنگ‌زدن به یک غریبه با شمارهٔ شرکت
     * روی کالر آی‌دی — و راهی برای پس‌گرفتنش نیست.
     */
    public function test_the_command_only_asks_and_never_dials_on_its_own(): void
    {
        $this->bind();

        $this->say('تماس 09121234567');

        $this->assertCount(0, $this->relayCalls(), 'فرمان نباید خودش تماس بگیرد');
        $this->assertNotNull($this->dialButton(), 'باید دکمهٔ تأیید بسازد');
        // ⚠️ شماره روی خودِ دکمه — کارفرما پیش از تپ می‌بیند به چه کسی زنگ می‌زند
        $this->assertStringContainsString('9121234567', $this->outbox());
    }

    public function test_confirming_places_the_call_to_the_number_that_was_shown(): void
    {
        $this->bind();

        $this->say('/call 09121234567');
        $this->click((string) $this->dialButton());

        $this->assertSame('9121234567', $this->dialledNumber());
    }

    public function test_a_stale_dial_button_does_not_place_a_call(): void
    {
        // 🔴 دکمه‌ها در تاریخچهٔ چت می‌مانند؛ کلیکِ ماهِ پیش نباید امروز زنگ بزند
        $this->bind();

        $this->click('v1:dn:9121234567:notavalidstamp');

        $this->assertCount(0, $this->relayCalls());
        $this->assertStringContainsString('کهنه', $this->outbox());
    }

    public function test_an_unbound_chat_cannot_dial(): void
    {
        /*
        | 🔴 آدرسِ وب‌هوکِ بله در لاگِ سرور و Cloudflare می‌نشیند و چرخاندنی
        | نیست. اگر لو برود، دارنده‌اش نباید بتواند از خطِ شرکت زنگ بزند.
        */
        $this->bind();

        $this->say('تماس 09121234567', from: '999999');

        $this->assertCount(0, $this->relayCalls());
        $this->assertNull($this->dialButton());
    }

    public function test_an_undialable_number_is_refused_before_a_button_exists(): void
    {
        // ⚠️ اعتبارسنجی پیش از ساختِ دکمه: دکمه‌ای که کلیکش خطا بدهد، روی
        //    موبایل بدتر از نبودنِ دکمه است.
        $this->bind();

        $this->say('تماس 34261000');

        $this->assertNull($this->dialButton());
        $this->assertStringContainsString('پیش‌شماره', $this->outbox());
    }

    public function test_a_bare_command_explains_itself_instead_of_failing(): void
    {
        $this->bind();

        $this->say('تماس');

        $this->assertNull($this->dialButton());
        $this->assertStringContainsString('مثال', $this->outbox());
    }

    // ══════════════════════════════════════════════════════════════════
    // تاریخِ گفتاری
    // ══════════════════════════════════════════════════════════════════

    /**
     * خواستهٔ کارفرما: «به تاریخ شمسی بتوانم بگویم شما در روزِ فلان، تاریخِ
     * فلان تماس گرفته بودید.»
     *
     * ⚠️ مقدارِ **مرجع**، نه رفت‌وبرگشت. رفت‌وبرگشت اگر هر دو جهت همان خطا را
     * داشته باشند سبز می‌مانَد — همان درسِ ثبت‌شدهٔ `jalali_ymd` در CLAUDE.md.
     * ۱۹ اوت ۲۰۲۶ چهارشنبه است و ۲۸ مرداد ۱۴۰۵.
     */
    public function test_the_spoken_date_names_the_weekday_and_the_jalali_month(): void
    {
        app()->setLocale('fa');

        $this->assertSame('چهارشنبه ۲۸ مرداد ۱۴۰۵ · ۱۱:۵۸',
            sdate_full('2026-08-19T08:28:00Z'));
    }

    /**
     * 🔴 روزِ هفته باید **پس از** انتقال به وقتِ تهران گرفته شود.
     *
     * تماسِ ۲۱:۳۰ به‌وقتِ UTC، به‌وقتِ تهران بامدادِ **فردا**ست — یعنی هم روزِ
     * ماه عوض می‌شود هم نامِ روزِ هفته. اگر روزِ هفته را از لحظهٔ UTC بگیریم،
     * خروجی «چهارشنبه ۲۹ مرداد» می‌شود: تاریخِ فردا با نامِ روزِ دیروز، و
     * هیچ‌چیز در ظاهرش خراب به‌نظر نمی‌رسد.
     */
    public function test_the_weekday_follows_tehran_not_utc(): void
    {
        app()->setLocale('fa');

        $this->assertSame('پنج‌شنبه ۲۹ مرداد ۱۴۰۵ · ۰۱:۰۰',
            sdate_full('2026-08-19T21:30:00Z'));
    }

    /**
     * 🔴 پنلِ مدیریت به `APP_LOCALE` بند نباشد.
     *
     * روت‌های `/admin/*` بیرونِ closureِ `$site`اند و هیچ middlewareِ `locale`
     * رویشان نمی‌دود، پس `app()->getLocale()` آن‌جا هرچه در `.env` باشد همان
     * است — امروز `fa`، ولی هیچ‌چیز نگهش نمی‌دارد. اگر این تابع مثلِ `sdate()`
     * به زبان نگاه می‌کرد، یک تغییرِ `.env` کلِ تاریخ‌های تماس را بی‌هیچ خطایی
     * میلادی می‌کرد.
     */
    public function test_the_spoken_date_does_not_depend_on_the_site_locale(): void
    {
        app()->setLocale('en');

        $this->assertSame('چهارشنبه ۲۸ مرداد ۱۴۰۵ · ۱۱:۵۸',
            sdate_full('2026-08-19T08:28:00Z'));
    }

    public function test_a_missing_date_does_not_blow_up(): void
    {
        app()->setLocale('fa');

        $this->assertSame('—', sdate_full(null));
    }

    /** جدولِ تماس‌ها هم واقعاً از همان تابع استفاده می‌کند، نه فقط تابع سالم است. */
    public function test_the_call_table_actually_renders_the_spoken_date(): void
    {
        \App\Models\PhoneCall::create([
            'call_reference_id' => 'ref-'.random_int(1, 999999),
            'direction' => 'incoming',
            'caller_number' => '09121234567',
            'started_at' => '2026-08-19T08:28:00Z',
        ]);

        $html = $this->actingAs($this->admin())->get('/admin/calls')->assertOk()->getContent();

        $this->assertStringContainsString('چهارشنبه', $html);
        $this->assertStringContainsString('مرداد', $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // جای دکمه در پنل
    // ══════════════════════════════════════════════════════════════════

    /**
     * خواستهٔ کارفرما: دکمهٔ تماس کنارِ «ورود به پنل کاربری» و «ارسال اعلان»
     * باشد، نه داخلِ تبِ تماس‌ها.
     *
     * ⚠️ ادعا روی **ترتیبِ رندرشده** است، نه صرفاً وجودِ دکمه: وجودش قبلاً هم
     * درست بود، جایش غلط بود.
     */
    public function test_the_call_button_sits_in_the_header_next_to_the_other_actions(): void
    {
        $c = Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'password' => 'secret123',
            'phone' => '09142223343',
        ]);

        $html = $this->actingAs($this->admin())->get('/admin/customers/'.$c->id)
            ->assertOk()->getContent();

        $broadcast = strpos($html, 'ارسال اعلان');
        $callForm = strpos($html, '/admin/customers/'.$c->id.'/call');
        $callsPane = strpos($html, 'تماس‌های این مشتری');

        $this->assertNotFalse($callForm, 'دکمهٔ تماس اصلاً رندر نشد');
        $this->assertGreaterThan($broadcast, $callForm, 'دکمهٔ تماس باید بعد از «ارسال اعلان» بیاید');
        $this->assertLessThan($callsPane, $callForm, 'و **پیش از** پنلِ تاریخچه — یعنی در نوارِ بالا');
    }
}
