<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * هیچ صفحهٔ عمومی نباید دادهٔ ساختگی با نامِ آدمِ واقعی نشان دهد.
 *
 * ═══ چیزی که این تست از آن آمد ═══
 *
 * ممیزیِ بیرونی پیدا کرد که `/panel-preview` روی سایتِ زنده **۲۰۰** می‌دهد،
 * بی‌هیچ احراز هویتی، و چون در closureِ `$site` ثبت شده بود در هر سه زبان.
 * روی صفحه: نامِ کاملِ مالکِ شرکت، «اعتبار حساب ۴۲۰٬۰۰۰ تومان»، «فاکتور
 * #۱۴۰۴۰۸۸۲ — ۱٬۲۹۰٬۰۰۰ تومان» و آی‌پی‌های واقعی‌نما. تاریخ‌های داخلش مالِ
 * ۱۴۰۴ بود: بیش از یک سال رها شده.
 *
 * ترکیبِ نامِ واقعی با فاکتورِ جعلی، نمایشِ یک رابطهٔ مالی است که وجود ندارد.
 *
 * 🔴 چرا هیچ‌کس ندید: صفحه نه در sitemap بود نه از جایی لینک شده — پس هر
 * خزندهٔ داخلی که فقط sitemap و لینک‌ها را دنبال کند هرگز نمی‌دیدش. تنها
 * راهِ کشفش `robots.txt` بود، که خودش با یک کامنتِ توضیحی آدرسش را می‌داد.
 *
 * ⚠️ درسِ عمومی‌تر: کنترلِ دسترسی «کسی لینکش را ندارد» نیست. هر مسیرِ عمومی
 * باید عمداً عمومی باشد.
 */
class NoPublicMockDataTest extends TestCase
{
    /**
     * نشانه‌های **ساختگی** که هیچ‌جای سایتِ عمومی جا ندارند.
     *
     * ⚠️ شمارهٔ فاکتور و آی‌پی‌ها این‌جایند و نامِ شخص نه — تفکیک عمدی است و
     * پایین توضیح داده شده.
     */
    private const FORBIDDEN = [
        '۱۴۰۴۰۸۸۲',        // شمارهٔ فاکتورِ جعلیِ صفحهٔ ماک
        '185.231.115.42',   // آی‌پیِ نمایشیِ همان صفحه
        '95.216.44.198',
    ];

    /**
     * 🔴 نامِ واقعی به‌تنهایی «نشت» نیست — مسئله **همنشینی‌اش با دادهٔ جعلی** بود.
     *
     * صفحهٔ ماک نامِ مالک را کنارِ فاکتور و اعتبارِ ساختگی می‌گذاشت و عملاً یک
     * رابطهٔ مالیِ ناموجود را نمایش می‌داد. ولی همان نام روی `/webdesign` —
     * صفحهٔ فرودِ شخصیِ خودش برای «طراحی سایت در ارومیه» — کاملاً بجاست و
     * اتفاقاً باید آن‌جا باشد.
     *
     * ⚠️ اگر این استثنا را نمی‌گذاشتم، تست روی یک صفحهٔ سالم قرمز می‌ماند و
     * نفرِ بعدی به‌جای رفعِ نشت، خودِ تست را خاموش می‌کرد. نگهبانی که هشدارِ
     * دروغ بدهد، دیر یا زود ساکت می‌شود.
     */
    private const NAME = 'احسان ابراهیمی';

    private const NAME_ALLOWED_ON = ['webdesign', 'en/webdesign', 'tr/webdesign'];

    // ═══════════════ ۱) خودِ صفحهٔ ماک ═══════════════

    /**
     * 🔴 ۴۱۰ و نه ۴۰۴ و نه ۲۰۰.
     *
     * آدرس‌ها ممکن است ایندکس شده باشند؛ ۴۱۰ به خزنده می‌گوید «برای همیشه
     * رفت» و حذفشان را سریع‌تر می‌کند، در حالی که ۴۰۴ یعنی «الان نیست» و
     * ماه‌ها دوباره امتحان می‌شود.
     */
    public function test_the_mock_panel_is_gone_in_every_language(): void
    {
        foreach ([
            '/panel-preview',
            '/panel-preview/server',
            '/panel-preview/admin',
            '/panel-preview/tickets',
            '/panel-preview/admin/tickets',
            '/en/panel-preview',
            '/en/panel-preview/admin',
            '/tr/panel-preview',
            '/tr/panel-preview/tickets',
        ] as $url) {
            $this->get($url)->assertStatus(410);
        }
    }

