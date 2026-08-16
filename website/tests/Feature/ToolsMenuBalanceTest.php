<?php

namespace Tests\Feature;

use App\Services\NetworkTools;
use App\Services\WebProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * توازن منوی «ابزارهای تخصصی» + سلامت صفحات و سیم‌کشی ابزارهای تازه.
 *
 * ═══ چرا ═══
 * خواسته‌ی صریح کارفرما (مرداد ۱۴۰۵): سه گروه منو باید هم‌اندازه باشند —
 * ۲/۳/۶ بود و «توازن را به هم می‌زد». حالا ۶/۶/۶ است و این تست همان عدد را
 * قفل می‌کند تا آیتم بعدی که کسی اضافه/کم کرد، بی‌صدا کج نشود.
 */
class ToolsMenuBalanceTest extends TestCase
{
    use RefreshDatabase;

    /** همه‌ی نوع‌های lookup تازه (صفحه‌دار) — چه در منو چه فقط لینک داخلی */
    private const NEW_TYPES = ['email', 'blacklist', 'speed', 'headers', 'redirects', 'iran-access', 'global-ping', 'global-http', 'pagespeed'];

    /**
     * آیتم‌های lookup که واقعاً در منو هستند (بازخورد کارفرما، شهریور ۱۴۰۵):
     * speed/headers/redirects/iran-access از منو درآمدند ولی صفحه‌هایشان
     * ماندند — «جزو لینک‌های داخلی».
     */
    private const MENU_LOOKUPS = ['email', 'blacklist', 'global-ping', 'global-http', 'pagespeed'];

