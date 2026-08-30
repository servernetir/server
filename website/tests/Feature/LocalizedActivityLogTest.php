<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لاگِ فعالیتِ مشتری به زبانِ خودش — و ترجمهٔ ردیف‌های فارسیِ قدیمی.
 *
 * ═══ چرا (۶ شهریور ۱۴۰۵) ═══
 *
 * کارفرما: «هنوز فعالیت‌های کاربر توی حسابِ انگلیسی فارسی است.» ریشه دو چیز
 * بود: نویسنده‌های زیادی متنِ فارسیِ سخت‌کد می‌نوشتند، و ردیف‌های قدیمی متنِ
 * ذخیره‌شده بودند. حالا نویسنده‌ها از کلیدهای ui.act_* می‌نویسند و مهاجرتِ
 * 000102 ردیف‌های قدیمیِ مشتریانِ خارجی را ترجمه می‌کند.
 */
class LocalizedActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $locale): Customer
    {
        return Customer::create([
            'email' => 'al'.random_int(1, 999999).'@example.com',
            'phone' => '+9053'.random_int(10000000, 99999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => $locale,
        ]);
    }

    /** 🔴 هیچ کلیدِ act_* در فایل‌های en/tr نباید حرفِ فارسی داشته باشد */
    public function test_activity_keys_exist_in_all_three_languages_without_persian_leftovers(): void
    {
        $fa = require base_path('lang/fa/ui.php');

        foreach (['en', 'tr'] as $locale) {
            $dict = require base_path('lang/'.$locale.'/ui.php');

            foreach ($fa as $key => $faVal) {
                if (! str_starts_with($key, 'act_') && ! str_starts_with($key, 'pay_desc_')) {
                    continue;
                }
                $this->assertArrayHasKey($key, $dict, "کلیدِ {$key} در {$locale} نیست.");
                $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', (string) $dict[$key],
                    "کلیدِ {$key} در {$locale} حرفِ فارسی دارد.");

                // جای‌نگهدارها باید در هر سه زبان یکی باشند
                preg_match_all('/:\w+/', (string) $faVal, $faVars);
                preg_match_all('/:\w+/', (string) $dict[$key], $locVars);
                sort($faVars[0]);
                sort($locVars[0]);
                $this->assertSame($faVars[0], $locVars[0], "جای‌نگهدارهای {$key} در {$locale} با fa نمی‌خوانَد.");
            }
        }
    }

    /** 🔴 مهاجرتِ داده، لاگ‌های فارسیِ قدیمیِ مشتریِ خارجی را ترجمه می‌کند */
    public function test_the_data_migration_translates_old_persian_activity_rows(): void
    {
        $en = $this->customer('en');
        $fa = $this->customer('fa');

        $mk = fn (Customer $c, string $d) => ActivityLog::create([
            'customer_id' => $c->id, 'actor' => 'customer', 'action' => 'login', 'description' => $d,
        ]);

        $login   = $mk($en, 'ورود موفق با ایمیل');
        $pay     = $mk($en, 'پرداخت 1,200,000 تومان از طریق zarinpal انجام شد');
        $cloud   = $mk($en, 'سرورِ ابری #42 — رمزِ root تازه شد.');
        $power   = $mk($en, 'سرورِ ابری #42 — power:on');
        $kyc     = $mk($en, 'هویت تأیید شد: John Doe');
        $susp    = $mk($en, 'تعلیقِ خودکار — فاکتورِ سررسیدشده (3 روز) پرداخت نشد');
        $unknown = $mk($en, 'متنی که هیچ الگویی ندارد');
        $faRow   = $mk($fa, 'ورود موفق با ایمیل');

        $migration = require base_path('database/migrations/2026_10_04_000102_localize_foreign_activity_logs.php');
        $migration->up();

        $this->assertSame('Signed in with email', $login->fresh()->description);
        $this->assertSame('Payment of 1,200,000 Toman via zarinpal completed', $pay->fresh()->description);
        $this->assertSame('Cloud server #42 — Root password reset.', $cloud->fresh()->description);
        $this->assertSame('Cloud server #42 — power:on', $power->fresh()->description);
        $this->assertSame('Identity verified: John Doe', $kyc->fresh()->description);
        $this->assertSame('Auto-suspended — renewal invoice unpaid for 3 days', $susp->fresh()->description);

        // ناشناخته و ردیفِ مشتریِ فارسی دست نمی‌خورند
        $this->assertSame('متنی که هیچ الگویی ندارد', $unknown->fresh()->description);
        $this->assertSame('ورود موفق با ایمیل', $faRow->fresh()->description);

        // اجرای دوباره بی‌اثر است
        $migration->up();
        $this->assertSame('Signed in with email', $login->fresh()->description);
    }

    /** توضیحِ پرداخت (Payment::description) در لحظهٔ نمایش ترجمه می‌شود */
    public function test_payment_description_renders_in_the_page_language(): void
    {
        $en = $this->customer('en');
        $invoice = \App\Models\Invoice::create([
            'customer_id' => $en->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 100, 'tax' => 0, 'total' => 100, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);
        $p = \App\Models\Payment::create([
            'customer_id' => $en->id, 'invoice_id' => $invoice->id, 'gateway' => 'zarinpal',
            'amount' => 100, 'currency_code' => 'IRT', 'status' => 'paid',
        ]);

        app()->setLocale('en');
        $this->assertSame('ServerNet wallet top-up', $p->description());

        app()->setLocale('fa');
        $this->assertSame('افزایش اعتبار سرورنت', $p->description());
    }
}
