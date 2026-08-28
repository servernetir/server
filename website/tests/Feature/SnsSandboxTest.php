<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Services\Sms\SnsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * راهِ درروِ SMS Sandbox — تأییدِ شماره با کدِ خودِ AWS.
 *
 * ═══ چرا (۶ شهریور ۱۴۰۵) ═══
 *
 * حسابِ AWS تا تأییدِ کیسِ «SMS Production Access» در سندباکس است: Publish
 * به شمارهٔ تأییدنشده ۲۰۰ + MessageId می‌دهد ولی هرگز تحویل نمی‌شود (در
 * کنسول: Sent 3 / Failed 3). کارفرما: «ببین می‌تونی با API شماره‌ها رو اضافه
 * کنی.» راهکار: CreateSMSSandboxPhoneNumber (خودِ AWS کد می‌فرستد — این پیام
 * در سندباکس هم می‌رسد) + VerifySMSSandboxPhoneNumber.
 */
class SnsSandboxTest extends TestCase
{
    use RefreshDatabase;

    private function armSns(bool $sandbox = true): void
    {
        Setting::put('aws_sns_key', 'AKIATEST12345');
        Setting::putSecret('aws_sns_secret', 'shhh-secret');
        Setting::put('aws_sns_region', 'us-east-1');

        if ($sandbox) {
            Setting::put('aws_sns_sandbox', '1');
        }
    }

    /** یک fake برای هر چهار Action — پاسخ از رویِ بدنهٔ درخواست انتخاب می‌شود */
    private function fakeSns(string $listXml, int $createStatus = 200, int $verifyStatus = 200): void
    {
        Http::fake(function ($request) use ($listXml, $createStatus, $verifyStatus) {
            $body = (string) $request->body();

            if (str_contains($body, 'Action=ListSMSSandboxPhoneNumbers')) {
                return Http::response($listXml);
            }

            if (str_contains($body, 'Action=CreateSMSSandboxPhoneNumber')) {
                return Http::response(
                    $createStatus === 200
                        ? '<CreateSMSSandboxPhoneNumberResponse/>'
                        : '<ErrorResponse><Error><Code>ValidationException</Code></Error></ErrorResponse>',
                    $createStatus,
                );
            }

            if (str_contains($body, 'Action=VerifySMSSandboxPhoneNumber')) {
                return Http::response(
                    $verifyStatus === 200
                        ? '<VerifySMSSandboxPhoneNumberResponse/>'
                        : '<ErrorResponse><Error><Code>VerificationException</Code></Error></ErrorResponse>',
                    $verifyStatus,
                );
            }

            return Http::response(
                '<PublishResponse><PublishResult><MessageId>x-1</MessageId></PublishResult></PublishResponse>'
            );
        });
    }

    private const EMPTY_LIST = '<ListSMSSandboxPhoneNumbersResponse><ListSMSSandboxPhoneNumbersResult><PhoneNumbers/></ListSMSSandboxPhoneNumbersResult></ListSMSSandboxPhoneNumbersResponse>';

    private const VERIFIED_LIST = '<ListSMSSandboxPhoneNumbersResponse><ListSMSSandboxPhoneNumbersResult><PhoneNumbers><member><PhoneNumber>+905321234567</PhoneNumber><Status>Verified</Status></member></PhoneNumbers></ListSMSSandboxPhoneNumbersResult></ListSMSSandboxPhoneNumbersResponse>';

