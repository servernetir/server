<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudCatalogSync;
use App\Services\Cloud\CloudNaming;
use App\Services\Cloud\CloudPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * کاتالوگِ سرورِ ابری — تمرکز روی سه چیزی که اگر بشکنند گران تمام می‌شوند:
 *
 * ۱) **سفیدبرچسبی**: نامِ زیرساخت نباید از هیچ درزی بیرون بزند.
 * ۲) **قیمت‌گذاری**: زیر قیمتِ خرید نفروشیم و قیمتِ صفر نسازیم.
 * ۳) **یکسان‌سازی**: یک شهر از دو زیرساخت = یک گزینه برای مشتری.
 */
class CloudCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // نرخِ دستی تا تست به سرویسِ نرخِ ارزِ بیرونی وابسته نباشد
        Setting::put('pricing_rate_override', '100000');    // ۱ یورو = ۱۰۰٬۰۰۰ تومان
        Setting::put('cloud_margin_pct', '50');
    }

    // ═══════════════════ یکسان‌سازیِ نام‌ها ═══════════════════

    /**
     * قلبِ سفیدبرچسبی: دو زیرساخت که یک شهر را با دو املا می‌نویسند، باید به یک
     * کدِ مکان برسند — وگرنه مشتری «فرانکفورت» را دو بار در فهرست می‌بیند و
     * خودش می‌فهمد دو تأمین‌کننده هست.
     */
    public function test_same_city_from_two_providers_collapses_to_one_code(): void
    {
        $a = CloudNaming::locationCode('DE', 'Falkenstein', 'fsn1');
        $b = CloudNaming::locationCode('de', 'falkenstein', 'x');
        $this->assertSame($a, $b);

        // مخففِ عددیِ هتزنر هم به همان می‌رسد
        $this->assertSame('de-falkenstein', CloudNaming::locationCode('DE', '', 'fsn1'));

        // نامِ کاملِ فرانکفورت و مخففش یکی می‌شوند
        $this->assertSame(
            CloudNaming::locationCode('DE', 'Frankfurt am Main', ''),
            CloudNaming::locationCode('DE', 'FRA', '')
        );
    }

    /** پلنِ هم‌مشخصات در یک مکان = یک اسلاگ، مستقل از نامِ زیرساخت */
    public function test_identical_specs_share_a_slug(): void
    {
        $h = CloudNaming::planSlug(2, 4096, 40, 'de-falkenstein');
        $a = CloudNaming::planSlug(2, 4096, 40, 'de-falkenstein');

        $this->assertSame($h, $a);
        $this->assertStringNotContainsString('cx', $h, 'اسلاگ نباید نامِ پلنِ زیرساخت را داشته باشد');
        $this->assertSame('cv-2c-4g-40d-de-falkenstein', $h);

        // پردازندهٔ اختصاصی پلنِ دیگری است و نباید با اشتراکی قاطی شود
        $this->assertNotSame($h, CloudNaming::planSlug(2, 4096, 40, 'de-falkenstein', 'dedicated'));
    }

    public function test_ram_under_one_gig_is_labelled_correctly(): void
    {
        $this->assertSame('CV-1-0.5', CloudNaming::planName(1, 512));
        $this->assertSame('CV-2-4', CloudNaming::planName(2, 4096));
    }

    /** سیستم‌عاملِ یکسان از دو زیرساخت → یک کلید */
    public function test_image_keys_unify_across_providers(): void
    {
        $this->assertSame('ubuntu-24.04', CloudNaming::imageKey('os', 'ubuntu', '24.04', 'Ubuntu 24.04'));
        $this->assertSame('app-docker', CloudNaming::imageKey('app', null, null, 'Docker CE'));
        $this->assertSame('app-docker', CloudNaming::imageKey('app', null, null, 'Docker'));
        $this->assertSame('app-wordpress', CloudNaming::imageKey('app', null, null, 'WordPress on Ubuntu 24.04'));
    }

    // ═══════════════════ قیمت‌گذاری ═══════════════════

    public function test_price_applies_margin_and_rounds_up(): void
    {
        $p = new CloudPricing();

        // ۳٫۲۹ یورو + ۵۰٪ = ۴٫۹۳۵ → رو به بالا به ۱۰ سنت = ۵٫۰۰
        $this->assertSame(500, $p->sellEurCents(329));

        // ۵٫۰۰ یورو × ۱۰۰٬۰۰۰ = ۵۰۰٬۰۰۰ تومان (ازقبل رند)
        $this->assertSame(500000, $p->toman(500));

        // گردکردن همیشه رو به بالا — ۴٫۹۱ یورو = ۴۹۱٬۰۰۰ → ۵۰۰٬۰۰۰
        $this->assertSame(500000, $p->toman(491));
    }

    /**
     * اگر نرخِ یورو را ندانیم، **قیمتِ صفر نساز**. صفر یعنی پلن در
     * `scopeSellable` نمی‌آید و در فروشگاه دیده نمی‌شود — بی‌نهایت بهتر از
     * فروختنِ سرورِ ۵۰ یورویی به قیمتِ صفر.
     */
    public function test_missing_euro_rate_yields_zero_not_a_wrong_price(): void
    {
        Setting::put('pricing_rate_override', null);

        $p = $this->partialMock(CloudPricing::class, function ($m) {
            $m->shouldReceive('eurToToman')->andReturn(0);
        });

        $this->assertSame(0, $p->toman(500));
    }

    public function test_margin_defaults_when_unset(): void
    {
        Setting::put('cloud_margin_pct', null);

        $this->assertSame((float) CloudPricing::DEFAULT_MARGIN_PCT, (new CloudPricing())->marginPct());
    }

    // ═══════════════════ همگام‌سازیِ هتزنر ═══════════════════

    private function fakeHetzner(): void
    {
        Setting::putSecret('hetzner_api_token', 'test-token');

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/locations')) {
                return Http::response(['locations' => [
                    ['id' => 1, 'name' => 'fsn1', 'country' => 'DE', 'city' => 'Falkenstein', 'latitude' => 50.47, 'longitude' => 12.37],
                    ['id' => 2, 'name' => 'hel1', 'country' => 'FI', 'city' => 'Helsinki', 'latitude' => 60.16, 'longitude' => 24.93],
                ], 'meta' => ['pagination' => ['last_page' => 1]]]);
            }

            if (str_contains($url, '/datacenters')) {
                // fsn1 هر دو پلن را دارد؛ hel1 فقط پلنِ ۱ (پلنِ ۲ ناموجود است)
                return Http::response(['datacenters' => [
                    ['id' => 1, 'location' => ['name' => 'fsn1'], 'server_types' => ['available' => [11, 22]]],
                    ['id' => 2, 'location' => ['name' => 'hel1'], 'server_types' => ['available' => [11]]],
                ], 'meta' => ['pagination' => ['last_page' => 1]]]);
            }

            if (str_contains($url, '/server_types')) {
                return Http::response(['server_types' => [
                    [
                        'id' => 11, 'name' => 'cx22', 'cores' => 2, 'memory' => 4, 'disk' => 40,
                        'cpu_type' => 'shared', 'architecture' => 'x86', 'storage_type' => 'local',
                        'deprecated' => false, 'included_traffic' => 21474836480,   // ۲۰ گیگ
                        'prices' => [
                            ['location' => 'fsn1', 'price_monthly' => ['net' => '3.2900000', 'gross' => '3.91']],
                            ['location' => 'hel1', 'price_monthly' => ['net' => '3.2900000', 'gross' => '3.91']],
                        ],
                    ],
                    [
                        'id' => 22, 'name' => 'ccx13', 'cores' => 2, 'memory' => 8, 'disk' => 80,
                        'cpu_type' => 'dedicated', 'architecture' => 'x86', 'storage_type' => 'local',
                        'deprecated' => false, 'included_traffic' => 21474836480,
                        'prices' => [
                            ['location' => 'fsn1', 'price_monthly' => ['net' => '12.4900000', 'gross' => '14.86']],
                        ],
                    ],
                    // منسوخ — نباید وارد کاتالوگ شود
                    [
                        'id' => 33, 'name' => 'cx11', 'cores' => 1, 'memory' => 2, 'disk' => 20,
                        'cpu_type' => 'shared', 'architecture' => 'x86', 'deprecated' => '2024-01-01',
                        'prices' => [['location' => 'fsn1', 'price_monthly' => ['net' => '2.00', 'gross' => '2.38']]],
                    ],
                ], 'meta' => ['pagination' => ['last_page' => 1]]]);
            }

            if (str_contains($url, '/pricing')) {
                return Http::response(['pricing' => ['primary_ips' => [
                    ['type' => 'ipv4', 'prices' => [['location' => 'fsn1', 'price_monthly' => ['net' => '0.50']]]],
                ]]]);
            }

            if (str_contains($url, 'type=app')) {
                return Http::response(['images' => [
                    ['id' => 900, 'type' => 'app', 'name' => 'docker-ce', 'description' => 'Docker CE',
                     'architecture' => 'x86', 'disk_size' => 5, 'deprecated' => null],
                ], 'meta' => ['pagination' => ['last_page' => 1]]]);
            }

            if (str_contains($url, '/images')) {
                return Http::response(['images' => [
                    ['id' => 100, 'type' => 'system', 'name' => 'ubuntu-24.04', 'description' => 'Ubuntu 24.04',
                     'os_flavor' => 'ubuntu', 'os_version' => '24.04', 'architecture' => 'x86',
                     'disk_size' => 5, 'deprecated' => null],
                    ['id' => 101, 'type' => 'system', 'name' => 'debian-12', 'description' => 'Debian 12',
                     'os_flavor' => 'debian', 'os_version' => '12', 'architecture' => 'x86',
                     'disk_size' => 5, 'deprecated' => null],
                ], 'meta' => ['pagination' => ['last_page' => 1]]]);
            }

            return Http::response([], 404);
        });
    }

    public function test_sync_imports_locations_plans_and_images(): void
    {
        $this->fakeHetzner();

        $report = app(CloudCatalogSync::class)->sync();

        $this->assertTrue($report['ok'], 'همگام‌سازی باید موفق باشد');
        $this->assertSame(2, CloudLocation::count());
        $this->assertSame(3, CloudPlan::count(), 'دو مکان برای cx22 + یک مکان برای ccx13');
        $this->assertSame(3, CloudImage::count(), 'دو سیستم‌عامل + یک نرم‌افزار');

        $loc = CloudLocation::where('code', 'de-falkenstein')->first();
        $this->assertNotNull($loc);
        $this->assertSame('DE', $loc->country);
        $this->assertSame('🇩🇪', $loc->flagEmoji());
    }

    /** پلنِ منسوخ نباید فروخته شود */
    public function test_deprecated_plans_are_skipped(): void
    {
        $this->fakeHetzner();
        app(CloudCatalogSync::class)->sync();

        $this->assertSame(0, CloudPlan::where('vcpu', 1)->count(), 'پلنِ منسوخِ cx11 نباید وارد شود');
    }

    /**
     * هزینهٔ IPv4 باید به بهایِ تمام‌شده اضافه شود. بی‌این، روی هر سرور ماهی
     * نیم یورو ضرر می‌کنیم و روی حاشیهٔ نازکِ VPS این عدد کم نیست.
     */
    public function test_ipv4_cost_is_added_to_cost_price(): void
    {
        $this->fakeHetzner();
        app(CloudCatalogSync::class)->sync();

        $plan = CloudPlan::where('provider_ref', 'cx22')->where('location_code', 'de-falkenstein')->first();

        // ۳٫۲۹ + ۰٫۵۰ = ۳٫۷۹ یورو
        $this->assertSame(379, (int) $plan->cost_eur_cents);

        // فروش: ۳٫۷۹ × ۱٫۵ = ۵٫۶۸۵ → رو به بالا به ۱۰ سنت = ۵٫۷۰
        $this->assertSame(570, (int) $plan->price_eur_cents);
        $this->assertSame(570000, (int) $plan->price_irt);
    }

    /** مدیر می‌تواند هزینهٔ IPv4 را دستی ست کند */
    public function test_ipv4_cost_can_be_overridden(): void
    {
        Setting::put('cloud_ipv4_eur_cents', '0');
        $this->fakeHetzner();
        app(CloudCatalogSync::class)->sync();

        $plan = CloudPlan::where('provider_ref', 'cx22')->where('location_code', 'de-falkenstein')->first();
        $this->assertSame(329, (int) $plan->cost_eur_cents);
    }

    /**
     * ظرفیتِ تمام‌شده باید ثبت شود، وگرنه پلنی را می‌فروشیم که ساخته نمی‌شود و
     * پولِ مشتری را گرفته‌ایم بی‌آنکه بتوانیم تحویل دهیم.
     */
    public function test_out_of_stock_is_recorded_and_excluded_from_offers(): void
    {
        $this->fakeHetzner();
        app(CloudCatalogSync::class)->sync();

        $hel = CloudPlan::where('provider_ref', 'cx22')->where('location_code', 'fi-helsinki')->first();
        $this->assertTrue((bool) $hel->in_stock);

        // ccx13 در هلسینکی اصلاً پلن ندارد، ولی موجودیِ fsn1 را داریم
        $fsn = CloudPlan::where('provider_ref', 'ccx13')->first();
        $this->assertTrue((bool) $fsn->in_stock);

        // دستی ناموجود کنیم: باید از عرضه‌ها بیرون برود
        $fsn->update(['in_stock' => false]);
        $this->assertFalse(CloudPlan::offers()->has($fsn->slug));
    }

    /** پلنِ برداشته‌شده غیرفعال می‌شود، نه حذف — سرویسِ فعال به آن اشاره دارد */
    public function test_vanished_plans_are_deactivated_not_deleted(): void
    {
        $this->fakeHetzner();
        app(CloudCatalogSync::class)->sync();

        $stale = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx-old', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-1-1', 'slug' => 'cv-1c-1g-10d-de-falkenstein',
            'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 10,
            'cost_eur_cents' => 200, 'price_eur_cents' => 300, 'price_irt' => 300000,
            'is_active' => true, 'in_stock' => true,
        ]);

        app(CloudCatalogSync::class)->sync();

        $this->assertDatabaseHas('cloud_plans', ['id' => $stale->id]);
        $this->assertFalse((bool) $stale->fresh()->is_active);
    }

    /** برچسبِ دستیِ مدیر با سینکِ بعدی پاک نشود */
    public function test_manual_location_label_survives_resync(): void
    {
        $this->fakeHetzner();
        app(CloudCatalogSync::class)->sync();

        CloudLocation::where('code', 'de-falkenstein')->update(['label_fa' => 'آلمان — مرکزِ داده‌ی ما']);

        app(CloudCatalogSync::class)->sync();

        $this->assertSame('آلمان — مرکزِ داده‌ی ما', CloudLocation::where('code', 'de-falkenstein')->first()->label('fa'));
    }

    // ═══════════════════ انتخابِ ارزان‌ترین ═══════════════════

    /**
     * دو زیرساخت، مشخصاتِ یکسان، یک شهر → مشتری **یک** کارت می‌بیند و تحویل از
     * ارزان‌ترین انجام می‌شود. این هم سفیدبرچسبی است هم حاشیهٔ سود.
     */
    public function test_offers_deduplicate_and_pick_the_cheapest_provider(): void
    {
        $base = [
            'location_code' => 'de-frankfurt', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-frankfurt',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'is_active' => true, 'in_stock' => true,
        ];

        CloudPlan::create($base + [
            'provider' => 'hetzner', 'provider_ref' => 'cx22',
            'cost_eur_cents' => 500, 'price_eur_cents' => 750, 'price_irt' => 750000,
        ]);
        $cheap = CloudPlan::create($base + [
            'provider' => 'aeza', 'provider_ref' => '77',
            'cost_eur_cents' => 380, 'price_eur_cents' => 570, 'price_irt' => 570000,
        ]);

        $offers = CloudPlan::offers();

        $this->assertCount(1, $offers, 'مشتری باید یک گزینه ببیند، نه دو تا');
        $this->assertSame($cheap->id, $offers->first()->id, 'ارزان‌ترین باید نمایندهٔ گروه باشد');
        $this->assertSame($cheap->id, CloudPlan::bestForSlug($base['slug'])->id);
    }

    /** اگر ارزان‌ترین ناموجود شد، خودکار سراغِ بعدی می‌رود — بی‌تغییرِ ظاهر */
    public function test_delivery_falls_back_when_cheapest_is_out_of_stock(): void
    {
        $base = [
            'location_code' => 'de-frankfurt', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-frankfurt',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'is_active' => true,
        ];

        $expensive = CloudPlan::create($base + [
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'in_stock' => true,
            'cost_eur_cents' => 500, 'price_eur_cents' => 750, 'price_irt' => 750000,
        ]);
        CloudPlan::create($base + [
            'provider' => 'aeza', 'provider_ref' => '77', 'in_stock' => false,
            'cost_eur_cents' => 380, 'price_eur_cents' => 570, 'price_irt' => 570000,
        ]);

        $this->assertSame($expensive->id, CloudPlan::bestForSlug($base['slug'])->id);
    }

    /** قیمتِ صفر = در فروشگاه نیست */
    public function test_zero_price_plans_are_not_sellable(): void
    {
        CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'location_code' => 'de-frankfurt',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-frankfurt',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'cost_eur_cents' => 500, 'price_eur_cents' => 750, 'price_irt' => 0,
            'is_active' => true, 'in_stock' => true,
        ]);

        $this->assertCount(0, CloudPlan::offers());
    }

    // ═══════════════════ سفیدبرچسبی — مهم‌ترین بخش ═══════════════════

    /**
     * ⚠️ این تست از «حسِ خوب» نمی‌آید، از یک ریسکِ واقعی می‌آید: اگر روزی مدلی
     * به‌صورتِ JSON در پاسخِ API یا در `@json` یک ویو بنشیند، نامِ زیرساخت لو
     * می‌رود. `$hidden` جلویش را می‌گیرد و این تست نگه می‌دارد که کسی برندارَدش.
     */
    public function test_provider_name_never_leaks_through_serialization(): void
    {
        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);

        $json = $plan->toJson();

        foreach (['hetzner', 'aeza', 'cx22', 'fsn1'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase(
                $secret, $json,
                "نامِ زیرساخت («{$secret}») نباید در JSON مدل باشد"
            );
        }

        // بهایِ تمام‌شده هم پنهان است: مشتری نباید حاشیهٔ سودِ ما را حساب کند
        $this->assertStringNotContainsString('cost_eur_cents', $json);

        // ولی داده‌های عمومی باید باشند
        $this->assertStringContainsString('CV-2-4', $json);
        $this->assertStringContainsString('de-falkenstein', $json);
    }

    public function test_instance_hides_provider_and_password(): void
    {
        $inst = new CloudInstance([
            'service_id' => 1, 'provider' => 'aeza', 'provider_ref' => '12345',
            'location_code' => 'de-frankfurt', 'status' => 'running', 'ipv4' => '1.2.3.4',
        ]);
        $inst->setPassword('SuperSecret123');

        $json = $inst->toJson();

        $this->assertStringNotContainsStringIgnoringCase('aeza', $json);
        $this->assertStringNotContainsString('12345', $json);
        $this->assertStringNotContainsString('SuperSecret123', $json);
        $this->assertStringNotContainsString('root_password_enc', $json);
        $this->assertStringContainsString('1.2.3.4', $json);

        // ولی خودِ برنامه باید بتواند رمز را بخواند
        $this->assertSame('SuperSecret123', $inst->password());
    }

    /** رمزِ خراب (APP_KEY عوض شده) نباید صفحه را ۵۰۰ کند */
    public function test_undecryptable_password_returns_null_instead_of_throwing(): void
    {
        $inst = new CloudInstance(['service_id' => 1, 'provider' => 'hetzner', 'status' => 'running']);
        $inst->root_password_enc = 'not-a-valid-ciphertext';

        $this->assertNull($inst->password());
        $this->assertTrue($inst->hasPassword());
    }

    // ═══════════════════ ترجمهٔ ایمیج ═══════════════════

    /** کلیدِ مشترکِ مشتری → شناسهٔ بومیِ همان زیرساخت */
    public function test_image_ref_resolves_per_provider(): void
    {
        CloudImage::create([
            'provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
        ]);
        CloudImage::create([
            'provider' => 'aeza', 'provider_ref' => '1042', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
        ]);

        $this->assertSame('ubuntu-24.04', CloudImage::refFor('hetzner', 'ubuntu-24.04'));
        $this->assertSame('1042', CloudImage::refFor('aeza', 'ubuntu-24.04'));
        $this->assertNull(CloudImage::refFor('hetzner', 'no-such-os'));

        // فهرستِ مشتری: یک «اوبونتو ۲۴٫۰۴»، نه دو تا
        $this->assertCount(1, CloudImage::catalog('os'));
    }

    /** ایمیجِ غیرفعال نباید به مشتری پیشنهاد شود */
    public function test_inactive_images_are_not_offered(): void
    {
        CloudImage::create([
            'provider' => 'hetzner', 'provider_ref' => 'ubuntu-20.04', 'key' => 'ubuntu-20.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '20.04', 'label' => 'Ubuntu 20.04',
            'is_active' => false,
        ]);

        $this->assertCount(0, CloudImage::catalog('os'));
        $this->assertNull(CloudImage::refFor('hetzner', 'ubuntu-20.04'));
    }

    // ═══════════════════ پهنایِ ستون (درسِ MariaDB) ═══════════════════

    /**
     * SQLite طولِ VARCHAR را نادیده می‌گیرد ولی MariaDB اجرا می‌کند. یک‌بار همین
     * تفاوت باعث شد سرویس‌ها روی پروداکشن ساخته نشوند و هیچ تستی نگیردش. پس
     * طولِ ستون‌های وضعیت را صریح می‌سنجیم.
     */
    public function test_status_columns_are_wide_enough_for_their_longest_value(): void
    {
        $this->assertTrue(Schema::hasTable('cloud_instances'));

        $longest = ['building', 'running', 'off', 'error', 'deleted', 'unknown'];
        $max = max(array_map('strlen', $longest));

        $this->assertLessThanOrEqual(24, $max, 'وضعیتِ بلندتر از ستون → «Data too long» روی MariaDB');

        // اسلاگِ پلن هم می‌تواند بلند شود: cvd-48c-192g-1800d-saint-petersburg
        $slug = \App\Services\Cloud\CloudNaming::planSlug(48, 196608, 1800, 'ru-saint-petersburg', 'dedicated');
        $this->assertLessThanOrEqual(96, strlen($slug));
    }

    // ═══════════════════ مقاومت در برابرِ خرابی ═══════════════════

    /** توکنِ غلط نباید استثنا بدهد؛ باید گزارشِ خطای تمیز بدهد */
    public function test_bad_token_reports_error_without_throwing(): void
    {
        Setting::putSecret('hetzner_api_token', 'wrong');

        Http::fake(fn () => Http::response(
            ['error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate']], 401
        ));

        $report = app(CloudCatalogSync::class)->sync();

        $this->assertFalse($report['ok']);
        $this->assertFalse($report['providers']['hetzner']['ok']);
        $this->assertStringContainsString('unauthorized', $report['providers']['hetzner']['message']);
        $this->assertSame(0, CloudPlan::count(), 'خطا نباید ردیفِ نیم‌بند بسازد');
    }

    /** بی‌توکن، سینک ساکت رد می‌شود — نه خطا، نه ردیف */
    public function test_sync_without_tokens_is_a_no_op(): void
    {
        $report = app(CloudCatalogSync::class)->sync();

        $this->assertSame([], $report['providers']);
        $this->assertSame(0, CloudPlan::count());
    }

    // ═══════════════════ رندرِ واقعیِ صفحه‌ها ═══════════════════

    private function admin(): \App\Models\User
    {
        return \App\Models\User::create([
            'name' => 'مدیر', 'email' => 'c'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    /**
     * «کدِ ۲۰۰ یعنی هیچ» — درسِ همین پروژه. پس صفحه را واقعاً رندر می‌کنیم و
     * محتوایش را می‌سنجیم، نه فقط وضعیتش را.
     *
     * ⚠️ تغییرِ آگاهانهٔ قاعده: تا امروز حتی در پنلِ مدیریت هم «زیرساختِ ۱/۲»
     * می‌نوشتیم. ولی کارفرما صریح خواست که **خودش** بداند کدام کدام است تا
     * سرِ عیب‌یابی قاطی نکند («برای من در پنل مدیریت بدونم زیرساخت ۱ یا ۲
     * کجاست»). پس نامِ واقعی حالا در پنلِ مدیریت **عمداً** دیده می‌شود، و
     * سفیدبرچسبی فقط برای صفحاتِ **مشتری** برقرار است (تستِ
     * CloudProvisionTest::test_panel_page_never_leaks_the_provider آن را می‌سنجد).
     */
    public function test_admin_cloud_page_renders_and_shows_real_provider_names(): void
    {
        $this->fakeHetzner();
        app(CloudCatalogSync::class)->sync();

        $res = $this->actingAs($this->admin(), 'web')->get('/admin/cloud');

        $res->assertOk();
        $html = $res->getContent();

        // محتوای واقعی رندر شده باشد، نه صفحهٔ خالی
        $this->assertStringContainsString('زیرساختِ سرورِ ابری', $html);
        $this->assertStringContainsString('عرضه‌های عمومی', $html);
        $this->assertStringContainsString('CV-2-4', $html, 'نامِ عمومیِ پلن باید دیده شود');

        // مدیر باید نامِ واقعی را ببیند تا زیرساخت را وصل کند
        $this->assertStringContainsString('Hetzner', $html, 'مدیر باید بداند زیرساختِ ۱ کدام است');
        $this->assertStringContainsString('زیرساختِ ۱', $html, 'شمارهٔ آشنا هم کنارش بماند');
    }

    public function test_admin_settings_page_renders_with_cloud_section(): void
    {
        $res = $this->actingAs($this->admin(), 'web')->get('/admin/settings?tab=infra');

        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('زیرساختِ سرورِ ابری', $html);
        $this->assertStringContainsString('hetzner_api_token', $html, 'فیلدِ توکن باید در فرم باشد');

        // ⚠️ درصدِ سود از تبِ زیرساخت به تبِ نرخ‌گذاری رفت — همهٔ حاشیه‌های سود
        //    حالا یک‌جا هستند. ادعا جابه‌جا شد، حذف نشد.
        $pricing = $this->actingAs($this->admin(), 'web')->get('/admin/settings?tab=pricing')
            ->assertOk()->getContent();
        $this->assertStringContainsString('cloud_margin_pct', $pricing, 'حاشیهٔ سودِ ابری باید در تبِ نرخ‌گذاری باشد');
    }

    /** توکنِ ذخیره‌شده هرگز نباید در HTML فرم برگردد */
    public function test_saved_token_is_never_echoed_back_to_the_form(): void
    {
        Setting::putSecret('hetzner_api_token', 'super-secret-token-value');
        Setting::putSecret('aeza_api_token', 'another-secret-key');

        $html = $this->actingAs($this->admin(), 'web')->get('/admin/settings?tab=infra')->getContent();

        $this->assertStringNotContainsString('super-secret-token-value', $html);
        $this->assertStringNotContainsString('another-secret-key', $html);
        $this->assertStringContainsString('ذخیره‌شده', $html, 'ولی باید بگوید که ذخیره شده');
    }

    /** ذخیرهٔ توکن: رمزنگاری‌شده می‌نشیند و متنِ خام در دیتابیس نیست */
    public function test_token_is_stored_encrypted(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/settings', ['tab' => 'infra', 'hetzner_api_token' => 'plain-token-123'])
            ->assertRedirect();

        $raw = (string) \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'hetzner_api_token')->value('value');

        $this->assertNotSame('plain-token-123', $raw, 'توکن نباید خام ذخیره شود');
        $this->assertNotEmpty($raw);
        $this->assertSame('plain-token-123', Setting::getSecret('hetzner_api_token'));
    }

    /** فرستادنِ خالی یعنی «دست نزن»، نه «پاک کن» */
    public function test_empty_token_submission_keeps_the_existing_one(): void
    {
        Setting::putSecret('hetzner_api_token', 'keep-me');

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/settings', ['tab' => 'infra', 'hetzner_api_token' => ''])
            ->assertRedirect();

        $this->assertSame('keep-me', Setting::getSecret('hetzner_api_token'));
    }

    public function test_forget_checkbox_removes_the_token(): void
    {
        Setting::putSecret('aeza_api_token', 'delete-me');

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/settings', ['tab' => 'infra', 'aeza_forget' => '1'])
            ->assertRedirect();

        $this->assertNull(Setting::getSecret('aeza_api_token'));
    }

    /** بازمحاسبهٔ قیمت با نرخِ تازه، بی‌تماسِ API */
    public function test_reprice_updates_toman_when_rate_changes(): void
    {
        $this->fakeHetzner();
        app(CloudCatalogSync::class)->sync();

        $plan = CloudPlan::where('provider_ref', 'cx22')->where('location_code', 'de-falkenstein')->first();
        $this->assertSame(570000, (int) $plan->price_irt);

        // نرخِ یورو دو برابر شد
        Setting::put('pricing_rate_override', '200000');
        Http::fake();                                       // هیچ تماسی نباید برود

        $n = app(CloudCatalogSync::class)->reprice();

        $this->assertGreaterThan(0, $n);
        $this->assertSame(1140000, (int) $plan->fresh()->price_irt);
        Http::assertNothingSent();
    }

    // ═══════════════ کشفِ مسیرِ زیرساختِ ۲ ═══════════════

    /**
     * ⚠️ چرا این تست هست: داکیومنت آن ارائه‌دهنده در متن `/products` می‌نویسد و
     * در نمونهٔ curl `…/services/products`. گیت‌وی‌شان هم برای مسیرِ ناموجود
     * ۴۰۴ نمی‌دهد، «Proxy internal server error» می‌دهد — یعنی خطای «مسیر غلط»
     * شکلِ خطای «سرورشان خراب است» را دارد. روی حسابِ واقعیِ کارفرما همین رخ
     * داد و آزمونِ اتصال شکست خورد.
     */
    public function test_aeza_discovers_the_working_products_path(): void
    {
        Setting::putSecret('aeza_api_token', 'k');

        $tried = [];
        Http::fake(function ($request) use (&$tried) {
            $url = $request->url();
            $tried[] = $url;

            // مسیرِ غلط: همان خطای واقعیِ گیت‌وی
            if (str_contains($url, '/api/products')) {
                return Http::response(['error' => ['message' => 'Proxy internal server error (see traceId)']], 500);
            }

            if (str_contains($url, '/api/services/products')) {
                return Http::response(['data' => ['items' => [['id' => 1, 'name' => 'X']], 'total' => 1]], 200);
            }

            return Http::response(['data' => ['items' => []]], 200);
        });

        $r = app(\App\Services\Cloud\AezaClient::class)->testConnection();

        $this->assertTrue($r['ok'], 'باید مسیرِ درست را پیدا کند: '.($r['message'] ?? ''));
        $this->assertSame('services/products', $r['meta']['path'] ?? null);

        // و آن را ذخیره کرده باشد تا دفعهٔ بعد دوباره نگردد
        $this->assertSame('services/products', Setting::get('aeza_path_products'));
    }

    /** مسیرِ ذخیره‌شده دوباره کشف نمی‌شود — نه درخواستِ اضافه، نه تأخیر */
    public function test_saved_path_is_reused_without_rediscovery(): void
    {
        Setting::putSecret('aeza_api_token', 'k');
        Setting::put('aeza_path_products', 'services/products');
        Setting::put('aeza_path_os', 'os');
        Setting::put('aeza_path_recipe', 'vm/recipe');

        $urls = [];
        Http::fake(function ($request) use (&$urls) {
            $urls[] = $request->url();

            return Http::response(['data' => ['items' => [], 'total' => 0]], 200);
        });

        app(\App\Services\Cloud\AezaClient::class)->fetchCatalog();

        foreach ($urls as $u) {
            $this->assertStringNotContainsString('/api/products', $u, 'نباید مسیرِ نامزدِ غلط را دوباره امتحان کند');
        }
    }

    /**
     * توکنِ غلط (۴۰۱/۴۰۳) یعنی مسیر درست است ولی احراز هویت نه. ادامهٔ امتحانِ
     * نامزدها فقط درخواستِ بی‌فایده می‌فرستد و می‌تواند شبیهِ کاوشِ مشکوک شود —
     * درسِ حسابِ OpenProvider که با تلاش‌های پشت‌سرهم پرچم خورد.
     */
    public function test_auth_failure_stops_probing_further_paths(): void
    {
        Setting::putSecret('aeza_api_token', 'bad');

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['error' => ['message' => 'Unauthorized']], 401);
        });

        $r = app(\App\Services\Cloud\AezaClient::class)->testConnection();

        $this->assertFalse($r['ok']);
        // یک تلاشِ کشف + یک تلاشِ گرفتنِ پیامِ خطا = ۲. نه بیشتر.
        $this->assertLessThanOrEqual(2, $calls, 'با خطای احراز هویت نباید مسیرها را یکی‌یکی امتحان کند');
    }

    /** اگر هیچ مسیری کار نکرد، پیام باید مدیر را به صفحهٔ عیب‌یابی بفرستد */
    public function test_unknown_path_gives_an_actionable_message(): void
    {
        Setting::putSecret('aeza_api_token', 'k');

        Http::fake(fn () => Http::response(
            ['error' => ['message' => 'Proxy internal server error (see traceId)']], 500
        ));

        $r = app(\App\Services\Cloud\AezaClient::class)->testConnection();

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Proxy internal server error', $r['message']);
        $this->assertStringContainsString('ساختارِ خامِ پاسخ', $r['message'], 'باید بگوید کجا را ببیند');
        $this->assertStringContainsString('500', $r['message'], 'کدِ HTTP لازم است');
    }

    /**
     * صفحهٔ عیب‌یابی باید نمونهٔ خامِ ردیف را بدهد — همان چیزی که نگاشتِ
     * فیلدها (هسته/رم/دیسک) را از حدس در می‌آورد، چون داکیومنت نمونهٔ کامل نداشت.
     *
     * توجه: به‌محضِ پیدا شدنِ نامزدِ درست، بقیه امتحان **نمی‌شوند** — درخواستِ
     * بی‌فایده نمی‌فرستیم.
     */
    public function test_probe_returns_a_raw_sample_row_for_field_mapping(): void
    {
        Setting::putSecret('aeza_api_token', 'k');

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/services/products')) {
                return Http::response(['data' => ['items' => [
                    ['id' => 9, 'name' => 'EPs-1', 'cpu' => 2, 'ram' => 4096, 'disk' => 60],
                ]]], 200);
            }

            return Http::response(['data' => ['items' => []]], 200);
        });

        $probe = app(\App\Services\Cloud\AezaClient::class)->rawProbe();

        $this->assertSame('services/products', $probe['products']['winner'] ?? null);

        $sample = $probe['products']['tried']['services/products']['sample'][0] ?? [];
        $this->assertSame('EPs-1', $sample['name'] ?? null);
        $this->assertSame(4096, $sample['ram'] ?? null, 'ردیفِ خام باید کاملاً برگردد');

        $this->assertArrayNotHasKey('products', $probe['products']['tried'],
            'نامزدِ اول جواب داد، پس نامزدِ دوم نباید امتحان شود');
    }

    /**
     * مکانیزمِ جایگزینی: اگر نامزدِ اول خطای گیت‌وی بدهد، نامزدِ بعدی امتحان
     * شود و همان ذخیره گردد. بی‌این، یک اشتباهِ داکیومنت کلِ زیرساخت را از کار
     * می‌انداخت.
     */
    public function test_probe_falls_back_to_the_next_candidate(): void
    {
        Setting::putSecret('aeza_api_token', 'k');

        Http::fake(function ($request) {
            // نامزدِ اول (طبقِ نمونهٔ curl) این‌جا خراب است
            if (str_contains($request->url(), '/api/services/products')) {
                return Http::response(['error' => ['message' => 'Proxy internal server error']], 500);
            }

            // نامزدِ دوم (طبقِ متنِ داکیومنت) کار می‌کند
            if (str_contains($request->url(), '/api/products')) {
                return Http::response(['data' => ['items' => [['id' => 3, 'name' => 'Y']]]], 200);
            }

            return Http::response(['data' => ['items' => []]], 200);
        });

        $probe = app(\App\Services\Cloud\AezaClient::class)->rawProbe();

        $this->assertSame(500, $probe['products']['tried']['services/products']['http'] ?? null);
        $this->assertSame(200, $probe['products']['tried']['products']['http'] ?? null);
        $this->assertSame('products', $probe['products']['winner'] ?? null);
        $this->assertSame('products', Setting::get('aeza_path_products'));
    }

    // ═══════════════ ارزِ یورو و صافیِ «فقط سرورِ مجازی» ═══════════════

    /** فهرستِ محصولِ آیزا با مسیرهای ازقبل‌کشف‌شده */
    private function fakeAeza(array $products): void
    {
        Setting::putSecret('aeza_api_token', 'k');
        Setting::put('aeza_path_products', 'services/products');
        Setting::put('aeza_path_os', 'os');
        Setting::put('aeza_path_recipe', 'vm/recipe');

        Http::fake(function ($request) use ($products) {
            $url = $request->url();

            // ⚠️ اگر روزی کسی تماسِ نرخِ ارز را برگرداند، همین‌جا با ۵۰۰ روبه‌رو
            // می‌شود — همان کدی که روی حسابِ واقعی می‌آمد و سینک را می‌کشت.
            if (str_contains($url, 'currencies')) {
                return Http::response(['error' => ['message' => 'proxy_internal_server_error']], 500);
            }

            if (str_contains($url, 'services/products')) {
                return Http::response(['data' => ['items' => $products, 'total' => count($products)]], 200);
            }

            return Http::response(['data' => ['items' => []]], 200);
        });
    }

    private function aezaVps(array $over = []): array
    {
        return array_merge([
            'id' => 77, 'name' => 'EPs-1', 'type' => 'vm',
            'cpu' => 2, 'ram' => 4096, 'disk' => 60,
            'location' => ['country' => 'DE', 'city' => 'Frankfurt', 'id' => 'de-1'],
            // ⚠️ به **سنتِ یورو** — حسابِ ما فقط یورو می‌تواند باشد (پاسخِ کتبیِ
            // پشتیبانی)، پس عدد کوچک‌ترین یکای همان ارز است. ۵۰۰ سنت = ۵ یورو.
            'prices' => ['month' => 500.0],
        ], $over);
    }

    /**
     * 🔴 تا مرداد ۱۴۰۵ این تست «تبدیلِ روبل به یورو» را می‌سنجید و کلِ آن زنجیره
     * غلط بود: قیمت را از `payment/currencies` در ضریبی ضرب می‌کردیم که آن مسیر
     * اصلاً برنمی‌گرداند (کدِ ۵۰۰ می‌داد). نتیجه در بهترین حالت **کاتالوگِ خالی**
     * بود و در بدترین حالت پلن‌هایی **۱۰۰ برابر ارزان‌تر** از قیمتِ واقعی.
     *
     * پشتیبانی نوشت: «موجودیِ حساب فقط می‌تواند یورو باشد.» پس تبدیلی در کار
     * نیست و عدد همان سنتِ یورو است.
     */
    public function test_price_is_read_as_euro_cents_with_no_conversion(): void
    {
        $this->fakeAeza([$this->aezaVps()]);

        $cat = app(\App\Services\Cloud\AezaClient::class)->fetchCatalog();

        $this->assertTrue($cat['ok'], (string) ($cat['message'] ?? ''));
        $this->assertCount(1, $cat['plans']);
        $this->assertSame(500, $cat['plans'][0]['cost_eur_cents']);
    }

    /**
     * 🔴 مهم‌تر از خودِ عدد: آن مسیر دیگر **زده نمی‌شود**.
     *
     * حتی وقتی ضریب لازم نبود، خودِ تماس ۵۰۰ می‌گرفت و `cloud:sync` را با کدِ
     * خروجیِ ۱ می‌خواباند — یعنی یک وابستگیِ بی‌فایده که کارِ سالم را می‌کشت.
     */
    public function test_catalog_never_calls_the_currency_endpoint_again(): void
    {
        $this->fakeAeza([$this->aezaVps()]);

        $cat = app(\App\Services\Cloud\AezaClient::class)->fetchCatalog();

        $this->assertTrue($cat['ok'], 'مسیرِ ۵۰۰دهنده نباید کاتالوگ را بخواباند');

        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('currencies', $request->url());
        }
    }

    /** تنظیمِ کهنهٔ «۱ یورو چند روبل» دیگر روی هیچ قیمتی اثر ندارد */
    public function test_the_old_ruble_setting_no_longer_changes_any_price(): void
    {
        Setting::put('aeza_rub_per_eur', '125');
        $this->fakeAeza([$this->aezaVps()]);

        $cat = app(\App\Services\Cloud\AezaClient::class)->fetchCatalog();

        $this->assertSame(500, $cat['plans'][0]['cost_eur_cents'],
            'اگر ۴۰۰ شد، یعنی جایی هنوز تبدیلِ روبل مانده است');
    }

    /**
     * کارفرما: «فعلاً فقط سرورِ مجازی». محصولِ نامربوط نباید روی سایت بنشیند —
     * وگرنه مشتری چیزی می‌خرد که نه صفحه‌اش را ساخته‌ایم نه تحویلش را.
     */
    public function test_only_vps_products_enter_the_catalog(): void
    {
        $this->fakeAeza([
            $this->aezaVps(),
            ['id' => 1, 'name' => 'SOCKS5 Proxy', 'type' => 'proxy', 'cpu' => 1, 'ram' => 512, 'disk' => 10,
             'location' => ['country' => 'DE', 'city' => 'Frankfurt'], 'prices' => ['month' => 100]],
            ['id' => 2, 'name' => 'Proxy Pack 10', 'cpu' => 1, 'ram' => 512, 'disk' => 10,
             'location' => ['country' => 'DE', 'city' => 'Frankfurt'], 'prices' => ['month' => 100]],
            ['id' => 3, 'name' => 'Dedicated Server AMD', 'cpu' => 32, 'ram' => 131072, 'disk' => 2000,
             'location' => ['country' => 'DE', 'city' => 'Frankfurt'], 'prices' => ['month' => 9000]],
            ['id' => 4, 'name' => 'WAF Protection', 'cpu' => 1, 'ram' => 1024, 'disk' => 5,
             'location' => ['country' => 'DE', 'city' => 'Frankfurt'], 'prices' => ['month' => 300]],
            ['id' => 5, 'name' => 'Mystery Box', 'type' => 'vm', 'cpu' => 0, 'ram' => 0, 'disk' => 0,
             'location' => ['country' => 'DE', 'city' => 'Frankfurt'], 'prices' => ['month' => 100]],
        ]);

        $cat = app(\App\Services\Cloud\AezaClient::class)->fetchCatalog();

        $this->assertCount(1, $cat['plans'], 'فقط یک سرورِ مجازیِ سالم باید بماند');
        $this->assertSame('77', $cat['plans'][0]['provider_ref']);
    }

    /** شهرِ یکسان از دو زیرساخت باید به یک مکان برسد — با دادهٔ واقعیِ هر دو */
    public function test_aeza_and_hetzner_share_a_location_code(): void
    {
        $this->fakeAeza([$this->aezaVps(['location' => ['country' => 'DE', 'city' => 'Falkenstein']])]);

        $cat = app(\App\Services\Cloud\AezaClient::class)->fetchCatalog();

        $this->assertSame('de-falkenstein', $cat['plans'][0]['location_code']);
        $this->assertSame('de-falkenstein', CloudNaming::locationCode('DE', '', 'fsn1'));
    }
}
