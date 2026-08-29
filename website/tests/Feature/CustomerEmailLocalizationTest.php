<?php

namespace Tests\Feature;

use App\Mail\TemplateMail;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Services\Customer\KycReview;
use App\Services\Notify\CustomerNotifier;
use App\Services\Notify\NotifyEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ایمیلِ مشتریِ سایتِ انگلیسی/ترکی — سه قاعدهٔ کارفرما (۷ شهریور ۱۴۰۵):
 *
 *   ۱) به زبانِ خودِ مشتری باشد، نه فارسی و نه «حسابتان به‌روزرسانی دارد»ِ عمومی
 *   ۲) هیچ اشاره‌ای به ایران نداشته باشد
 *   ۳) در همان قالبِ برنددارِ بقیهٔ ایمیل‌ها برود (emails.layout)، به زبانِ خودش
 *
 * پیش از این، کلِ کاتالوگِ اعلان جز ۴ رویداد ترجمهٔ en/tr نداشت و همه به
 * ntf_generic سقوط می‌کردند؛ قالب هم با locale لحظهٔ اجرا (کرون = fa) رندر
 * می‌شد؛ و ایمیلِ تأییدِ KYC انگلیسی صریحاً «Iran-hosted plans» می‌گفت.
 */
class CustomerEmailLocalizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * هر رویدادِ مشتری‌خبرکنِ کاتالوگ باید متنِ en و tr داشته باشد.
     *
     * 🔴 فیکسچر خودِ کاتالوگ است، نه فهرستِ دست‌نویس — رویدادِ تازه‌ای که به
     * NotifyEvent اضافه شود و ترجمه نگیرد، همین‌جا قرمز می‌شود؛ وگرنه مشتریِ
     * خارجی برای آن رویداد دوباره ایمیلِ عمومیِ بی‌معنی می‌گیرد.
     */
    public function test_every_customer_event_has_english_and_turkish_email_text(): void
    {
        $en = require base_path('lang/en/ui.php');
        $tr = require base_path('lang/tr/ui.php');

        $missing = [];

        foreach (NotifyEvent::all() as $key => $meta) {
            if (! $meta['wired'] || ! NotifyEvent::notifiesCustomer($key)) {
                continue;
            }

            foreach (['en' => $en, 'tr' => $tr] as $lang => $strings) {
                foreach (['_s', '_b'] as $suffix) {
                    if (blank($strings['ntf_'.$key.$suffix] ?? null)) {
                        $missing[] = $lang.': ntf_'.$key.$suffix;
                    }
                }
            }
        }

        $this->assertSame([], $missing,
            "این رویدادها برای مشتریِ خارجی به ایمیلِ عمومی سقوط می‌کنند:\n".implode("\n", $missing));
    }

    /** هیچ متنِ ایمیلِ en/tr نباید نامی از ایران ببرد — قاعدهٔ صریحِ کارفرما */
    public function test_no_intl_email_text_mentions_iran(): void
    {
        foreach (['en', 'tr'] as $lang) {
            $strings = require base_path('lang/'.$lang.'/ui.php');

            foreach ($strings as $key => $value) {
                if (! is_string($value) || (! str_starts_with($key, 'ntf_') && ! str_starts_with($key, 'email_'))) {
                    continue;
                }

                $this->assertFalse(
                    (bool) preg_match('/iran|İran|ایران/iu', $value),
                    "کلیدِ {$lang}.{$key} به ایران اشاره می‌کند: «{$value}»"
                );
            }
        }
    }

    /** قالبِ برنددار باید به زبانِ مشتری رندر شود، نه locale لحظهٔ اجرا */
    public function test_the_branded_layout_follows_the_mailable_locale(): void
    {
        app()->setLocale('fa');   // شبیه‌سازیِ کرون: locale اجرا فارسی است

        $html = (new TemplateMail('Subject', '<p>Body</p>', 'en'))->render();

        $this->assertStringContainsString('lang="en"', $html);
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('Cloud Infrastructure', $html);
        $this->assertStringNotContainsString('زیرساخت', $html);
        // و رندرِ ایمیل نباید locale پروسه را عوض‌شده رها کند
        $this->assertSame('fa', app()->getLocale());
    }

    /**
     * مقدارِ متغیرها هم باید بین‌المللی شود: فراخوان‌ها مبلغ را با رقمِ فارسی و
     * «تومان» می‌سازند و بدونِ نرمال‌سازی، ایمیلِ انگلیسی با «۲۵۰٬۰۰۰ تومان»
     * می‌رفت — یعنی همان فارسی، فقط با جملهٔ انگلیسی دورش.
     */
    public function test_persian_digits_and_currency_words_are_normalized_for_intl_customers(): void
    {
        Mail::fake();

        $customer = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'intl'.random_int(1, 99999).'@example.com',
            'phone' => '+90532'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'en',
        ]);

        app(CustomerNotifier::class)->templated($customer, 'expiring', [
            'service' => 'CV-2-4',
            'days'    => fa_num(3),
            'amount'  => fa_num(number_format(250000)).' تومان',
            'link'    => 'https://console.servernet.cloud/account/invoices',
        ], 'متنِ فارسیِ پشتیبان');

        Mail::assertSent(TemplateMail::class, function (TemplateMail $mail) {
            $this->assertSame('en', $mail->locale, 'locale مشتری به قالب نرسید');
            $this->assertStringContainsString('CV-2-4', $mail->bodyHtml);
            $this->assertStringContainsString('250,000 IRT', $mail->bodyHtml);
            $this->assertStringContainsString('3 day', $mail->bodyHtml);
            $this->assertFalse((bool) preg_match('/[۰-۹]|تومان/u', $mail->title.$mail->bodyHtml),
                'رقمِ فارسی یا «تومان» به ایمیلِ انگلیسی نشت کرد');

            return true;
        });
    }

    /** نتیجهٔ KYC: برنددار، به زبانِ مشتری، و بدونِ هیچ نامی از ایران */
    public function test_kyc_result_email_is_branded_localized_and_iran_free(): void
    {
        Mail::fake();
        Storage::fake('local');

        $customer = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'kyc'.random_int(1, 99999).'@example.com',
            'phone' => '+90532'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'en',
        ]);

        $this->actingAs($customer, 'customer')->post('/en/account/verify', [
            'type' => 'individual',
            'first_name' => 'Mehmet', 'last_name' => 'Yilmaz',
            'birth_date' => '1990-04-12', 'country' => 'TR',
            'address' => 'Bagdat Cd. 1', 'city' => 'Istanbul', 'id_type' => 'passport',
            'doc_passport' => UploadedFile::fake()->create('passport.pdf', 60, 'application/pdf'),
            'doc_selfie'   => UploadedFile::fake()->image('selfie.jpg'),
            'doc_address'  => UploadedFile::fake()->image('bill.png'),
        ])->assertSessionHasNoErrors();
        auth('customer')->logout();

        $profile = CustomerProfile::where('customer_id', $customer->id)->firstOrFail();

        $res = app(KycReview::class)->approve($profile, null);
        $this->assertTrue($res['ok']);

        Mail::assertSent(TemplateMail::class, function (TemplateMail $mail) {
            $this->assertSame('en', $mail->locale);
            $this->assertStringContainsString('verified', $mail->bodyHtml);
            $this->assertFalse((bool) preg_match('/iran|ایران/iu', $mail->title.$mail->bodyHtml),
                'ایمیلِ KYC مشتریِ خارجی به ایران اشاره کرد');

            return true;
        });
    }
}
