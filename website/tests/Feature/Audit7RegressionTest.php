<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قفل‌های رگرسیونیِ ممیزی ۷ — سه یافته‌ای که «شش دور نامرئی بودند» + قلم‌های رودمپ.
 *
 * «۲۲ صفحهٔ دوبل هر ۱۰ تستِ قبلی را سبز رد می‌کردند، چون ۲۰۰ برمی‌گردانند،
 * کنونیکال دارند و در sitemap هستند. خطای طراحی: تست‌ها حولِ "آیا سرور درست
 * جواب می‌دهد" نوشته شده بود، نه "آیا آنچه جواب می‌دهد یکتاست".»
 */
class Audit7RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function location(string $code, string $country = 'DE', string $city = 'falkenstein'): CloudLocation
    {
        return CloudLocation::create([
            'code' => $code, 'country' => $country, 'city' => $city, 'is_active' => true, 'sort' => 0,
        ]);
    }

    private function plan(string $locationCode, string $slug): CloudPlan
    {
        return CloudPlan::create([
            'provider' => 'test', 'provider_ref' => 'ref-'.$slug, 'location_code' => $locationCode,
            'public_name' => 'CX '.$slug, 'slug' => $slug, 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'disk_type' => 'nvme', 'traffic_gb' => 20000, 'cpu_kind' => 'shared',
            'price_irt' => 900000, 'price_eur_cents' => 500, 'cost_eur_cents' => 400,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ]);
    }

    // ── یافتهٔ ۱: کدِ کشورِ دوبل ← ۳۰۱ تک‌پرش به صفحهٔ کشور ────────────────

    public function test_doubled_country_code_pages_redirect_permanently_to_the_country_page(): void
    {
        // حتی بدونِ ردیفِ DB — تشخیص از الگوست، پیش از هر پرس‌وجو
        $this->get('/cloud/de-de-dedicated')->assertStatus(301)
            ->assertRedirect(lroute('catalog', ['category' => 'vps', 'slug' => 'germany']));

        $this->get('/cloud/ru-ru-rx32-hi-cpu')->assertStatus(301)
            ->assertRedirect(lroute('catalog', ['category' => 'vps', 'slug' => 'russia']));

        $this->get('/cloud/se-se-promo')->assertStatus(301)
            ->assertRedirect(lroute('catalog', ['category' => 'vps', 'slug' => 'sweden']));
    }

    public function test_first_generation_product_group_codes_also_redirect_not_404(): void
    {
        // نسلِ اولِ همان باگ (ru-amd، de-dedicated) از ۲۴ اوت ۴۰۴ شده بود؛
        // حکمِ سئوی ممیزی ۷: «چون ۳۰۱ در هر دو حالت بی‌ضرر است، پیش‌فرض را ۳۰۱ بگذار»
        $this->get('/cloud/ru-amd')->assertStatus(301);
        $this->get('/cloud/de-dedicated')->assertStatus(301);
        $this->get('/en/cloud/fi-fi-shared')->assertStatus(301);

        // کشورِ خیالیِ ws- صفحهٔ کشور ندارد — به فهرستِ ابر برمی‌گردد، بدونِ حلقه
        $this->get('/cloud/ws-dedicated')->assertStatus(301)
            ->assertRedirect(lroute('cloud.index'));
    }

    public function test_real_city_and_catalog_cloud_pages_are_untouched_by_the_legacy_pattern(): void
    {
        $this->assertFalse(CloudLocation::isLegacyCode('de-falkenstein'));
        $this->assertFalse(CloudLocation::isLegacyCode('us-ashburn-va'));
        $this->assertFalse(CloudLocation::isLegacyCode('exit-de'));
        $this->assertFalse(CloudLocation::isLegacyCode('sg-singapore'));

        $this->assertTrue(CloudLocation::isLegacyCode('de-de-dedicated'));
        $this->assertTrue(CloudLocation::isLegacyCode('ru-intel'));
        $this->assertTrue(CloudLocation::isLegacyCode('ws-shared'));

        // صفحهٔ کاتالوگِ /cloud/iaas قربانیِ الگو نمی‌شود
        $this->get('/cloud/iaas')->assertOk();

        // صفحهٔ شهرِ واقعی با پلنِ فروختنی همچنان رندر می‌شود
        $this->location('de-falkenstein');
        $this->plan('de-falkenstein', 'cx-2-4-40-de-falkenstein');
        $this->get('/cloud/de-falkenstein')->assertOk();
    }

    public function test_sitemap_never_advertises_legacy_codes_even_when_they_sell(): void
    {
        // ردیفِ legacyِ **دارای پلنِ فروختنی** — دقیقاً همانی که از فیلترِ
        // «بی‌پلن‌ها» ی مهاجرتِ ۲۴ اوت رد شده بود
        $this->location('de-de-dedicated', 'DE', '');
        $this->plan('de-de-dedicated', 'cx-2-4-40-de-de-dedicated');
        $this->location('de-falkenstein');
        $this->plan('de-falkenstein', 'cx-2-4-40-de-falkenstein');

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('/cloud/de-falkenstein', $xml);
        $this->assertStringNotContainsString('/cloud/de-de-dedicated', $xml);
        $this->assertDoesNotMatchRegularExpression('~<loc>[^<]*/([a-z]{2})-\1-~', $xml);
    }

    public function test_catalog_sync_refuses_to_create_new_legacy_location_rows(): void
    {
        $sync = new \ReflectionMethod(\App\Services\Cloud\CloudCatalogSync::class, 'syncLocations');

        $sync->invoke(app(\App\Services\Cloud\CloudCatalogSync::class), [
            ['code' => 'de-de-dedicated', 'country' => 'DE'],   // تولدِ ممنوع
            ['code' => 'de-frankfurt', 'country' => 'DE'],      // شهرِ واقعی — مجاز
        ]);

        $this->assertDatabaseMissing('cloud_locations', ['code' => 'de-de-dedicated']);
        $this->assertDatabaseHas('cloud_locations', ['code' => 'de-frankfurt']);

        // ردیفِ legacyِ از-قبل-موجود همچنان به‌روز می‌شود (پلن‌هایش زنده‌اند)
        $this->location('se-se-promo', 'SE', '');
        $sync->invoke(app(\App\Services\Cloud\CloudCatalogSync::class), [
            ['code' => 'se-se-promo', 'country' => 'SE', 'city' => 'stockholm'],
        ]);
        $this->assertDatabaseHas('cloud_locations', ['code' => 'se-se-promo', 'city' => 'stockholm']);
    }

    // ── قلم ۰: /healthz — تفکیک‌کنندهٔ «زیرِ لاراول یا لایهٔ اپ» ────────────

    public function test_healthz_returns_plain_ok_and_is_never_cached(): void
    {
        config(['pagecache.enabled' => true, 'pagecache.mode' => 'denylist']);
        \Illuminate\Support\Facades\Cache::flush();

        $r = $this->get('/healthz');
        $r->assertOk();
        $this->assertSame('ok', $r->getContent());
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('app;dur=', (string) $r->headers->get('Server-Timing'));

        // بارِ دوم هم HIT نمی‌شود — هر بار کلِ بوت را می‌سنجد
        $this->assertNotSame('HIT', (string) $this->get('/healthz')->headers->get('X-Cache'));
    }

    // ── قلم ۳: /go/pay — گذرگاهِ شمارش‌پذیرِ سفارش ← پرداخت ────────────────

    public function test_go_pay_redirects_to_console_with_fresh_signature_and_carries_cycle(): void
    {
        $r = $this->get('/go/pay?sku=wordpress-3&cycle=yearly&src=order&sid=abcdef1234567890&ref=blog');

        $r->assertStatus(302);
        $to = (string) $r->headers->get('Location');

        $this->assertStringContainsString('console.servernet.cloud', $to);
        $this->assertStringContainsString('sku=wordpress-3', $to);
        $this->assertStringContainsString('cycle=yearly', $to);
        $this->assertStringContainsString('sig=', $to);
        $this->assertStringContainsString('sid=abcdef1234567890', $to);
        $this->assertStringContainsString('ref=blog', $to);
        $this->assertStringNotContainsString('price', $to);
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function test_go_pay_sanitizes_garbage_instead_of_trusting_it(): void
    {
        // دوره‌ی ناشناخته ← پیش‌فرضِ config، نه خطا و نه عبورِ خام
        $to = (string) $this->get('/go/pay?sku=wordpress-3&cycle=<script>&ref=..%2F..')
            ->assertStatus(302)->headers->get('Location');

        $this->assertStringContainsString('cycle='.config('billing.default_cycle', 'monthly'), $to);
        $this->assertStringNotContainsString('script', $to);
        $this->assertStringNotContainsString('ref=', $to);

        // skuی بی‌الگو ← ۴۰۴ (نه ریدایرکتِ کور)
        $this->get('/go/pay?sku=../../etc')->assertNotFound();
        $this->get('/go/pay')->assertNotFound();
    }

    public function test_go_pay_clicks_are_counted_server_side(): void
    {
        $dir = storage_path('framework/testing/funnel');
        @array_map('unlink', glob($dir.'/events-*.jsonl') ?: []);

        $this->get('/go/pay?sku=wordpress-3&cycle=yearly&src=order')->assertStatus(302);

        $rows = implode('', array_map('file_get_contents', glob($dir.'/events-*.jsonl') ?: []));
        $this->assertStringContainsString('"e":"pay_redirect"', $rows);
        $this->assertStringContainsString('"sku":"wordpress-3"', $rows);
        $this->assertStringContainsString('"cycle":"yearly"', $rows);
    }

    // ── قلم ۲: همهٔ صفحاتِ سفارش در sitemap، با عنوانِ تراکنشیِ یکتا ────────

    public function test_every_active_order_page_is_in_the_sitemap_and_indexable(): void
    {
        Product::create([
            'name' => 'پکیج آ', 'slug' => 'wp-a-1', 'group' => 'wordpress', 'category' => 'shared',
            'price' => 700000, 'price_eur' => 0, 'setup_fee' => 0, 'cycle' => 'monthly', 'tax_percent' => 10,
            'is_active' => true,
        ]);
        Product::create([
            'name' => 'پکیج ب', 'slug' => 'wp-b-1', 'group' => 'wordpress', 'category' => 'shared',
            'price' => 900000, 'price_eur' => 0, 'setup_fee' => 0, 'cycle' => 'monthly', 'tax_percent' => 10,
            'is_active' => false,   // غیرفعال ⇒ صفحه ۴۰۴ ⇒ نباید اعلام شود
        ]);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('/order/wp-a-1', $xml);
        $this->assertStringNotContainsString('/order/wp-b-1', $xml);

        $html = $this->get('/order/wp-a-1')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="robots" content="noindex', $html);
    }

    public function test_order_titles_are_transactional_and_distinct_per_sku(): void
    {
        foreach ([['wp-t-1', 700000], ['wp-t-2', 1400000]] as [$slug, $price]) {
            Product::create([
                'name' => 'پکیج '.$slug, 'slug' => $slug, 'group' => 'wordpress', 'category' => 'shared',
                'price' => $price, 'price_eur' => 0, 'setup_fee' => 0, 'cycle' => 'monthly', 'tax_percent' => 10,
                'is_active' => true,
            ]);
        }

        $t1 = $this->title($this->get('/order/wp-t-1')->getContent());
        $t2 = $this->title($this->get('/order/wp-t-2')->getContent());

        $this->assertNotSame('', $t1);
        $this->assertNotSame($t1, $t2, 'دو SKU نباید عنوانِ یکسان بگیرند — RG-META-UNIQ-13');
    }

    // ── ردِ پای robots: /go خزیدنی نیست ─────────────────────────────────────

    public function test_robots_txt_disallows_the_pay_gateway(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /go/', $robots);
    }

    private function title(string $html): string
    {
        return preg_match('~<title>(.*?)</title>~su', $html, $m) === 1 ? trim($m[1]) : '';
    }
}
