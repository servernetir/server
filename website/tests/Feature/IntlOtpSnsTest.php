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

    /** با SNSِ آماده، کدِ خارجی به موبایل می‌رود و چالش روی کانالِ sms است */
    public function test_a_foreign_signup_gets_an_sms_challenge_when_sns_is_armed(): void
    {
        $this->armSns();
        Http::fake(['sns.eu-central-1.amazonaws.com/*' => Http::response(
            '<PublishResponse><PublishResult><MessageId>ok</MessageId></PublishResult></PublishResponse>'
        )]);

        $res = $this->post('/en/register', [
            'email' => 'tr-customer@example.com', 'type' => 'individual',
            'first_name' => 'Mehmet', 'last_name' => 'Yilmaz',
            'phone' => '+90 532 123 45 67',
        ]);

        $res->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('otp_challenges', [
            'channel' => 'sms', 'destination' => '+905321234567', 'purpose' => 'register',
        ]);
    }

    /** بی‌SNS، ثبت‌نامِ خارجی نمی‌شکند — کد به ایمیل برمی‌گردد */
    public function test_without_sns_the_foreign_signup_falls_back_to_email(): void
    {
        // مسیرِ ایمیلی صریحاً mailer('smtp') می‌زند؛ در تست transport آرایه‌ای می‌شود
        config()->set('mail.mailers.smtp', ['transport' => 'array']);

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
