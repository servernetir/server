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
     * محتوایش را می‌سنجیم، نه فقط وضعیتش را. یک `@` سرگردان در کامنتِ Blade
     * قبلاً کلِ بدنهٔ یک صفحه را بلعیده بود بی‌آنکه کد ۲۰۰ عوض شود.
     */
    public function test_admin_cloud_page_renders_without_leaking_provider_names(): void
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

        // ⚠️ حتی در پنلِ مدیریت هم نامِ ارائه‌دهنده نوشته نمی‌شود («زیرساختِ ۱/۲»)
        foreach (['hetzner', 'Hetzner', 'aeza', 'Aeza', 'cx22', 'CX22'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "«{$secret}» نباید در HTML باشد");
        }
    }

    public function test_admin_settings_page_renders_with_cloud_section(): void
    {
        $res = $this->actingAs($this->admin(), 'web')->get('/admin/settings');

        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('زیرساختِ سرورِ ابری', $html);
        $this->assertStringContainsString('hetzner_api_token', $html, 'فیلدِ توکن باید در فرم باشد');
        $this->assertStringContainsString('حاشیهٔ سودِ سرورِ ابری', $html);
    }

    /** توکنِ ذخیره‌شده هرگز نباید در HTML فرم برگردد */
    public function test_saved_token_is_never_echoed_back_to_the_form(): void
    {
        Setting::putSecret('hetzner_api_token', 'super-secret-token-value');
        Setting::putSecret('aeza_api_token', 'another-secret-key');

        $html = $this->actingAs($this->admin(), 'web')->get('/admin/settings')->getContent();

        $this->assertStringNotContainsString('super-secret-token-value', $html);
        $this->assertStringNotContainsString('another-secret-key', $html);
        $this->assertStringContainsString('ذخیره‌شده', $html, 'ولی باید بگوید که ذخیره شده');
    }

    /** ذخیرهٔ توکن: رمزنگاری‌شده می‌نشیند و متنِ خام در دیتابیس نیست */
    public function test_token_is_stored_encrypted(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/settings', ['hetzner_api_token' => 'plain-token-123'])
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
            ->post('/admin/settings', ['hetzner_api_token' => ''])
            ->assertRedirect();

        $this->assertSame('keep-me', Setting::getSecret('hetzner_api_token'));
    }

    public function test_forget_checkbox_removes_the_token(): void
    {
        Setting::putSecret('aeza_api_token', 'delete-me');

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/settings', ['aeza_forget' => '1'])
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
}
