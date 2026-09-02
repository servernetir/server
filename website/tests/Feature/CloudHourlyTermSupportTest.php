<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudProvider;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 ساعتی فقط روی ردیفی فروخته شود که زیرساخت واقعاً ساعتی می‌فروشدش.
 *
 * ═══ رخدادِ واقعی (۱۰–۱۱ شهریور ۱۴۰۵) ═══
 *
 * سرویس‌های #۹۶ و #۹۸ — سوئد/استکهلم، «۸۰۰ تومان در ساعت»، پرداخت از کیفِ
 * پول — هر دو با این پاسخ شکستند:
 *
 *     400 {"error":"Product 269 does not support term 'hour'"}
 *
 * ۸۰۰ عددِ حدسیِ ما بود: ceil(530000/720) گردشده به ۱۰۰. یعنی `hourlyIrt()`
 * که «ماهانه ÷ ۷۲۰» را به‌عنوانِ **کفِ قیمت** داشت، به‌عنوانِ **مجوزِ فروش**
 * خوانده می‌شد. زیرساخت آن محصول را ساعتی نمی‌فروخت، ولی فروشگاه ساعتی
 * عرضه‌اش می‌کرد، پول را از کیفِ پول کم می‌کرد، و شکست را سرِ تحویل کشف
 * می‌کرد. یک مشتری شش سفارشِ لغوشده پشتِ‌سرِهم گرفت.
 *
 * ⚠️ کامنتِ `CloudProvisioner` فرض کرده بود «زیرساختی که term را نپذیرد خودش
 * month می‌گیرد، پس فرستادنش برای همه بی‌خطر است». این پرونده دقیقاً همان
 * فرضِ نانوشته را می‌بندد: بعضی زیرساخت‌ها **رد می‌کنند**.
 */
class CloudHourlyTermSupportTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    // ───────────────────────── فیکسچرها ─────────────────────────

    private function location(string $code = 'se-stockholm'): void
    {
        CloudLocation::firstOrCreate(['code' => $code],
            ['country' => 'SE', 'city' => 'Stockholm', 'is_active' => true]);
    }

    /**
     * یک ردیفِ پلن. `cost_hour_eur_micro` عمداً صریح است — همان ستونی که
     * زیرساخت پرش می‌کند وقتی تعرفهٔ ساعتی دارد.
     */
    private function plan(string $provider, array $over = []): CloudPlan
    {
        $this->location((string) ($over['location_code'] ?? 'se-stockholm'));

        return CloudPlan::create(array_merge([
            // ⚠️ یکتاییِ (provider, provider_ref, location_code) در جدول هست؛
            //    مرجعِ تکراری فیکسچر را می‌شکند نه ادعا را.
            'provider' => $provider, 'provider_ref' => $provider.'-'.self::$seq++,
            'provider_location' => 'sto', 'location_code' => 'se-stockholm',
            'public_name' => 'CV-1-4', 'slug' => 'cv-1c-4g-10d-se-stockholm',
            'vcpu' => 1, 'ram_mb' => 4096, 'disk_gb' => 10, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 201, 'price_eur_cents' => 260, 'price_irt' => 530000,
            'cost_hour_eur_micro' => null,
            'is_active' => true, 'in_stock' => true,
        ], $over));
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'ht'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-pass-123'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function image(string $provider): CloudImage
    {
        return CloudImage::firstOrCreate(['provider' => $provider, 'key' => 'ubuntu-24.04'], [
            'provider_ref' => 'ubuntu-24-04', 'kind' => 'os', 'family' => 'ubuntu',
            'version' => '24.04', 'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'is_active' => true,
        ]);
    }

    private function hourlyOrder(CloudPlan $plan): Service
    {
        $this->image((string) $plan->provider);

        return Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'سرور مجازی ساعتی', 'currency_code' => 'IRT',
            'price' => 530000, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => 800, 'status' => 'awaiting_provision',
            'provision_status' => 'pending', 'activated_at' => now(),
            'last_metered_at' => now(), 'cloud_plan_id' => $plan->id,
            'cloud_image_key' => 'ubuntu-24.04',
        ]);
    }

    /**
     * درایورِ جاسوس: می‌شمارد که آیا اصلاً صدا زده شد.
     *
     * 🔴 مهم‌ترین ادعای این پرونده «سفارش رد شد» نیست، «**سفارش اصلاً فرستاده
     * نشد**» است. زیرساختِ سفارش‌محور برای هر POST پول می‌گیرد.
     */
    private function bindSpyDriver(string $slug, array $result): object
    {
        $spy = new class($slug, $result) implements CloudProvider
        {
            public int $created = 0;

            public array $lastSpec = [];

            public function __construct(private string $driverSlug, private array $result)
            {
            }

            public function slug(): string
            {
                return $this->driverSlug;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function testConnection(): array
            {
                return ['ok' => true, 'message' => ''];
            }

            public function fetchCatalog(): array
            {
                return ['ok' => true, 'message' => '', 'locations' => [], 'plans' => [], 'images' => []];
            }

            public function createServer(array $spec): array
            {
                $this->created++;
                $this->lastSpec = $spec;

                return array_merge([
                    'ok' => false, 'message' => '', 'ref' => null, 'ipv4' => null,
                    'ipv6' => null, 'root_password' => null, 'status' => 'error',
                ], $this->result);
            }

            public function serverStatus(string $ref): array
            {
                return ['ok' => true, 'message' => '', 'status' => 'running',
                    'ipv4' => '203.0.113.9', 'ipv6' => null, 'traffic_used_gb' => null];
            }

            public function listServers(): array
            {
                return ['ok' => true, 'message' => '', 'servers' => []];
            }

            public function power(string $ref, string $action): array
            {
                return ['ok' => true, 'message' => ''];
            }

            public function rebuild(string $ref, string $imageRef, ?string $password = null): array
            {
                return ['ok' => true, 'message' => '', 'root_password' => null];
            }

            public function resetPassword(string $ref): array
            {
                return ['ok' => true, 'message' => '', 'root_password' => 'pw-'.$ref];
            }

            public function console(string $ref): array
            {
                return ['ok' => false, 'message' => '', 'url' => null, 'password' => null];
            }

            public function metrics(string $ref, string $window = '24h'): array
            {
                return ['ok' => false, 'message' => '', 'series' => []];
            }

            public function deleteServer(string $ref): array
            {
                return ['ok' => true, 'message' => ''];
            }

            public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array
            {
                return ['ok' => false, 'message' => ''];
            }

            public function uploadSshKey(string $name, string $publicKey): array
            {
                return ['ok' => false, 'message' => '', 'ref' => null];
            }

            public function addExtraIps(string $ref, int $count): array
            {
                return ['ok' => false, 'message' => '', 'ips' => []];
            }

            public function capabilities(): array
            {
                return ['ssh_key' => false];
            }
        };

        $this->partialMock(CloudManager::class,
            fn ($m) => $m->shouldReceive('forPlan')->andReturn($spy));

        return $spy;
    }

    // ═══════════ ۱) خودِ قاعده ═══════════

    /** پلنِ زیرساختِ ترم‌دار بی‌تعرفهٔ ساعتی = ساعتی‌فروش نیست. */
    public function test_term_based_provider_without_an_hourly_tariff_is_not_hourly_capable(): void
    {
        $this->assertFalse($this->plan('aeza')->supportsHourly(),
            'محصولِ ۲۶۹ تعرفهٔ ساعتی ندارد ولی ساعتی‌فروش شمرده شد — همان باگِ #۹۶/#۹۸');

        $this->assertTrue($this->plan('aeza', ['cost_hour_eur_micro' => 5000,
            'slug' => 'cv-1c-4g-10d-se-b'])->supportsHourly());
    }

    /** زیرساختِ بی‌ترم (هتزنر/پراکسموکس/آروان) همیشه ساعتی‌فروش است. */
    public function test_providers_without_a_billing_term_stay_hourly_capable(): void
    {
        foreach (['hetzner', 'proxmox', 'arvan'] as $i => $p) {
            $this->assertTrue(
                $this->plan($p, ['slug' => 'cv-x-'.$i, 'cost_hour_eur_micro' => null])->supportsHourly(),
                $p.' سفارشش اصلاً فیلدِ ترم ندارد؛ نباید از فروشِ ساعتی حذف شود'
            );
        }
    }

    /**
     * 🔴 اسکوپِ SQL و متدِ PHP باید روی **همان** مجموعه یک جواب بدهند.
     *
     * دو پیاده‌سازیِ موازیِ یک قاعده یعنی روزی یکی عوض می‌شود و دیگری جا
     * می‌مانَد — و آن‌وقت فروشگاه و تحویل دو حرفِ متفاوت می‌زنند.
     */
    public function test_hourly_capable_scope_matches_the_method(): void
    {
        $rows = [
            $this->plan('aeza', ['slug' => 's-a']),                                 // بی‌تعرفه
            $this->plan('aeza', ['slug' => 's-b', 'cost_hour_eur_micro' => 4200]),
            $this->plan('aeza', ['slug' => 's-c', 'cost_hour_eur_micro' => 0]),     // صفر = ندارد
            $this->plan('hetzner', ['slug' => 's-d']),
            $this->plan('proxmox', ['slug' => 's-e']),
        ];

        $byMethod = collect($rows)->filter->supportsHourly()->pluck('id')->sort()->values()->all();
        $byScope = CloudPlan::query()->hourlyCapable()->pluck('id')->sort()->values()->all();

        $this->assertSame($byMethod, $byScope);
        $this->assertCount(3, $byScope);
    }

    // ═══════════ ۲) انتخابِ عرضه ═══════════

    /**
     * 🔴 عرضهٔ ساعتی = ارزان‌ترینِ **ساعتی‌فروش‌ها**، نه ارزان‌ترینِ مطلق.
     *
     * وگرنه نرخی که نشان می‌دهیم مالِ ردیفی است که سفارش رویش رد می‌شود.
     */
    public function test_hourly_offer_is_the_cheapest_row_that_can_actually_be_bought_hourly(): void
    {
        $cheapNoHourly = $this->plan('aeza', ['cost_eur_cents' => 201]);
        $pricierHourly = $this->plan('hetzner', ['cost_eur_cents' => 380]);

        $slug = (string) $cheapNoHourly->slug;

        $this->assertTrue(CloudPlan::offers('se-stockholm')->get($slug)->is($cheapNoHourly),
            'عرضهٔ ماهانه باید همان ارزان‌ترین بماند');

        $this->assertTrue(CloudPlan::offers('se-stockholm', true)->get($slug)->is($pricierHourly),
            'عرضهٔ ساعتی روی ردیفی نشست که زیرساخت ساعتی نمی‌فروشدش');
    }

    /** اسلاگی که هیچ ردیفِ ساعتی‌فروشی ندارد، در عرضهٔ ساعتی اصلاً نیست. */
    public function test_a_slug_with_no_hourly_capable_row_drops_out_of_the_hourly_offers(): void
    {
        $plan = $this->plan('aeza');

        $this->assertNotNull(CloudPlan::offers('se-stockholm')->get((string) $plan->slug));
        $this->assertNull(CloudPlan::offers('se-stockholm', true)->get((string) $plan->slug));
    }

    /** انتخابِ دیرهنگامِ تحویل هم همان قید را دارد. */
    public function test_best_for_slug_skips_the_row_that_refuses_the_hourly_term(): void
    {
        $noHourly = $this->plan('aeza', ['cost_eur_cents' => 201]);
        $hourly = $this->plan('hetzner', ['cost_eur_cents' => 380]);

        $this->assertTrue(CloudPlan::bestForSlug((string) $noHourly->slug)->is($noHourly));
        $this->assertTrue(CloudPlan::bestForSlug((string) $noHourly->slug, true)->is($hourly));
    }

    // ═══════════ ۳) تحویل ═══════════

    /**
     * 🔴 قلبِ پرونده: سفارشِ ساعتی روی پلنِ ماهانه‌فروش **اصلاً فرستاده نمی‌شود**.
     *
     * درایور جاسوس است: اگر یک بار هم صدا زده شود، یعنی همان POSTی که در
     * زیرساختِ سفارش‌محور پول دارد، دوباره رفته.
     */
    public function test_hourly_service_never_reaches_a_provider_that_bills_it_monthly(): void
    {
        $plan = $this->plan('aeza');
        $service = $this->hourlyOrder($plan);

        $spy = $this->bindSpyDriver('aeza', ['ok' => false,
            'message' => "Product 269 does not support term 'hour'"]);

        $ok = app(CloudProvisioner::class)->provision($service);

        $this->assertFalse($ok);
        $this->assertSame(0, $spy->created,
            '🔴 سفارش به زیرساخت رفت — گاردِ ترم پیش از تماسِ شبکه‌ای نایستاده');

        $fresh = $service->fresh();
        $this->assertSame('failed', $fresh->provision_status);
        $this->assertStringContainsString('ساعتی', (string) $fresh->provision_error);
    }

    /**
     * و همان سرویس، اگر ردیفِ ساعتی‌فروشی در همان اسلاگ باشد، **تحویل می‌شود**
     * — انتخابِ دیرهنگام خودش جابه‌جا می‌کند.
     *
     * ⚠️ نیمهٔ دوم عمدی است: گاردی که فقط «نه» بگوید می‌تواند با بستنِ همه‌چیز
     * سبز شود. این تست ثابت می‌کند مسیرِ سالم باز مانده.
     */
    public function test_hourly_service_moves_to_the_hourly_capable_row_and_is_delivered(): void
    {
        $noHourly = $this->plan('aeza', ['cost_eur_cents' => 201]);
        $hourly = $this->plan('hetzner', ['cost_eur_cents' => 380]);

        $this->image('hetzner');                       // هر دو زیرساخت اوبونتو دارند
        $service = $this->hourlyOrder($noHourly);      // سفارش روی ردیفِ ارزانِ بی‌ساعتی

        $spy = $this->bindSpyDriver('hetzner', ['ok' => true, 'ref' => 'srv-1',
            'ipv4' => '203.0.113.9', 'root_password' => 'pw', 'status' => 'running']);

        $ok = app(CloudProvisioner::class)->provision($service);

        $this->assertTrue($ok, 'تحویلِ سالم هم بسته شد — گارد بیش از اندازه سفت است');
        $this->assertSame(1, $spy->created);
        $this->assertSame('hour', $spy->lastSpec['term'] ?? null);
        $this->assertSame($hourly->id, (int) $service->fresh()->cloud_plan_id);
    }

    /**
     * 🔴 و روی زیرساختِ **بی‌ترم**، همان رد نباید کفِ بها را پاک کند.
     *
     * برای هتزنر `cost_hour_eur_micro` مجوزِ فروش نیست، **بهایِ ساعتیِ واقعی**
     * است و کفِ «هرگز زیرِ بها» را می‌سازد (درسِ sn-svc-76). پاک‌کردنش کف را
     * برمی‌دارد و فروشِ زیرِ بها می‌سازد — و چون آن زیرساخت هرهم ساعتی‌فروش
     * می‌مانَد، حتی یک قدم هم به سمتِ «امن‌تر» نیست؛ فقط ضرر است.
     */
    public function test_a_refusal_never_wipes_the_cost_floor_of_a_termless_provider(): void
    {
        $plan = $this->plan('hetzner', ['cost_hour_eur_micro' => 6000]);
        $service = $this->hourlyOrder($plan);

        $this->bindSpyDriver('hetzner', ['ok' => false,
            'message' => "Product 269 does not support term 'hour'"]);

        app(CloudProvisioner::class)->provision($service);

        $this->assertSame(6000, (int) $plan->fresh()->cost_hour_eur_micro,
            '🔴 کفِ بهایِ ساعتیِ زیرساختِ بی‌ترم پاک شد — راهِ مستقیم به فروشِ زیرِ بها');
    }

    /**
     * 🔴 شاخهٔ «زیرساختی که ایمیج را دارد» هم نباید گارد را از پشت دور بزند.
     *
     * ═══ این تست یک باگِ واقعی در خودِ همین رفع گرفت ═══
     *
     * نسخهٔ اولِ گارد بالاتر نشسته بود، کنارِ انتخابِ اولِ زیرساخت. ولی `$plan`
     * تا لحظهٔ `createServer` **سه** بار عوض می‌شود، و سومی همین شاخه است:
     * «سیستم‌عاملِ انتخابی روی این زیرساخت نیست، سراغِ زیرساختی برو که داردش».
     * آن شاخه سرویسِ ساعتی را دوباره روی ردیفِ ماهانه‌فروش می‌نشاند و سفارش
     * می‌رفت — دقیقاً همان شکستی که قرار بود بسته شود.
     *
     * ⚠️ درس: گارد را کنارِ **آخرین** جابه‌جایی بگذار، نه کنارِ اولی.
     */
    public function test_the_image_fallback_cannot_put_an_hourly_service_on_a_monthly_only_row(): void
    {
        // اوبونتو فقط روی زیرساختِ ماهانه‌فروش هست؛ ردیفِ ساعتی‌فروش ندارد.
        $noHourly = $this->plan('aeza', ['cost_eur_cents' => 380]);
        $this->plan('hetzner', ['cost_eur_cents' => 201]);   // ارزان‌تر و ساعتی‌فروش، ولی بی‌ایمیج
        $this->image('aeza');

        $service = $this->hourlyOrder($noHourly);

        $spy = $this->bindSpyDriver('aeza', ['ok' => true, 'ref' => 'srv-9',
            'ipv4' => '203.0.113.9', 'root_password' => 'pw', 'status' => 'running']);

        $ok = app(CloudProvisioner::class)->provision($service);

        $this->assertFalse($ok);
        $this->assertSame(0, $spy->created,
            '🔴 شاخهٔ «زیرساختی که ایمیج را دارد» سفارشِ ساعتی را روی ردیفِ ماهانه‌فروش فرستاد');
        $this->assertSame('failed', $service->fresh()->provision_status);
    }

    /**
     * 🔴 ردِ زیرساخت فقط **همان ردیف** را از فروشِ ساعتی برمی‌دارد.
     *
     * نه کلِ زیرساخت را (قرنطینهٔ سراسری برای نبودِ یک تعرفه فاجعه است) و نه
     * فروشِ ماهانهٔ خودِ ردیف را.
     */
    public function test_a_refusal_takes_only_that_row_off_hourly(): void
    {
        // ردیف تعرفهٔ ساعتی **دارد** (پس گاردِ بالادست جلویش را نمی‌گیرد) ولی
        // زیرساخت سرِ سفارش ردش می‌کند — یعنی دادهٔ کاتالوگِ ما کهنه بوده.
        $plan = $this->plan('aeza', ['cost_hour_eur_micro' => 5000]);
        $other = $this->plan('aeza', ['slug' => 'cv-2c-8g-20d-se', 'provider_ref' => '270']);

        $service = $this->hourlyOrder($plan);

        $spy = $this->bindSpyDriver('aeza', ['ok' => false,
            'message' => "Product 269 does not support term 'hour'"]);

        app(CloudProvisioner::class)->provision($service);

        $this->assertSame(1, $spy->created, 'این‌بار باید واقعاً فرستاده شود — کاتالوگ می‌گفت ساعتی دارد');

        $this->assertNull($plan->fresh()->cost_hour_eur_micro,
            'ردیف از فروشِ ساعتی برداشته نشد — مشتریِ بعدی همان شکست را می‌خرد');
        $this->assertFalse($plan->fresh()->supportsHourly());

        $this->assertFalse((bool) $plan->fresh()->admin_disabled,
            'فروشِ ماهانهٔ همان ردیف هم بسته شد — رد کردنِ یک تعرفه، محصول را باطل نمی‌کند');
        $this->assertFalse((bool) $other->fresh()->admin_disabled,
            '🔴 کلِ زیرساخت قرنطینه شد — یک تعرفهٔ نبوده نباید همهٔ پلن‌های سالم را ببندد');
    }
}