    public function test_the_mock_controller_and_views_are_actually_deleted(): void
    {
        $this->assertFileDoesNotExist(app_path('Http/Controllers/PanelPreviewController.php'));

        foreach (['dashboard', 'server', 'tickets', 'admin', 'admin-tickets'] as $v) {
            $this->assertFileDoesNotExist(resource_path("views/panel/{$v}.blade.php"),
                "ویوِ ماکِ «{$v}» هنوز هست — مسیرش بسته شده ولی فایل مانده");
        }

        // ⚠️ اینها مالِ پنلِ **واقعی**‌اند و نباید حذف شده باشند
        foreach (['layout', 'avatar', 'bank-mark'] as $v) {
            $this->assertFileExists(resource_path("views/panel/{$v}.blade.php"),
                "«{$v}» را پنلِ واقعی استفاده می‌کند و نباید حذف می‌شد");
        }
    }

    // ═══════════════ ۲) robots.txt ═══════════════

    /**
     * robots.txt کنترلِ دسترسی نیست؛ یک فایلِ **عمومی** است.
     *
     * کامنتِ توضیحیِ داخلش دقیقاً فهرست می‌کرد چه چیزی ارزشِ پنهان‌کردن داشته
     * — و صریح می‌گفت داخلِ آن صفحه «دادهٔ جعلی و نامِ یک شخصِ واقعی» هست.
     */
    public function test_robots_txt_carries_no_internal_notes(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringNotContainsString('#', $robots,
            'robots.txt نباید هیچ کامنتی داشته باشد — توضیحات جای دیگری می‌مانند');

        $this->assertStringNotContainsString('panel-preview', $robots,
            'مسیرِ حذف‌شده نباید در robots.txt فهرست شود؛ Disallow فقط تبلیغش می‌کند');

        // ساختارِ لازم دست‌نخورده مانده باشد
        $this->assertStringContainsString('Sitemap:', $robots);
        $this->assertStringContainsString('Disallow: /system/', $robots);
    }

    // ═══════════════ ۳) قاعدهٔ عمومی ═══════════════

    /**
     * 🔴 هیچ صفحهٔ عمومیِ **بی‌احراز هویتی** نباید این نشانه‌ها را چاپ کند.
     *
     * ⚠️ عمداً روی همهٔ روت‌های GETِ بدونِ پارامتر می‌دود، نه روی فهرستِ دستی.
     * فهرستِ دستی همان چیزی است که یک بار جا افتاد: `/panel-preview` در هیچ
     * فهرستی نبود، نه sitemap و نه لینک‌های سایت.
     */
    public function test_no_public_page_prints_mock_data(): void
    {
        $checked = 0;
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            // فقط مسیرهای ثابت و عمومی — پارامتردار و پنل و ادمین کنار می‌روند
            if (str_contains($uri, '{') || preg_match('~^(admin|account|api|system)(/|$)~', $uri)) {
                continue;
            }
            if (! empty(array_intersect(['auth', 'admin', 'customer'], $route->gatherMiddleware()))) {
                continue;
            }

            $res = $this->get('/'.ltrim($uri, '/'));
            if ($res->getStatusCode() !== 200) {
                continue;
            }
            $checked++;
            $html = $res->getContent();

            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($html, $needle)) {
                    $offenders[] = "/{$uri} → «{$needle}»";
                }
            }

            if (str_contains($html, self::NAME) && ! in_array($uri, self::NAME_ALLOWED_ON, true)) {
                $offenders[] = "/{$uri} → نامِ شخصِ واقعی روی صفحه‌ای که موضوعش او نیست";
            }
        }

        $this->assertGreaterThan(20, $checked, 'تعداد صفحاتِ سنجیده‌شده کم است — پیمایش کار نکرده');
        $this->assertSame([], $offenders,
            "دادهٔ ساختگی/شخصی روی صفحهٔ عمومی:\n".implode("\n", $offenders));
    }
}
