<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Otp\OtpService;
use App\Services\Sms\SnsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * موبایل و کدِ تأیید برای مشتریِ غیرایرانی — Amazon SNS.
 *
 * ═══ چرا (۵ شهریور ۱۴۰۵) ═══
 *
 * کارفرما: «خارجی‌ها هم اجباری شماره بدهند و با آمازون OTP بفرستیم.» تا امروز
 * ثبت‌نامِ en/tr اصلاً موبایل نمی‌پرسید و درایورهای پیامک فقط ۰۹ می‌فهمیدند.
 */
class IntlOtpSnsTest extends TestCase
{
    use RefreshDatabase;


    private function armSns(): void
    {
        Setting::put('aws_sns_key', 'AKIATEST12345');
        Setting::putSecret('aws_sns_secret', 'shhh-secret');
        Setting::put('aws_sns_region', 'eu-central-1');
    }

    // ═══════════ نرمال‌سازی ═══════════

    /** 🔴 قالب‌های بین‌المللی به +E.164 و ایرانی به ۰۹ — بی‌هیچ ابهامی */
    public function test_normalize_understands_international_numbers(): void
    {
        $otp = app(OtpService::class);

        // ایرانی — رفتارِ قدیمی مو نخورده
        $this->assertSame('09121234567', $otp->normalize('sms', '+98 912 123 4567'));
        $this->assertSame('09121234567', $otp->normalize('sms', '09121234567'));
        $this->assertSame('09121234567', $otp->normalize('sms', '9121234567'));

        // بین‌المللی — هر سه شکلِ ورودی
        $this->assertSame('+905321234567', $otp->normalize('sms', '+90 532 123 45 67'));
        $this->assertSame('+905321234567', $otp->normalize('sms', '00905321234567'));
        $this->assertSame('+905321234567', $otp->normalize('sms', '905321234567'));
        $this->assertSame('+491701234567', $otp->normalize('sms', '+49 170 123 4567'));

        // شمارهٔ ملیِ بی‌کدِ کشور — کشور حدس‌زدنی نیست، رد
        $this->assertSame('', $otp->normalize('sms', '05321234567'));
        $this->assertSame('', $otp->normalize('sms', 'abc'));
    }

    // ═══════════ مسیریابی و امضا ═══════════

    /** 🔴 شمارهٔ + از SNS می‌رود، با امضای SigV4 و SMSType=Transactional */
    public function test_an_international_code_goes_to_sns_signed(): void
    {
        $this->armSns();
        Http::fake(['sns.eu-central-1.amazonaws.com/*' => Http::response(
            '<PublishResponse><PublishResult><MessageId>abc-123</MessageId></PublishResult></PublishResponse>'
        )]);

        $ok = app(SnsSender::class)->sendOtp('+905321234567', '123456');

        $this->assertTrue($ok);
        Http::assertSent(function ($r) {
            $auth = (string) $r->header('Authorization')[0];
            $body = (string) $r->body();

            return str_contains($r->url(), 'sns.eu-central-1.amazonaws.com')
                && str_starts_with($auth, 'AWS4-HMAC-SHA256 Credential=AKIATEST12345/')
                && str_contains($auth, '/eu-central-1/sns/aws4_request')
                && str_contains($body, 'Action=Publish')
                && str_contains($body, rawurlencode('+905321234567'))
                && str_contains($body, 'Transactional')
                && str_contains($body, '123456');
        });
    }

    /** پاسخِ ۲۰۰ِ بی‌MessageId موفق شمرده نمی‌شود — قاعدهٔ «۲۰۰ ولی نرفت» */
    public function test_a_200_without_a_message_id_is_a_failure(): void
    {
        $this->armSns();
        Http::fake(['sns.eu-central-1.amazonaws.com/*' => Http::response(
            '<ErrorResponse><Error><Code>Throttled</Code></Error></ErrorResponse>'
        )]);

        $this->assertFalse(app(SnsSender::class)->sendOtp('+905321234567', '123456'));
    }

    /** بی‌کلید، درایور صادقانه خاموش است — نه تماس، نه وانمود */
    public function test_without_credentials_the_driver_is_disabled(): void
    {
        Http::fake();
        $this->assertFalse(app(SnsSender::class)->enabled());
        $this->assertFalse(app(SnsSender::class)->sendOtp('+905321234567', '123456'));
        Http::assertNothingSent();
    }

    // ═══════════ ثبت‌نامِ خارجی ═══════════

    /** 🔴 نام و موبایل حالا اجباری‌اند */
    public function test_foreign_registration_requires_name_and_mobile(): void
    {
        $res = $this->post('/en/register', [
            'email' => 'x@example.com', 'type' => 'individual',
        ]);

        $res->assertSessionHasErrors(['phone', 'first_name', 'last_name']);
    }

