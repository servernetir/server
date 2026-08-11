<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحهٔ تنظیمات نباید `<form>`ِ تودرتو داشته باشد.
 *
 * ═══ باگی که این تست از آن آمد ═══
 *
 * کارفرما: «۵ را ۱۰۰ کردم، روی ذخیره کلیک می‌کنم هیچ اتفاقی نمی‌افتد.»
 *
 * علت: یک `<form>`ِ «قطعِ اتصالِ تقویمِ گوگل» **وسطِ** فرمِ اصلی نشسته بود. HTML
 * فرمِ تودرتو را نمی‌پذیرد؛ مرورگر تا `<form>`ِ داخلی را ببیند، فرمِ **بیرونی
 * را همان‌جا می‌بندد**. پس دکمهٔ «ذخیره» — که پایین‌تر بود — به هیچ فرمی وصل
 * نبود و کلیک روی آن واقعاً هیچ کاری نمی‌کرد: نه درخواستی، نه خطایی.
 *
 * یعنی **هیچ‌کدام** از تنظیماتِ آن صفحه ذخیره نمی‌شد: نه توکنِ زیرساخت‌ها، نه
 * سقفِ محافظِ سرور، هیچ‌کدام.
 *
 * ⚠️ چرا هیچ‌کدام از ۱۸۸۵ تست نگرفتش: همه `POST /admin/settings` را **مستقیم**
 * می‌زنند و کنترلر کاملاً سالم است. آنچه شکسته بود رابطهٔ دکمه با فرم در
 * **مرورگر** بود. همان قاعدهٔ ثبت‌شدهٔ پروژه: «کدِ ۲۰۰ یعنی هیچ» — و این بار
 * حتی ۲۰۰ هم نبود، چون اصلاً درخواستی نمی‌رفت.
 *
 * ⚠️ درسِ عمومی‌تر: تستی که فقط نقطهٔ پایانی را می‌زند، **هرگز** نمی‌فهمد که
 * کاربر راهی برای رسیدن به آن نقطه ندارد.
 */
class AdminSettingsFormNestingTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $tab = 'general'): string
    {
        $admin = User::create([
            'name' => 'مدیر', 'email' => 'st'.random_int(1000, 9999).'@example.test',
            'password' => bcrypt('secret-for-test'), 'role' => 'admin',
        ]);

        return $this->actingAs($admin)->get('/admin/settings?tab='.$tab)->assertOk()->getContent();
    }

    /**
     * ⚠️ از وقتی صفحه تب‌بندی شد، «صفحهٔ تنظیمات» یک HTML نیست بلکه هفت‌تاست.
     * ادعای تودرتویی باید روی **همه**شان برود، وگرنه فردا یک فرمِ تودرتو در
     * تبی که این تست نمی‌بیند اضافه می‌شود و همان باگ از درِ دیگر برمی‌گردد.
     */
    public static function tabs(): array
    {
        return array_map(fn ($t) => [$t], array_keys(
            \App\Http\Controllers\Admin\SettingsController::TABS
        ));
    }

    /**
     * 🔴 هستهٔ ادعا: هیچ `<form>`ای پیش از بسته‌شدنِ فرمِ قبلی باز نشود.
     *
     * ⚠️ عمداً روی **همهٔ** فرم‌های صفحه ادعا می‌کند، نه فقط آن یکی که شکسته
     * بود. اگر فردا کسی فرمِ تازه‌ای وسطِ فرمِ اصلی بگذارد، همین تست می‌گیردش —
     * وگرنه باگ از درِ دیگر برمی‌گردد و باز هم بی‌صدا.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tabs')]
    public function test_no_form_is_nested_inside_another(string $tab): void
    {
        $html = $this->page($tab);

        preg_match_all('~<form\b|</form>~i', $html, $m, PREG_OFFSET_CAPTURE);

        $depth = 0;

        foreach ($m[0] as [$tag, $pos]) {
            if (stripos($tag, '</') === 0) {
                $depth--;

                continue;
            }

            $depth++;

            $this->assertLessThanOrEqual(1, $depth,
                'یک <form> داخلِ <form>ِ دیگر باز شد (نویسه '.$pos.'). '
                .'مرورگر فرمِ بیرونی را همان‌جا می‌بندد و دکمهٔ ذخیره بی‌اثر می‌شود.');
        }

        $this->assertSame(0, $depth, 'تعدادِ <form> و </form> نمی‌خواند');
    }

    /**
     * ⚠️ نیمهٔ دوم: دکمهٔ ذخیره باید واقعاً **به فرمِ تنظیمات وصل** باشد.
     *
     * بی‌این، کسی می‌توانست فرمِ تودرتو را با بردنِ دکمه به بیرون «حل» کند و
     * دکمه‌ای بسازد که باز هم هیچ‌جا پست نمی‌کند.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tabs')]
    public function test_the_save_button_belongs_to_the_settings_form(string $tab): void
    {
        // تب‌هایی که فیلدِ `Setting` ندارند فرمِ تنظیمات هم ندارند و درست است:
        // هزینه‌ها و پیام‌ها به مسیرِ خودشان POST می‌کنند، راهنما فقط متن است.
        if (\App\Http\Controllers\Admin\SettingsController::fieldsFor($tab) === []) {
            $this->markTestSkipped('تبِ '.$tab.' فرمِ تنظیمات ندارد');
        }

        $html = $this->page($tab);

        // فرمِ تنظیمات را جدا کن و مطمئن شو دکمهٔ ذخیره داخلش است
        $ok = preg_match(
            '~<form[^>]*action="/admin/settings"[^>]*>(.*?)</form>~is',
            $html, $m
        );

        $this->assertSame(1, $ok, 'فرمِ تنظیماتِ تبِ '.$tab.' پیدا نشد');
        $this->assertStringContainsString('type="submit"', $m[1],
            'دکمهٔ ذخیرهٔ تبِ '.$tab.' بیرونِ فرم افتاده — کلیک روی آن هیچ کاری نمی‌کند');
    }

    /** دکمهٔ قطعِ اتصال باید با `form=` به فرمِ بیرونیِ خودش وصل باشد */
    public function test_the_disconnect_button_targets_its_own_outer_form(): void
    {
        $html = $this->page();

        /*
         * ⚠️ شرط روی **دکمه** است نه روی فرم: فرمِ خالی همیشه رندر می‌شود (بی‌ضرر
         * و بیرونِ فرمِ اصلی)، ولی دکمه فقط وقتی می‌آید که تقویمِ گوگل واقعاً وصل
         * باشد. نسخهٔ اولِ همین تست وجودِ `gcal-disconnect` را می‌سنجید و روی
         * نصبِ بی‌گوگل قرمز می‌شد — یعنی تستی که وضعیتِ فیکسچر را با باگ اشتباه
         * می‌گرفت.
         */
        if (! str_contains($html, 'قطع اتصال')) {
            $this->markTestSkipped('تقویمِ گوگل روی این نصب وصل نیست، پس دکمه رندر نمی‌شود');
        }

        $this->assertStringContainsString('id="gcal-disconnect"', $html);
        $this->assertStringContainsString('form="gcal-disconnect"', $html);
    }
}
