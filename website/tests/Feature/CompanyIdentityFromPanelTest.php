<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * هویتِ حقوقی از **پنلِ مدیریت** وارد می‌شود، نه از `.env`.
 *
 * برداشتنِ شمارهٔ ثبت و شناسهٔ ملی از روزنامهٔ رسمی کارِ اداری است نه دیپلوی،
 * و کسی که آن‌ها را دارد لزوماً به `.env` سرور دسترسی ندارد.
 *
 * ⚠️ `.env` عمداً راهِ دوم می‌مانَد تا روی نصبی که جدولِ `settings` ندارد هم
 * کار کند.
 */
class CompanyIdentityFromPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** مقدارِ پنل بر `.env` می‌چربد و روی صفحهٔ تماس می‌آید. */
    public function test_the_panel_value_wins_and_shows_on_the_contact_page(): void
    {
        config(['company.legal_name' => 'FROM-ENV', 'company.registration_no' => '111']);

        Setting::put('company_legal_name', 'شرکت آزمون (سهامی خاص)');
        Setting::put('company_reg_no', '552134');

        $html = $this->get('/contact')->assertOk()->getContent();

        $this->assertStringContainsString('شرکت آزمون (سهامی خاص)', $html);
        $this->assertStringNotContainsString('FROM-ENV', $html, 'مقدارِ .env بر مقدارِ پنل چربید');
    }

    /** بی‌مقدارِ پنل، `.env` می‌مانَد. */
    public function test_env_is_still_the_fallback(): void
    {
        config(['company.national_id' => '10320123456']);

        $rows = collect(company_identity())->pluck('value')->all();

        $this->assertContains('10320123456', $rows);
    }

    /**
     * 🔴 مقدارِ پرنشده هیچ‌جا رندر نمی‌شود.
     *
     * خطرِ واقعی این است که کسی برای «کامل دیده‌شدنِ» صفحه جای‌نگهدار بگذارد.
     * شمارهٔ ثبتِ ساختگی از نداشتنش بدتر است.
     */
    public function test_nothing_is_invented_when_the_panel_is_empty(): void
    {
        config(['company' => ['address' => []]]);

        $this->assertSame([], company_identity());
        $this->assertNull(company_address());
    }

    /**
     * 🔴 نشانیِ نیمه‌پر یعنی هیچ نشانی.
     *
     * «تهران» به‌تنهایی نشانی نیست و در schema یک `PostalAddress`ِ ناقص
     * می‌سازد که از نبودنش بدتر است.
     */
    public function test_a_half_filled_address_is_no_address(): void
    {
        Setting::put('company_city', 'تهران');

        $this->assertNull(company_address(), 'نشانی بی‌خیابان ساخته شد');

        Setting::put('company_address', 'خیابان ولیعصر، پلاک ۱');

        $this->assertNotNull(company_address());
        $this->assertStringContainsString('تهران', company_address());
    }

    /**
     * شناسه‌های ثبتی و نشانی **در فوتر می‌آیند** — و فقط وقتی پر شده باشند.
     *
     * ═══ ⚠️ این تست یک تصمیم را برگرداند، پس تاریخچه‌اش این‌جا می‌مانَد ═══
     *
     * نسخهٔ قبلی نامش `test_the_footer_shows_neither_identity_nor_address`
     * بود و عکسِ این را قفل می‌کرد، با استنادِ صریح به «تصمیمِ کارفرما».
     * بعد ممیزی ۶ (حقوقی) آن تصمیم را با این استدلال کنار گذاشت: افشای
     * شناسهٔ ثبتی الزامِ قانونی است و راه‌حلِ استاندارد، فوترِ سراسری است که
     * هر ۵۶۷ صفحه را پوشش می‌دهد؛ `/about` تنها کافی ولی شکننده است.
     *
     * 🔴 **این تعارض هنوز به تأییدِ کارفرما نرسیده.** کامنتِ خودِ فوتر صریح
     * می‌گوید «اگر نپذیرفت، همین یک بلوک را بردار». تست به رفتارِ **امروزِ**
     * کد به‌روز شد تا نگهبان قرمزِ دائمی نباشد — نه چون تصمیم قطعی شده.
     * اگر کارفرما تصمیمِ اولش را نگه دارد، بلوکِ `f-legal` در
     * `partials/footer.blade.php` برداشته می‌شود و این تست هم برمی‌گردد.
     *
     * ⚠️ پاک‌کردنِ خودِ افشای حقوقی برای سبزکردنِ یک تستِ کهنه، جهتِ خطرناک‌ترِ
     * این تعارض بود؛ برای همین این طرف اصلاح شد و آن طرف نه.
     */
    public function test_the_footer_shows_the_registered_identity_when_it_is_filled(): void
    {
        Setting::put('company_legal_name', 'شرکت آزمون (سهامی خاص)');
        Setting::put('company_reg_no', '552134');
        Setting::put('company_address', 'خیابان ولیعصر، پلاک ۱');
        Setting::put('company_city', 'تهران');

        // صفحه‌ای که بخشِ «هویتِ حقوقی»ِ خودش را ندارد، تا تشخیص قطعی باشد
        $html = $this->get('/about')->assertOk()->getContent();
        $footer = substr($html, (int) strrpos($html, '<footer'));

        $this->assertStringContainsString('f-legal', $footer);
        $this->assertStringContainsString('خیابان ولیعصر', $footer, 'نشانی در فوتر نیست');
    }

    /**
     * 🔴 و مهم‌تر از اینکه کدام تصمیم برنده شود: **جای‌نگهدار هرگز رندر نشود.**
     *
     * این ادعا زیرِ هر دو سیاست درست است و تنها چیزی است که واقعاً به مشتری
     * آسیب می‌زند — فوترِ سراسری با «شمارهٔ ثبت: —» روی ۵۶۷ صفحه، به‌جای
     * اعتماد، بی‌دقتی می‌فروشد.
     */
    public function test_the_footer_stays_silent_while_the_panel_is_empty(): void
    {
        $html = $this->get('/about')->assertOk()->getContent();
        $footer = substr($html, (int) strrpos($html, '<footer'));

        $this->assertStringNotContainsString('f-legal', $footer,
            'بلوکِ هویت بی‌آنکه مدیر چیزی وارد کرده باشد رندر شد');
    }

    /**
     * 🔴 و در JSON-LD **می‌آیند** — همان‌جا که واقعاً کار می‌کنند.
     *
     * ⚠️ این تست لازم بود: دادهٔ ساختاریافته مستقیم `config()` می‌خواند، پس
     * مقدارِ پنل به آن نمی‌رسید. مدیر مقدار را وارد می‌کرد، روی صفحهٔ تماس
     * می‌دیدش، و در schema هیچ نبود — بی‌هیچ خطایی.
     */
    public function test_the_panel_values_reach_the_structured_data(): void
    {
        Setting::put('company_legal_name', 'شرکت آزمون (سهامی خاص)');
        Setting::put('company_national_id', '10320123456');
        Setting::put('company_address', 'خیابان ولیعصر، پلاک ۱');
        Setting::put('company_city', 'تهران');
        Setting::put('company_postcode', '1234567890');

        $html = $this->get('/')->assertOk()->getContent();

        preg_match('~<script type="application/ld\+json">(.+?)</script>~s', $html, $m);
        $this->assertNotEmpty($m, 'هیچ JSON-LD روی صفحهٔ اصلی نیست');

        $org = json_decode($m[1], true);

        $this->assertSame('شرکت آزمون (سهامی خاص)', $org['legalName'] ?? null);
        $this->assertSame('10320123456', $org['identifier'] ?? null);
        $this->assertSame('خیابان ولیعصر، پلاک ۱', $org['address']['streetAddress'] ?? null);
        $this->assertSame('تهران', $org['address']['addressLocality'] ?? null);
        $this->assertSame('IR', $org['address']['addressCountry'] ?? null);
    }

    /** فرمِ تنظیمات ذخیره می‌کند و مقدار را به فرم برمی‌گرداند. */
    public function test_the_settings_form_saves_and_shows_them_back(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/settings', [
                'tab'                 => 'general',
                'company_legal_name'  => 'شرکت آزمون (سهامی خاص)',
                'company_national_id' => '10320123456',
            ])
            ->assertRedirect();

        $this->assertSame('شرکت آزمون (سهامی خاص)', Setting::get('company_legal_name'));

        $html = $this->actingAs($this->admin())
            ->get('/admin/settings?tab=general')->assertOk()->getContent();

        $this->assertStringContainsString('10320123456', $html);
    }

    /**
     * ⚠️ خالی فرستادن یعنی **پاک کردن**، نه «بدونِ تغییر».
     *
     * برخلافِ رازها. اگر الگوی `filled()` را کپی می‌کردم، مدیر هرگز نمی‌توانست
     * مقدارِ غلط را بردارد.
     */
    public function test_clearing_a_field_really_clears_it(): void
    {
        Setting::put('company_reg_no', '552134');

        $this->actingAs($this->admin())
            ->post('/admin/settings', ['tab' => 'general', 'company_reg_no' => ''])
            ->assertRedirect();

        $this->assertSame('', (string) Setting::get('company_reg_no', ''));
    }
}
