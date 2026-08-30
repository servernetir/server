<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نشانهٔ اعتماد یا **واقعی** است یا اصلاً نیست.
 *
 * ═══ چرا این تست از خودِ قابلیت مهم‌تر است ═══
 *
 * ممیزیِ ده‌مدیره گلوگاهِ سرورنت را «لایهٔ اعتماد» خواند. وسوسهٔ طبیعیِ چنین
 * نتیجه‌ای این است که فوتر «کامل» به‌نظر برسد — یک کادرِ نماد، یک «شماره ثبت:
 * —»، یک نشانیِ نصفه.
 *
 * ولی خریدارِ ایرانی نمادِ اعتماد را **کلیک می‌کند**. مهری که به صفحهٔ نامعتبر
 * برود، همان لحظه کلِ سایت را کلاهبردار می‌کند — یعنی دقیقاً برعکسِ کاری که
 * برایش گذاشته شده. و «شماره ثبت: —» صریح‌تر از سکوت می‌گوید نداریم.
 *
 * ⚠️ همان درسی که در `/status` (بی‌عددِ آپتایمِ ساختگی) و در صفحهٔ دانش
 * (وبینارِ ناموجود با دکمهٔ مرده) ثبت شده. این‌جا فقط بهایش بیشتر است.
 *
 * پس ادعای مرکزی: **با پیکربندیِ خالی، هیچ ردی از این بخش‌ها روی صفحه نیست.**
 */
class TrustSignalsNeverFakeTest extends TestCase
{
    use RefreshDatabase;

    private function clearCompany(): void
    {
        config([
            'company.legal_name' => '', 'company.registration_no' => '',
            'company.national_id' => '', 'company.economic_code' => '',
            'company.address' => ['street' => '', 'city' => '', 'province' => '', 'postcode' => '', 'country' => 'IR'],
            'company.enamad' => ['id' => '', 'code' => ''],
            'company.samandehi' => ['id' => '', 'code' => ''],
        ]);
    }