    private const OFF_MENU = ['speed', 'headers', 'redirects', 'iran-access'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** بخش مگامنوی ابزارها از HTML صفحه‌ی اول */
    private function toolsPanel(string $path = '/'): string
    {
        $html = $this->get($path)->assertOk()->getContent();
        $start = strpos($html, 'id="menu-tools"');
        $end = strpos($html, 'id="menu-knowledge"');
        $this->assertNotFalse($start, 'پنل ابزارها در صفحه نیست');
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    // ═══════════════ توازن ═══════════════

    public function test_all_three_columns_have_exactly_six_items(): void
    {
        $panel = $this->toolsPanel();

        $cols = array_slice(explode('tmega-col', $panel), 1);
        $this->assertCount(3, $cols, 'باید دقیقاً سه ستون باشد');

        foreach ($cols as $i => $col) {
            $this->assertSame(
                6,
                substr_count($col, 'tmega-link'),
                'ستون '.($i + 1).' باید دقیقاً ۶ آیتم داشته باشد — توازن ۶/۶/۶ خواسته‌ی صریح کارفرماست'
            );
        }
    }

    public function test_every_new_menu_item_links_to_its_page(): void
    {
        $panel = $this->toolsPanel();

        foreach (self::MENU_LOOKUPS as $type) {
            $this->assertStringContainsString('/lookup/'.$type, $panel, "لینک {$type} در منو نیست");
        }
        $this->assertStringContainsString('/tools/domain-ideas', $panel);
        $this->assertStringContainsString('/tools/speedtest', $panel);
    }

    /**
     * بازخورد کارفرما: این چهار ابزار «برای استفاده‌ی هرروزه جذاب نیستند» —
     * از منو خارج شدند ولی صفحه‌هایشان زنده‌اند (لینک داخلی/سئو).
     */
    public function test_offmenu_tools_left_the_menu_but_kept_their_pages(): void
    {
        $panel = $this->toolsPanel();

        foreach (self::OFF_MENU as $type) {
            $this->assertStringNotContainsString('/lookup/'.$type.'"', $panel, "«{$type}» باید از منو خارج شده باشد");
            $this->get('/lookup/'.$type)->assertOk();
        }
    }

    /** کشوی موبایل همان آیتم‌ها را دارد — منوی دسکتاپ و موبایل از هم جدا نیفتند */
    public function test_the_mobile_drawer_carries_the_same_new_items(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (self::MENU_LOOKUPS as $type) {
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($html, '/lookup/'.$type),
                "آیتم {$type} باید هم در مگامنو باشد هم در کشوی موبایل"
            );
        }
        $this->assertGreaterThanOrEqual(2, substr_count($html, '/tools/speedtest'));
    }

    public function test_the_english_menu_is_balanced_too(): void
    {
        $panel = $this->toolsPanel('/en');

        $this->assertStringContainsString('/en/lookup/global-ping', $panel);
        $this->assertStringContainsString('/en/tools/speedtest', $panel);
        $this->assertStringContainsString('Global Ping', $panel);
    }

    // ═══════════════ صفحات ابزارهای تازه ═══════════════

    /**
     * «کد ۲۰۰ یعنی هیچ» — پس محتوای هر صفحه هم سنجیده می‌شود: h1 واقعی،
     * و هیچ کلید ترجمه‌ی خامی به کاربر نشت نکرده باشد.
     */
    public function test_every_new_lookup_page_renders_with_real_content(): void
    {
        $types = config('lookup.types');

        foreach (self::NEW_TYPES as $type) {
            $this->assertArrayHasKey($type, $types, "نوع {$type} در config/lookup نیست");

            $html = $this->get('/lookup/'.$type)->assertOk()->getContent();

            $this->assertStringContainsString($types[$type]['fa']['h1a'], $html, "h1 صفحه‌ی {$type}");
            $this->assertStringNotContainsString('ui.lk_', $html, "کلید خام در صفحه‌ی {$type}");
            $this->assertStringNotContainsString('ui.tb_', $html);
        }
    }

    /** هر سه زبان محتوای کامل دارند — کلید جامانده یعنی متن خام جلوی کاربر */
    public function test_new_lookup_types_have_full_content_in_all_three_locales(): void
    {
        $types = config('lookup.types');

        foreach (self::NEW_TYPES as $type) {
            foreach (['fa', 'en', 'tr'] as $loc) {
                foreach (['t', 'meta_t', 'meta_d', 'h1a', 'h1b', 'lead', 'intro', 'faq'] as $key) {
                    $this->assertNotEmpty(
                        $types[$type][$loc][$key] ?? null,
                        "config/lookup: {$type}.{$loc}.{$key} خالی است"
                    );
                }
                $this->assertGreaterThanOrEqual(3, count($types[$type][$loc]['faq']));
            }
        }
    }

    /**
     * سه فایل زبان باید کلیدهای یکسان داشته باشند — قرارداد سه‌زبانگی پروژه.
     * (کل فایل سنجیده می‌شود، نه فقط کلیدهای تازه؛ پس هر جاماندگی آینده هم می‌گیرد.)
     */
    public function test_the_three_ui_files_have_identical_key_sets(): void
    {
        $fa = array_keys(require base_path('lang/fa/ui.php'));
        $en = array_keys(require base_path('lang/en/ui.php'));
        $tr = array_keys(require base_path('lang/tr/ui.php'));

        $this->assertSame([], array_diff($fa, $en), 'کلیدهای fa که در en نیستند');
        $this->assertSame([], array_diff($en, $fa), 'کلیدهای en که در fa نیستند');
        $this->assertSame([], array_diff($fa, $tr), 'کلیدهای fa که در tr نیستند');
        $this->assertSame([], array_diff($tr, $fa), 'کلیدهای tr که در fa نیستند');
    }

    // ═══════════════ سیم‌کشی API ═══════════════

    /**
     * هر نوع تازه باید در match کنترلر به متد درستش برسد. سرویس‌ها استاب
     * می‌شوند؛ اگر کسی شاخه‌ی match را جا بیندازد، unknown_kind برمی‌گردد
     * و این تست قرمز می‌شود.
     */
    public function test_the_api_dispatches_every_new_kind_to_its_service(): void
    {
        $net = \Mockery::mock(NetworkTools::class);
        $net->shouldReceive('emailHealth')->once()->with('example.com')->andReturn(['ok' => true, 'via' => 'email']);
        $net->shouldReceive('blacklist')->once()->with('example.com')->andReturn(['ok' => true, 'via' => 'blacklist']);
        $this->app->instance(NetworkTools::class, $net);

        $probe = \Mockery::mock(WebProbe::class);
        $probe->shouldReceive('speed')->once()->with('example.com')->andReturn(['ok' => true, 'via' => 'speed']);
        $probe->shouldReceive('headers')->once()->with('example.com')->andReturn(['ok' => true, 'via' => 'headers']);
        $probe->shouldReceive('redirects')->once()->with('example.com')->andReturn(['ok' => true, 'via' => 'redirects']);
        $probe->shouldReceive('iranAccess')->once()->with('example.com')->andReturn(['ok' => true, 'via' => 'access']);
        $probe->shouldReceive('pagespeed')->once()->with('example.com')->andReturn(['ok' => true, 'via' => 'cwv']);
        $this->app->instance(WebProbe::class, $probe);

        $ch = \Mockery::mock(\App\Services\CheckHost::class);
        $ch->shouldReceive('ping')->once()->with('example.com')->andReturn(['ok' => true, 'via' => 'chping']);
        $ch->shouldReceive('http')->once()->with('example.com')->andReturn(['ok' => true, 'via' => 'chhttp']);
        $this->app->instance(\App\Services\CheckHost::class, $ch);

        foreach (['email' => 'email', 'blacklist' => 'blacklist', 'speed' => 'speed', 'headers' => 'headers', 'redirects' => 'redirects', 'iran-access' => 'access', 'global-ping' => 'chping', 'global-http' => 'chhttp', 'pagespeed' => 'cwv'] as $type => $via) {
            $this->postJson('/api/lookup', ['type' => $type, 'query' => 'example.com'])
                ->assertOk()
                ->assertJsonPath('via', $via);
        }
    }
}
