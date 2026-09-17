<?php

namespace Tests\Feature;

use App\Support\ErrorTracker;
use App\Support\LegacyUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * آدرس‌های قدیمیِ وردپرس و ۴۰۴های جامانده — `LegacyUrlResolver`.
 *
 * ═══ رخداد (شهریور ۱۴۰۵) ═══
 *
 * ردیابِ ۴۰۴ هر روز صدها ردیف داشت: نامک‌های فارسیِ وبلاگِ قدیمی
 * (`/آموزش-اتصال-درگاه-زرین-پال-در-اپلیکیشن`)، `/category/آموزش/…/page/27`،
 * و سیلِ `/null`. ریشه: موقعِ ایمپورتِ وردپرس **هر نامکِ فارسی با
 * `post-{id}` جایگزین شد**، پس هیچ آدرسِ قدیمی مقصدی نداشت و اعتبارِ همهٔ
 * لینک‌های ورودی‌شان دور ریخته می‌شد.
 *
 * نامک‌ها در تست **درصد-رمزشده** فرستاده می‌شوند — همان چیزی که خزنده و مرورگر
 * واقعاً می‌فرستند. رشتهٔ خامِ فارسی در تست یعنی تست مسیری را می‌سنجد که هیچ
 * درخواستِ واقعی از آن نمی‌آید.
 */
class LegacyUrlRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ErrorTracker::clear();
        LegacyUrlResolver::flush();
    }

    protected function tearDown(): void
    {
        ErrorTracker::clear();
        parent::tearDown();
    }

    /** مثلِ خزنده: هر بخشِ مسیر درصد-رمز می‌شود */
    private function enc(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function assertMovesTo(string $path, string $to): void
    {
        $res = $this->get($path);

        $res->assertStatus(301);
        $this->assertSame(url($to), $res->headers->get('Location'), "{$path} باید به {$to} برود");
    }

    public function test_an_old_persian_post_lands_on_its_new_slug(): void
    {
        $this->assertMovesTo($this->enc('/وی-پی-ان-یا-فیلترشکن-چیست؛-6-کاربرد-وی-پی-ان/'), '/blog/what-is-vpn');
        $this->assertMovesTo($this->enc('/آموزش-اتصال-درگاه-زرین-پال-در-اپلیکیشن'), '/blog/zarinpal-app-integration');
    }

    /** لاگِ زنده هر دو شکلِ رمزگذاری را دارد: %D8 و %d8 */
    public function test_lowercase_percent_encoding_resolves_too(): void
    {
        $this->assertMovesTo(strtolower($this->enc('/درباره-ما')), '/about');
    }

    /** «درباره-ما» با امتیازِ عددی به مقالهٔ WHOIS می‌رسید — تصمیمِ دستی برنده است */
    public function test_old_pages_go_to_their_real_counterpart(): void
    {
        $this->assertMovesTo($this->enc('/هاست-ویندوز'), '/hosting/windows');
        $this->assertMovesTo($this->enc('/سرور-اختصاصی'), '/dedicated/iran');
        $this->assertMovesTo('/contact-us', '/contact');
        // بک‌لینک‌های بیرونی حروفِ بزرگ دارند؛ کلیدهای نقشه کوچک‌اند
        $this->assertMovesTo('/About-Us/', '/about');
    }

    public function test_wordpress_suffixes_are_stripped_before_lookup(): void
    {
        $this->assertMovesTo($this->enc('/آموزش-و-پشتیبانی/page/3'), '/docs');
        $this->assertMovesTo($this->enc('/نگاهی-به-سرور-گوشی/feed/'), '/blog/phone-servers-explained');
    }

    public function test_categories_keep_their_topic(): void
    {
        $this->assertMovesTo($this->enc('/category/آموزش/seo/page/2'), '/blog?cat=seo');
        $this->assertMovesTo($this->enc('/category/آموزش'), '/blog?cat=tutorial');
        $this->assertMovesTo($this->enc('/category/دسته-ای-که-هرگز-نبود'), '/blog');
    }

    /**
     * 🔴 نامِ شهر فقط به‌عنوانِ **واژهٔ کامل**.
     *
     * نسخهٔ اول «بازرگانی» (تجارت) را شهرِ «بازرگان» می‌خواند و صفحهٔ «طراحی سایت
     * بازرگانی در ارومیه» را به صفحهٔ یک شهرِ مرزی می‌فرستاد.
     */
    public function test_town_names_match_whole_words_only(): void
    {
        $this->assertMovesTo($this->enc('/سرورنت-طراحی-سایت-در-پیرانشهر/'), '/urmia/cities/piranshahr');
        $this->assertMovesTo($this->enc('/سرورنت-طراحی-سایت-در-بازرگان'), '/urmia/cities/bazargan');
        $this->assertMovesTo($this->enc('/طراحی-سایت-بازرگانی-در-ارومیه'), '/urmia');
    }

    /** ترتیبِ قاعده‌ها: مشخص پیش از عمومی */
    public function test_specific_topics_win_over_general_ones(): void
    {
        $this->assertMovesTo($this->enc('/طراحی-سایت-با-وردپرس'), '/hosting/wordpress');
        $this->assertMovesTo($this->enc('/بهترین-سرور-مجازی-برای-سرخطی'), '/vps/iran');
        $this->assertMovesTo($this->enc('/یک-نوشته-بی-موضوع-قدیمی'), '/blog');
    }

    /** اسپمِ تزریق‌شده روی وردپرسِ قدیمی ریدایرکت نمی‌شود — اعتبارش به ما نرسد */
    public function test_injected_spam_is_gone_not_redirected(): void
    {
        $this->get('/online-payday-loans-in-georgia-easy-solution-to')->assertStatus(410);
        $this->get('/kak-obygrat-kazino-bez-depozita')->assertStatus(410);
    }

    /** ⚠️ «dating» درونِ «updating» اسپم نیست — واژهٔ کامل */
    public function test_spam_words_do_not_catch_innocent_slugs(): void
    {
        $this->get('/updating-php-safely-x9f3')->assertNotFound();
    }

    public function test_wordpress_and_theme_assets_are_gone(): void
    {
        $this->get('/wp-content/uploads/2022/02/111.webp')->assertStatus(410);
        $this->get('/fonts/vazir.woff2')->assertStatus(410);
        $this->get('/en/css/style.css')->assertStatus(410);
    }

    /** ویجتِ چت `href = null` را «null»ِ نسبی می‌کرد — از هر صفحه‌ای */
    public function test_null_links_go_to_their_parent(): void
    {
        $this->assertMovesTo('/docs/null', '/docs');
        $this->assertMovesTo('/tr/null', '/tr');
        $this->assertMovesTo('/null', '/');
    }

    public function test_the_language_prefix_is_kept(): void
    {
        $this->assertMovesTo('/en/order/download-4', '/en/hosting/download');
        $this->assertMovesTo('/tr/order/backup-2', '/tr/hosting/backup');
    }

    /** صفحه‌های ارومیه فقط فارسی‌اند؛ en/tr آن‌جا ۴۱۰ می‌گیرند — ۳۰۱ نباید به ۴۱۰ برسد */
    public function test_urmia_targets_never_send_other_languages_to_a_410(): void
    {
        $this->assertMovesTo('/en/'.$this->enc('سرورنت-طراحی-سایت-در-پیرانشهر'), '/en/solutions/web-design');
    }

    public function test_plugin_sitemaps_and_the_old_seo_tool(): void
    {
        $this->assertMovesTo('/post-sitemap.xml', '/sitemap.xml');
        $this->assertMovesTo('/seo/domain/example.com', '/tools/seo');
        $this->assertMovesTo('/seo/contact', '/contact');
    }

    /** آدرسی که حالا مقصد دارد «لینکِ خراب» نیست؛ ولی ۴۰۴ِ واقعی هنوز ثبت می‌شود */
    public function test_resolved_addresses_stay_out_of_the_404_log(): void
    {
        $this->get($this->enc('/درباره-ما'));
        $this->get('/docs/null');
        $this->get('/wp-content/uploads/x.png');
        $this->get('/mktg-9f3a-landing')->assertNotFound();

        $urls = array_column(ErrorTracker::recent(200, 'notfound'), 'url');

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('mktg-9f3a-landing', $urls[0]);
    }

    /**
     * 🔴 نامکِ لاتینِ ناشناخته عمداً ۴۰۴ می‌مانَد.
     *
     * سایتِ امروز فقط نامکِ لاتین دارد؛ ریدایرکتِ یک نامکِ لاتینِ ناشناخته به
     * صفحه‌ای عمومی، **لینکِ خرابِ خودمان** را از ردیاب پنهان می‌کرد.
     */
    public function test_unknown_latin_slugs_are_left_alone(): void
    {
        $this->get('/some-unknown-latin-slug-7c1e')->assertNotFound();
        $this->get('/en/blog/kubernetes-for-beginners/comment')->assertNotFound();
    }

    /** ۳۰۱ روی POST بدنه را دور می‌ریزد */
    public function test_non_get_requests_are_never_redirected(): void
    {
        $this->assertNull(LegacyUrlResolver::resolve(
            \Illuminate\Http\Request::create($this->enc('/درباره-ما'), 'POST')
        ));
    }

    public function test_machine_paths_are_never_touched(): void
    {
        foreach (['/api/v1/me/v1/models', '/system/null', '/admin/null', '/v1/null'] as $p) {
            $this->assertNull(LegacyUrlResolver::resolve(\Illuminate\Http\Request::create($p, 'GET')), $p);
        }
    }

    /**
     * سلامتِ خودِ نقشه: کلیدها نرمال‌شده ذخیره شده‌اند (وگرنه هرگز تطبیق
     * نمی‌خورند)، مقصدها مسیرِ داخلی‌اند، و هیچ مقصدی خودش کلید نیست (زنجیره).
     */
    public function test_the_map_is_normalized_and_has_no_chains(): void
    {
        $exact = (array) config('legacy_urls.exact');

        $this->assertGreaterThan(150, count($exact));

        foreach ($exact as $key => $to) {
            $this->assertSame(LegacyUrlResolver::normalize((string) $key), (string) $key, "کلیدِ نرمال‌نشده: {$key}");
            $this->assertStringStartsWith('/', $to, "مقصدِ بیرونی: {$key}");
            $this->assertArrayNotHasKey(LegacyUrlResolver::normalize($to), $exact, "زنجیره: {$key} → {$to}");
        }

        foreach ((array) config('legacy_urls.rules') as [$re, $to]) {
            $this->assertNotFalse(@preg_match($re, 'x'), "الگوی خراب: {$re}");
            $this->assertStringStartsWith('/', $to);
        }
    }

    /** همتای `gen.py` — حروفِ عربی، نیم‌فاصله، کشیده و ارقامِ فارسی */
    public function test_normalize_folds_the_variants_wordpress_produced(): void
    {
        $this->assertSame('آموزشکسبدرآمد-9', LegacyUrlResolver::normalize("/آموزش\u{200C}كسب\u{0640}درآمد ۹/"));
        $this->assertSame('معرفی-کلاینت', LegacyUrlResolver::normalize('/معرفي-كلاينت/'));
    }
}
