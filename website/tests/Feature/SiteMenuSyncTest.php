<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\SiteMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * منوی سایت باید با کاتالوگِ زنده بخواند.
 *
 * ═══ باگی که این تست‌ها می‌بندند ═══
 * زیرمنوی «سرور مجازی» دستی نوشته شده بود و drift داشت: «سرور مجازی فرانسه» و
 * «ایران» را تبلیغ می‌کرد که در کاتالوگ نبودند، و سنگاپور را که بود نداشت.
 * یعنی مشتری روی لینکی می‌زد که محصولی پشتش نبود.
 */
class SiteMenuSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function location(string $code, string $country, string $city): CloudLocation
    {
        return CloudLocation::create([
            'code' => $code, 'country' => $country, 'city' => $city, 'is_active' => true,
        ]);
    }

    private function plan(string $locationCode, array $over = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider' => 'hetzner', 'provider_ref' => 'cx22-'.$locationCode,
            'location_code' => $locationCode, 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-'.$locationCode,
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ], $over));
    }

    /** @return array<int, array<string, mixed>> آیتم‌های گروهِ «موقعیت مکانی» */
    private function locationItems(): array
    {
        $mega = app(SiteMenu::class)->mega();

        foreach ($mega['vps']['groups'] as $g) {
            if (($g['en'] ?? '') === 'Locations') {
                return $g['items'];
            }
        }

        return [];
    }

    // ═══════════════ همگامی ═══════════════

    public function test_menu_lists_locations_that_actually_have_sellable_plans(): void
    {
        $this->location('de-falkenstein', 'DE', 'Falkenstein');
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('de-falkenstein');
        $this->plan('sg-singapore');

        $items = $this->locationItems();
        $fa = array_column($items, 'fa');

        $this->assertContains('سرور مجازی فالکن‌اشتاین', $fa);
        $this->assertContains('سرور مجازی سنگاپور', $fa, 'مکانی که داریم باید در منو باشد');

        // لینکِ فراگیر باید **آخرِ همه** باشد، بعد از مکان‌های اتمام‌ظرفیت
        $this->assertSame('همهٔ سرورهای مجازی', end($fa));
    }

    /**
     * 🔴 قلبِ ماجرا: مکانی که پلنِ قابلِ فروش ندارد نباید تبلیغ شود — مشتری روی
     * لینکی نزند که محصولی پشتش نیست.
     */
    public function test_locations_without_sellable_plans_are_not_advertised(): void
    {
        $this->location('de-falkenstein', 'DE', 'Falkenstein');
        $this->plan('de-falkenstein');

        // فرانسه مکان دارد ولی پلنش قیمت ندارد (نرخِ ارز نبوده)
        $this->location('fr-paris', 'FR', 'Paris');
        $this->plan('fr-paris', ['price_irt' => 0]);

        // کانادا پلنِ ناموجود دارد
        $this->location('ca-toronto', 'CA', 'Toronto');
        $this->plan('ca-toronto', ['in_stock' => false]);

        $fa = array_column($this->locationItems(), 'fa');

        $this->assertContains('سرور مجازی فالکن‌اشتاین', $fa);
        $this->assertNotContains('سرور مجازی پاریس', $fa, 'پلنِ بی‌قیمت نباید تبلیغ شود');
        $this->assertNotContains('سرور مجازی تورنتو', $fa, 'پلنِ ناموجود نباید تبلیغ شود');
    }

    /** مکانِ غیرفعالِ دستی هم بیرون بمانَد */
    public function test_inactive_location_is_excluded(): void
    {
        $this->location('de-falkenstein', 'DE', 'Falkenstein');
        $this->plan('de-falkenstein');

        $hidden = $this->location('us-ashburn', 'US', 'Ashburn');
        $this->plan('us-ashburn');
        $hidden->update(['is_active' => false]);

        $fa = array_column($this->locationItems(), 'fa');

        $this->assertNotContains('سرور مجازی اشبرن', $fa);
    }

    // ═══════════════ محافظه‌کاری ═══════════════

    /**
     * ⚠️ منوی خالی در هدرِ سایت از منوی کهنه بدتر است. اگر کاتالوگ خالی بود،
     * همان فهرستِ config برمی‌گردد.
     */
    public function test_empty_catalog_falls_back_to_the_config_menu(): void
    {
        $items = $this->locationItems();

        $this->assertNotEmpty($items, 'منو هرگز نباید خالی شود');

        // فهرستِ config با `slug` کار می‌کند، فهرستِ زنده با `route`
        $this->assertArrayHasKey('slug', $items[0], 'باید به config برگشته باشد');
    }

    /** گروه‌های بازاریابی (کاربرد/سیستم‌عامل) دست نمی‌خورند */
    public function test_marketing_groups_are_untouched(): void
    {
        $this->location('de-falkenstein', 'DE', 'Falkenstein');
        $this->plan('de-falkenstein');

        $mega = app(SiteMenu::class)->mega();
        $groupNames = array_map(fn ($g) => $g['en'] ?? '', $mega['vps']['groups']);

        $this->assertContains('By use case', $groupNames);
        $this->assertContains('Operating system', $groupNames);

        foreach ($mega['vps']['groups'] as $g) {
            if (($g['en'] ?? '') === 'By use case') {
                $slugs = array_column($g['items'], 'slug');
                $this->assertContains('trading', $slugs, 'صفحاتِ سئویی باید بمانند');
            }
        }
    }

    /** بقیهٔ بخش‌های مگا-منو (هاست، دامنه، …) دست نمی‌خورند */
    public function test_other_mega_sections_are_untouched(): void
    {
        $this->location('de-falkenstein', 'DE', 'Falkenstein');
        $this->plan('de-falkenstein');

        $before = config('servernet.mega');
        $after = app(SiteMenu::class)->mega();

        foreach (array_keys($before) as $key) {
            if ($key === 'vps') {
                continue;
            }

            $this->assertSame($before[$key], $after[$key], "بخشِ «{$key}» نباید عوض شود");
        }
    }

    // ═══════════════ کش ═══════════════

    /** همگام‌سازیِ کاتالوگ باید کشِ منو را دور بریزد */
    public function test_sync_forgets_the_menu_cache(): void
    {
        $this->location('de-falkenstein', 'DE', 'Falkenstein');
        $this->plan('de-falkenstein');

        // فقط مکان‌های **زنده** شمرده می‌شوند: آیتمی که به cloud.location لینک دارد
        $liveCount = fn () => count(array_filter(
            $this->locationItems(),
            fn ($i) => ($i['route'][0] ?? '') === 'cloud.location'
        ));

        $this->assertSame(1, $liveCount());

        // مکانِ تازه اضافه می‌شود ولی کش هنوز کهنه است
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $this->assertSame(1, $liveCount(), 'کش باید همان مقدارِ قبلی را بدهد');

        SiteMenu::forget();

        $this->assertSame(2, $liveCount(), 'بعد از پاک‌کردنِ کش باید تازه شود');
    }

    // ═══════════════ رندرِ واقعیِ صفحه ═══════════════

    /**
     * «کدِ ۲۰۰ یعنی هیچ» — پس صفحهٔ واقعی رندر و محتوایش سنجیده می‌شود.
     */
    public function test_homepage_header_shows_the_live_locations(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('سرور مجازی سنگاپور', $html);
        // لینک باید به صفحهٔ همان مکان برود
        $this->assertStringContainsString('/cloud/sg-singapore', $html);
    }

    /** و در نسخهٔ انگلیسی هم برچسبِ درست را بدهد */
    public function test_english_header_uses_english_labels(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('Singapore VPS', $html);
        $this->assertStringContainsString('/en/cloud/sg-singapore', $html);
    }

    /** منوی زنده نباید نامِ زیرساخت را لو بدهد */
    public function test_menu_never_leaks_the_provider(): void
    {
        $this->location('de-falkenstein', 'DE', 'Falkenstein');
        $this->plan('de-falkenstein');

        $json = json_encode($this->locationItems(), JSON_UNESCAPED_UNICODE);

        foreach (['hetzner', 'aeza', 'cx22'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, (string) $json);
        }
    }

    // ═══════════════ «اتمام ظرفیت» برای مکان‌هایی که نداریم ═══════════════

    /**
     * خواستهٔ کارفرما: مکانی که موجودی نداریم حذف نشود، «اتمام ظرفیت» بخورد.
     * حذفش ارزشِ سئوی آن صفحه را از بین می‌برد؛ برچسب هم صادقانه‌تر از لینکِ
     * ساکتی است که مشتری رویش می‌زند و محصولی نمی‌بیند.
     */
    public function test_unavailable_locations_are_marked_out_of_stock_not_removed(): void
    {
        // فقط سنگاپور را داریم
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $fa = array_column($this->locationItems(), 'fa');
        $joined = implode(' | ', $fa);

        // زندهٔ قابلِ خرید
        $this->assertContains('سرور مجازی سنگاپور', $fa);

        // و مکان‌های تبلیغاتی هنوز هستند، ولی علامت‌دار
        $this->assertStringContainsString('اتمام ظرفیت', $joined);
        $this->assertStringContainsString('سرور مجازی ایران — اتمام ظرفیت', $joined);
        $this->assertStringContainsString('سرور مجازی فرانسه — اتمام ظرفیت', $joined);
    }

    /**
     * 🔴 نکتهٔ حساس: config در سطحِ **کشور** می‌نویسد و کاتالوگ در سطحِ **شهر**.
     * اگر کشور را نسنجیم، «سرور مجازی آلمان» را «اتمام ظرفیت» علامت می‌زنیم در
     * حالی که دو شهرِ آلمان را فعالانه می‌فروشیم — با دستِ خودمان فروش را
     * می‌خوابانیم.
     */
    public function test_country_with_live_cities_is_not_marked_out_of_stock(): void
    {
        $this->location('de-falkenstein', 'DE', 'Falkenstein');
        $this->location('de-nuremberg', 'DE', 'Nuremberg');
        $this->plan('de-falkenstein');
        $this->plan('de-nuremberg');

        $joined = implode(' | ', array_column($this->locationItems(), 'fa'));

        $this->assertStringContainsString('فالکن‌اشتاین', $joined);
        $this->assertStringContainsString('نورنبرگ', $joined);
        $this->assertStringNotContainsString('آلمان — اتمام ظرفیت', $joined,
            'آلمان را داریم، پس نباید اتمام ظرفیت بخورد');

        // ولی ایران را نداریم
        $this->assertStringContainsString('ایران — اتمام ظرفیت', $joined);
    }

    /** «سرور مجازی خارج» با داشتنِ هر کشورِ غیرایرانی، موجود حساب می‌شود */
    public function test_international_counts_as_available_with_any_foreign_location(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $joined = implode(' | ', array_column($this->locationItems(), 'fa'));

        $this->assertStringNotContainsString('خارج — اتمام ظرفیت', $joined);
    }

    /** لینکِ مکانِ اتمام‌ظرفیت باز می‌مانَد — صفحه‌اش سئو دارد */
    public function test_out_of_stock_items_keep_their_link(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        foreach ($this->locationItems() as $item) {
            if (str_contains((string) ($item['fa'] ?? ''), 'اتمام ظرفیت')) {
                $this->assertTrue(
                    isset($item['slug']) || isset($item['route']),
                    'آیتمِ اتمام‌ظرفیت باید همچنان لینک داشته باشد'
                );

                return;
            }
        }

        $this->fail('هیچ آیتمِ اتمام‌ظرفیتی پیدا نشد');
    }

    /** متنِ برچسب از تنظیمات قابلِ تغییر است (مثلاً به «به‌زودی») */
    public function test_out_of_stock_label_is_configurable(): void
    {
        \App\Models\Setting::put('menu_soldout_label_fa', 'به‌زودی');

        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $joined = implode(' | ', array_column($this->locationItems(), 'fa'));

        $this->assertStringContainsString('به‌زودی', $joined);
        $this->assertStringNotContainsString('اتمام ظرفیت', $joined);
    }

    /** و در هدرِ واقعیِ صفحه هم دیده شود */
    public function test_out_of_stock_badge_renders_on_the_homepage(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('سرور مجازی سنگاپور', $html);
        $this->assertStringContainsString('اتمام ظرفیت', $html);
    }
}
