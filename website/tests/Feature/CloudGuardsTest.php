<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\CloudOperations;
use Illuminate\Support\Facades\Http;

/**
 * سه محافظِ آخر: قیمتِ تغییرِ پلن، اجازهٔ مدیر، و فرمِ افزودنی‌ها.
 */
class CloudGuardsTest extends CloudProvisionTest
{
    // ═══════════ ۱) تغییرِ پلن: قیمتِ **دوره**، نه ماهانه ═══════════

    /**
     * 🔴 پیش از اصلاح: `priceFor` قیمتِ ماهانه را برمی‌گرداند ولی
     * `services.price` مبلغِ یک دورهٔ کامل است. روی سرویسِ سالانه، ارتقا قیمت را
     * از «۱۲ ماه پلنِ کوچک» به «۱ ماه پلنِ بزرگ» می‌شکست — ارتقا به تخفیفِ ~۹۲٪
     * تبدیل می‌شد و فاکتورِ تمدیدِ سالِ بعد هم همان عددِ غلط را می‌گرفت.
     *
     * تست‌های قبلی نگرفتنش چون همه سرویسِ ماهانه بودند و ۱ ماه با ۱ ماه یکی است.
     */
    public function test_resize_prices_the_whole_cycle_not_one_month(): void
    {
        Setting::put('pricing_rate_override', '100000');

        $small = $this->plan('hetzner', [
            'provider_ref' => 'cx22', 'price_irt' => 500000, 'cost_eur_cents' => 379,
        ]);
        $big = $this->plan('hetzner', [
            'provider_ref' => 'cx32', 'price_irt' => 1000000, 'cost_eur_cents' => 700,
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80,
            'slug' => 'cv-4c-8g-80d-de-falkenstein', 'public_name' => 'CV-4-8',
        ]);

        $months = Service::monthsIn('yearly');
        $discount = max(0, min(90, (int) (config('billing.cycles.yearly.discount_pct') ?? 0)));

        $service = $this->service($small, [
            'cycle' => 'yearly',
            'price' => \App\Models\Product::roundUpToman(500000 * $months * (100 - $discount) / 100),
        ]);

        $expected = \App\Models\Product::roundUpToman(1000000 * $months * (100 - $discount) / 100);

        // سرور باید خاموش باشد تا تغییرِ پلن مجاز شود
        $inst = new \App\Models\CloudInstance([
            'service_id' => $service->id, 'provider' => 'hetzner',
            'provider_ref' => '999', 'location_code' => $small->location_code,
            'status' => 'off',
        ]);
        $inst->save();

        // ⚠️ تغییرِ پلن وضعیتِ **زنده** را از زیرساخت می‌پرسد، نه ستونِ دیتابیس.
        // پس پاسخِ GET /servers/{id} باید سرورِ خاموش را نشان دهد.
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/servers/')) {
                return Http::response(['server' => [
                    'id' => 999, 'status' => 'off',
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.7'], 'ipv6' => ['ip' => null]],
                    'server_type' => ['name' => 'cx22'],
                ]], 200);
            }