    /**
     * 🔴 جریانِ کاملِ دومرحله‌ای (خواستِ کارفرما: «شماره و ایمیل هر دو تأیید
     * شوند»): اول کدِ ایمیل، بعد از قبولی کدِ پیامکی، و در پایان **هر دو**
     * مهرِ تأیید روی حساب.
     */
    public function test_a_foreign_signup_verifies_email_then_phone(): void
    {
        $this->armSns();
        \Illuminate\Support\Facades\Mail::fake();

        // کدِ پیامکی مثلِ گوشیِ واقعی از خودِ درایور ضبط می‌شود (در DB فقط hash است)
        $smsCode = null;
        $this->app->instance(\App\Services\Sms\SnsSender::class,
            new class($smsCode) extends \App\Services\Sms\SnsSender {
                public function __construct(private mixed &$slot) {}
                public function enabled(): bool { return true; }
                public function sendOtp(string $m, string $code): bool { $this->slot = $code; return true; }
            });

        // ── گامِ ۱: شروع ⇒ چالشِ ایمیلی، نه پیامکی
        $this->post('/en/register', [
            'email' => 'tr-customer@example.com', 'type' => 'individual',
            'first_name' => 'Mehmet', 'last_name' => 'Yilmaz',
            'phone' => '+90 532 123 45 67',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('otp_challenges', [
            'channel' => 'email', 'destination' => 'tr-customer@example.com', 'purpose' => 'register',
        ]);
        $this->assertNull($smsCode, 'پیامک نباید پیش از تأییدِ ایمیل برود.');

        // کدِ ایمیلی از خودِ نامهٔ ارسالی (transport آرایه‌ای)
        $emailCode = null;
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OtpMail::class, function ($m) use (&$emailCode) {
            $emailCode = $m->code;

            return true;
        });
        $this->assertNotNull($emailCode, 'کدِ ایمیلی صادر نشد.');

        // ── گامِ ۲: تأییدِ ایمیل ⇒ کدِ دوم پیامک می‌شود
        $this->post('/en/register/verify', ['code' => $emailCode])
            ->assertRedirect('/en/register/verify')
            ->assertSessionHas('reg_notice');

        $this->assertNotNull($smsCode, 'بعد از ایمیل باید کدِ پیامکی برود.');

        // ── گامِ ۳: تأییدِ پیامک ⇒ رمز و پایان ⇒ هر دو مهر
        $this->post('/en/register/verify', ['code' => $smsCode])
            ->assertRedirect('/en/register/finish');

        $this->post('/en/register/finish', [
            'password' => 'super-secret-10', 'password_confirmation' => 'super-secret-10',
            'terms' => '1',
        ])->assertRedirect();

        $c = \App\Models\Customer::where('email', 'tr-customer@example.com')->firstOrFail();
        $this->assertNotNull($c->email_verified_at, 'ایمیل باید تأییدشده مهر بخورد.');
        $this->assertNotNull($c->phone_verified_at, 'موبایل باید تأییدشده مهر بخورد.');
        $this->assertSame('+905321234567', $c->phone);

        $p = \App\Models\CustomerProfile::where('customer_id', $c->id)->where('is_default', true)->first();
        $this->assertSame('Mehmet', $p?->first_name);
        $this->assertSame('Yilmaz', $p?->last_name);
    }

    /** شکستِ پیامکِ مرحلهٔ دوم، ثبت‌نام را گروگان نمی‌گیرد — خطای روشن + راهِ برگشت */
    public function test_a_failed_sms_stage_shows_a_clear_error_not_a_dead_end(): void
    {
        $this->armSns();
        \Illuminate\Support\Facades\Mail::fake();
        Http::fake(['sns.us-east-1.amazonaws.com/*' => Http::response('err', 500),
            'sns.eu-central-1.amazonaws.com/*' => Http::response('err', 500)]);

        $this->post('/en/register', [
            'email' => 'us-customer@example.com', 'type' => 'individual',
            'first_name' => 'John', 'last_name' => 'Doe',
            'phone' => '+1 202 555 0147',
        ])->assertRedirect();

        $emailCode = null;
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OtpMail::class, function ($m) use (&$emailCode) {
            $emailCode = $m->code;

            return true;
        });

        $this->post('/en/register/verify', ['code' => $emailCode])
            ->assertRedirect('/en/register/verify')
            ->assertSessionHasErrors('code');
    }

    /** بی‌SNS، ثبت‌نامِ خارجی نمی‌شکند — کد به ایمیل برمی‌گردد */
    public function test_without_sns_the_foreign_signup_falls_back_to_email(): void
    {
        // مسیرِ ایمیلی صریحاً mailer('smtp') می‌زند؛ در تست transport آرایه‌ای می‌شود
        \Illuminate\Support\Facades\Mail::fake();

        $res = $this->post('/en/register', [
            'email' => 'fallback@example.com', 'type' => 'individual',
            'first_name' => 'Jane', 'last_name' => 'Doe',
            'phone' => '+49 170 123 4567',
        ]);

        $res->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('otp_challenges', [
            'channel' => 'email', 'destination' => 'fallback@example.com', 'purpose' => 'register',
        ]);
    }

    /** شمارهٔ بی‌کدِ کشور در جریانِ خارجی، خطای روشن می‌گیرد */
    public function test_a_foreign_number_without_country_code_is_rejected(): void
    {
        $res = $this->post('/en/register', [
            'email' => 'x2@example.com', 'type' => 'individual',
            'first_name' => 'A', 'last_name' => 'B',
            'phone' => '05321234567',
        ]);

        $res->assertSessionHasErrors(['phone']);
    }
}