    /** 🔴 پیکربندیِ خالی ⇒ هیچ کادر، هیچ برچسب، هیچ مهر. */
    public function test_an_empty_config_renders_absolutely_nothing(): void
    {
        $this->clearCompany();

        foreach (['/', '/contact'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            foreach (['f-seals', 'f-legal', 'ct-legal', 'trustseal.enamad.ir', 'logo.samandehi.ir'] as $needle) {
                $this->assertStringNotContainsString($needle, $html,
                    "«{$needle}» با پیکربندیِ خالی روی {$path} رندر شد");
            }

            $this->assertStringNotContainsString(__('ui.trust_reg_no').':', $html,
                'برچسبِ شماره ثبت بی‌مقدار چاپ شد');
        }
    }

    /** و در دادهٔ ساختاریافته هم کلیدِ خالی نمی‌نشیند. */
    public function test_empty_config_leaves_the_schema_clean(): void
    {
        $this->clearCompany();

        $html = $this->get('/')->assertOk()->getContent();

        preg_match('~<script type="application/ld\+json">(\{"@context".*?"Organization".*?)</script>~s', $html, $m);
        $this->assertNotEmpty($m[1] ?? '', 'دادهٔ Organization پیدا نشد');

        $org = json_decode($m[1], true);

        $this->assertArrayNotHasKey('legalName', $org);
        $this->assertArrayNotHasKey('address', $org);
        $this->assertArrayNotHasKey('identifier', $org);
    }

    /** با دادهٔ واقعی، همه‌چیز می‌آید — و درست. */
    public function test_real_data_shows_up_everywhere(): void
    {
        config([
            'company.legal_name' => 'شرکت نمونه',
            'company.registration_no' => '123456',
            'company.national_id' => '10320000000',
            'company.address' => ['street' => 'خیابان آزادی، پلاک ۱', 'city' => 'تهران',
                'province' => 'تهران', 'postcode' => '1234567890', 'country' => 'IR'],
            'company.enamad' => ['id' => '111', 'code' => 'abc'],
        ]);

        $home = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('trustseal.enamad.ir', $home, 'مهرِ نماد نیامد');

        /*
        | ⚠️ این ادعا **دو بار** جهت عوض کرده و هر دو بار یک تصمیمِ بیرونی
        | پشتش بوده، نه یک تغییرِ فنی:
        |
        |   ۱) اول `f-legal` را در صفحهٔ اصلی می‌خواست (شناسه‌ها در فوتر).
        |   ۲) کارفرما گفت فقط `/contact` — ادعا وارونه شد.
        |   ۳) ممیزی ۶ (حقوقی) آن را برگرداند: افشا الزامِ قانونی است و فوترِ
        |      سراسری هر ۵۶۷ صفحه را پوشش می‌دهد؛ `/contact` تنها شکننده است.
        |   ۴) شهریور ۱۴۰۵ کارفرما تصمیمِ گامِ ۲ را نگه داشت. بلوک برداشته شد.
        |
        | 🔴 و توجه: «در فوتر نیست» یعنی سراسری نیست، نه اینکه افشا نداریم.
        | ادعای `/contact` سه خط پایین‌تر همان را نگه می‌دارد.
        | `CompanyIdentityFromPanelTest::test_the_footer_shows_neither_identity_nor_address`
        | همین را از سمتِ مقابل قفل می‌کند و تاریخچهٔ کامل آن‌جاست.
        |
        | ⚠️ و ادعایی که زیرِ **هر سه** حالت درست می‌مانَد جای دیگری است و
        | دست‌نخورده ماند: با پیکربندیِ خالی هیچ جای‌نگهداری رندر نشود
        | (`test_empty_config_leaves_the_schema_clean` و همسایه‌اش).
        |
        | 🔴 مهرِ نماد مستقل از این جدال است: مهر نشانی **دیداری** است که در
        | لحظهٔ خرید باید دیده شود، ولی شمارهٔ ثبت چیزی است که کاربر یک‌بار و
        | آگاهانه دنبالش می‌گردد.
        */
        $this->assertStringNotContainsString('f-legal', $home, 'شناسه‌های ثبتی به فوتر برگشتند');

        $this->assertStringContainsString('123456',
            $this->get('/contact')->assertOk()->getContent(),
            'شناسه‌های ثبتی روی صفحهٔ تماس نیامدند');

        $org = json_decode(
            preg_match('~<script type="application/ld\+json">(\{"@context".*?"Organization".*?)</script>~s', $home, $m) ? $m[1] : '{}',
            true);

        $this->assertSame('شرکت نمونه', $org['legalName'] ?? null);
        $this->assertSame('تهران', $org['address']['addressLocality'] ?? null);
        $this->assertContains('10320000000', (array) ($org['identifier'] ?? []));

        $contact = $this->get('/contact')->assertOk()->getContent();
        $this->assertStringContainsString('ct-legal', $contact);
    }

    /**
     * 🔴 مهرِ نیمه‌ساخته اصلاً ساخته نمی‌شود.
     *
     * با `id` بی‌`code`، آدرسِ تأیید به صفحهٔ نامعتبرِ نماد می‌رود — بدترین
     * حالتِ ممکن برای چیزی که کارش اثباتِ اعتبار است.
     */
    public function test_a_half_configured_seal_is_not_rendered(): void
    {
        $this->clearCompany();
        config(['company.enamad' => ['id' => '111', 'code' => '']]);

        $this->assertSame([], trust_seals());
        $this->assertStringNotContainsString('trustseal.enamad.ir',
            $this->get('/')->assertOk()->getContent());
    }

    /**
     * ⚠️ کشور به‌تنهایی «نشانی» نیست.
     *
     * پیش‌فرضِ `IR` همیشه پر است؛ اگر شرطِ خالی‌بودن روی کلِ آرایه بود، فوتر
     * برای همیشه «ایران» را به‌عنوانِ نشانی نشان می‌داد.
     */
    public function test_a_country_alone_is_not_an_address(): void
    {
        $this->clearCompany();

        $this->assertNull(company_address());

        config(['company.address' => ['street' => 'خیابان الف', 'city' => '', 'country' => 'IR']]);
        $this->assertNull(company_address(), 'نشانیِ بی‌شهر هم نشانی نیست');
    }

    /**
     * ⚠️ CSP اسکریپت و آی‌فریمِ بیرونی را بی‌صدا بلاک می‌کند، ولی `img-src`
     * هر https را می‌پذیرد. پس مهر باید **تصویری** بماند.
     *
     * اگر روزی کسی کدِ اسکریپتیِ نماد را بچسباند، چیزی رندر نمی‌شود و هیچ
     * خطایی هم نمی‌آید — همان تلهٔ ثبت‌شده در CLAUDE.md.
     */
    public function test_the_seal_stays_an_image_because_csp_blocks_scripts(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertDoesNotMatchRegularExpression('~script-src[^;]*enamad~', $csp,
            'اگر روزی enamad به script-src اضافه شد، این تصمیم باید آگاهانه بازبینی شود');

        $this->assertMatchesRegularExpression('~img-src[^;]*https:~', $csp,
            'مهرِ تصویری بی‌اجازهٔ img-src رندر نمی‌شود');
    }
}
