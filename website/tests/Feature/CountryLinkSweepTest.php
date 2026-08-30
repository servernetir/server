<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\SiteMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * جاروی کاملِ لینک‌های کشورِ «سرور مجازی».
 *
 * ═══ چرا این تست جدا از بقیه لازم بود ═══
 *
 * تست‌های موجود **منطق** را می‌سنجند: حذفِ پلنِ مغلوب، نشت‌نکردنِ نامِ زیرساخت،
 * ترتیبِ قیمت، برچسبِ شهر. ولی هیچ‌کدام نمی‌پرسید «آیا هر لینکی که واقعاً در منو
 * هست، واقعاً باز می‌شود؟»
 *
 * و همان جا بود که کارفرما ایراد می‌دید: منو کشور را نشان می‌داد، ولی کلیک روی
 * آن به صفحهٔ خالی/ناقص/۴۰۴ می‌رسید — چون مسیرِ منو از دو راهِ متفاوت ساخته
 * می‌شود (`/vps/{slug}`ِ بازاریابی یا `/cloud/{code}`ِ مکان) و هر کدام
 * کنترلرِ جدا دارد.
 *
 * این تست از **خودِ منو** شروع می‌کند، نه از فهرستِ دستیِ URLها — پس کشورِ
 * تازه‌ای که فردا به کاتالوگ اضافه شود هم خودکار پوشش می‌گیرد.
 */
