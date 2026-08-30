<?php

namespace Tests\Feature;

use App\Models\ServerPart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * فروشگاهِ قطعات باید قابلِ کشف باشد — و صفحهٔ مقایسه نباید.
 *
 * ═══ چرا هر دو نیمه لازم است ═══
 *
 * صفحاتِ دسته و نسل هرکدام متنِ یکتا و کلیدواژهٔ خودشان را دارند («رم سرور
 * ECC»، «قطعات HP نسل ۹»). اگر در نقشهٔ سایت نباشند، تنها راهِ کشفشان مگامنو
 * است — که خزنده لزوماً دنبال نمی‌کند. همان اشتباهی که یک بار ۲۰۳ صفحه را از
 * دیدِ گوگل پنهان کرده بود.
 *
 * 🔴 نیمهٔ دوم برعکس است: `/parts/compare` خروجی‌اش ترکیبِ انتخابیِ کاربر است،
 * پس بی‌نهایت آدرسِ تقریباً یکسان می‌سازد. ایندکس‌شدنشان یعنی بودجهٔ خزشِ سایت
 * صرفِ جدول‌های تکراری شود به‌جای صفحاتِ محصول.
 */
class PartsAreCrawlableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        ServerPart::create([
            'slug'          => 'xeon-e5-2680-v4',
            'category'      => 'cpu',
            'brand'         => 'Intel',
            'compat_gens'   => ['gen9'],
            'condition'     => 'refurb',
            'price_contact' => false,
            'price_eur'     => 3400,
            'active'        => true,
            'name'          => ['fa' => 'پردازنده', 'en' => 'Processor', 'tr' => 'İşlemci'],
        ]);
    }

    public function test_hub_categories_generations_and_products_are_all_in_the_sitemap(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach ([
            '/parts',
            '/parts/cpu',
            '/parts/ram',
            '/parts/disk',
            '/servers/hp/gen8',
            '/servers/hp/gen12',
            '/parts/cpu/xeon-e5-2680-v4',
        ] as $path) {
            $this->assertStringContainsString(
                '<loc>'.url($path).'</loc>',
                $xml,
                $path.' باید در نقشهٔ سایت باشد'
            );
        }
    }

    /** هر سه زبان در نقشه بیایند، وگرنه نسخهٔ en/tr هرگز کشف نمی‌شود. */
    public function test_every_locale_of_a_part_page_is_listed(): void
    {
        $xml = $this->get('/sitemap.xml')->getContent();

        foreach (['', 'en/', 'tr/'] as $prefix) {
            $this->assertStringContainsString('<loc>'.url('/'.$prefix.'parts/cpu').'</loc>', $xml);
        }
    }

    /** 🔴 صفحهٔ مقایسه نه در نقشه است و نه ایندکس‌پذیر. */
    public function test_the_compare_page_is_excluded_and_noindex(): void
    {
        $xml = $this->get('/sitemap.xml')->getContent();
        $this->assertStringNotContainsString('parts/compare', $xml);

        $html = $this->get('/parts/compare?parts=xeon-e5-2680-v4')->assertOk()->getContent();
        $this->assertStringContainsString('name="robots" content="noindex,follow"', $html);
        // صفحهٔ noindex نباید canonical و hreflang هم بدهد
        $this->assertStringNotContainsString('rel="canonical"', $html);
    }

    /** صفحهٔ محصول باید دادهٔ ساختاریافتهٔ Product و مسیرِ راهنما بدهد. */
    public function test_a_product_page_carries_product_and_breadcrumb_schema(): void
    {
        $html = $this->get('/parts/cpu/xeon-e5-2680-v4')->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringContainsString('"@type":"Offer"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"availability":"https://schema.org/InStock"', $html);
    }

    /**
     * ⚠️ قطعهٔ استعلامی نباید `offers` بگیرد.
     *
     * `price: 0` یعنی «رایگان» و برای گوگل ادعای قیمتی است که در صفحه نیست —
     * دقیقاً همان چیزی که rich result را رد می‌کند.
     */
    public function test_a_contact_priced_part_publishes_no_offer(): void
    {
        ServerPart::where('slug', 'xeon-e5-2680-v4')->update(['price_contact' => true]);

        $html = $this->get('/parts/cpu/xeon-e5-2680-v4')->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringNotContainsString('"@type":"Offer"', $html);
        $this->assertStringNotContainsString('"price":"0"', $html);
    }
}