    /** ثبت‌نام تا مرزِ مرحلهٔ پیامکی: ایمیل تأییدشده برمی‌گردد */
    private function passEmailStage(string $email): void
    {
        Mail::fake();

        $this->post('/en/register', [
            'email' => $email, 'type' => 'individual',
            'first_name' => 'Mehmet', 'last_name' => 'Yilmaz',
            'phone' => '+90 532 123 45 67',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $emailCode = null;
        Mail::assertSent(\App\Mail\OtpMail::class, function ($m) use (&$emailCode) {
            $emailCode = $m->code;

            return true;
        });
        $this->assertNotNull($emailCode);

        $this->post('/en/register/verify', ['code' => $emailCode])->assertRedirect();
    }

    // ═══════════ خودِ درایور ═══════════

    /** 🔴 هر سه Actionِ سندباکس امضاشده می‌روند و وضعیت درست پارس می‌شود */
    public function test_the_sandbox_actions_are_signed_and_parsed(): void
    {
        $this->armSns();
        $this->fakeSns(self::VERIFIED_LIST);

        $sns = app(SnsSender::class);

        $this->assertTrue($sns->sandboxMode());
        $this->assertSame('Verified', $sns->sandboxStatus('+905321234567'));
        $this->assertNull($sns->sandboxStatus('+15551234567'), 'شمارهٔ غایب باید null باشد.');
        $this->assertTrue($sns->sandboxAdd('+15551234567'));
        $this->assertTrue($sns->sandboxVerify('+15551234567', '482910'));

        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Action=CreateSMSSandboxPhoneNumber')
            && str_contains((string) $r->body(), rawurlencode('+15551234567'))
            && str_starts_with((string) $r->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIATEST12345/'));
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Action=VerifySMSSandboxPhoneNumber')
            && str_contains((string) $r->body(), 'OneTimePassword=482910'));
    }

    // ═══════════ جریانِ ثبت‌نام ═══════════

    /** 🔴 در حالتِ سندباکس، کد را AWS می‌فرستد و Publish هرگز صدا زده نمی‌شود */
    public function test_in_sandbox_mode_the_sms_stage_uses_aws_own_code(): void
    {
        $this->armSns();
        $this->fakeSns(self::EMPTY_LIST);

        $this->passEmailStage('sandbox-tr@example.com');

        // مرحلهٔ پیامکی با کدِ AWS شروع شده
        $this->assertSame(__('ui.auth_sms_sandbox_sent'), session('reg_notice'));
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Action=CreateSMSSandboxPhoneNumber'));

        // کدی که مشتری از پیامکِ AWS خوانده — سنجش با VerifySMSSandboxPhoneNumber
        $this->post('/en/register/verify', ['code' => '482910'])
            ->assertRedirect('/en/register/finish');

        $this->post('/en/register/finish', [
            'password' => 'super-secret-10', 'password_confirmation' => 'super-secret-10',
            'terms' => '1',
        ])->assertRedirect();

        $c = Customer::where('email', 'sandbox-tr@example.com')->firstOrFail();
        $this->assertNotNull($c->email_verified_at);
        $this->assertNotNull($c->phone_verified_at, 'تأییدِ سندباکسی باید مهرِ موبایل بزند.');

        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'Action=Publish'));
    }

    /** شمارهٔ از قبل Verified مسیرِ عادی (Publishِ کدِ خودمان) را می‌رود */
    public function test_an_already_verified_number_takes_the_normal_publish_path(): void
    {
        $this->armSns();
        $this->fakeSns(self::VERIFIED_LIST);

        $this->passEmailStage('verified-tr@example.com');

        $this->assertDatabaseHas('otp_challenges', [
            'channel' => 'sms', 'destination' => '+905321234567', 'purpose' => 'register',
        ]);
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Action=Publish'));
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'Action=CreateSMSSandboxPhoneNumber'));
    }

    /** 🔴 سندباکسِ پر (سقفِ ~۱۰ شماره) ثبت‌نام را گروگان نمی‌گیرد — ایمیل کافی است */
    public function test_a_full_sandbox_never_blocks_registration(): void
    {
        $this->armSns();
        $this->fakeSns(self::EMPTY_LIST, createStatus: 400);

        $this->passEmailStage('unlucky@example.com');

        $this->post('/en/register/finish', [
            'password' => 'super-secret-10', 'password_confirmation' => 'super-secret-10',
            'terms' => '1',
        ])->assertRedirect();

        $c = Customer::where('email', 'unlucky@example.com')->firstOrFail();
        $this->assertNotNull($c->email_verified_at);
        $this->assertNull($c->phone_verified_at, 'شمارهٔ تأییدنشده نباید مهر بخورد.');
    }

    /** کدِ غلطِ سندباکسی خطای روشن می‌گیرد و مشتری می‌تواند دوباره تلاش کند */
    public function test_a_wrong_sandbox_code_is_a_clear_error(): void
    {
        $this->armSns();
        $this->fakeSns(self::EMPTY_LIST, verifyStatus: 400);

        $this->passEmailStage('typo@example.com');

        $this->post('/en/register/verify', ['code' => '000000'])
            ->assertRedirect()->assertSessionHasErrors('code');
    }

    /** با تیکِ خاموش (پیش‌فرض)، هیچ Actionِ سندباکسی صدا زده نمی‌شود */
    public function test_with_the_toggle_off_the_normal_path_is_untouched(): void
    {
        $this->armSns(sandbox: false);
        $this->fakeSns(self::EMPTY_LIST);

        $this->passEmailStage('normal@example.com');

        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Action=Publish'));
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'SandboxPhoneNumber'));
    }
}