            return Http::response(['action' => ['id' => 1, 'status' => 'running']], 201);
        });

        $r = app(CloudOperations::class)->resize($service->fresh(), (string) $big->slug);

        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));

        $price = (int) $service->fresh()->price;

        $this->assertSame($expected, $price);
        $this->assertGreaterThan(1000000, $price,
            'قیمتِ سالانه باید چند برابرِ ماهانه باشد، نه برابرِ آن');
    }

    /** روی سرویسِ ماهانه همان رفتارِ قبلی می‌مانَد */
    public function test_resize_on_a_monthly_service_uses_the_monthly_price(): void
    {
        Setting::put('pricing_rate_override', '100000');

        $small = $this->plan('hetzner', ['provider_ref' => 'cx22', 'price_irt' => 500000]);
        $big = $this->plan('hetzner', [
            'provider_ref' => 'cx32', 'price_irt' => 1000000,
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80,
            'slug' => 'cv-4c-8g-80d-de-falkenstein', 'public_name' => 'CV-4-8',
        ]);

        $service = $this->service($small, ['cycle' => 'monthly', 'price' => 500000]);

        $inst = new \App\Models\CloudInstance([
            'service_id' => $service->id, 'provider' => 'hetzner',
            'provider_ref' => '999', 'location_code' => $small->location_code,
            'status' => 'off',
        ]);
        $inst->save();

        // ⚠️ تغییرِ پلن وضعیتِ **زنده** را از زیرساخت می‌پرسد، نه ستونِ دیتابیس.
        // پس پاسخِ GET /servers/{id} باید سرورِ خاموش را نشان دهد.
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/servers/')) {
                return Http::response(['server' => [
                    'id' => 999, 'status' => 'off',
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.7'], 'ipv6' => ['ip' => null]],
                    'server_type' => ['name' => 'cx22'],
                ]], 200);
            }

            return Http::response(['action' => ['id' => 1, 'status' => 'running']], 201);
        });

        $r = app(CloudOperations::class)->resize($service->fresh(), (string) $big->slug);

        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $this->assertSame(1000000, (int) $service->fresh()->price);
    }

    // ═══════════ ۲) اجازهٔ مدیر روی مسیرهای پرهزینه ═══════════

    private function author(): User
    {
        return User::create([
            'name' => 'نویسنده', 'email' => 'au'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'author',
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'ad'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    /**
     * 🔴 پیش از اصلاح: گروهِ /admin تنها پشتِ auth:web بود. یک کاربرِ نقشِ
     * «نویسنده» (که برای نوشتنِ بلاگ ساخته می‌شود) می‌توانست ساختارِ خامِ پاسخِ
     * زیرساخت را ببیند و همگام‌سازی را بزند که سهمیهٔ API را می‌سوزاند.
     */
    public function test_author_cannot_reach_cloud_infrastructure_pages(): void
    {
        $author = $this->author();

        foreach (['/admin/cloud', '/admin/cloud/probe'] as $url) {
            $this->actingAs($author, 'web')->get($url)->assertForbidden();
        }

        $this->actingAs($author, 'web')->post('/admin/cloud/sync')->assertForbidden();
        $this->actingAs($author, 'web')->post('/admin/cloud/test')->assertForbidden();
    }

    /** و هیچ تماسی با زیرساخت هم نرفته باشد */
    public function test_blocked_author_never_touches_the_provider_api(): void
    {
        Setting::putSecret('hetzner_api_token', 'test-token');
        Http::fake();

        $this->actingAs($this->author(), 'web')->post('/admin/cloud/sync')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_author_cannot_reach_settings_or_products_or_servers(): void
    {
        $author = $this->author();

        foreach (['/admin/settings', '/admin/products', '/admin/servers'] as $url) {
            $this->actingAs($author, 'web')->get($url)->assertForbidden();
        }
    }

    /** ولی مدیر می‌تواند */
    public function test_admin_can_reach_them(): void
    {
        $admin = $this->admin();

        foreach (['/admin/cloud', '/admin/settings', '/admin/products'] as $url) {
            $this->actingAs($admin, 'web')->get($url)->assertOk();
        }
    }

    /** مدیریتِ محتوا عمداً برای نویسنده باز می‌مانَد — کارِ واقعیِ او است */
    public function test_author_can_still_manage_content(): void
    {
        $this->actingAs($this->author(), 'web')->get('/admin/posts')->assertOk();
    }

    /** مهمان همان‌طور که بود، به ورود هدایت می‌شود (نه ۴۰۳) */
    public function test_guest_is_redirected_not_forbidden(): void
    {
        $this->get('/admin/cloud')->assertRedirect();
    }

    // ═══════════ ۳) فرمِ افزودنی‌ها در سرورساز ═══════════

    public function test_builder_shows_the_ssh_key_and_extra_ip_fields(): void
    {
        Setting::put('pricing_rate_override', '100000');
        Setting::put('cloud_extra_ip_eur_cents', '100');

        $plan = $this->plan();
        $this->image();
        $customer = $this->customer();

        $html = $this->actingAs($customer, 'customer')
            ->get(route('account.cloud.store', ['location' => $plan->location_code]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ssh_key_new', $html, 'کادرِ کلیدِ SSH باید باشد');
        $this->assertStringContainsString('extra_ipv4', $html, 'انتخابگرِ IP اضافه باید باشد');
        $this->assertStringContainsString('رایگان', $html, 'کلیدِ SSH باید «رایگان» علامت بخورد');

        // قیمتِ IP باید عددِ واقعی باشد، نه جای‌نگهدار
        $this->assertStringContainsString(fa_num(number_format(150000)), $html);

        // و هیچ آکولادِ کامپایل‌نشده‌ای نمانده باشد
        $this->assertStringNotContainsString('{{', $html);
    }

    /**
     * اگر مکان IP اضافه ندارد، گزینه‌اش **نمایش داده نشود**. نشان‌دادنِ
     * گزینه‌ای که سرِ ثبتِ سفارش رد می‌شود، بدترین نوعِ رابطِ کاربری است.
     */
    public function test_extra_ip_is_hidden_where_it_cannot_be_delivered(): void
    {
        Setting::put('pricing_rate_override', '100000');
        Setting::putSecret('aeza_api_token', 'k');

        // فقط زیرساختِ ۲ این مکان را دارد و IP اضافه نمی‌دهد
        $plan = $this->plan('aeza', ['provider_ref' => '77', 'location_code' => 'nl-amsterdam',
            'slug' => 'cv-2c-4g-40d-nl-amsterdam']);
        \App\Models\CloudLocation::create(['code' => 'nl-amsterdam', 'country' => 'NL', 'city' => 'Amsterdam']);
        $this->image('aeza', '1042');

        $html = $this->actingAs($this->customer(), 'customer')
            ->get(route('account.cloud.store', ['location' => 'nl-amsterdam']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('extra_ipv4', $html);
        // ولی کلیدِ SSH همیشه هست (رایگان و بی‌وابستگی به زیرساخت در فرم)
        $this->assertStringContainsString('ssh_key_new', $html);
    }

    /** سفارشِ IP اضافه روی مکانی که نمی‌تواند بدهد، سرور-محور رد شود */
    public function test_ordering_an_undeliverable_addon_is_rejected(): void
    {
        Setting::put('pricing_rate_override', '100000');
        Setting::putSecret('aeza_api_token', 'k');

        $plan = $this->plan('aeza', ['provider_ref' => '77']);
        $this->image('aeza', '1042');

        $before = Service::count();

        $this->actingAs($this->customer(), 'customer')
            ->post(route('account.cloud.store.place'), [
                'location' => $plan->location_code,
                'plan' => $plan->slug,
                'image' => 'ubuntu-24.04',
                'cycle' => 'monthly',
                'extra_ipv4' => 2,
            ])
            ->assertSessionHasErrors('extra_ipv4');

        $this->assertSame($before, Service::count(), 'سرویسی نباید ساخته شود');
    }
}