class CountryLinkSweepTest extends TestCase
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
            'code' => $code, 'country' => $country, 'city' => $city, 'is_active' => true, 'sort' => 0,
        ]);
    }

    private function plan(string $loc, array $over = []): CloudPlan
    {
        static $n = 0;
        $n++;

        return CloudPlan::create(array_merge([
            'provider' => 'hetzner', 'provider_ref' => 'ref'.$n,
            'location_code' => $loc, 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-'.$loc.'-'.$n,
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $over));
    }

    /**
     * کاتالوگی که هر دو راهِ ساختِ مسیر را می‌پوشاند:
     * آلمان و فرانسه صفحهٔ بازاریابی دارند، سنگاپور ندارد (⇒ /cloud/{code}).
     */
    private function seedCatalog(): void
    {
        foreach ([
            ['de-fsn', 'DE', 'Falkenstein'],
            ['de-nbg', 'DE', 'Nuremberg'],
            ['fr-par', 'FR', 'Paris'],
            ['sg-sin', 'SG', 'Singapore'],
        ] as [$code, $iso, $city]) {
            $this->location($code, $iso, $city);
            $this->plan($code);
            // پلنِ دوم با مشخصاتِ متفاوت تا جدول واقعاً چند ردیف داشته باشد
            $this->plan($code, ['vcpu' => 4, 'ram_mb' => 8192, 'price_irt' => 990000]);
        }
    }

    /**
     * لینک‌های تبِ «سرور» در منو.
     *
     * ⚠️ `$onlyLocations` مهم است: آن تب هم‌زمان **سه** چیز دارد — سرورِ مجازیِ
     * کشورها، سرورِ اختصاصی، و سرورِ فیزیکی. ادعاهای محتوایی (جدولِ پلن،
     * سفیدبرچسبی) فقط دربارهٔ گروهِ کشورهاست؛ `/dedicated/iran` یک صفحهٔ
     * بازاریابیِ config-محور است و جدولِ پلنِ ابری ندارد — و نداشتنش باگ نیست.
     * نسخهٔ اول این تست همه را یک‌کاسه کرد و اشتباهاً قرمز شد.
     *
     * @return array<int,array{label:string,url:string}>
     */
    private function menuLinks(string $locale = 'fa', bool $onlyLocations = false): array
    {
        app()->setLocale($locale);

        $mega = app(SiteMenu::class)->mega();
        $out = [];

        foreach ($mega['vps']['groups'] ?? [] as $g) {
            if ($onlyLocations && ($g['en'] ?? '') !== 'Locations') {
                continue;
            }

            foreach ($g['items'] ?? [] as $it) {
                if (! isset($it['route'])) {
                    continue;
                }

                [$name, $params] = $it['route'];

                // ⚠️ `lroute()` و نه `route()`: نامِ خام باید پیشوندِ زبان بگیرد،
                //    وگرنه تست همیشه نسخهٔ فارسی را می‌زند و باگِ زبانی پنهان می‌مانَد.
                $out[] = ['label' => $it[$locale] ?? $it['fa'] ?? '?', 'url' => lroute($name, $params)];
            }
        }

        return $out;
    }

    /** @return array<int,array{label:string,url:string}> فقط گروهِ کشورها */
    private function countryLinks(string $locale = 'fa'): array
    {
        return $this->menuLinks($locale, onlyLocations: true);
    }

    // ═══════════════ جاروی اصلی ═══════════════

    /**
     * 🔴 **هر** لینکِ تبِ سرور باید باز شود — کشور، اختصاصی، فیزیکی، در هر سه زبان.
     *
     * این عمداً وسیع‌ترین ادعای این فایل است: لینکِ مرده در منو بدترین نوعِ
     * ایراد است، چون مشتری آن را می‌بیند، رویش کلیک می‌کند و به بن‌بست می‌خورد.
     */
    public function test_every_link_in_the_server_menu_opens(): void
    {
        $this->seedCatalog();

        $broken = [];

        foreach (['fa', 'en', 'tr'] as $loc) {
            foreach ($this->menuLinks($loc) as $link) {
                $status = $this->get($link['url'])->getStatusCode();

                if ($status !== 200) {
                    $broken[] = "[$loc] {$link['label']} → {$link['url']} = $status";
                }
            }
        }

        $this->assertSame([], $broken, "\nلینکِ شکسته در منو:\n".implode("\n", $broken));
        $this->assertNotEmpty($this->countryLinks(), 'منو هیچ لینکِ کشوری ندارد — جارو بی‌معنا می‌شود');
    }

    /** هیچ صفحهٔ کشوری نباید کلیدِ ترجمه‌نشده نشان دهد */
    public function test_no_country_page_shows_a_raw_translation_key(): void
    {
        $this->seedCatalog();

        $bad = [];

        foreach (['fa', 'en', 'tr'] as $loc) {
            foreach ($this->menuLinks($loc) as $link) {
                $html = $this->get($link['url'])->getContent();

                // ⚠️ `assertFalse` و نه `assertDoesNotMatch…`: دومی کلِ HTML صفحه
                //    را در پیامِ خطا چاپ می‌کند و خروجی بی‌استفاده می‌شود.
                if (preg_match('~(?<![\w./-])ui\.[a-z0-9_]+~i', $html, $m)) {
                    $bad[] = "[$loc] {$link['url']} → {$m[0]}";
                }
            }
        }

        $this->assertSame([], $bad, "\nکلیدِ خام:\n".implode("\n", $bad));
    }

    /**
     * سفیدبرچسبی روی صفحاتِ **سرورِ مجازی**.
     *
     * ⚠️ عمداً `/dedicated/*` را کنار می‌گذارد. تبِ منو هم سرورِ مجازی را دارد
     * هم سرورِ اختصاصی و فیزیکی، و `/dedicated/hetzner` یک **محصولِ واقعی** است
     * (فروشِ سرورِ اختصاصیِ آن برند). قاعدهٔ سفیدبرچسبی دربارهٔ سرورِ مجازیِ
     * خودِ مشتری است، نه هر جای HTML — همان نکته‌ای که در CLAUDE.md تصریح شده و
     * اولین نسخهٔ این تست نادیده‌اش گرفته بود و اشتباهاً قرمز شد.
     */
    public function test_no_vps_country_page_leaks_the_provider(): void
    {
        $this->seedCatalog();

        $bad = [];

        foreach ($this->countryLinks() as $link) {
            if (str_contains($link['url'], '/dedicated/') || str_contains($link['url'], '/servers')) {
                continue;
            }

            $html = strtolower($this->get($link['url'])->getContent());

            foreach (['hetzner', 'aeza', 'arvan'] as $p) {
                // نامِ برهنه در **متنِ دیده‌شدنی**، نه در href منوی سراسری
                if (preg_match('~>[^<]*\b'.$p.'\b~', $html)) {
                    $bad[] = "{$link['url']} → $p";
                }
            }
        }

        $this->assertSame([], $bad, "\nنشتِ نامِ زیرساخت:\n".implode("\n", $bad));
    }

    /**
     * هر صفحهٔ کشور باید یا جدولِ پلن داشته باشد یا پیامِ صریحِ «فعلاً نداریم».
     *
     * ⚠️ لینکِ فراگیرِ آخرِ منو (`/cloud`) از این قاعده بیرون است و **نوعِ
     * متفاوتی** از صفحه است: کارتِ کشورها را نشان می‌دهد نه جدولِ پلن. جدا
     * سنجیده می‌شود، چون یکی‌کردنشان یعنی یا این تست دروغ بگوید یا آن صفحه
     * بی‌دلیل قرمز شود.
     */
    public function test_every_country_page_shows_plans_or_says_why_not(): void
    {
        $this->seedCatalog();

        $index = lroute('cloud.index');
        $vague = [];

        foreach ($this->countryLinks() as $link) {
            if (rtrim($link['url'], '/') === rtrim($index, '/')) {
                continue;
            }

            $html = $this->get($link['url'])->assertOk()->getContent();

            $hasPlans = str_contains($html, 'plan-table') || str_contains($html, 'cvl-');
            $saysEmpty = str_contains($html, 'به‌زودی') || str_contains($html, 'موجود نیست')
                || str_contains($html, 'در دسترس نیست');

            if (! $hasPlans && ! $saysEmpty) {
                $vague[] = $link['url'];
            }
        }

        $this->assertSame([], $vague,
            "\nصفحهٔ گنگ (نه جدول، نه توضیحِ خالی‌بودن):\n".implode("\n", $vague));
    }

    /** و خودِ صفحهٔ فراگیر باید کارتِ کشورها را بدهد */
    public function test_the_catch_all_index_shows_country_cards(): void
    {
        $this->seedCatalog();

        $html = $this->get(lroute('cloud.index'))->assertOk()->getContent();

        $this->assertTrue(str_contains($html, 'cvps-'),
            'صفحهٔ /cloud کارتِ کشور نشان نمی‌دهد');

        // هر کشورِ کاتالوگ باید کارت داشته باشد، وگرنه پلن‌هایش نامرئی‌اند
        foreach (['آلمان', 'فرانسه', 'سنگاپور'] as $name) {
            $this->assertStringContainsString($name, $html, "کشورِ {$name} کارت ندارد");
        }
    }

    /**
     * 🔴 صفحهٔ کشور نباید زیرِ هدرِ ثابت برود.
     *
     * `cloud-location` با `.section` شروع می‌شود و آن کلاس ۱۱۰ پیکسل فاصله دارد
     * در برابرِ هدرِ ۱۱۱ پیکسلی — یعنی واقعاً یک پیکسل زیرِ هدر بود. قاعدهٔ
     * سراسریِ `#main{padding-top:var(--header-h)}` رفعش کرد؛ این‌جا فقط
     * می‌سنجیم که این صفحات از همان قاعده استفاده می‌کنند و جبرانِ دستیِ
     * دوباره ندارند.
     */
    public function test_country_pages_rely_on_the_global_header_offset(): void
    {
        $css = file_get_contents(public_path('assets/css/site.css'));

        $this->assertTrue(str_contains($css, '#main > .section:first-child'),
            'صفحاتی که با .section شروع می‌شوند از قاعدهٔ هدر جا افتاده‌اند');

        foreach (['cloud', 'cloud-location'] as $page) {
            $html = file_get_contents(resource_path('views/pages/'.$page.'.blade.php'));

            $this->assertDoesNotMatchRegularExpression(
                '~padding-top:\s*1[0-9]{2}px~', $html,
                "$page جبرانِ دستیِ هدر دارد — با قاعدهٔ سراسری فاصلهٔ دوبرابر می‌شود"
            );
        }
    }
}
