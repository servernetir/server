<?php

namespace Tests\Feature;

use App\Http\Controllers\HourlyVpsController;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\CountryLandingSeo;
use App\Services\Cloud\CloudPricingSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsCatalogPricing;
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
    use SeedsCatalogPricing;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCatalogPricing();
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
        foreach (['', '/en', '/tr'] as $prefix) {
            $res = $this->get($prefix.'/vps/hourly');
            $res->assertOk();
            $res->assertDontSee('ui.hv_', false);                     // کلیدِ خامِ ترجمه

            // The guide remains useful without catalog rows, and its contextual
            // links must retain the page's language instead of dropping to Persian.
            $this->assertSame(1, preg_match('~<section[^>]+id="linux-hourly-guide"[^>]*>(.*?)</section>~s', $res->getContent(), $guide));
            foreach (['/vps/linux', '/docs/connecting-to-linux-server-ssh', '/hosting/python'] as $target) {
                $this->assertStringContainsString('href="'.url($prefix.$target).'"', $guide[1]);
            }
            $this->assertStringContainsString('billing_mode=hourly', $guide[1]);
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

    public function test_new_country_links_reuse_the_loaded_locations_without_one_query_per_country(): void
    {
        foreach (['PH' => 'ph-manila', 'BG' => 'bg-sofia', 'MX' => 'mx-mexico-city'] as $iso => $code) {
            CloudLocation::create([
                'code' => $code, 'country' => $iso, 'city' => $code,
                'is_active' => true, 'sort' => 10,
            ]);
            CloudPlan::create([
                'provider' => 'hetzner', 'provider_ref' => 'country-'.$code,
                'location_code' => $code, 'public_name' => 'CV-1-2',
                'slug' => 'country-'.$code, 'vcpu' => 1, 'ram_mb' => 2048,
                'disk_gb' => 50, 'disk_type' => 'ssd', 'cost_eur_cents' => 300,
                'price_eur_cents' => 490, 'price_irt' => 720000,
                'is_active' => true, 'in_stock' => true,
            ]);
        }

        $locationReads = [];
        DB::listen(function ($query) use (&$locationReads): void {
            if (str_contains(strtolower($query->sql), 'cloud_locations')) {
                $locationReads[] = $query->sql;
            }
        });

        $countries = collect(app(HourlyVpsController::class)->show()->getData()['countries'])
            ->keyBy('iso');

        foreach (['PH' => 'ph-manila', 'BG' => 'bg-sofia', 'MX' => 'mx-mexico-city'] as $iso => $code) {
            $this->assertSame(url('/cloud/'.$code), $countries[$iso]['url']);
        }
        // One table-existence check and one active-location read; no per-country reads.
        $this->assertCount(2, $locationReads, 'Country links should share the active-location query.');
    }

    public function test_hourly_rates_are_checked_once_per_displayed_offer(): void
    {
        $this->seedIranAndGermany();

        $snapshot = new class extends CloudPricingSnapshot {
            public int $checks = 0;

            public function current(?string $provider = null): bool
            {
                $this->checks++;

                return parent::current($provider);
            }
        };
        app()->instance(CloudPricingSnapshot::class, $snapshot);

        $data = app(HourlyVpsController::class)->show()->getData();

        $this->assertCount(2, $data['countries']);
        // The catalog checks once; each displayed offer checks once before its rate is reused.
        $this->assertSame(3, $snapshot->checks);
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

        $description = app(CountryLandingSeo::class)->content('IR')['description'];
        $this->assertStringContainsString('<meta name="description" content="'.e($description).'">', $html);
    }
    public function test_calculator_uses_the_same_plan_quotes_and_preserves_localized_order_links(): void
    {
        $this->seedIranAndGermany();
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $html = $this->get($prefix.'/vps/hourly')->assertOk()->getContent();
            $this->assertSame(1, preg_match('~<script type="application/json" id="hourly-explorer-config">(.*?)</script>~s', $html, $match));
            $config = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($locale, $config['locale']);
            $this->assertSame($locale === 'fa' ? 1 : 100, $config['minor']);
            $plan = CloudPlan::where('slug', 'cv-1c-2g-25d-ir')->firstOrFail();
            $quote = collect($config['plans'])->firstWhere('country', 'IR');
            $this->assertNotNull($quote);
            $this->assertSame($locale === 'fa' ? $plan->hourlyIrt() : $plan->hourlyEurCents(), $quote['hourly']);
            $this->assertSame($locale === 'fa' ? (int) $plan->price_irt : (int) $plan->price_eur_cents, $quote['monthly']);
            $this->assertSame(2048, $quote['ram']);
            $this->assertStringContainsString('plan=cv-1c-2g-25d-ir', $quote['url']);
            $this->assertStringContainsString('billing_mode=hourly', $quote['url']);
            $this->assertStringNotContainsString('provider', $match[1]);
            $this->assertStringNotContainsString('hourly.title', $html);
            $this->assertStringContainsString('id="buying-guide"', $html);
            $this->assertStringContainsString('data-hourly-compare=', $html);
        }
    }

    public function test_extended_faq_schema_matches_the_visible_questions(): void
    {
        $this->seedIranAndGermany();
        $html = $this->get('/vps/hourly')->assertOk()->getContent();
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $blocks);
        foreach ($blocks[1] as $block) {
            $schema = json_decode($block, true, flags: JSON_THROW_ON_ERROR);
            if (($schema['@type'] ?? '') !== 'FAQPage') continue;
            $this->assertCount(11, $schema['mainEntity']);
            foreach ($schema['mainEntity'] as $item) {
                $this->assertStringContainsString(e($item['name']), $html);
                $this->assertStringContainsString(e($item['acceptedAnswer']['text']), $html);
            }
        }
    }

}
