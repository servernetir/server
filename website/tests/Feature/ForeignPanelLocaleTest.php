<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * هیچ فارسی‌ای در تجربهٔ مشتریِ en/tr — نه پیام، نه فرم، نه ایمیل.
 *
 * ═══ چرا (۶ شهریور ۱۴۰۵) ═══
 *
 * کارفرما با یک حسابِ انگلیسی ثبت‌نام کرد و دید: پیامِ «حساب ساخته شد» فارسی،
 * فرمِ شارژ «مبلغ (تومان)»، پیامِ ثبتِ KYC فارسی، و ایمیلِ خوش‌آمد فارسی.
 * «کلاً نباید در زبانِ انگلیسی و ترکی هیچ چیزِ فارسی نمایش داده شود.»
 */
class ForeignPanelLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function foreigner(string $locale = 'en'): Customer
    {
        return Customer::create([
            'email' => 'f'.random_int(1, 999999).'@example.com',
            'phone' => '+90532'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => $locale,
        ]);
    }

    /** 🔴 پیامِ پایانِ ثبت‌نام و ایمیلِ خوش‌آمد به زبانِ خودِ مشتری است */
    public function test_signup_flash_and_welcome_email_speak_the_customers_language(): void
    {
        Mail::fake();
        Http::fake();

        $this->post('/en/register', [
            'email' => 'locale-check@example.com', 'type' => 'individual',
            'first_name' => 'Jane', 'last_name' => 'Doe',
            'phone' => '+49 170 123 4567',
        ])->assertRedirect();

        $emailCode = null;
        Mail::assertSent(\App\Mail\OtpMail::class, function ($m) use (&$emailCode) {
            $emailCode = $m->code;

            return true;
        });

        $this->post('/en/register/verify', ['code' => $emailCode])->assertRedirect();

        $res = $this->post('/en/register/finish', [
            'password' => 'super-secret-10', 'password_confirmation' => 'super-secret-10',
            'terms' => '1',
        ])->assertRedirect();

        // پیامِ موفقیت انگلیسی است، نه فارسی
        $this->assertSame('Your account is ready. Welcome!', session('ok'));

        // ایمیلِ خوش‌آمد انگلیسی — نه الگویِ فارسیِ /admin/templates
        Mail::assertSent(\App\Mail\TemplateMail::class, function ($m) {
            return str_contains((string) $m->title, 'Welcome to ServerNet');
        });
    }

    /** 🔴 فرمِ شارژ برای en یورویی است و کنترلر یورو را به تومان تبدیل می‌کند */
    public function test_topup_is_in_euros_for_foreign_customers(): void
    {
        Http::fake();
        Setting::put('pricing_rate_override', '100000');   // ۱€ = ۱۰۰هزار تومان

        $c = $this->foreigner();

        $html = (string) $this->actingAs($c, 'customer')
            ->get('/en/account/topup')->assertOk()->getContent();
        $this->assertStringContainsString('Amount (EUR)', $html);
        $this->assertStringNotContainsString('Toman', $html, 'هیچ اشاره‌ای به تومان نباید باشد.');
        $this->assertStringContainsString('€5', $html, 'دکمه‌های سریع باید یورویی باشند.');

        $this->actingAs($c, 'customer')
            ->post('/en/account/topup', ['amount' => 10])
            ->assertRedirect()->assertSessionHasNoErrors();

        $invoice = Invoice::where('customer_id', $c->id)->where('kind', 'topup')->firstOrFail();
        $this->assertSame(1_000_000, (int) $invoice->total, '€۱۰ با نرخِ ۱۰۰هزار = یک میلیون تومان.');
        $this->assertSame('Account credit top-up', $invoice->items()->first()->title);
    }

    /** شارژِ فارسی مثل قبل تومانی است — رگرسیون نداشته باشیم */
    public function test_topup_stays_in_toman_for_fa(): void
    {
        Http::fake();
        $c = $this->foreigner('fa');

        $this->actingAs($c, 'customer')
            ->post('/account/topup', ['amount' => 500000])
            ->assertRedirect()->assertSessionHasNoErrors();

        $invoice = Invoice::where('customer_id', $c->id)->where('kind', 'topup')->firstOrFail();
        $this->assertSame(500_000, (int) $invoice->total);
    }

    /** 🔴 پیامِ ثبتِ KYC انگلیسی است، و داشبورد لینکِ «احراز هویت» دارد */
    public function test_kyc_flash_is_localized_and_dashboard_links_to_verification(): void
    {
        Http::fake();
        Storage::fake('local');
        $c = $this->foreigner();

        $this->actingAs($c, 'customer')->post('/en/account/verify', [
            'type' => 'individual',
            'first_name' => 'Jane', 'last_name' => 'Doe',
            'birth_date' => '1990-04-12', 'country' => 'DE',
            'address' => 'Hauptstr. 1', 'city' => 'Berlin', 'id_type' => 'passport',
            'doc_passport' => UploadedFile::fake()->create('passport.pdf', 300, 'application/pdf'),
            'doc_selfie'   => UploadedFile::fake()->image('selfie.jpg'),
            'doc_address'  => UploadedFile::fake()->image('bill.png'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Your information and documents were submitted for review. We usually review within one business day.',
            session('ok'),
        );

        // داشبورد: پیلِ «احرازنشده» باید لینک به فرمِ احراز باشد
        $html = (string) $this->actingAs($this->foreigner(), 'customer')
            ->get('/en/account')->assertOk()->getContent();
        $this->assertStringContainsString('account/profile#company', $html);
    }

    /** پروفایلِ تأییدشدهٔ خارجی روی داشبورد «تأییدشده» می‌گیرد — نه فقط استعلامِ ایرانی */
    public function test_a_verified_foreign_profile_shows_as_verified_on_the_dashboard(): void
    {
        Http::fake();
        $c = $this->foreigner();
        CustomerProfile::create([
            'customer_id' => $c->id, 'is_default' => true, 'type' => 'individual',
            'status' => 'verified', 'email' => $c->email, 'mobile' => $c->phone,
        ]);

        $html = (string) $this->actingAs($c, 'customer')
            ->get('/en/account')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.pnl_identity_ok', [], 'en'), $html);
        $this->assertStringNotContainsString('account/profile#company', $html);
    }

    /** 🔴 اعلانِ عمومیِ رویدادِ بی‌ترجمه هم انگلیسی می‌رود، هرگز فارسی */
    public function test_untranslated_events_fall_back_to_a_generic_english_email(): void
    {
        Mail::fake();
        Http::fake();
        $c = $this->foreigner();

        app(\App\Services\Notify\CustomerNotifier::class)
            ->templated($c, 'service_ready', ['service' => 'X'], 'سرویس شما آماده شد');

        Mail::assertSent(\App\Mail\TemplateMail::class, function ($m) {
            return str_contains((string) $m->title, 'ServerNet - account update');
        });
    }
}
