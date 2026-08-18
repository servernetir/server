<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PhoneCall;
use App\Models\User;
use App\Services\CloudPhone\OutgoingCallService;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * تلفن ابری — پنل مدیریت و تماسِ خروجی.
 *
 * ⚠️ `Http::fake()` در هر تست **یک بار** ثبت می‌شود. درسِ ثبت‌شدهٔ پروژه:
 * استابِ `'*'` همه‌گیر هر fakeِ بعدی را بی‌اثر می‌کند و تست بی‌صدا هیچ‌چیز
 * نمی‌سنجد.
 */
class CloudPhoneAdminTest extends TestCase
{
    use RefreshDatabase;

    private const RELAY = 'https://flow.servernet.cloud/webhook/cloud-phone-outgoing';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cloud_phone.relay_url', self::RELAY);
        config()->set('services.cloud_phone.relay_secret', 'shared-secret-for-tests');
        config()->set('services.cloud_phone.extension', '71057757');
        config()->set('services.cloud_phone.agent_number', '09142223343');
    }

    /*
    | ⚠️ پیش‌فرض `null` است، نه `71057757`.
    |
    | آن عدد **خطِ ابری** است نه شماره‌ای که بشود زنگ زد — بی‌پیش‌شمارهٔ شهر و
    | فقط ۸ رقم. تا وقتی اعتبارسنجی سست بود این fixture بی‌سروصدا سبز می‌ماند؛
    | حالا که سفت شده، خودش نشان داد که از اول اشتباه بوده.
    */
    private function admin(?string $extension = null): User
    {
        return User::create([
            'name' => 'مدیر',
            'email' => 'admin@example.com',
            'password' => 'secret123',
            'role' => 'admin',
            'phone_extension' => $extension,
        ]);
    }

    private function customer(?string $phone = '+989142223343'): Customer
    {
        return Customer::create([
            'email' => 'c@example.com',
            'password' => 'secret123',
            'phone' => $phone,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // سرویسِ تماس خروجی
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_successful_call_sends_a_signed_envelope(): void
    {
        Http::fake([self::RELAY => Http::response(['status' => 'sent'], 200)]);

        $result = app(OutgoingCallService::class)->place('09121112222');

        $this->assertSame(OutgoingCallService::OK, $result['status']);

        Http::assertSent(function ($request) {
            $envelope = $request['envelope'] ?? '';

            $this->assertStringStartsWith('CLOUD_PHONE_V1:', $envelope);

            [$b64, $sig] = explode('.', substr($envelope, strlen('CLOUD_PHONE_V1:')), 2);

            // امضا باید با همان رازِ مشترک بخوانَد
            $this->assertSame(
                hash_hmac('sha256', $b64, 'shared-secret-for-tests'),
                $sig,
                'امضا با راز نمی‌خواند — n8n پاکت را رد می‌کند',
            );

            $payload = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);

            $this->assertSame('outgoing_call', $payload['action']);
            $this->assertSame('9121112222', $payload['to_number'], 'مقصد، نرمال‌شده');
            // نگاشتِ تأییدشده با رویدادِ واقعی: from_number = پایی که اول زنگ می‌خورد
            $this->assertSame('9142223343', $payload['from_number']);
            $this->assertSame('71057757', $payload['caller_extension'], 'خطِ ابری');
            $this->assertNotEmpty($payload['request_id']);
            $this->assertNotEmpty($payload['issued_at']);

            return true;
        });
    }

    public function test_the_api_token_never_leaves_our_server(): void
    {
        /*
        | 🔴 `PHONE_TOKEN` باید فقط در گرهٔ Relay Config داخلِ n8n باشد.
        | پاکتی که لو برود نباید کلیدِ حساب را لو بدهد.
        */
        config()->set('services.cloud_phone.token', 'super-secret-api-token');

        Http::fake([self::RELAY => Http::response(['status' => 'sent'], 200)]);

        app(OutgoingCallService::class)->place('09121112222');

        Http::assertSent(function ($request) {
            $this->assertStringNotContainsString('super-secret-api-token', json_encode($request->data()));
            $this->assertStringNotContainsString('super-secret-api-token', json_encode($request->headers()));

            return true;
        });
    }

    public function test_a_200_that_is_not_sent_counts_as_failure(): void
    {
        /*
        | 🔴 fail-closed. ورک‌فلو برای پاکتِ ردشده هم ۲۰۰ می‌دهد. اگر فقط به کدِ
        | HTTP نگاه کنیم، رازِ ناهماهنگ «موفق» گزارش می‌شود و مدیر منتظرِ زنگی
        | می‌ماند که هرگز نمی‌آید.
        */
        Http::fake([self::RELAY => Http::response(['status' => 'ignored', 'reason' => 'bad_signature'], 200)]);

        $result = app(OutgoingCallService::class)->place('09121112222');

        $this->assertSame(OutgoingCallService::FAILED, $result['status']);
    }

    public function test_the_suppliers_error_detail_reaches_the_error_tracker(): void
    {
        /*
        | ⚠️ «api_status_500» به‌تنهایی هیچ نمی‌گوید. گره بدنهٔ پاسخِ
        | تأمین‌کننده را هم برمی‌گرداند و باید تا ردیابِ خطا برسد — وگرنه
        | عیب‌یابی می‌شود حدس‌زدن.
        */
        Http::fake([self::RELAY => Http::response([
            'status' => 'failed',
            'reason' => 'api_status_500',
            'detail' => '{"error":"caller_extension not found"}',
        ], 200)]);

        ErrorTracker::clear();

        $result = app(OutgoingCallService::class)->place('09121112222');

        $this->assertSame(OutgoingCallService::FAILED, $result['status']);

        $logged = json_encode(ErrorTracker::recent(20), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('api_status_500', $logged);
        $this->assertStringContainsString('caller_extension not found', $logged);
    }

    public function test_an_unknown_200_body_is_also_a_failure(): void
    {
        // صفحهٔ خطای HTML با کدِ ۲۰۰، پاسخِ پروکسی، ورک‌فلوی نیمه‌ویرایش‌شده…
        Http::fake([self::RELAY => Http::response('<html>OK</html>', 200)]);

        $this->assertSame(
            OutgoingCallService::FAILED,
            app(OutgoingCallService::class)->place('09121112222')['status'],
        );
    }

    public function test_no_agent_number_anywhere_means_no_call_is_attempted(): void
    {
        // نه شمارهٔ شخصیِ کاربر، نه پیش‌فرضِ سراسری
        config()->set('services.cloud_phone.agent_number', '');

        Http::fake();

        $result = app(OutgoingCallService::class)->place('09121112222');

        $this->assertSame(OutgoingCallService::NO_AGENT, $result['status']);
        Http::assertNothingSent();
    }

    public function test_the_global_default_is_used_when_the_user_has_no_personal_number(): void
    {
        /*
        | 🔴 خواستهٔ صریحِ کارفرما: «فعلاً فقط خودم مدیریت می‌کنم» — یعنی دکمهٔ
        | تماس نباید به ثبتِ شمارهٔ تک‌تکِ کاربران گره بخورد.
        */
        Http::fake([self::RELAY => Http::response(['status' => 'sent'], 200)]);

        $this->assertSame(
            OutgoingCallService::OK,
            app(OutgoingCallService::class)->place('09121112222', null)['status'],
        );

        Http::assertSent(function ($request) {
            $b64 = explode('.', substr($request['envelope'], strlen('CLOUD_PHONE_V1:')), 2)[0];
            $p = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);

            $this->assertSame('9142223343', $p['from_number'], 'پیش‌فرضِ سراسری باید استفاده شود');

            return true;
        });
    }

    public function test_a_one_digit_personal_number_is_refused(): void
    {
        /*
        | 🔴 رگرسیونِ خرابیِ واقعیِ ۱۸ آگوست.
        |
        | عددِ `1` در فیلدِ شمارهٔ تماس‌گیرندهٔ کاربر ثبت شده بود.
        | `normalize('1')` مقدارِ `'1'` می‌داد نه `null`، پس از نگهبان رد شد و
        | رله `from_number: "01"` را به تأمین‌کننده فرستاد — تماس شکست خورد و
        | علتش سه لایه آن‌طرف‌تر پیدا شد.
        */
        Http::fake();

        $result = app(OutgoingCallService::class)->place('09121112222', '1');

        $this->assertSame(OutgoingCallService::NO_AGENT, $result['status']);
        $this->assertStringContainsString('1', $result['message']);
        Http::assertNothingSent();
    }

    public function test_a_local_agent_number_without_area_code_is_refused(): void
    {
        // شمارهٔ ثابتِ بی‌پیش‌شماره شماره‌گیری‌شدنی نیست — همان قاعدهٔ مقصد
        Http::fake();

        $this->assertSame(
            OutgoingCallService::NO_AGENT,
            app(OutgoingCallService::class)->place('09121112222', '34261000')['status'],
        );
        Http::assertNothingSent();
    }

    public function test_the_users_form_rejects_a_number_that_is_too_short(): void
    {
        $admin = $this->admin(extension: null);

        $this->actingAs($admin)
            ->post('/admin/users/'.$admin->id.'/extension', ['phone_extension' => '1'])
            ->assertSessionHasErrors('phone_extension');

        // ولی شمارهٔ کامل قبول می‌شود
        $this->actingAs($admin)
            ->post('/admin/users/'.$admin->id.'/extension', ['phone_extension' => '09351234567'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_personal_number_overrides_the_global_default(): void
    {
        Http::fake([self::RELAY => Http::response(['status' => 'sent'], 200)]);

        app(OutgoingCallService::class)->place('09121112222', '09351234567');

        Http::assertSent(function ($request) {
            $b64 = explode('.', substr($request['envelope'], strlen('CLOUD_PHONE_V1:')), 2)[0];
            $p = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);

            $this->assertSame('9351234567', $p['from_number'], 'شمارهٔ شخصی مقدم است');

            return true;
        });
    }

    public function test_a_local_number_without_area_code_is_refused(): void
    {
        /*
        | 🔴 مهم‌ترین ادعای این فایل بعد از امضا.
        |
        | تماس‌گیرندهٔ ورودی `34261000` می‌آید چون تأمین‌کننده پیش‌شماره را حذف
        | می‌کند. اگر همان را شماره‌گیری کنیم، به یک غریبه در شهرِ دیگر زنگ
        | می‌زنیم — از خطِ شرکت و با شمارهٔ شرکت.
        */
        Http::fake();

        $result = app(OutgoingCallService::class)->place('34261000');

        $this->assertSame(OutgoingCallService::BAD_NUMBER, $result['status']);
        Http::assertNothingSent();
    }

    public function test_an_http_relay_url_is_refused(): void
    {
        // پاکت شمارهٔ مشتری دارد؛ روی http هر واسطی می‌خواندش
        config()->set('services.cloud_phone.relay_url', 'http://flow.servernet.cloud/webhook/x');

        Http::fake();

        $this->assertSame(
            OutgoingCallService::DISABLED,
            app(OutgoingCallService::class)->place('09121112222')['status'],
        );
        Http::assertNothingSent();
    }

    public function test_a_missing_secret_disables_the_relay(): void
    {
        config()->set('services.cloud_phone.relay_secret', '');

        Http::fake();

        $this->assertFalse(app(OutgoingCallService::class)->enabled());
        Http::assertNothingSent();
    }

    // ══════════════════════════════════════════════════════════════════════
    // روتِ تماس در پنل
    // ══════════════════════════════════════════════════════════════════════

    public function test_admin_can_call_a_customer(): void
    {
        Http::fake([self::RELAY => Http::response(['status' => 'sent'], 200)]);

        $this->actingAs($this->admin())
            ->post('/admin/customers/'.$this->customer()->id.'/call')
            ->assertRedirect()
            ->assertSessionHas('ok');

        Http::assertSentCount(1);
    }

    public function test_the_destination_number_comes_from_the_database_not_the_form(): void
    {
        /*
        | 🔴 ادعای امنیتیِ اصلی.
        |
        | اگر شماره از فرم خوانده می‌شد، هر کسی با دسترسیِ پنل می‌توانست از خطِ
        | شرکت به هر شماره‌ای زنگ بزند — پنلِ مدیریت می‌شد تلفنِ رایگانِ
        | بین‌المللی. فرم عمداً نادیده گرفته می‌شود.
        */
        Http::fake([self::RELAY => Http::response(['status' => 'sent'], 200)]);

        $this->actingAs($this->admin())
            ->post('/admin/customers/'.$this->customer()->id.'/call', [
                'to_number' => '00447700900000',   // تلاش برای تزریق شمارهٔ دلخواه
                'number' => '00447700900000',
            ])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            $b64 = explode('.', substr($request['envelope'], strlen('CLOUD_PHONE_V1:')), 2)[0];
            $payload = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);

            $this->assertSame('9142223343', $payload['to_number'], 'شمارهٔ فرم نباید اثری داشته باشد');

            return true;
        });
    }

    public function test_calling_a_customer_without_a_number_fails_gracefully(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->post('/admin/customers/'.$this->customer(null)->id.'/call')
            ->assertRedirect()
            ->assertSessionHas('err');

        Http::assertNothingSent();
    }

    public function test_an_author_cannot_place_calls(): void
    {
        // تماس پول خرج می‌کند و از خطِ شرکت می‌رود — نقشِ نویسنده نباید بتواند
        $author = User::create([
            'name' => 'نویسنده', 'email' => 'a@example.com',
            'password' => 'secret123', 'role' => 'author', 'phone_extension' => '201',
        ]);

        Http::fake();

        $this->actingAs($author)
            ->post('/admin/customers/'.$this->customer()->id.'/call')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_visitor_cannot_place_calls(): void
    {
        Http::fake();

        $this->post('/admin/customers/'.$this->customer()->id.'/call')->assertRedirect();

        Http::assertNothingSent();
    }

    // ══════════════════════════════════════════════════════════════════════
    // صفحهٔ گزارش تماس‌ها
    // ══════════════════════════════════════════════════════════════════════

    private function seedCalls(): Customer
    {
        $customer = $this->customer();

        PhoneCall::create([
            'call_reference_id' => 'ref-answered', 'direction' => 'incoming',
            'caller_number' => '09142223343', 'caller_number_norm' => '9142223343',
            'customer_id' => $customer->id, 'match_confidence' => PhoneCall::MATCH_EXACT,
            'started_at' => now()->subHours(2), 'ended_at' => now()->subHours(2)->addMinute(),
            'duration_seconds' => 60, 'answered' => true, 'event_count' => 5,
        ]);

        PhoneCall::create([
            'call_reference_id' => 'ref-missed', 'direction' => 'incoming',
            'caller_number' => '34261000', 'caller_number_norm' => '34261000',
            'match_confidence' => PhoneCall::MATCH_MANY,
            'started_at' => now()->subHour(), 'answered' => false, 'event_count' => 2,
        ]);

        PhoneCall::create([
            'call_reference_id' => 'ref-live', 'direction' => 'incoming',
            'caller_number' => '09121112222', 'caller_number_norm' => '9121112222',
            'started_at' => now(), 'answered' => null, 'event_count' => 1,
        ]);

        return $customer;
    }

    public function test_the_call_log_lists_calls_and_counts_them(): void
    {
        $this->seedCalls();

        $res = $this->actingAs($this->admin())->get('/admin/calls');

        $res->assertOk();
        $res->assertSee('گزارش تماس‌ها');
        $res->assertSee('09142223343');
        $res->assertSee('34261000');
    }

    public function test_the_missed_filter_excludes_calls_still_in_progress(): void
    {
        /*
        | 🔴 تماسی که هنوز `Ended` نگرفته `answered = null` دارد و
        | **از‌دست‌رفته نیست**. اگر در این فیلتر بیفتد، کارشناس به کسی زنگ
        | می‌زند که همین حالا پشتِ خط است.
        */
        $this->seedCalls();

        $this->assertSame(1, PhoneCall::missed()->count());

        $this->actingAs($this->admin())->get('/admin/calls?f=missed')
            ->assertOk()
            ->assertSee('34261000')
            ->assertDontSee('09121112222');
    }

    public function test_an_ambiguous_match_is_shown_as_ambiguous_not_as_unknown(): void
    {
        $this->seedCalls();

        $this->actingAs($this->admin())->get('/admin/calls')
            ->assertOk()
            ->assertSee('چند مشتری خوردند');
    }

    public function test_searching_by_a_zero_prefixed_number_finds_the_normalised_row(): void
    {
        // مدیر «۰۹۱۴…» تایپ می‌کند ولی ستونِ نرمال «۹۱۴…» دارد
        $this->seedCalls();

        $this->actingAs($this->admin())->get('/admin/calls?q=09142223343')
            ->assertOk()
            ->assertSee('09142223343')
            ->assertDontSee('34261000');
    }

    public function test_the_customer_page_shows_a_calls_tab(): void
    {
        $customer = $this->seedCalls();

        $this->actingAs($this->admin())->get('/admin/customers/'.$customer->id)
            ->assertOk()
            ->assertSee('تماس‌های این مشتری')
            ->assertSee('data-pane="calls"', false);
    }

    public function test_the_call_button_works_without_a_personal_number(): void
    {
        // خواستهٔ کارفرما: دکمه نباید به ثبتِ شمارهٔ هر کاربر گره بخورد
        $customer = $this->seedCalls();

        $this->actingAs($this->admin(extension: null))
            ->get('/admin/customers/'.$customer->id)
            ->assertOk()
            ->assertSee('تماس با')
            ->assertDontSee('شمارهٔ تماس‌گیرنده تنظیم نشده');
    }

    public function test_the_button_explains_itself_when_no_number_is_configured_at_all(): void
    {
        /*
        | دکمه‌ای که کلیکش خطا بدهد بدتر از نبودنِ دکمه است — و مدیر را می‌فرستد
        | سراغِ تیم فنی به‌جای اینکه بگوید چه چیزی کم است.
        */
        config()->set('services.cloud_phone.agent_number', '');

        $customer = $this->seedCalls();

        $this->actingAs($this->admin(extension: null))
            ->get('/admin/customers/'.$customer->id)
            ->assertOk()
            ->assertSee('شمارهٔ تماس‌گیرنده تنظیم نشده');
    }

    public function test_the_call_log_survives_a_server_without_the_migration(): void
    {
        // نگهبانِ مهاجرت — صفحه نباید ۵۰۰ شود
        Schema::drop('phone_calls');

        $this->actingAs($this->admin())->get('/admin/calls')
            ->assertOk()
            ->assertSee('هنوز ساخته نشده');
    }

    // ══════════════════════════════════════════════════════════════════════
    // داخلیِ کارکنان
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_admin_can_set_and_clear_an_extension(): void
    {
        $admin = $this->admin(extension: null);

        $this->actingAs($admin)
            ->post('/admin/users/'.$admin->id.'/extension', ['phone_extension' => '09351234567'])
            ->assertRedirect();

        $this->assertSame('09351234567', $admin->fresh()->phoneExtension());

        $this->actingAs($admin)
            ->post('/admin/users/'.$admin->id.'/extension', ['phone_extension' => ''])
            ->assertRedirect();

        // ⚠️ رشتهٔ خالی باید null شود، وگرنه پیش‌فرضِ سراسری بی‌صدا دور زده می‌شود
        $this->assertNull($admin->fresh()->phoneExtension());
    }

    public function test_a_non_numeric_extension_is_rejected(): void
    {
        $admin = $this->admin(extension: null);

        $this->actingAs($admin)
            ->post('/admin/users/'.$admin->id.'/extension', ['phone_extension' => 'drop table'])
            ->assertSessionHasErrors('phone_extension');
    }
}
