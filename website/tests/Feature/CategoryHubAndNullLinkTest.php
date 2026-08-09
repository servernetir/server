<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دو ۴۰۴ِ واقعی از ردیابِ خطا — و چرا هرکدام درمانِ متفاوتی داشت.
 *
 * ═══ ۱) `/vps` و `/hosting` ═══
 *
 * ترافیکِ واقعی داشتند و روت نداشتند. جستجوی کلِ مخزن — هم ایستا و هم با
 * رندرِ صفحات و برداشتِ همهٔ hrefها — نشان داد **هیچ لینکی در سایت به آنها
 * نمی‌رود**. پس «لینک را درست کن» مقصدی نداشت؛ چیزی که کم بود خودِ روت بود.
 * منبعِ ترافیک بیرونی است: ۳۰۱های `servernet.ir` (که `config/legacy.php` هر
 * مسیرِ نگاشته‌نشده را عیناً به این‌جا می‌فرستد) و کاربری که آدرسِ بدیهیِ
 * دسته‌ای را حدس می‌زند که خودِ هدر نامش را می‌بَرد ولی تبش `<button>` است.
 *
 * ═══ ۲) `/null`، `/cloud/null`، `/servers/null` ═══
 *
 * شکلِ آدرس‌ها ثابت می‌کند لینک یک href **نسبیِ** «null» بوده — چون دقیقاً
 * `dirname(referer) + "/null"`اند. در جاوااسکریپت این یک الگوی مشخص است:
 * `getAttribute('href')` وقتی ویژگی نباشد `null` می‌دهد و
 * `setAttribute(name, null)` آن را به **رشتهٔ «null»** تبدیل می‌کند.
 * (`href` عضوِ `[LegacyNullToEmptyString]` نیست.)
 *
 * ⚠️ این تست فایلِ ایستای جاوااسکریپت را می‌خوانَد، چون آن کد فقط در مرورگر
 * اجرا می‌شود و هیچ تستِ سروری اجرایش نمی‌کند — همان «کدِ ۲۰۰ ولی صفحهٔ مرده».
 */
class CategoryHubAndNullLinkTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['fa' => '', 'en' => '/en', 'tr' => '/tr'];

    // ═══════════════════════ هابِ دسته‌ها ═══════════════════════

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function hubs(): array
    {
        return [
            '/hosting' => ['/hosting', '/hosting/linux'],
            '/vps'     => ['/vps', '/cloud'],
            '/domain'  => ['/domain', '/domains'],
        ];
    }

    /**
     * 🔴 هر سه هاب باید در **هر سه زبان** ۳۰۱ بدهند، و مقصد باید پیشوندِ
     * همان زبان را داشته باشد.
     *
     * ادعای دوم مهم‌تر از اولی است: `Route::redirect()` مقصدش را در **لحظهٔ
     * ثبت** حساب می‌کند و این closure سه بار ثبت می‌شود، پس با آن، هر سه
     * نسخه به آدرسِ فارسی می‌رفتند و مشتریِ انگلیسی وسطِ کار زبانش عوض می‌شد.
     *
     * @dataProvider hubs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hubs')]
    public function test_a_bare_category_url_redirects_in_every_language(string $hub, string $target): void
    {
        foreach (self::LOCALES as $loc => $prefix) {
            $res = $this->get($prefix.$hub);

            $this->assertSame(301, $res->getStatusCode(),
                "{$loc}: {$prefix}{$hub} باید ۳۰۱ بدهد، نه ".$res->getStatusCode());

            $res->assertRedirect(url($prefix.$target));

            // و مقصد باید واقعاً باز شود — ۳۰۱ به ۴۰۴ بدتر از ۴۰۴ است
            $this->get($prefix.$target)->assertOk();
        }
    }

    /** حروفِ بزرگ نباید ناخواسته به این هاب‌ها بخورد (روتِ locale دو حرفی است) */
    public function test_the_hubs_do_not_swallow_unrelated_paths(): void
    {
        $this->get('/hosting/linux')->assertOk();
        $this->get('/cloud')->assertOk();
        $this->get('/domains')->assertOk();
    }

    // ═══════════════════════ لینکِ /null ═══════════════════════

    /**
     * الگوی `setAttribute('href', X.getAttribute('href'))` نباید برگردد.
     *
     * ⚠️ ادعا روی **الگو** است نه روی خروجی، چون خروجی فقط در مرورگر ساخته
     * می‌شود. اگر روزی کسی «ساده‌سازی» کند و گارد را بردارد، همان ۴۰۴ها
     * برمی‌گردند و هیچ خطایی هم تولید نمی‌شود.
     */
    public function test_no_javascript_writes_a_raw_getattribute_result_into_an_href(): void
    {
        foreach (glob(public_path('assets/js/*.js')) ?: [] as $file) {
            $js = file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                "~setAttribute\(\s*'href'\s*,\s*[A-Za-z_$][\w$]*\.getAttribute\(~",
                $js,
                basename($file).': مقدارِ خامِ getAttribute در href نوشته می‌شود — '
                .'اگر ویژگی نباشد، رشتهٔ «null» می‌نشیند و /cloud/null می‌سازد'
            );
        }
    }

    /** و گاردِ واقعی باید سرِ جایش باشد */
    public function test_the_city_chip_guards_a_missing_href(): void
    {
        $js = file_get_contents(public_path('assets/js/site.js'));

        $this->assertMatchesRegularExpression(
            '~var href = chip\.getAttribute\(\'href\'\);\s*\n\s*if \(buy && href\)~',
            $js,
            'گاردِ چیپِ شهر برداشته شده — دکمهٔ خرید دوباره به /cloud/null می‌رود'
        );
    }

    /**
     * همان کلاسِ باگ با رشتهٔ «undefined»: `dataset.urlY` که نباشد،
     * `a.href = undefined` آدرسِ `/undefined` می‌سازد.
     */
    public function test_the_billing_toggle_never_writes_undefined_into_a_buy_link(): void
    {
        $js = file_get_contents(public_path('assets/js/site.js'));

        $this->assertStringNotContainsString('a.href = yearly ? a.dataset.urlY : a.dataset.urlM;', $js);
        $this->assertMatchesRegularExpression('~if \(u\) \{ a\.href = u; \}~', $js);
    }

    /** و لینکِ اکشنِ ویجتِ گفتگو بدونِ آدرس اصلاً ساخته نمی‌شود */
    public function test_the_chat_widget_skips_actions_without_a_url(): void
    {
        $js = file_get_contents(public_path('assets/js/site.js'));

        $this->assertStringContainsString("typeof a.url !== 'string'", $js,
            'اکشنِ بی‌آدرس باید رد شود، نه اینکه لینکی به «null» بسازد');
    }

    /**
     * ⚠️ خودِ آدرسِ `/null` هنوز ۴۰۴ است و باید باشد — این تست فقط ثابت
     * می‌کند که چنین صفحه‌ای عمداً وجود ندارد، تا کسی برای «رفعِ ۴۰۴» یک
     * ریدایرکتِ سرپوش‌گذارانه نسازد و علتِ واقعی پنهان بماند.
     */
    public function test_slash_null_is_still_a_genuine_404(): void
    {
        $this->get('/null')->assertNotFound();
        $this->get('/cloud/null')->assertNotFound();
        $this->get('/servers/null')->assertNotFound();
    }
}
