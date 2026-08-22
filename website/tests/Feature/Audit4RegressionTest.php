<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قفل‌های رگرسیونیِ ممیزی ۴ — «بدون assertion، دور پنجم دوباره همان‌ها را
 * گزارش می‌کند.» (QA)
 */
class Audit4RegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * چهار دور ممیزی «لینک اشتراک‌گذاری ۴۰۴» را شمرد و تشخیص نهایی QA این
     * بود: الگوهای /share و /sharing باید از قالب حذف شوند، نه تعمیر.
     * معیار پذیرش، عیناً: «تعداد href منطبق بر /share|/sharing باید صفر باشد.»
     */
    public function test_the_blog_template_has_no_share_or_sharing_hrefs(): void
    {
        $tpl = file_get_contents(resource_path('views/pages/blog-post.blade.php'));

        $this->assertStringNotContainsString('/share/url', $tpl,
            'الگوی t.me/share/url برگشته — چهار دور ممیزی این را ۴۰۴ شمرد');
        $this->assertStringNotContainsString('share-offsite', $tpl,
            'الگوی linkedin sharing/share-offsite برگشته');

        // و جایگزین‌ها واقعاً هستند — حذفِ بدونِ جایگزین، حذفِ قابلیت است
        $this->assertStringContainsString('wa.me', $tpl);
        $this->assertStringContainsString('mailto:', $tpl);
        $this->assertStringContainsString('data-native-share', $tpl);
    }

    /**
     * زنجیرهٔ fallback (ممیزی ۴): «هیچ پستی نمی‌تواند صفر لینکِ محصول رندر
     * کند.» ۲۳ پستِ بی‌لینک دقیقاً پست‌هایی بودند که دسته‌شان در نقشه نبود.
     */
    public function test_related_product_never_returns_null_for_any_category(): void
    {
        // دستهٔ ناشناخته و دستهٔ خالی — هر دو باید به hub برسند
        foreach (['some-legacy-category', null, '', 'news'] as $cat) {
            $rel = blog_related_product($cat);

            $this->assertNotNull($rel, 'دستهٔ «'.var_export($cat, true).'» صفر لینک رندر می‌کند');
            $this->assertNotSame('', $rel['title']);
            $this->assertStringContainsString('/hosting/', $rel['href']);
        }

        // و دسته‌های نگاشته همچنان مقصدِ اختصاصیِ خودشان را دارند، نه fallback
        $this->assertStringContainsString('/cloud/iaas', blog_related_product('cloud')['href']);
    }

    /**
     * ذخیرهٔ مدلِ قیمت/محتوا باید کشِ صفحه را همان لحظه باطل کند — بدونِ
     * این، هوکِ AppServiceProvider تزئینی است و «قیمتِ کهنه تا پایان TTL»
     * (ریسکِ شمارهٔ ۱ ممیزی ۴) برمی‌گردد.
     */
    public function test_saving_a_content_model_purges_the_page_cache(): void
    {
        config(['pagecache.enabled' => true]);
        \Illuminate\Support\Facades\Cache::flush();

        $this->get('/')->assertHeader('X-Cache', 'MISS');
        $this->get('/')->assertHeader('X-Cache', 'HIT');

        Setting::updateOrCreate(['key' => 'pagecache_probe'], ['value' => 'x']);

        $this->get('/')->assertHeader('X-Cache', 'MISS');
    }

    /** صفحات تازهٔ ممیزی ۴ در هر سه زبان بالا می‌آیند. */
    public function test_new_audit4_pages_render_in_all_locales(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/speed')->assertOk();
            $this->get($prefix.'/abuse')->assertOk();
        }
    }
}
