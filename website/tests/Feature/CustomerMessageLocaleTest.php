<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Services\Notify\CustomerNotifier;
use App\Support\CustomerMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomerMessageLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_vps_orders_keep_details_in_the_selected_language(): void
    {
        $raw = 'سفارشِ سرورِ مجازی «web-01» — CV-2-4 · 🇩🇪 آلمان — فرانکفورت · ماهانه توسط مشتری ثبت شد';
        $log = new ActivityLog(['action' => 'purchase', 'description' => $raw]);
        foreach (['en', 'tr'] as $locale) {
            $display = $log->customerDescription($locale);
            $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $display);
            $this->assertStringContainsString('web-01', $display);
            $this->assertStringContainsString('CV-2-4', $display);
            $this->assertStringContainsString('Frankfurt', $display);
            $this->assertStringContainsString(config('billing.cycles.monthly.label.'.$locale), $display);
        }
        $this->assertSame($raw, $log->description);
        $this->assertSame($raw, $log->customerDescription('fa'));
    }

    public function test_existing_translated_events_follow_language_switches(): void
    {
        foreach (['fa', 'en', 'tr'] as $source) {
            $text = trans('ui.act_prov_mail', ['ip' => '192.0.2.10'], $source);
            foreach (['fa', 'en', 'tr'] as $target) {
                $this->assertSame(trans('ui.act_prov_mail', ['ip' => '192.0.2.10'], $target),
                    CustomerMessage::activity($text, 'provision', $target));
            }
        }
    }

    public function test_nested_login_channels_and_country_names_follow_language_switches(): void
    {
        $text = trans('ui.act_login', ['channel' => trans('ui.act_ch_email', [], 'en').trans('ui.act_login_app', [], 'en')], 'en');
        $this->assertSame(trans('ui.act_login', ['channel' => trans('ui.act_ch_email', [], 'tr').trans('ui.act_login_app', [], 'tr')], 'tr'),
            CustomerMessage::activity($text, 'login', 'tr'));
        $hourly = trans('ui.act_hourly_order', ['label' => 'web-01', 'loc' => 'Germany — Frankfurt', 'rate' => '€0.10'], 'en');
        $this->assertStringContainsString('Almanya', CustomerMessage::activity($hourly, 'purchase', 'tr'));
    }

    public function test_hourly_orders_and_security_logs_are_localized(): void
    {
        $hourly = 'سفارش سرور ساعتی «web-01» — 🇮🇷 ایران — ارومیه · ۱,۷۰۰ تومان در ساعت (پرداخت از کیف پول)';
        foreach (['en', 'tr'] as $locale) {
            $text = CustomerMessage::activity($hourly, 'purchase', $locale);
            $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $text);
            $this->assertStringContainsString('web-01', $text);
            $this->assertStringContainsString('1,700 IRT', $text);
            $this->assertStringContainsString('Urmia', $text);
            $this->assertSame(trans('activity.two_factor_enabled', [], $locale),
                CustomerMessage::activity('ورود دومرحله‌ای (اپلیکیشن) فعال شد', 'security', $locale));
        }
    }

    public function test_unknown_and_nested_persian_details_never_leak_and_do_not_claim_success(): void
    {
        foreach (['en', 'tr'] as $locale) {
            $this->assertSame(trans('activity.summary_provision', [], $locale),
                CustomerMessage::activity('خطای داخلی تأمین‌کننده', 'provision', $locale));
            $this->assertSame(trans('ui.act_prov_failed', ['reason' => trans('activity.detail', [], $locale)], $locale),
                CustomerMessage::activity('تحویلِ سرورِ ابری ناموفق: خطای داخلی تأمین‌کننده', 'provision', $locale));
            $this->assertSame(trans('ui.act_cloud_evt', ['id' => 42, 'text' => trans('ui.act_cloud_rootpw', [], $locale)], $locale),
                CustomerMessage::activity('سرورِ ابری #42 — رمزِ root تازه شد.', 'service', $locale));
        }
    }

    public function test_real_dashboard_translates_existing_logs_without_modifying_them(): void
    {
        Http::fake();
        $c = Customer::create(['email' => 'message-test@example.com', 'phone' => '+905321234567',
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa', 'is_reseller' => true]);
        $raw = 'ایمیلِ تحویلِ سرور فرستاده شد (IP: 192.0.2.10).';
        $log = ActivityLog::create(['customer_id' => $c->id, 'actor' => 'system', 'action' => 'provision', 'description' => $raw]);
        foreach (['en', 'tr'] as $locale) {
            $this->actingAs($c, 'customer')->get('/'.$locale.'/account')->assertOk()
                ->assertSee(trans('ui.act_prov_mail', ['ip' => '192.0.2.10'], $locale))
                ->assertDontSee($raw)->assertDontSee('نمایندگی دامنه');
        }
        $this->assertSame($raw, $log->fresh()->description);
    }

    public function test_direct_notification_events_bypass_persian_sms_and_templates(): void
    {
        Mail::fake();
        Http::fake();
        foreach (['en', 'tr'] as $locale) {
            $c = Customer::create(['email' => $locale.'@example.com', 'phone' => '+90532123456'.($locale === 'en' ? '1' : '2'),
                'password' => 'secret1234', 'status' => 'active', 'locale' => $locale]);
            app(CustomerNotifier::class)->event($c, 'service_ready', ['service' => 'سرور مجازی web-01', 'ip' => '192.0.2.10'], 'پیام فارسی');
        }
        Mail::assertSent(\App\Mail\TemplateMail::class, 2);
        Mail::assertSent(\App\Mail\TemplateMail::class, function ($mail) {
            $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', strip_tags($mail->render()));
            $this->assertStringContainsString('web-01', $mail->render());
            return true;
        });
        Http::assertNothingSent();
    }

    public function test_new_activity_dictionaries_have_matching_keys_and_placeholders(): void
    {
        $fa = require lang_path('fa/activity.php');
        foreach (['en', 'tr'] as $locale) {
            $dict = require lang_path($locale.'/activity.php');
            $this->assertSame(array_keys($fa), array_keys($dict));
            foreach ($fa as $key => $value) {
                $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $dict[$key]);
                preg_match_all('/(?<![a-z0-9_]):\w+/i', $value, $a);
                preg_match_all('/(?<![a-z0-9_]):\w+/i', $dict[$key], $b);
                sort($a[0]); sort($b[0]);
                $this->assertSame($a[0], $b[0]);
            }
        }
    }

    public function test_invalid_login_identifiers_use_the_request_language(): void
    {
        foreach (['en', 'tr'] as $locale) {
            $this->post('/'.$locale.'/login', ['method' => 'email', 'identifier' => 'invalid-email'])
                ->assertSessionHasErrors(['identifier' => trans('activity.login_email_invalid', [], $locale)]);
        }
    }

    public function test_foreign_kyc_notice_is_one_localized_email_even_when_the_staff_reason_is_persian(): void
    {
        Mail::fake();
        Http::fake();
        foreach (['en', 'tr'] as $locale) {
            $c = Customer::create(['email' => 'kyc-'.$locale.'@example.com', 'phone' => '+90532123456'.($locale === 'en' ? '3' : '4'),
                'password' => 'secret1234', 'status' => 'active', 'locale' => $locale]);
            $profile = \App\Models\CustomerProfile::create(['customer_id' => $c->id, 'email' => $c->email,
                'type' => 'individual', 'status' => 'pending']);
            $result = app(\App\Services\Customer\KycReview::class)->reject($profile, 'تصویر مدرک خوانا نیست', null);
            $this->assertTrue($result['ok']);
            $this->assertSame('تصویر مدرک خوانا نیست', $profile->fresh()->reject_reason);
        }
        Mail::assertSent(\App\Mail\TemplateMail::class, 2);
        foreach (Mail::sent(\App\Mail\TemplateMail::class) as $mail) {
            $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', strip_tags($mail->render()));
        }
        Http::assertNothingSent();
    }
}
