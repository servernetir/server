<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * صفحهٔ فرودِ «سرور مجازی ساعتی» — /vps/hourly.
 *
 * ═══ چرا این صفحه و این تست وجود دارد ═══
 *
 * فروشِ ساعتی از مرداد ۱۴۰۵ زنده بود (متر، کیفِ پول، تیکِ فروشگاه) ولی هیچ
 * صفحهٔ عمومی نداشت؛ Search Console عبارتِ «سرور مجازی ساعتی» را با ۷۵٪ CTR
 * ولی فقط ۴ نمایش در سه ماه نشان می‌داد — یعنی تقاضا بود و صفحه نبود.
 *
 * سه چیز را قفل می‌کند:
 *   ۱) صفحه در هر سه زبان بالا می‌آید، حتی با کاتالوگِ خالی (سرورِ مهاجرت‌نخورده)
 *   ۲) عددِ روی صفحه **همان** `CloudPlan::hourlyIrt()` است — نه عددِ دست‌نویس
 *   ۳) راه‌های رسیدن به صفحه (منو، نقشهٔ سایت، llms.txt) و راهِ خروج از آن
 *      (لینکِ فروشگاه با billing_mode=hourly) سرِ جایشان هستند
 */
class HourlyVpsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function seedIranAndGermany(): void
    {
        CloudLocation::create(['code' => 'ir-tehran', 'country' => 'IR', 'city' => 'Tehran', 'is_active' => true, 'sort' => 1]);
        CloudLocation::create(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true, 'sort' => 2]);

        CloudPlan::create([
            'provider' => 'proxmox', 'provider_ref' => 'ir-small', 'location_code' => 'ir-tehran',
            'public_name' => 'CV-1-2', 'slug' => 'cv-1c-2g-25d-ir',
            'vcpu' => 1, 'ram_mb' => 2048, 'disk_gb' => 25, 'disk_type' => 'nvme',
            'cost_eur_cents' => 300, 'price_eur_cents' => 490, 'price_irt' => 720000,
            'is_active' => true, 'in_stock' => true,
        ]);
        CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'location_code' => 'de-frankfurt',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 1440000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    // ═══════════════ صفحه ═══════════════

    public function test_it_renders_in_all_three_languages_with_an_empty_catalogue(): void
    {
        foreach (['/vps/hourly', '/en/vps/hourly', '/tr/vps/hourly'] as $path) {
            $res = $this->get($path);
            $res->assertOk();
            $res->assertDontSee('ui.hv_', false);                     // کلیدِ خامِ ترجمه
        }

        $this->get('/vps/hourly')->assertSee('سرور مجازی ساعتی');
        $this->get('/en/vps/hourly')->assertSee('Hourly VPS');
    }

    public function test_the_persian_page_targets_the_keyword_in_title_h1_and_description(): void
    {
        $html = $this->get('/vps/hourly')->getContent();

        $this->assertMatchesRegularExpression('~<title>[^<]*سرور مجازی ساعتی~u', $html);
        $this->assertMatchesRegularExpression('~<h1[^>]*>[^<]*سرور مجازی ساعتی~u', $html);
        $this->assertMatchesRegularExpression('~<meta name="description" content="[^"]*سرور مجازی ساعتی~u', $html);
    }

    public function test_the_hourly_rate_on_the_page_is_the_model_rate_not_a_hand_typed_number(): void
    {
        $this->seedIranAndGermany();

        $iran = CloudPlan::where('slug', 'cv-1c-2g-25d-ir')->firstOrFail();
        $expected = fa_num(number_format($iran->hourlyIrt()));         // ۷۲۰٬۰۰۰ ÷ ۷۲۰ = ۱٬۰۰۰
        $minStart = fa_num(number_format($iran->hourlyStartMinIrt()));

        $html = $this->get('/vps/hourly')->getContent();

        $this->assertStringContainsString($expected, $html, 'نرخِ ساعتیِ ارزان‌ترین پلن باید روی صفحه باشد');
        $this->assertStringContainsString($minStart, $html, 'کفِ اعتبارِ شروع باید از همان مدل بیاید');
        $this->assertStringContainsString('ایران', $html);
        $this->assertStringContainsString('آلمان', $html);
    }

    public function test_structured_data_marks_the_price_as_per_hour(): void
    {
        $this->seedIranAndGermany();

        $html = $this->get('/vps/hourly')->getContent();

        $this->assertStringContainsString('"UnitPriceSpecification"', $html);
        $this->assertStringContainsString('"unitCode":"HUR"', $html);
        $this->assertStringContainsString('"FAQPage"', $html);
        $this->assertStringContainsString('"HowTo"', $html);
        $this->assertStringContainsString('"BreadcrumbList"', $html);

        // IRR یعنی ریال: ۱٬۰۰۰ تومان ⇒ ۱۰٬۰۰۰
        $iran = CloudPlan::where('slug', 'cv-1c-2g-25d-ir')->firstOrFail();
        $this->assertStringContainsString('"price":'.($iran->hourlyIrt() * 10), $html);
    }

    public function test_with_an_empty_catalogue_no_offer_schema_is_emitted(): void
    {
        $html = $this->get('/vps/hourly')->getContent();

        $this->assertStringNotContainsString('"@type":"Offer"', $html, 'بدونِ قیمت نباید Offer ساخته شود');
        $this->assertStringContainsString('"FAQPage"', $html);
    }

    public function test_the_buy_links_preselect_hourly_billing_in_the_store(): void
    {
        $this->seedIranAndGermany();

        $html = $this->get('/vps/hourly')->getContent();

        $this->assertStringContainsString('billing_mode=hourly', $html);
        $this->assertStringContainsString('location=ir-tehran', $html);
        $this->assertStringContainsString('plan=cv-1c-2g-25d-ir', $html);
    }

    // ═══════════════ راه‌های رسیدن ═══════════════

    public function test_it_is_in_the_sitemap_and_llms_txt(): void
    {
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>'.url('/vps/hourly').'</loc>', $sitemap);
        $this->assertStringContainsString('<loc>'.url('/en/vps/hourly').'</loc>', $sitemap);
        $this->assertStringContainsString('<loc>'.url('/tr/vps/hourly').'</loc>', $sitemap);

        $this->assertStringContainsString('/vps/hourly', $this->get('/llms.txt')->assertOk()->getContent());
    }

    public function test_it_is_linked_from_the_mega_menu_and_from_every_vps_product_page(): void
    {
        $this->assertStringContainsString('href="'.url('/vps/hourly').'"', $this->get('/')->getContent());

        foreach (['iran', 'germany', 'linux'] as $slug) {
            $this->assertStringContainsString(
                'href="'.url('/vps/hourly').'"',
                $this->get("/vps/$slug")->getContent(),
                "/vps/$slug باید به صفحهٔ ساعتی لینک دهد"
            );
        }
    }

    // ═══════════════ عنوان‌های تراکنشی ═══════════════

    public function test_key_vps_and_domain_pages_carry_transactional_titles(): void
    {
        $this->assertMatchesRegularExpression('~<title>خرید سرور مجازی ایران~u', $this->get('/vps/iran')->getContent());
        $this->assertMatchesRegularExpression('~<title>خرید سرور مجازی آلمان~u', $this->get('/vps/germany')->getContent());
        $this->assertMatchesRegularExpression('~<title>خرید سرور مجازی \(VPS\)~u', $this->get('/cloud')->getContent());
        $this->assertMatchesRegularExpression('~<title>خرید و ثبت دامنه~u', $this->get('/domains')->getContent());
        $this->assertMatchesRegularExpression('~<title>ثبت دامنه ir~u', $this->get('/domain/ir')->getContent());
    }

    public function test_seo_description_wins_over_hero_copy_on_product_pages(): void
    {
        $html = $this->get('/vps/iran')->getContent();

        $this->assertMatchesRegularExpression('~<meta name="description" content="خرید سرور مجازی ایران~u', $html);
    }
}
