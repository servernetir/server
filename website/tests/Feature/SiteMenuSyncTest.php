<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\SiteMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * منوی «سرور مجازی» باید کشورمحور، ساده و همگام با کاتالوگ باشد.
 *
 * ═══ قاعدهٔ نمایش (خواستهٔ صریحِ کارفرما) ═══
 * فقط «سرور مجازی (نامِ کشور)» — بی‌قیمت، بی‌شمارشِ پلن، و **بی‌«اتمام ظرفیت»**.
 * منطق: کاربر راغب شود، وارد صفحهٔ کشور شود و آنجا موجودی را ببیند. کشورهای
 * اصیلِ config همیشه هستند؛ کشوری که زنده پلن دارد ولی در config نیست هم اضافه
 * می‌شود (مثلاً سنگاپور).
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

    /** برچسب‌های فارسیِ گروهِ موقعیت، به‌هم چسبیده — برای سنجشِ متن */
    private function joinedFa(): string
    {
        return implode(' | ', array_column($this->locationItems(), 'fa'));
    }

    // ═══════════════ ساختار و سادگی ═══════════════

    /** کشورِ اصیلِ config همیشه هست، به شکلِ سادهٔ «سرور مجازی (کشور)» */
    public function test_config_countries_are_shown_as_plain_links(): void
    {
        $joined = $this->joinedFa();

        $this->assertStringContainsString('سرور مجازی ایران', $joined);
        $this->assertStringContainsString('سرور مجازی آلمان', $joined);
        $this->assertStringContainsString('سرور مجازی فرانسه', $joined);

        // لینکِ فراگیر آخرِ همه
        $fa = array_column($this->locationItems(), 'fa');
        $this->assertSame('همهٔ سرورهای مجازی', end($fa));
    }

    /**
     * 🔴 قلبِ خواستهٔ تازه: هیچ قیمت، هیچ شمارشِ پلن، و هیچ «اتمام ظرفیت» در منو.
     */
    public function test_menu_has_no_price_no_count_no_sold_out(): void
    {
        // یک کشورِ زنده هم داریم تا مطمئن شویم حتی زنده‌ها هم برچسب نمی‌گیرند
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $joined = implode(' ', array_map(
            fn ($i) => ($i['fa'] ?? '').' '.($i['en'] ?? '').' '.($i['tr'] ?? ''),
            $this->locationItems()
        ));

        $this->assertStringNotContainsString('اتمام ظرفیت', $joined);
        $this->assertStringNotContainsString('Out of stock', $joined);
        $this->assertStringNotContainsString('پلن', $joined);
        $this->assertStringNotContainsString('plans', $joined);
        $this->assertStringNotContainsString('تومان', $joined);
        $this->assertStringNotContainsString('€', $joined);
        $this->assertStringNotContainsString(' از ', $joined);
    }

    /** کشوری که زنده پلن دارد ولی در config نیست، اضافه می‌شود (سنگاپور) */
    public function test_live_country_missing_from_config_is_added(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $this->assertStringContainsString('سرور مجازی سنگاپور', $this->joinedFa());
    }

    /** کشوری که هم config داردش هم زنده است، فقط **یک بار** می‌آید */
    public function test_country_in_both_config_and_catalog_is_not_duplicated(): void
    {
        // آلمان در config هست؛ حالا زنده هم می‌شود
        $this->location('de-falkenstein', 'DE', 'Falkenstein');
        $this->plan('de-falkenstein');

        $fa = array_column($this->locationItems(), 'fa');
        $germany = array_filter($fa, fn ($s) => str_contains((string) $s, 'آلمان'));

        $this->assertCount(1, $germany, 'آلمان نباید تکراری شود');
    }

    // ═══════════════ محافظه‌کاری ═══════════════

    /**
     * ⚠️ منوی خالی در هدرِ سایت از منوی کهنه بدتر است. اگر کاتالوگ خالی بود،
     * همان کشورهای config برمی‌گردند.
     */
    public function test_empty_catalog_still_shows_config_countries(): void
    {
        $items = $this->locationItems();

        $this->assertNotEmpty($items, 'منو هرگز نباید خالی شود');
        $this->assertArrayHasKey('slug', $items[0], 'باید کشورهای config باشند');
        $this->assertStringNotContainsString('اتمام ظرفیت', $this->joinedFa());
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

    /** «سرور اختصاصی» دیگر «اتمام ظرفیت» نمی‌خورد و کاملاً دست‌نخورده است */
    public function test_dedicated_is_not_marked_sold_out(): void
    {
        $this->location('de-falkenstein', 'DE', 'Falkenstein');
        $this->plan('de-falkenstein');

        $before = config('servernet.mega');
        $after = app(SiteMenu::class)->mega();

        $this->assertSame($before['dedicated'], $after['dedicated'],
            'بخشِ سرور اختصاصی نباید عوض شود');

        $joined = json_encode($after['dedicated'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('اتمام ظرفیت', (string) $joined);
    }

    /** بقیهٔ بخش‌های مگا-منو (هاست، دامنه، ابری) دست نمی‌خورند */
    public function test_other_mega_sections_are_untouched(): void
    {
        $this->location('de-falkenstein', 'DE', 'Falkenstein');
        $this->plan('de-falkenstein');

        $before = config('servernet.mega');
        $after = app(SiteMenu::class)->mega();

        // فقط vps کشورمحور می‌شود؛ بقیه (شاملِ dedicated) باید یکی باشند.
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
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        // فقط کشورهای **زنده** (کلیدِ iso) شمرده می‌شوند، نه کشورهای config
        $liveCount = fn () => count(array_filter(
            $this->locationItems(),
            fn ($i) => isset($i['iso'])
        ));

        $this->assertSame(1, $liveCount());

        // کشورِ زندهٔ تازه (که config ندارد) اضافه می‌شود ولی کش کهنه است
        $this->location('jp-tokyo', 'JP', 'Tokyo');
        $this->plan('jp-tokyo');

        $this->assertSame(1, $liveCount(), 'کش باید همان مقدارِ قبلی را بدهد');

        SiteMenu::forget();

        $this->assertSame(2, $liveCount(), 'بعد از پاک‌کردنِ کش باید تازه شود');
    }

    // ═══════════════ رندرِ واقعیِ صفحه ═══════════════

    /**
     * «کدِ ۲۰۰ یعنی هیچ» — پس صفحهٔ واقعی رندر و محتوایش سنجیده می‌شود.
     * کشوری که صفحهٔ بازاریابی دارد (سنگاپور) باید به همان صفحه لینک شود.
     */
    public function test_homepage_header_links_a_country_to_its_marketing_page(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('سرور مجازی سنگاپور', $html);
        $this->assertStringContainsString('/vps/singapore', $html);
    }

    /**
     * کشوری که هنوز صفحهٔ بازاریابی ندارد (ژاپن) به صفحهٔ مکانش برمی‌گردد،
     * نه ۴۰۴ — همان قاعدهٔ CloudCountry::marketingSlug.
     */
    public function test_country_without_marketing_page_falls_back_to_location(): void
    {
        $this->location('jp-tokyo', 'JP', 'Tokyo');
        $this->plan('jp-tokyo');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('سرور مجازی ژاپن', $html);
        $this->assertStringContainsString('/cloud/jp-tokyo', $html);
    }

    /** و در نسخهٔ انگلیسی هم برچسبِ درست و لینکِ صفحهٔ بازاریابی را بدهد */
    public function test_english_header_uses_english_labels(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('Singapore VPS', $html);
        $this->assertStringContainsString('/en/vps/singapore', $html);
    }

    /** منوی زنده نباید نامِ زیرساخت را لو بدهد */
    public function test_menu_never_leaks_the_provider(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $json = json_encode($this->locationItems(), JSON_UNESCAPED_UNICODE);

        foreach (['hetzner', 'aeza', 'cx22'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, (string) $json);
        }
    }

    // ═══════════════ idempotency ═══════════════

    /**
     * 🔴 باگی که یک بازبینِ مستقل پیدا کرد: composer خروجیِ `mega()` را در
     * `config('servernet.mega')` می‌نویسد و اگر `mega()` همان کلید را بخواند،
     * روی خروجیِ خودش می‌دود و کشورهای زنده و لینکِ فراگیر دوبرابر می‌شوند.
     */
    public function test_mega_is_idempotent_even_after_the_composer_overwrote_config(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $first = app(SiteMenu::class)->mega();

        // همان کاری که view composer می‌کند
        config(['servernet.mega' => $first]);

        $second = app(SiteMenu::class)->mega();

        $this->assertSame($first, $second, 'خروجی باید با هر بار صدا زدن یکی باشد');
    }

    /** لینکِ فراگیر دقیقاً یک بار — و همچنان آخرِ فهرست */
    public function test_catch_all_link_appears_exactly_once_and_stays_last(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $this->get('/')->assertOk();
        $this->get('/')->assertOk();

        $fa = array_column($this->locationItems(), 'fa');

        $this->assertCount(1, array_keys($fa, 'همهٔ سرورهای مجازی', true),
            'لینکِ فراگیر باید فقط یک بار باشد');
        $this->assertSame('همهٔ سرورهای مجازی', end($fa), 'و آخرِ همه بمانَد');
    }

    /** مکان‌های زنده هم با رندرهای بیشتر تکرار نشوند */
    public function test_live_locations_are_not_duplicated_by_extra_renders(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->location('jp-tokyo', 'JP', 'Tokyo');
        $this->plan('sg-singapore');
        $this->plan('jp-tokyo');

        $this->get('/')->assertOk();
        $this->get('/')->assertOk();

        $live = array_filter(
            $this->locationItems(),
            fn ($i) => isset($i['iso'])
        );

        $this->assertCount(2, $live, 'دو کشورِ زنده داریم، نه بیشتر');
    }

    /**
     * 🔴 حساس‌ترین تست: `mega()` باید از عکسِ دست‌نخورده بخواند، نه از کلیدی که
     * composer رویش می‌نویسد. کلیدِ `servernet.mega` را آلوده می‌کنیم؛ اگر
     * `mega()` از آن بخواند، آلودگی در خروجی ظاهر می‌شود.
     */
    public function test_mega_reads_the_pristine_snapshot_not_the_key_it_writes_into(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $polluted = config('servernet.mega');

        foreach ($polluted['vps']['groups'] as $i => $group) {
            if (($group['en'] ?? '') === 'Locations') {
                $polluted['vps']['groups'][$i]['items'][] = [
                    'slug' => 'atlantis',
                    'fa'   => 'سرور مجازی آتلانتیس',
                    'en'   => 'Atlantis VPS',
                    'tr'   => 'Atlantis VPS',
                ];
            }
        }

        config(['servernet.mega' => $polluted]);

        $this->assertStringNotContainsString('آتلانتیس', $this->joinedFa(),
            'mega() باید از عکسِ دست‌نخورده بخواند، نه از کلیدی که خودش رویش نوشته می‌شود');
    }

    /**
     * و کمربندِ دوم: حتی روی مسیرِ fallback (وقتی عکسِ دست‌نخورده نیست) هم نه
     * کشورِ زنده و نه لینکِ فراگیر دو بار نمی‌نشیند.
     */
    public function test_stays_stable_even_on_the_fallback_path(): void
    {
        $this->location('sg-singapore', 'SG', 'Singapore');
        $this->plan('sg-singapore');

        $once = app(SiteMenu::class)->mega();

        // کلیدِ عکس باید **واقعاً حذف** شود؛ ست‌کردنش به null بی‌فایده است چون
        // null هم مقداری معتبر است و `config($key, $default)` را بی‌اثر می‌کند.
        $servernet = config('servernet');
        unset($servernet['mega_source']);
        config(['servernet' => $servernet, 'servernet.mega' => $once]);

        $twice = app(SiteMenu::class)->mega();

        $this->assertSame($once, $twice, 'حتی روی مسیرِ fallback هم خروجی باید پایدار بمانَد');

        // نه کشورِ زنده دوتا شده، نه لینکِ فراگیر
        foreach ($twice['vps']['groups'] as $g) {
            if (($g['en'] ?? '') !== 'Locations') {
                continue;
            }

            $fa = array_column($g['items'], 'fa');
            $this->assertCount(1, array_keys($fa, 'همهٔ سرورهای مجازی', true));

            $live = array_filter($g['items'], fn ($i) => isset($i['iso']));
            $this->assertCount(1, $live, 'سنگاپور نباید دوتا شود');
        }
    }
}
