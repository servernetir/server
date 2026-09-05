<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Cloud\HetznerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ترکیبِ «نوعِ سرور × مکان» که زیرساخت عرضه‌اش نمی‌کند.
 *
 * ═══ رخدادی که این فایل از آن آمد (۱۴ شهریور ۱۴۰۵) ═══
 *
 * یک مشتری در یک‌ساعت‌ونیم **۱۶ بار** در کشورهای مختلف سرور خرید و هر بار
 * تحویل با `[invalid_input] unsupported location for server type` شکست خورد
 * (سرویس‌های ۱۲۱ تا ۱۴۰ در ردیابِ خطای پروداکشن).
 *
 * دو نقص، هرکدام به‌تنهایی کافی:
 *
 * ۱) کاتالوگ ردیف‌ها را از جدولِ **تعرفهٔ** `prices[]` می‌ساخت — یعنی
 *    حاصل‌ضربِ دکارتیِ «هر نوع × هر مکان». `prices` نمی‌گوید کجا عرضه می‌شود.
 * ۲) هیچ‌کدام از کلیدهای `quarantineProvider` این متن را نمی‌گرفت، پس ردیفِ
 *    مقصر در فروش می‌مانْد و مشتریِ بعدی همان شکست را می‌خرید — سومین تکرارِ
 *    همان الگو، بعد از `firewall` و `resource_limit`.
 *
 * ⚠️ **چرا فیکسچرِ قبلی هرگز نگرفتش:** در `CloudCatalogTest::fakeHetzner()`
 * هیچ نوعی وجود ندارد که برای مکانی **قیمت** داشته باشد و در `available`
 * همان مکان **نباشد** — یعنی دقیقاً همان ترکیبی که در واقعیت رخ داد. پس هر
 * ردیفی که آن فیکسچر می‌سازد `in_stock=true` می‌گیرد و تستِ «ناموجودی ثبت
 * می‌شود» ناچار است خودش با `update()` ناموجودی بسازد. تستی که حالتِ مسئله را
 * نسازد، فقط رفعِ خودش را می‌سنجد.
 */
class CloudUnsupportedLocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * فیکسچرِ هتزنر با **همان** ترکیبی که در پروداکشن شکست خورد:
     * نوعِ ۲۲ برای `hel1` تعرفه دارد ولی `hel1` عرضه‌اش نمی‌کند.
     */
    private function fakeHetzner(bool $withSupported = true, bool $withTypeLocations = false): void
    {
        Setting::putSecret('hetzner_api_token', 'test-token');
        Http::swap(new \Illuminate\Http\Client\Factory);

        $dcTypes = fn (array $available, array $supported) => $withSupported
            ? ['available' => $available, 'supported' => $supported]
            : ['available' => $available];

        $type11 = [
            'id' => 11, 'name' => 'cx22', 'cores' => 2, 'memory' => 4, 'disk' => 40,
            'cpu_type' => 'shared', 'architecture' => 'x86', 'storage_type' => 'local',
            'deprecated' => false, 'included_traffic' => 21474836480,
            'prices' => [
                ['location' => 'fsn1', 'price_monthly' => ['net' => '3.29']],
                ['location' => 'hel1', 'price_monthly' => ['net' => '3.29']],
            ],
        ];

        // 🔴 قلبِ تست: تعرفهٔ hel1 دارد ولی hel1 عرضه‌اش نمی‌کند
        $type22 = [
            'id' => 22, 'name' => 'ccx13', 'cores' => 4, 'memory' => 8, 'disk' => 80,
            'cpu_type' => 'dedicated', 'architecture' => 'x86', 'storage_type' => 'local',
            'deprecated' => false, 'included_traffic' => 21474836480,
            'prices' => [
                ['location' => 'fsn1', 'price_monthly' => ['net' => '12.49']],
                ['location' => 'hel1', 'price_monthly' => ['net' => '12.49']],
            ],
        ];

        if ($withTypeLocations) {
            $type11['locations'] = [['location' => ['name' => 'fsn1']], ['location' => ['name' => 'hel1']]];
            $type22['locations'] = [['location' => ['name' => 'fsn1']]];
        }

        Http::fake([
            '*/v1/locations*' => Http::response(['locations' => [
                ['id' => 1, 'name' => 'fsn1', 'country' => 'DE', 'city' => 'Falkenstein'],
                ['id' => 2, 'name' => 'hel1', 'country' => 'FI', 'city' => 'Helsinki'],
            ], 'meta' => ['pagination' => ['last_page' => 1]]]),

            '*/v1/datacenters*' => Http::response(['datacenters' => [
                // fsn1 هر دو نوع را عرضه و موجود دارد
                ['id' => 1, 'location' => ['name' => 'fsn1'],
                    'server_types' => $dcTypes([11, 22], [11, 22])],
                // hel1 فقط نوعِ ۱۱ را عرضه می‌کند — نوعِ ۲۲ آن‌جا **وجود ندارد**
                ['id' => 2, 'location' => ['name' => 'hel1'],
                    'server_types' => $dcTypes([11], [11])],
            ], 'meta' => ['pagination' => ['last_page' => 1]]]),

            '*/v1/server_types*' => Http::response([
                'server_types' => [$type11, $type22],
                'meta' => ['pagination' => ['last_page' => 1]],
            ]),

            '*/v1/pricing*' => Http::response(['pricing' => ['primary_ips' => [
                ['type' => 'ipv4', 'prices' => [['location' => 'fsn1', 'price_monthly' => ['net' => '0.50']]]],
            ]]]),

            '*' => Http::response(['images' => [], 'meta' => ['pagination' => ['last_page' => 1]]]),
        ]);
    }

    /** @return array<string, array<string, mixed>> کلید: `نوع@مکان` */
    private function plansFrom(array $catalog): array
    {
        $out = [];
        foreach ((array) ($catalog['plans'] ?? []) as $p) {
            $out[$p['provider_ref'].'@'.$p['provider_location']] = $p;
        }

        return $out;
    }

    // ═══════════ کاتالوگ ═══════════

    /**
     * 🔴 ادعای اصلی: ترکیبی که عرضه نمی‌شود **اصلاً ردیف نمی‌گیرد**.
     *
     * نه اینکه `in_stock=false` بگیرد — «وجود ندارد» با «الان تمام شده» یکی
     * نیست. دومی فردا برمی‌گردد، اولی هرگز.
     */
    public function test_a_type_priced_where_it_is_not_offered_never_becomes_a_row(): void
    {
        $this->fakeHetzner();

        $catalog = app(HetznerClient::class)->fetchCatalog();
        $plans = $this->plansFrom($catalog);

        $this->assertTrue($catalog['ok']);

        // این ترکیب دقیقاً همانی است که در پروداکشن ۱۶ بار شکست خورد
        $this->assertArrayNotHasKey('ccx13@hel1', $plans,
            'نوعی که مکان عرضه‌اش نمی‌کند نباید ردیف بگیرد.');

        // و بقیه دست‌نخورده‌اند — گارد نباید کاتالوگِ سالم را هم ببلعد
        $this->assertArrayHasKey('ccx13@fsn1', $plans);
        $this->assertArrayHasKey('cx22@fsn1', $plans);
        $this->assertArrayHasKey('cx22@hel1', $plans);
    }

    /** فیلدِ تازهٔ `server_types[].locations` اگر باشد، مرجعِ نهایی است */
    public function test_the_newer_per_type_locations_field_is_honoured(): void
    {
        $this->fakeHetzner(withSupported: false, withTypeLocations: true);

        $plans = $this->plansFrom(app(HetznerClient::class)->fetchCatalog());

        $this->assertArrayNotHasKey('ccx13@hel1', $plans);
        $this->assertArrayHasKey('ccx13@fsn1', $plans);
        $this->assertArrayHasKey('cx22@hel1', $plans);
    }

    /**
     * 🔴 نقشهٔ خالی هرگز «هیچ‌چیز موجود نیست» خوانده نمی‌شود.
     *
     * هتزنر اعلام کرده `datacenter.server_types.*` از ۱ اکتبر ۲۰۲۶ حذف
     * می‌شود. روزی که حذف شود، کدِ قبلی برای **همهٔ** پلن‌های این زیرساخت
     * `in_stock=false` می‌نوشت و کلِ خط با کدِ ۲۰۰ از فروشگاه غیب می‌شد.
     */
    public function test_an_empty_availability_map_fails_loudly_instead_of_emptying_the_shop(): void
    {
        Setting::putSecret('hetzner_api_token', 'test-token');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/v1/locations*' => Http::response(['locations' => [
                ['id' => 1, 'name' => 'fsn1', 'country' => 'DE', 'city' => 'Falkenstein'],
            ], 'meta' => ['pagination' => ['last_page' => 1]]]),
            '*/v1/datacenters*' => Http::response(['datacenters' => [
                ['id' => 1, 'location' => ['name' => 'fsn1'], 'server_types' => []],
            ], 'meta' => ['pagination' => ['last_page' => 1]]]),
            '*' => Http::response(['meta' => ['pagination' => ['last_page' => 1]]]),
        ]);

        $catalog = app(HetznerClient::class)->fetchCatalog();

        $this->assertFalse($catalog['ok']);
        $this->assertSame([], $catalog['plans']);
    }

    // ═══════════ تحویل ═══════════

    private function makePlan(string $location): CloudPlan
    {
        return CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'ccx13',
            'provider_location' => $location, 'location_code' => $location,
            'public_name' => 'CV-4-8', 'slug' => 'cv-4c-8g-80d-'.$location,
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'dedicated', 'arch' => 'x86',
            'cost_eur_cents' => 1249, 'price_eur_cents' => 1800, 'price_irt' => 1800000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function callGuard(CloudPlan $plan, string $message): bool
    {
        $m = new \ReflectionMethod(CloudProvisioner::class, 'disableCombinationIfUnsupported');
        $m->setAccessible(true);

        return (bool) $m->invoke(app(CloudProvisioner::class), $plan, $message);
    }

    /**
     * 🔴 فقط **همان ردیف** بسته می‌شود، نه کلِ زیرساخت.
     *
     * بارِ قبل که یک خطای ساختاری به فهرستِ قرنطینه اضافه شد، یک شکست
     * **۲۲۱ پلن** را بست و بازکردنشان ۲۲۱ کلیک بود.
     */
    public function test_an_unsupported_combination_closes_only_its_own_row(): void
    {
        $bad = $this->makePlan('fi-helsinki');
        $good = $this->makePlan('de-falkenstein');

        $this->assertTrue(
            $this->callGuard($bad, 'تحویلِ سرور ناموفق: [invalid_input] unsupported location for server type'),
            'این متن باید شناخته شود.'
        );

        $this->assertTrue((bool) $bad->fresh()->admin_disabled);
        $this->assertFalse((bool) $bad->fresh()->in_stock);
        $this->assertFalse(CloudPlan::sellable()->where('id', $bad->id)->exists());

        // 🔴 مهم‌ترین ادعای منفی: بقیهٔ همان زیرساخت دست‌نخورده‌اند
        $this->assertFalse((bool) $good->fresh()->admin_disabled);
        $this->assertTrue(CloudPlan::sellable()->where('id', $good->id)->exists());
    }

    /** یادداشتِ قرنطینه لازم است وگرنه `cloud:reopen` نمی‌تواند بازش کند */
    public function test_the_closed_row_carries_the_quarantine_prefix(): void
    {
        $plan = $this->makePlan('fi-helsinki');

        $this->callGuard($plan, '[invalid_input] unsupported location for server type');

        $this->assertStringStartsWith(
            CloudProvisioner::QUARANTINE_PREFIX,
            (string) $plan->fresh()->admin_note
        );
    }

    /** خطای بی‌ربط نباید چیزی ببندد — وگرنه یک قطعیِ گذرا کاتالوگ را می‌خورد */
    public function test_an_unrelated_error_closes_nothing(): void
    {
        $plan = $this->makePlan('fi-helsinki');

        $this->assertFalse($this->callGuard($plan, '[resource_unavailable] no resources available'));
        $this->assertFalse((bool) $plan->fresh()->admin_disabled);
    }
}
