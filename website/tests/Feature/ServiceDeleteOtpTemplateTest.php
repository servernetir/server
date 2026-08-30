<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Service;
use App\Services\Otp\OtpService;
use App\Services\Sms\SignedRelaySender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SupportsPatterns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * کدِ یک‌بارمصرفِ **حذفِ سرور** الگوی اختصاصیِ خودش را دارد.
 *
 * ═══ 🔴 خرابیِ واقعی که این فایل برای تکرارنشدنش نوشته شد ═══
 *
 * کارفرما: «زمانی که سروری رو حذف سرویس میکنم پیامک OTP ورود میاد اینو باید
 * اختصاصی حذف سرویسش کنیم.»
 *
 * جداییِ **اعتبارسنجی** از روزِ اول درست بود: حذف هدفِ خودش را داشت
 * (`service_terminate`) و `verify()` روی `purpose` فیلتر می‌کند، پس کدِ حذف
 * هرگز ورود نمی‌داد. آنچه غلط بود، **متنِ پیامک** بود: `issue()` برای هر هدفی
 * همان `sendOtp()` را صدا می‌زد و آن نامِ الگو را سخت‌کد `otp` دارد. یعنی
 * مشتری‌ای که داشت سرورش را برای همیشه پاک می‌کرد، پیامکی می‌گرفت که می‌گفت
 * «کد ورود» — بدترین لحظهٔ ممکن برای ابهام.
 *
 * ⚠️ چرا فیکسچرِ قدیمی این را نمی‌گرفت: فقط `SmsSender` را پیاده می‌کرد، نه
 * `SupportsPatterns`. پس هر پیامک از درِ `sendOtp` رد می‌شد و **نامِ الگو
 * اصلاً در تست دیده نمی‌شد**. فیکسچرِ این فایل عمداً همان قراردادی را دارد که
 * درایورِ واقعیِ رله دارد.
 */
class ServiceDeleteOtpTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,array{template:?string,mobile:string,params:array<string,string>}> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->app->instance(SmsSender::class, $this->patternDriver());
    }

    /** فرستندهٔ آزمایشی با همان قراردادِ درایورِ واقعیِ رله (الگو + کدِ ورود) */
    private function patternDriver(): SmsSender
    {
        return new class($this) implements SmsSender, SupportsPatterns
        {
            public function __construct(private ServiceDeleteOtpTemplateTest $t) {}

            public function enabled(): bool { return true; }

            public function name(): string { return 'fake-pattern'; }

            public function send(string $m, string $text): bool
            {
                $this->t->sent[] = ['template' => null, 'mobile' => $m, 'params' => ['text' => $text]];

                return true;
            }

            /** درایورِ واقعی این را با نامِ الگوی سخت‌کدِ `otp` می‌فرستد */
            public function sendOtp(string $m, string $code): bool
            {
                $this->t->sent[] = ['template' => 'otp', 'mobile' => $m, 'params' => ['code' => $code]];

                return true;
            }

            public function hasPattern(string $event): bool
            {
                return in_array($event, SignedRelaySender::TEMPLATES, true);
            }

            public function sendPattern(string $m, string $event, array $values): ?bool
            {
                if (! $this->hasPattern($event)) {
                    return null;      // همان معنایی که رلهٔ واقعی می‌دهد
                }

                $this->t->sent[] = ['template' => $event, 'mobile' => $m, 'params' => array_map(strval(...), $values)];

                return true;
            }
        };
    }

    /** @return array{template:?string,mobile:string,params:array<string,string>} */
    private function lastSms(): array
    {
        $this->assertNotEmpty($this->sent, 'هیچ پیامکی فرستاده نشد');

        return (array) end($this->sent);
    }

    private function lastCode(): string
    {
        return (string) ($this->lastSms()['params']['code'] ?? '');
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'd'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function activeService(Customer $c): Service
    {
        return Service::create([
            'customer_id' => $c->id, 'name' => 'سرور مجازی زنده', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done', 'activated_at' => now(),
        ]);
    }

    // ═══════════════ 🔴 قلبِ فایل: نامِ الگو ═══════════════

    public function test_the_deletion_code_uses_its_own_pattern_not_the_login_one(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/terminate/start");

        $sms = $this->lastSms();

        $this->assertSame('otp_service_delete', $sms['template'],
            'پیامکِ حذفِ سرور با الگوی «'.($sms['template'] ?? 'ــ').'» رفت. '
            .'الگوی `otp` متنِ «کد ورود» دارد و مشتری وسطِ حذفِ دائمیِ سرورش آن را می‌گیرد.');

        $this->assertNotSame('otp', $sms['template'], 'الگوی ورود نباید برای حذف استفاده شود');
    }

    /**
     * ⚠️ نامِ متغیر هم بخشی از ادعاست: الگوی اپراتور `%code%` دارد. با کلیدِ
     * دیگری، آی‌پی‌پنل جای‌نگهدارِ پرنشده را رد می‌کند و پیامک **بی‌صدا** نمی‌رود
     * — و n8n هم برای پاکتِ ردشده کدِ ۲۰۰ می‌دهد، پس از بیرون شبیهِ موفقیت است.
     */
    public function test_the_deletion_pattern_carries_a_six_digit_code_variable(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/terminate/start");

        $params = $this->lastSms()['params'];

        $this->assertSame(['code'], array_keys($params),
            'الگو فقط متغیرِ `code` می‌گیرد؛ متغیرِ اضافه را اپراتور رد می‌کند');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $params['code']);
    }

    /** و ورود دست‌نخورده مانده — وگرنه یک رفعِ باگ، پرتکرارترین مسیر را می‌خواباند */
    public function test_the_login_code_still_uses_the_generic_pattern(): void
    {
        $c = $this->customer();

        $this->post('/login', ['method' => 'mobile', 'identifier' => $c->phone])
            ->assertRedirect('/login/code');

        $this->assertSame('otp', $this->lastSms()['template'],
            'کدِ ورود باید با الگوی عمومیِ `otp` برود');
    }

    // ═══════════════ 🔴 جداییِ هدف‌ها (الزامِ امنیتی) ═══════════════

    /**
     * کدی که برای «حذفِ سرور» صادر شده **هرگز** نباید ورود بدهد.
     *
     * اگر هدف مشترک بود، هر کسی که آن پیامک را ببیند — همخانه، همکار، یا کسی که
     * گوشیِ قفل‌نشده را دستش بگیرد — می‌توانست با همان کد واردِ حساب شود. و
     * پیامکِ حذف عمداً از پیامکِ ورود **فوری‌تر** خوانده می‌شود، چون هشدارآمیز
     * است.
     */
    public function test_a_code_issued_for_deletion_can_never_verify_a_login(): void
    {
        $c = $this->customer();
        $otp = app(OtpService::class);

        $this->assertTrue($otp->issue('sms', $c->phone, 'service_terminate', null)->ok);
        $code = $this->lastCode();
        $this->assertNotSame('', $code);

        $this->assertFalse($otp->verify('sms', $c->phone, 'login', $code)->ok,
            '🔴 کدِ حذفِ سرور ورود داد — یعنی هدف‌ها از هم جدا نیستند');
        $this->assertFalse($otp->recentlyVerified('sms', $c->phone, 'login'),
            'حتی «اخیراً تأیید شده»ی ورود هم نباید با کدِ حذف روشن شود');

        // ✅ و روی هدفِ خودش کار می‌کند — بی‌این، فقط ثابت کرده‌ایم کد بی‌فایده است
        $this->assertTrue($otp->verify('sms', $c->phone, 'service_terminate', $code)->ok);
    }

    /** همان ادعا از درِ HTTP: پیامکِ حذف را بردار و با آن وارد شو — نباید بشود */
    public function test_the_deletion_code_is_rejected_by_the_real_login_form(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/terminate/start");
        $deleteCode = $this->lastCode();

        Auth::guard('customer')->logout();

        $this->post('/login', ['method' => 'mobile', 'identifier' => $c->phone])
            ->assertRedirect('/login/code');

        $this->post('/login/verify', ['code' => $deleteCode])->assertSessionHasErrors('code');

        $this->assertFalse(Auth::guard('customer')->check(),
            '🔴 با کدِ حذفِ سرور واردِ حساب شد');
        $this->assertSame('active', $s->fresh()->status, 'و سرویس هم نباید دست خورده باشد');
    }

    /** و برعکس: کدِ ورود نباید سرور را حذف کند */
    public function test_a_login_code_cannot_delete_a_server(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        // اول نشستِ حذف را بساز (وگرنه کنترلر پیش از رسیدن به کد رد می‌کند)
        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/terminate/start");

        // بعد یک کدِ **ورود** بگیر
        $this->assertTrue(app(OtpService::class)->issue('sms', $c->phone, 'login', null)->ok);
        $loginCode = $this->lastCode();

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate", ['code' => $loginCode])
            ->assertSessionHasErrors();

        $this->assertSame('active', $s->fresh()->status, '🔴 کدِ ورود سرور را حذف کرد');
    }

    // ═══════════════ سقوطِ بی‌خطر ═══════════════

    /**
     * ⚠️ درایوری که این الگو را نمی‌شناسد نباید مشتری را قفل کند.
     *
     * پیامکِ با متنِ «کد ورود» گیج‌کننده است، ولی نرسیدنِ **هیچ** کدی یعنی مشتری
     * نمی‌تواند سرورِ خودش را حذف کند و مجبور است تیکت بزند. و بی‌خطر است: همان
     * کد جز برای همین هدف تأیید نمی‌شود (تستِ بالا).
     */
    public function test_a_driver_without_the_pattern_falls_back_instead_of_locking_the_customer_out(): void
    {
        $this->app->instance(SmsSender::class, new class($this) implements SmsSender
        {
            public function __construct(private ServiceDeleteOtpTemplateTest $t) {}

            public function enabled(): bool { return true; }

            public function name(): string { return 'fake-plain'; }

            public function send(string $m, string $text): bool { return true; }

            public function sendOtp(string $m, string $code): bool
            {
                $this->t->sent[] = ['template' => 'otp', 'mobile' => $m, 'params' => ['code' => $code]];

                return true;
            }
        });

        $c = $this->customer();
        $s = $this->activeService($c);

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate/start")
            ->assertSessionHasNoErrors();

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate", ['code' => $this->lastCode()])
            ->assertRedirect();

        $this->assertSame('terminated', $s->fresh()->status);
    }
}
