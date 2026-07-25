<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ساختِ package در WHM.
 *
 * تلهٔ واقعی: WHM برای «نامحدود» مقدارِ 0 را **رد** می‌کند و می‌گوید
 * `Invalid value "0" for the "bwlimit" setting.` — و چون مشخصاتِ کاتالوگ
 * «ترافیک نامحدود» دارد، این مسیر برای هر ۵۲ پکیج داغ بود و همه شکست خوردند.
 */
class WhmPackageSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 's'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function whm(string $name, string $country): Server
    {
        return Server::create([
            'name' => $name, 'type' => 'whm', 'status' => 'active', 'country' => $country,
            'hostname' => strtolower($name).'.test', 'username' => 'root', 'api_token' => 't',
            'verify_tls' => false,
        ]);
    }

    /** هیچ درخواستی نباید bwlimit=0 بفرستد — نه از پیش‌فرض، نه از مشخصات */
    public function test_unlimited_bandwidth_is_sent_as_the_string_unlimited(): void
    {
        Http::fake([
            '*/json-api/version*' => Http::response(['metadata' => ['result' => 1], 'data' => ['version' => '110']]),
            '*/json-api/addpkg*'  => Http::response(['metadata' => ['result' => 1]]),
        ]);

        $this->whm('WHM-IR-01', 'IR');
        $this->whm('WHM-DE-01', 'DE');

        Product::create([
            'name' => 'وردپرس', 'slug' => 'wordpress-1', 'category' => 'shared',
            'price' => 250000, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
            'specs' => [
                ['label' => '5 GB NVMe', 'value' => ''],
                ['label' => 'ترافیک نامحدود', 'value' => ''],
            ],
        ]);

        $this->actingAs($this->staff(), 'web')->post('/admin/products-whm-sync-all')->assertRedirect();

        $addpkg = collect(Http::recorded())
            ->map(fn ($pair) => (string) $pair[0]->url())
            ->filter(fn ($u) => str_contains($u, 'addpkg'));

        $this->assertTrue($addpkg->isNotEmpty(), 'هیچ درخواستِ addpkg فرستاده نشد');

        foreach ($addpkg as $url) {
            $this->assertStringNotContainsString('bwlimit=0', $url, 'مقدارِ 0 برای bwlimit ممنوع است');
            $this->assertStringContainsString('bwlimit=unlimited', rawurldecode($url));
        }
    }

    /** روی هر دو سرور ساخته می‌شود، چون مشتری مکان را در خرید انتخاب می‌کند */
    public function test_package_is_created_on_every_whm_server(): void
    {
        Http::fake([
            '*/json-api/version*' => Http::response(['metadata' => ['result' => 1]]),
            '*/json-api/addpkg*'  => Http::response(['metadata' => ['result' => 1]]),
        ]);

        $this->whm('WHM-IR-01', 'IR');
        $this->whm('WHM-DE-01', 'DE');
        $product = Product::create([
            'name' => 'وردپرس', 'slug' => 'wordpress-2', 'category' => 'shared',
            'price' => 250000, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
        ]);

        $this->actingAs($this->staff(), 'web')->post('/admin/products-whm-sync-all')->assertRedirect();

        $hosts = collect(Http::recorded())
            ->map(fn ($pair) => (string) $pair[0]->url())
            ->filter(fn ($u) => str_contains($u, 'addpkg'))
            ->map(fn ($u) => parse_url($u, PHP_URL_HOST))
            ->unique()->values();

        $this->assertCount(2, $hosts, 'باید روی هر دو سرور ساخته شود');
        $this->assertSame('sn_wordpress_2', $product->fresh()->plan);
    }

    /** اگر به هیچ سروری وصل نشدیم، دلیل را بگو — نه «۵۲ ناموفق» بی‌توضیح */
    public function test_connection_failure_reports_the_real_reason(): void
    {
        Http::fake([
            '*/json-api/*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'Access denied']]),
        ]);

        $this->whm('WHM-IR-01', 'IR');
        Product::create([
            'name' => 'وردپرس', 'slug' => 'wordpress-3', 'category' => 'shared',
            'price' => 250000, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
        ]);

        $this->actingAs($this->staff(), 'web')
            ->post('/admin/products-whm-sync-all')
            ->assertSessionHasErrors();
    }
}
