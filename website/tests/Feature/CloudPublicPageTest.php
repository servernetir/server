<?php

namespace Tests\Feature;

use App\Http\Controllers\CloudCatalogController;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * صفحاتِ عمومیِ فروشِ سرورِ مجازی — /cloud و /cloud/{location}.
 *
 * ═══ چرا این تست‌ها و نه تستِ «کد ۲۰۰» ═══
 *
 * درسِ همین پروژه: «کد ۲۰۰ یعنی هیچ». یک `@کلمه`ی سرگردان در Blade — حتی داخلِ
 * کامنت — کلِ بدنهٔ صفحه را می‌بلعد و پاسخ هنوز ۲۰۰ است. یک کلیدِ زبانِ جاافتاده
 * «ui.cloud_h1» را روی صفحه چاپ می‌کند و پاسخ هنوز ۲۰۰ است. پس هر تست این‌جا
 * **محتوای رندرشده** را می‌سنجد:
 *
 *   ۱) هر سه زبان: برچسبِ بومیِ مکان‌ها + قیمت با ارزِ درست
 *   ۲) سفیدبرچسبی: نامِ زیرساخت در بدنهٔ صفحه نباشد
 *      (⚠️ سنجش روی `<main>` است نه کلِ HTML، چون مگامنوی سراسریِ سایت از قبل
 *       صفحهٔ محصولِ /dedicated/hetzner را دارد و آن محصولِ دیگری است)
 *   ۳) JSON-LD واقعاً json_decode شود و context داشته باشد
 *   ۴) کاتالوگِ خالی صفحهٔ آبرومند بدهد نه ۵۰۰
 *   ۵) هیچ «{{» کامپایل‌نشده و هیچ «ui.» خام در خروجی نماند
 *
 * روت‌ها این‌جا ثبت می‌شوند چون `routes/web.php` مرزِ هماهنگ‌کننده است؛ ثبتِ
 * تستی عیناً همان الگوی سه‌زبانهٔ closureِ `$site` را بازمی‌سازد.
 */
class CloudPublicPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerCloudRoutes();
    }

    /**
     * ثبتِ روت‌ها + **بازچینشِ ترتیب**.
     *
     * ⚠️ درسِ گران‌قیمتی که همین تست بیرون کشید: در `routes/web.php` دو روتِ
     * فراگیر وجود دارد که هر دو مسیرِ ما را می‌قاپند اگر جلوترشان نباشیم —
     *
     *   • `/{category}/{slug}` (کاتالوگ، با category در ['vps','cloud',…]) →
     *     `/cloud/de-falkenstein` را می‌گیرد و ۴۰۴ می‌دهد.
     *   • `/{loc}/{rest?}` (هدایتِ پیشوندِ زبانِ بزرگ‌حرف) → `/en/cloud` را
     *     می‌گیرد و چون `en` از قبل کوچک است، `abort(404)` می‌کند.
     *
     * پس قطعهٔ روتِ تحویل‌داده‌شده **باید** داخلِ closureِ `$site` و **بالاتر از**
     * `Route::get('/{category}/{slug}')` بنشیند. روتِ ثبت‌شده در تست به انتهای
     * فهرست می‌رود، پس این‌جا مجموعه را دستی بازمی‌چینیم تا همان ترتیبِ نهاییِ
     * فایلِ واقعی سنجیده شود — وگرنه تستِ سبز، چیزی را ثابت می‌کرد که در
     * پروداکشن ۴۰۴ است.
     */
    private function registerCloudRoutes(): void
    {
        $group = function (): void {
            Route::get('/cloud', [CloudCatalogController::class, 'index'])->name('cloud.index');
            Route::get('/cloud/{location}', [CloudCatalogController::class, 'location'])
                ->name('cloud.location')->where('location', '[a-z0-9-]+');
        };

        Route::middleware(['web', 'locale:fa'])->group($group);
        Route::middleware(['web', 'locale:en'])->prefix('en')->name('en.')->group($group);
        Route::middleware(['web', 'locale:tr'])->prefix('tr')->name('tr.')->group($group);

        $ours = [
            'cloud.index', 'cloud.location',
            'en.cloud.index', 'en.cloud.location',
            'tr.cloud.index', 'tr.cloud.location',
        ];

        $mine = [];
        $rest = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array((string) $route->getName(), $ours, true)) {
                $mine[] = $route;
            } else {
                $rest[] = $route;
            }
        }

        $collection = new RouteCollection;
        foreach (array_merge($mine, $rest) as $route) {
            $collection->add($route);
        }

        Route::setRoutes($collection);
    }

    // ─────────────────────────── داده ───────────────────────────

    private function plan(array $attrs): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'disk_type' => 'nvme',
            'traffic_gb' => 20480,
            'cpu_kind' => 'shared',
            'arch' => 'x86',
            'is_active' => true,
            'in_stock' => true,
        ], $attrs));
    }

    /**
     * کاتالوگِ نمونه: چهار مکانِ فعال در چهار قاره/منطقه + یک مکانِ **غیرفعال**.
     *
     * مکانِ غیرفعال عمداً ارزان‌ترین پلن را دارد؛ اگر جایی از کد مکانِ غیرفعال را
     * فیلتر نکند، «شروع از ۳۰۰٬۰۰۰» تبلیغ می‌کنیم و مشتری چیزی را می‌بیند که در
     * فهرست پیدا نمی‌کند.
     */
    private function seedCatalog(): void
    {
        CloudLocation::create(['code' => 'de-falkenstein', 'country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true, 'sort' => 1]);
        CloudLocation::create(['code' => 'fi-helsinki',    'country' => 'FI', 'city' => 'Helsinki',    'is_active' => true, 'sort' => 2]);
        CloudLocation::create(['code' => 'ae-dubai',       'country' => 'AE', 'city' => 'Dubai',       'is_active' => true, 'sort' => 3]);
        CloudLocation::create(['code' => 'us-ashburn',     'country' => 'US', 'city' => 'Ashburn',     'is_active' => true, 'sort' => 4]);
        CloudLocation::create(['code' => 'ru-moscow',      'country' => 'RU', 'city' => 'Moscow',      'is_active' => false, 'sort' => 5]);

        // یک اسلاگ، دو زیرساخت → مشتری باید **یک** ردیف ببیند، با قیمتِ ارزان‌تر
        $this->plan([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-falkenstein', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'cost_eur_cents' => 500, 'price_eur_cents' => 750, 'price_irt' => 750000,
        ]);
        $this->plan([
            'provider' => 'aeza', 'provider_ref' => '77',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-falkenstein', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'cost_eur_cents' => 380, 'price_eur_cents' => 570, 'price_irt' => 570000,
        ]);
        $this->plan([
            'provider' => 'hetzner', 'provider_ref' => 'ccx13', 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CVD-4-8',
            'slug' => 'cvd-4c-8g-80d-de-falkenstein', 'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80,
            'cpu_kind' => 'dedicated', 'traffic_gb' => 0,
            'cost_eur_cents' => 900, 'price_eur_cents' => 1350, 'price_irt' => 1350000,
        ]);
        $this->plan([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'hel1',
            'location_code' => 'fi-helsinki', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-fi-helsinki', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'cost_eur_cents' => 420, 'price_eur_cents' => 630, 'price_irt' => 630000,
        ]);
        $this->plan([
            'provider' => 'aeza', 'provider_ref' => '91',
            'location_code' => 'ae-dubai', 'public_name' => 'CV-2-2',
            'slug' => 'cv-2c-2g-30d-ae-dubai', 'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 30,
            'cost_eur_cents' => 600, 'price_eur_cents' => 900, 'price_irt' => 900000,
        ]);
        $this->plan([
            'provider' => 'aeza', 'provider_ref' => '92',
            'location_code' => 'us-ashburn', 'public_name' => 'CV-1-1',
            'slug' => 'cv-1c-1g-20d-us-ashburn', 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20,
            'cost_eur_cents' => 300, 'price_eur_cents' => 450, 'price_irt' => 450000,
        ]);
        // مکانِ غیرفعال — ارزان‌ترین، ولی نباید هیچ‌جا دیده شود
        $this->plan([
            'provider' => 'aeza', 'provider_ref' => '93',
            'location_code' => 'ru-moscow', 'public_name' => 'CV-1-2',
            'slug' => 'cv-1c-2g-20d-ru-moscow', 'vcpu' => 1, 'ram_mb' => 2048, 'disk_gb' => 20,
            'cost_eur_cents' => 200, 'price_eur_cents' => 300, 'price_irt' => 300000,
        ]);
    }

    // ─────────────────────────── ابزارِ سنجش ───────────────────────────

    /** فقط بدنهٔ صفحه — بی‌هدر و فوتر و مگامنوی سراسری */
    private function mainOf(string $html): string
    {
        $start = strpos($html, '<main id="main">');
        $end = strpos($html, '</main>');
        $this->assertNotFalse($start, 'قالبِ سایت رندر نشده — <main> در خروجی نیست');
        $this->assertNotFalse($end, 'قالبِ سایت نیمه‌کاره رندر شده — </main> نیست');

        return substr($html, $start, $end - $start);
    }

    /** هیچ Blade نیم‌کامپایل و هیچ کلیدِ زبانِ خام نماند */
    private function assertRenderIsClean(string $main): void
    {
        $this->assertStringNotContainsString('{{', $main, 'یک عبارتِ Blade کامپایل نشده و خام چاپ شده');
        $this->assertStringNotContainsString('@endsection', $main, 'دایرکتیوِ Blade به خروجی رسیده');
        $this->assertStringNotContainsString('ui.cloud_', $main, 'کلیدِ زبانِ نبود، خام چاپ شده');
        $this->assertStringNotContainsString('ui.brand', $main);
    }

    /** @return array<int, array<string, mixed>> بلوک‌های JSON-LD صفحه، decode‌شده */
    private function jsonLdBlocks(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1], 'هیچ بلوکِ JSON-LD در صفحه نیست');

        $out = [];
        foreach ($m[1] as $raw) {
            $data = json_decode(trim($raw), true);
            $this->assertIsArray($data, 'JSON-LD معتبر نیست: '.substr($raw, 0, 120));
            $this->assertArrayHasKey('@context', $data, 'JSON-LD بدونِ context برای گوگل بی‌اعتبار است');
            $this->assertSame('https://schema.org', $data['@context']);
            $this->assertArrayHasKey('@type', $data);
            $out[] = $data;
        }

        return $out;
    }

    /** @param  array<int, array<string, mixed>>  $blocks */
    private function ldOfType(array $blocks, string $type): ?array
    {
        foreach ($blocks as $b) {
            if (($b['@type'] ?? null) === $type) {
                return $b;
            }
        }

        return null;
    }

    // ═══════════════════════ ۱) رندرِ سه‌زبانه ═══════════════════════

    public function test_index_renders_in_persian_with_localized_locations_and_toman(): void
    {
        $this->seedCatalog();

        $res = $this->get('/cloud');
        $res->assertOk();
        $main = $this->mainOf($res->getContent());

        $this->assertRenderIsClean($main);

        // عنوان و متنِ فارسی
        $this->assertStringContainsString('سرور مجازی', $main);

        // نامِ بومیِ کشور و شهر (از CloudLocation::COUNTRIES و CITIES_FA)
        $this->assertStringContainsString('آلمان', $main);
        $this->assertStringContainsString('فالکن‌اشتاین', $main);
        $this->assertStringContainsString('فینلاند', $main);
        $this->assertStringContainsString('امارات', $main);
        $this->assertStringContainsString('آمریکا', $main);

        // گروه‌بندیِ قاره‌ای
        $this->assertStringContainsString('اروپا', $main);
        $this->assertStringContainsString('خاورمیانه و قفقاز', $main);
        $this->assertStringContainsString('آمریکای شمالی', $main);

        // قیمتِ تومانی با رقمِ فارسی — ارزان‌ترینِ دو زیرساختِ فالکن‌اشتاین
        $this->assertStringContainsString('تومان', $main);
        $this->assertStringContainsString('۵۷۰,۰۰۰ تومان', $main);
        $this->assertStringNotContainsString('۷۵۰,۰۰۰', $main, 'قیمتِ زیرساختِ گران‌تر نباید نمایش داده شود');

        // مکانِ غیرفعال هیچ‌جا نیست
        $this->assertStringNotContainsString('مسکو', $main);
        $this->assertStringNotContainsString('۳۰۰,۰۰۰', $main);

        // ارزِ دیگری قاطی نشده
        $this->assertStringNotContainsString('€', $main);
    }

    public function test_index_renders_in_english_with_euro(): void
    {
        $this->seedCatalog();

        $res = $this->get('/en/cloud');
        $res->assertOk();
        $main = $this->mainOf($res->getContent());

        $this->assertRenderIsClean($main);

        $this->assertStringContainsString('Cloud VPS', $main);
        $this->assertStringContainsString('Germany', $main);
        $this->assertStringContainsString('Falkenstein', $main);
        $this->assertStringContainsString('Finland', $main);
        $this->assertStringContainsString('United States', $main);
        $this->assertStringContainsString('Europe', $main);
        $this->assertStringContainsString('North America', $main);

        $this->assertStringContainsString('€5.70', $main);
        $this->assertStringContainsString('€4.50', $main);
        $this->assertStringNotContainsString('تومان', $main);
        $this->assertStringNotContainsString('Moscow', $main);
    }

    public function test_index_renders_in_turkish(): void
    {
        $this->seedCatalog();

        $res = $this->get('/tr/cloud');
        $res->assertOk();
        $main = $this->mainOf($res->getContent());

        $this->assertRenderIsClean($main);

        $this->assertStringContainsString('Bulut VPS', $main);
        $this->assertStringContainsString('Almanya', $main);
        $this->assertStringContainsString('Avrupa', $main);
        $this->assertStringContainsString('Kuzey Amerika', $main);
        $this->assertStringContainsString('€5.70', $main);
        $this->assertStringNotContainsString('تومان', $main);
    }

    // ═══════════════════════ ۲) سفیدبرچسبی ═══════════════════════

    /**
     * ⚠️ مهم‌ترین تستِ این صفحه. اگر نامِ زیرساخت بیرون بزند، مشتری می‌تواند
     * همان سرور را مستقیم و ارزان‌تر بخرد.
     */
    public function test_no_infrastructure_name_leaks_into_the_page_body(): void
    {
        $this->seedCatalog();

        foreach (['/cloud', '/en/cloud', '/tr/cloud', '/cloud/de-falkenstein', '/en/cloud/de-falkenstein'] as $url) {
            $main = $this->mainOf($this->get($url)->assertOk()->getContent());

            foreach (['hetzner', 'aeza', 'cx22', 'ccx13', 'fsn1', 'hel1', 'provider_ref', 'cost_eur_cents'] as $secret) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $secret, $main,
                    "«{$secret}» نباید در بدنهٔ {$url} باشد"
                );
            }
        }
    }

    /** دو زیرساخت با مشخصاتِ یکسان در یک شهر = یک ردیف در جدول */
    public function test_duplicate_specs_from_two_providers_render_as_one_row(): void
    {
        $this->seedCatalog();

        $main = $this->mainOf($this->get('/cloud')->assertOk()->getContent());

        // هر ردیفِ جدول یک data-row دارد؛ ۵ عرضهٔ قابلِ فروش داریم (۶ پلن منهای
        // تکراری) و مسکو غیرفعال است → ۵ ردیف
        $this->assertSame(5, substr_count($main, 'data-row="1"'), 'تعدادِ ردیف‌ها با تعدادِ عرضه‌ها نمی‌خواند');

        // اسلاگِ مشترک فقط یک بار در لینکِ خرید می‌آید
        $this->assertSame(1, substr_count($main, 'plan=cv-2c-4g-40d-de-falkenstein'));
    }

    // ═══════════════════════ ۳) JSON-LD ═══════════════════════

    public function test_index_json_ld_is_valid_and_complete(): void
    {
        $this->seedCatalog();

        $blocks = $this->jsonLdBlocks($this->get('/cloud')->assertOk()->getContent());

        $crumbs = $this->ldOfType($blocks, 'BreadcrumbList');
        $list = $this->ldOfType($blocks, 'ItemList');
        $faq = $this->ldOfType($blocks, 'FAQPage');

        $this->assertNotNull($crumbs, 'BreadcrumbList نیست');
        $this->assertNotNull($list, 'ItemList نیست');
        $this->assertNotNull($faq, 'FAQPage نیست');

        $this->assertCount(2, $crumbs['itemListElement']);

        // Product + Offer با ارزِ ISO. تومان کدِ ISO ندارد، پس IRR و مبلغ ×۱۰.
        $first = $list['itemListElement'][0]['item'];
        $this->assertSame('Product', $first['@type']);
        $this->assertSame('Offer', $first['offers']['@type']);
        $this->assertSame('IRR', $first['offers']['priceCurrency']);
        $this->assertSame('4500000', $first['offers']['price'], 'ارزان‌ترین (۴۵۰٬۰۰۰ تومان) = ۴٬۵۰۰٬۰۰۰ ریال');
        $this->assertSame(5, $list['numberOfItems']);

        // نامِ محصول باید سفیدبرچسب باشد
        $this->assertStringNotContainsStringIgnoringCase('cx', $first['name']);

        $this->assertNotEmpty($faq['mainEntity']);
        $this->assertSame('Question', $faq['mainEntity'][0]['@type']);
        $this->assertSame('Answer', $faq['mainEntity'][0]['acceptedAnswer']['@type']);
    }

    public function test_english_json_ld_uses_euro(): void
    {
        $this->seedCatalog();

        $blocks = $this->jsonLdBlocks($this->get('/en/cloud')->assertOk()->getContent());
        $list = $this->ldOfType($blocks, 'ItemList');

        $offer = $list['itemListElement'][0]['item']['offers'];
        $this->assertSame('EUR', $offer['priceCurrency']);
        $this->assertSame('4.50', $offer['price']);
    }

    public function test_location_json_ld_has_three_breadcrumb_levels(): void
    {
        $this->seedCatalog();

        $blocks = $this->jsonLdBlocks($this->get('/cloud/de-falkenstein')->assertOk()->getContent());
        $crumbs = $this->ldOfType($blocks, 'BreadcrumbList');

        $this->assertNotNull($crumbs);
        $this->assertCount(3, $crumbs['itemListElement']);
        $this->assertStringContainsString('/cloud', $crumbs['itemListElement'][1]['item']);
        $this->assertStringContainsString('/cloud/de-falkenstein', $crumbs['itemListElement'][2]['item']);
        $this->assertNotNull($this->ldOfType($blocks, 'ItemList'));
        $this->assertNotNull($this->ldOfType($blocks, 'FAQPage'));
    }

    // ═══════════════════════ ۴) صفحهٔ مکان ═══════════════════════

    public function test_location_page_has_unique_seo_text_latency_and_plans(): void
    {
        $this->seedCatalog();

        $res = $this->get('/cloud/de-falkenstein');
        $res->assertOk();
        $main = $this->mainOf($res->getContent());

        $this->assertRenderIsClean($main);

        // عنوانِ یکتا
        $this->assertStringContainsString('سرور مجازی آلمان — فالکن‌اشتاین', $main);

        // «چرا این مکان» — تیترِ h2 با نامِ شهر + جملهٔ ویژهٔ آلمان
        $this->assertStringContainsString('چرا فالکن‌اشتاین؟', $main);
        $this->assertStringContainsString('بزرگ‌ترین گرهِ اینترنتِ اروپا', $main);

        // تأخیرِ تقریبی به ایران و اروپا (اعدادِ جدولِ LATENCY برای DE: ۹۵ و ۸)
        $this->assertStringContainsString('به ایران', $main);
        $this->assertStringContainsString('به اروپا', $main);
        $this->assertStringContainsString('۹۵', $main);
        $this->assertStringContainsString('ms', $main);

        // «مناسبِ چه کاری»
        $this->assertStringContainsString('مناسبِ چه کاری است؟', $main);

        // پلن‌های همین مکان و نه مکانِ دیگر
        $this->assertStringContainsString('CV-2-4', $main);
        $this->assertStringContainsString('CVD-4-8', $main);
        $this->assertStringNotContainsString('CV-1-1', $main, 'پلنِ مکانِ دیگر نباید این‌جا باشد');

        // قیمت
        $this->assertStringContainsString('۵۷۰,۰۰۰ تومان', $main);

        // «مصرفِ منصفانه» برای پلنی که ترافیکش صفر است
        $this->assertStringContainsString('مصرفِ منصفانه', $main);
    }

    /** متنِ دو مکان باید واقعاً فرق کند، وگرنه محتوای نازک است */
    public function test_two_locations_do_not_share_the_same_seo_text(): void
    {
        $this->seedCatalog();

        $de = $this->mainOf($this->get('/cloud/de-falkenstein')->assertOk()->getContent());
        $ae = $this->mainOf($this->get('/cloud/ae-dubai')->assertOk()->getContent());

        $this->assertStringContainsString('بزرگ‌ترین گرهِ اینترنتِ اروپا', $de);
        $this->assertStringContainsString('کم‌ترین تأخیر را به کاربرِ ایرانی', $ae);

        // فهرستِ «مناسبِ چه کاری» هم بر پایهٔ منطقه فرق می‌کند
        $this->assertStringContainsString('مخاطبِ اروپایی', $de);
        $this->assertStringContainsString('کم‌ترین تأخیر', $ae);
        $this->assertNotSame($de, $ae);
    }

    public function test_location_page_renders_in_english_and_turkish(): void
    {
        $this->seedCatalog();

        $en = $this->mainOf($this->get('/en/cloud/de-falkenstein')->assertOk()->getContent());
        $this->assertRenderIsClean($en);
        $this->assertStringContainsString('Cloud VPS in Germany', $en);
        $this->assertStringContainsString('to Iran', $en);
        $this->assertStringContainsString('€5.70', $en);

        $tr = $this->mainOf($this->get('/tr/cloud/de-falkenstein')->assertOk()->getContent());
        $this->assertRenderIsClean($tr);
        $this->assertStringContainsString('Almanya', $tr);
        $this->assertStringContainsString('€5.70', $tr);
    }

    public function test_unknown_or_inactive_location_is_404(): void
    {
        $this->seedCatalog();

        $this->get('/cloud/xx-nowhere')->assertNotFound();
        $this->get('/cloud/ru-moscow')->assertNotFound();      // مکانِ غیرفعال
        $this->get('/en/cloud/xx-nowhere')->assertNotFound();
    }

    /**
     * ⚠️ رگرسیونی که نزدیک بود از دست برود: `/cloud/{slug}` از قبل صفحاتِ
     * بازاریابیِ کاتالوگ را داشت (`/cloud/iaas`، `/cloud/ai-infrastructure`).
     * چون روتِ مکان‌ها **بالاتر** از روتِ فراگیرِ کاتالوگ می‌نشیند، آن نشانی‌ها از
     * کنترلرِ ما رد می‌شوند و باید همان صفحهٔ قبلی را بدهند، نه ۴۰۴.
     *
     * `ai-infrastructure` بدترین حالت است: شکلش عیناً شکلِ کدِ مکان است
     * (دو حرف + خط تیره)، پس با هیچ الگوی روتی نمی‌شد جدایش کرد.
     */
    public function test_existing_cloud_catalog_pages_still_work_under_the_same_prefix(): void
    {
        $this->seedCatalog();

        foreach (['iaas', 'ai-infrastructure', 'object-storage'] as $slug) {
            $res = $this->get('/cloud/'.$slug);
            $res->assertOk();

            $main = $this->mainOf($res->getContent());
            $this->assertStringNotContainsString('cvl-head', $main, "/cloud/{$slug} باید صفحهٔ کاتالوگ باشد نه صفحهٔ مکان");
            $this->assertStringNotContainsString('{{', $main);
        }

        // و انگلیسی هم همین‌طور
        $this->get('/en/cloud/iaas')->assertOk();
    }

    /** مکانی که پلنِ فروختنی ندارد: صفحه بالا بیاید، ولی جدولِ خالیِ آبرومند */
    public function test_location_without_sellable_plans_renders_gracefully(): void
    {
        $this->seedCatalog();
        CloudPlan::where('location_code', 'us-ashburn')->update(['in_stock' => false]);

        $res = $this->get('/cloud/us-ashburn');
        $res->assertOk();
        $main = $this->mainOf($res->getContent());

        $this->assertRenderIsClean($main);
        $this->assertStringContainsString('پلنِ آماده‌ای در این مکان نداریم', $main);
        $this->assertStringContainsString('همهٔ مکان‌ها', $main);
    }

    // ═══════════════════════ ۵) لینکِ خرید ═══════════════════════

    public function test_buy_links_point_to_the_panel_vps_builder_with_location_and_plan(): void
    {
        $this->seedCatalog();

        $fa = $this->mainOf($this->get('/cloud')->assertOk()->getContent());
        $this->assertStringContainsString('/account/cloud-store?location=us-ashburn', $fa);
        $this->assertStringContainsString('plan=cv-1c-1g-20d-us-ashburn', $fa);

        // پیشوندِ زبان روی لینکِ پنل هم می‌آید
        $en = $this->mainOf($this->get('/en/cloud')->assertOk()->getContent());
        $this->assertStringContainsString('/en/account/cloud-store?location=', $en);

        // صفحهٔ مکان هم دکمهٔ خرید دارد
        $loc = $this->mainOf($this->get('/cloud/ae-dubai')->assertOk()->getContent());
        $this->assertStringContainsString('/account/cloud-store?location=ae-dubai', $loc);
    }

    // ═══════════════════════ ۶) فیلترِ سمتِ کاربر ═══════════════════════

    public function test_filters_only_offer_values_that_actually_exist(): void
    {
        $this->seedCatalog();

        $main = $this->mainOf($this->get('/cloud')->assertOk()->getContent());

        // کشورهای موجود
        foreach (['DE', 'FI', 'AE', 'US'] as $code) {
            $this->assertStringContainsString('value="'.$code.'"', $main);
        }
        // کشورِ مکانِ غیرفعال نباید گزینه داشته باشد
        $this->assertStringNotContainsString('value="RU"', $main);

        // دادهٔ فیلتر روی ردیف‌ها
        $this->assertStringContainsString('data-c="DE"', $main);
        $this->assertStringContainsString('data-v="4"', $main);
        $this->assertStringContainsString('data-r="8192"', $main);
        $this->assertStringContainsString('data-p="450000"', $main);

        // مرتب‌سازی و شمارنده
        $this->assertStringContainsString('id="clp-sort"', $main);
        $this->assertStringContainsString('id="clp-count"', $main);
    }

    // ═══════════════════════ ۷) کاتالوگِ خالی ═══════════════════════

    public function test_index_with_empty_catalog_is_graceful_not_an_error(): void
    {
        $res = $this->get('/cloud');
        $res->assertOk();
        $main = $this->mainOf($res->getContent());

        $this->assertRenderIsClean($main);
        $this->assertStringContainsString('کاتالوگِ سرورِ ابری در حالِ آماده‌سازی است', $main);
        $this->assertStringContainsString('تماس با ما', $main);

        // جدول و فیلتر ساخته نمی‌شوند
        $this->assertStringNotContainsString('id="clp-body"', $main);
        $this->assertStringNotContainsString('data-row="1"', $main);

        // ولی نانِ خرده‌نانِ ساختاریافته باید باشد تا صفحه برای گوگل بی‌هویت نماند
        $blocks = $this->jsonLdBlocks($res->getContent());
        $this->assertNotNull($this->ldOfType($blocks, 'BreadcrumbList'));
        $this->assertNull($this->ldOfType($blocks, 'ItemList'), 'ItemListِ خالی نباید منتشر شود');
    }

    public function test_empty_catalog_in_english_and_turkish_too(): void
    {
        $en = $this->mainOf($this->get('/en/cloud')->assertOk()->getContent());
        $this->assertRenderIsClean($en);
        $this->assertStringContainsString('cloud catalogue is being prepared', $en);
    }

    /** پلنِ ناموجود یا قیمتِ صفر = فروشگاهِ خالی، نه ردیفِ خراب */
    public function test_out_of_stock_and_zero_price_plans_never_reach_the_page(): void
    {
        $this->seedCatalog();
        CloudPlan::query()->update(['in_stock' => false]);

        $main = $this->mainOf($this->get('/cloud')->assertOk()->getContent());
        $this->assertStringContainsString('کاتالوگِ سرورِ ابری در حالِ آماده‌سازی است', $main);

        CloudPlan::query()->update(['in_stock' => true, 'price_irt' => 0]);

        $main = $this->mainOf($this->get('/cloud')->assertOk()->getContent());
        $this->assertStringContainsString('کاتالوگِ سرورِ ابری در حالِ آماده‌سازی است', $main);
    }

    // ═══════════════════════ ۸) لینک‌سازیِ داخلی ═══════════════════════

    public function test_internal_links_connect_cloud_to_solutions_and_back(): void
    {
        $this->seedCatalog();

        $index = $this->mainOf($this->get('/cloud')->assertOk()->getContent());
        $this->assertStringContainsString('/solutions', $index);
        $this->assertStringContainsString('/contact', $index);
        $this->assertStringContainsString('/cloud/de-falkenstein', $index, 'هر مکان باید از فهرست لینک بگیرد');
        $this->assertStringContainsString('/cloud/us-ashburn', $index);

        $loc = $this->mainOf($this->get('/cloud/de-falkenstein')->assertOk()->getContent());
        $this->assertStringContainsString('/solutions', $loc);
        $this->assertStringContainsString('href="'.url('/cloud').'"', $loc, 'صفحهٔ مکان باید به فهرست برگردد');
        // مکان‌های نزدیک: هم‌قاره‌ها اول
        $this->assertStringContainsString('/cloud/fi-helsinki', $loc);
    }

    /** صفحهٔ فهرست در هر سه زبان باید canonical و alternate درست بدهد */
    public function test_locale_alternates_are_present(): void
    {
        $this->seedCatalog();

        $html = $this->get('/cloud')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/cloud').'">', $html);
        $this->assertStringContainsString('hreflang="en" href="'.url('/en/cloud').'"', $html);
        $this->assertStringContainsString('hreflang="tr" href="'.url('/tr/cloud').'"', $html);
    }
}
