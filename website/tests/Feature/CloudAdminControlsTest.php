<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\CloudCatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ابزارهای مدیریتیِ کاتالوگِ ابری: فیلتر، مرتب‌سازی و خاموش/روشن.
 *
 * مهم‌ترین تستِ این فایل «سینک تصمیمِ مدیر را برنمی‌گرداند» است — چون
 * `is_active` مالِ سینک است و اگر مدیر روی همان می‌نوشت، پکیجِ عمداً بسته دو
 * روز بعد خودش باز می‌شد و فروخته می‌شد.
 */
class CloudAdminControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('pricing_rate_override', '100000');
        Setting::put('cloud_margin_pct', '50');
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'ac'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function seedCatalog(): void
    {
        CloudLocation::create(['code' => 'de-falkenstein', 'country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);
        CloudLocation::create(['code' => 'de-nuremberg', 'country' => 'DE', 'city' => 'Nuremberg', 'is_active' => true]);
        CloudLocation::create(['code' => 'sg-singapore', 'country' => 'SG', 'city' => 'Singapore', 'is_active' => true]);

        foreach ([
            ['hetzner', 'cx22', 'de-falkenstein', 2, 4096, 570000],
            ['hetzner', 'cx32', 'de-nuremberg', 4, 8192, 1090000],
            ['aeza', '77', 'sg-singapore', 2, 4096, 490000],
        ] as [$prov, $ref, $loc, $cpu, $ram, $irt]) {
            CloudPlan::create([
                'provider' => $prov, 'provider_ref' => $ref, 'location_code' => $loc,
                'public_name' => 'CV-'.$cpu.'-'.($ram / 1024),
                'slug' => 'cv-'.$cpu.'c-'.($ram / 1024).'g-40d-'.$loc,
                'vcpu' => $cpu, 'ram_mb' => $ram, 'disk_gb' => 40,
                'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => $irt,
                'is_active' => true, 'in_stock' => true,
            ]);
        }
    }


    /** اسلاگ‌های ردیف‌های جدولِ «مدیریتِ پلن‌ها» به ترتیبِ ظاهر */
    private function mgmtRows(string $html): array
    {
        preg_match_all('/data-plan="([^"]+)"/', $html, $m);

        return $m[1] ?? [];
    }

    // ═══════════ خاموش/روشنِ پلن ═══════════

    public function test_admin_can_disable_a_plan_with_a_note(): void
    {
        $this->seedCatalog();
        $plan = CloudPlan::first();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/cloud/plans/'.$plan->id.'/toggle', ['note' => 'گران بود'])
            ->assertRedirect();

        $plan->refresh();
        $this->assertTrue($plan->admin_disabled);
        $this->assertSame('گران بود', $plan->admin_note);

        // و از فروشگاه واقعاً بیرون رفته
        $this->assertFalse(CloudPlan::offers()->has($plan->slug));
    }

    public function test_toggle_back_reopens_the_plan(): void
    {
        $this->seedCatalog();
        $plan = CloudPlan::first();
        $plan->update(['admin_disabled' => true, 'admin_note' => 'x']);

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/cloud/plans/'.$plan->id.'/toggle')
            ->assertRedirect();

        $plan->refresh();
        $this->assertFalse($plan->admin_disabled);
        $this->assertNull($plan->admin_note, 'یادداشتِ بستنِ قبلی نباید بماند');
        $this->assertTrue(CloudPlan::offers()->has($plan->slug));
    }

    /**
     * 🔴 قلبِ ماجرا: همگام‌سازیِ بعدی نباید تصمیمِ مدیر را برگرداند.
     *
     * `updateOrCreate` سینک همهٔ ستون‌هایی که می‌نویسد را بازنویسی می‌کند؛ اگر
     * `admin_disabled` در آن فهرست بود، هر اجرای کرونِ دو روزه پکیجِ عمداً
     * بسته را بی‌صدا باز می‌کرد.
     */
    public function test_sync_does_not_resurrect_an_admin_disabled_plan(): void
    {
        Setting::putSecret('hetzner_api_token', 'test-token');
        $this->seedCatalog();

        $plan = CloudPlan::where('provider', 'hetzner')->where('provider_ref', 'cx22')->first();
        $plan->update(['admin_disabled' => true, 'admin_note' => 'بسته']);

        // سینک همان پلن را دوباره می‌آورد
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/locations')) {
                return Http::response(['locations' => [
                    ['id' => 1, 'name' => 'fsn1', 'country' => 'DE', 'city' => 'Falkenstein'],
                ], 'meta' => ['pagination' => ['last_page' => 1]]], 200);
            }

            if (str_contains($url, '/datacenters')) {
                return Http::response(['datacenters' => [
                    ['id' => 1, 'location' => ['name' => 'fsn1'], 'server_types' => ['available' => [11]]],
                ], 'meta' => ['pagination' => ['last_page' => 1]]], 200);
            }

            if (str_contains($url, '/server_types')) {
                return Http::response(['server_types' => [[
                    'id' => 11, 'name' => 'cx22', 'cores' => 2, 'memory' => 4, 'disk' => 40,
                    'cpu_type' => 'shared', 'architecture' => 'x86', 'storage_type' => 'local',
                    'deprecated' => false, 'included_traffic' => 21474836480,
                    'prices' => [['location' => 'fsn1', 'price_monthly' => ['net' => '3.29']]],
                ]], 'meta' => ['pagination' => ['last_page' => 1]]], 200);
            }

            return Http::response(['pricing' => ['primary_ips' => []],
                'images' => [], 'meta' => ['pagination' => ['last_page' => 1]]], 200);
        });

        app(CloudCatalogSync::class)->sync();

        $plan->refresh();
        $this->assertTrue($plan->admin_disabled, 'سینک نباید تصمیمِ مدیر را برگرداند');
        $this->assertSame('بسته', $plan->admin_note);
        $this->assertTrue((bool) $plan->is_active, 'ولی واقعیتِ ارائه‌دهنده باید تازه شود');
    }

    // ═══════════ خاموش/روشنِ کشور و زیرساخت ═══════════

    public function test_disabling_a_country_closes_all_its_locations(): void
    {
        $this->seedCatalog();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/cloud/countries/DE/toggle')
            ->assertRedirect();

        $this->assertFalse((bool) CloudLocation::where('code', 'de-falkenstein')->first()->is_active);
        $this->assertFalse((bool) CloudLocation::where('code', 'de-nuremberg')->first()->is_active);
        // سنگاپور دست نخورده
        $this->assertTrue((bool) CloudLocation::where('code', 'sg-singapore')->first()->is_active);
    }

    public function test_disabling_a_provider_removes_its_plans_from_sale(): void
    {
        $this->seedCatalog();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/cloud/providers/aeza/toggle')
            ->assertRedirect();

        $this->assertTrue(CloudPlan::providerIsDisabled('aeza'));

        $slugs = CloudPlan::offers()->keys();
        $this->assertFalse($slugs->contains('cv-2c-4g-40d-sg-singapore'), 'پلنِ زیرساختِ خاموش نباید فروخته شود');
        $this->assertTrue($slugs->contains('cv-2c-4g-40d-de-falkenstein'), 'زیرساختِ دیگر باید بماند');

        // برگرداندن
        $this->actingAs($this->admin(), 'web')->post('/admin/cloud/providers/aeza/toggle');
        $this->assertFalse(CloudPlan::providerIsDisabled('aeza'));
    }

    public function test_unknown_provider_toggle_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/cloud/providers/evil/toggle')
            ->assertRedirect()
            ->assertSessionHas('err');
    }

    /** تحویل هم باید زیرساختِ خاموش را دور بزند — نه فقط ویترین */
    public function test_delivery_skips_a_disabled_provider(): void
    {
        $this->seedCatalog();

        // هر دو زیرساخت یک اسلاگ دارند؛ ارزان‌ترْ آیزاست
        CloudPlan::where('provider', 'aeza')->update([
            'slug' => 'cv-2c-4g-40d-de-falkenstein', 'location_code' => 'de-falkenstein',
            'cost_eur_cents' => 300,
        ]);

        CloudPlan::setProviderDisabled('aeza', true);

        $best = CloudPlan::bestForSlug('cv-2c-4g-40d-de-falkenstein');
        $this->assertNotNull($best);
        $this->assertSame('hetzner', $best->provider, 'تحویل نباید سراغِ زیرساختِ خاموش برود');
    }

    // ═══════════ فیلتر و مرتب‌سازی ═══════════

    public function test_filter_by_provider_and_country(): void
    {
        $this->seedCatalog();
        $admin = $this->admin();

        $rows = $this->mgmtRows($this->actingAs($admin, 'web')
            ->get('/admin/cloud?provider=aeza')->assertOk()->getContent());
        $this->assertContains('cv-2c-4g-40d-sg-singapore', $rows);
        $this->assertNotContains('cv-4c-8g-40d-de-nuremberg', $rows);

        $rows = $this->mgmtRows($this->actingAs($admin, 'web')
            ->get('/admin/cloud?country=DE')->assertOk()->getContent());
        $this->assertContains('cv-2c-4g-40d-de-falkenstein', $rows);
        $this->assertNotContains('cv-2c-4g-40d-sg-singapore', $rows);
    }

    public function test_sort_whitelist_rejects_arbitrary_columns(): void
    {
        $this->seedCatalog();

        // ورودیِ خراب نباید ۵۰۰ بدهد؛ باید بی‌صدا به پیش‌فرض برگردد
        $this->actingAs($this->admin(), 'web')
            ->get('/admin/cloud?sort=provider;drop table')
            ->assertOk();
    }

    public function test_sort_by_ram_descending_orders_rows(): void
    {
        $this->seedCatalog();

        $rows = $this->mgmtRows($this->actingAs($this->admin(), 'web')
            ->get('/admin/cloud?sort=ram_d')->assertOk()->getContent());

        // پلنِ ۸ گیگی باید قبل از ۴ گیگی بیاید
        $this->assertLessThan(
            array_search('cv-2c-4g-40d-de-falkenstein', $rows),
            array_search('cv-4c-8g-40d-de-nuremberg', $rows),
        );
    }

    /** فیلترِ «بسته‌شده توسطِ من» فقط همان‌ها را بدهد */
    public function test_state_filter_shows_only_admin_disabled(): void
    {
        $this->seedCatalog();
        CloudPlan::where('provider_ref', 'cx22')->update(['admin_disabled' => true]);

        $rows = $this->mgmtRows($this->actingAs($this->admin(), 'web')
            ->get('/admin/cloud?state=off')->assertOk()->getContent());

        $this->assertContains('cv-2c-4g-40d-de-falkenstein', $rows);
        $this->assertNotContains('cv-4c-8g-40d-de-nuremberg', $rows);
    }

    // ═══════════ اجازه ═══════════

    public function test_author_cannot_toggle_anything(): void
    {
        $this->seedCatalog();
        $author = User::create([
            'name' => 'نویسنده', 'email' => 'w'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'author',
        ]);
        $plan = CloudPlan::first();

        $this->actingAs($author, 'web')
            ->post('/admin/cloud/plans/'.$plan->id.'/toggle')->assertForbidden();
        $this->actingAs($author, 'web')
            ->post('/admin/cloud/providers/hetzner/toggle')->assertForbidden();

        $this->assertFalse((bool) $plan->fresh()->admin_disabled);
    }
}
