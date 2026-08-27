<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ساختِ package در WHM از روی پکیجِ فروش (addpkg) + استخراجِ حدومرز از مشخصات.
 */
class WhmPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*/json-api/addpkg*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'ok']])]);
    }

    private function admin(): User
    {
        return User::create(['name' => 'م', 'email' => 'a'.random_int(1, 99999).'@x.com', 'password' => bcrypt('x'), 'role' => 'admin']);
    }

    public function test_admin_creates_whm_package_and_connects_product(): void
    {
        $server = Server::create(['name' => 'WHM-1', 'type' => 'whm', 'hostname' => 'w.test', 'username' => 'root', 'api_token' => 't', 'verify_tls' => false, 'status' => 'active']);
        $product = Product::create([
            'name' => 'هاست وردپرس WP-5', 'category' => 'shared', 'server_id' => $server->id,
            'price' => 250000, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
            'specs' => [['label' => '۵ گیگابایت فضای NVMe', 'value' => ''], ['label' => 'پهنای باند نامحدود', 'value' => '']],
        ]);

        $this->actingAs($this->admin(), 'web')
            ->post("/admin/products/{$product->id}/whm-sync")
            ->assertRedirect();

        // پکیج به package وصل شد
        $this->assertStringStartsWith('sn_', (string) $product->fresh()->plan);

        // addpkg با فضای ۵ گیگ (=۵۱۲۰ مگ) و پهنای باندِ «unlimited».
        // ⚠️ این ادعا قبلاً bwlimit=0 بود — یعنی همان چیزی که WHM ردش می‌کند:
        //   Invalid value "0" for the "bwlimit" setting.
        // تست، رفتارِ غلط را قفل کرده بود؛ حالا مقدارِ درست را می‌سنجد.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'addpkg')
            && str_contains($r->url(), 'quota=5120')
            && str_contains(rawurldecode($r->url()), 'bwlimit=unlimited'));
    }

    public function test_existing_package_is_treated_as_connected(): void
    {
        Http::fake(['*/json-api/addpkg*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'Package already exists']])]);

        $server = Server::create(['name' => 'WHM-2', 'type' => 'whm', 'hostname' => 'w2.test', 'username' => 'root', 'api_token' => 't', 'verify_tls' => false, 'status' => 'active']);
        $product = Product::create(['name' => 'هاست', 'category' => 'shared', 'server_id' => $server->id, 'price' => 100000, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true]);

        $this->actingAs($this->admin(), 'web')
            ->post("/admin/products/{$product->id}/whm-sync")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringStartsWith('sn_', (string) $product->fresh()->plan);   // با اینکه از قبل بود، وصل شد
    }

    /**
     * 🔴 محصولی که SSH وعده داده (خطِ پایتون) باید hasshell=y بگیرد.
     *
     * چرا: صفحهٔ «هاست پایتون» صریح «دسترسی کامل SSH» و pip/venv می‌فروشد،
     * ولی پیش‌فرضِ addPackage hasshell=n است — روی WHM-IR-01 واقعاً همین
     * نشسته بود (۵ شهریور ۱۴۰۵) و مشتری هاستی می‌گرفت که pip در آن ناممکن بود.
     */
    public function test_a_python_package_gets_shell_access(): void
    {
        $server = Server::create(['name' => 'WHM-3', 'type' => 'whm', 'hostname' => 'w3.test', 'username' => 'root', 'api_token' => 't', 'verify_tls' => false, 'status' => 'active']);
        $product = Product::create([
            'name' => 'هاست پایتون PY-1', 'slug' => 'python-1', 'category' => 'shared',
            'server_id' => $server->id, 'price' => 150000, 'cycle' => 'monthly',
            'tax_percent' => 10, 'is_active' => true,
            'specs' => [['label' => '۵ گیگابایت فضای NVMe', 'value' => '']],
        ]);

        $this->actingAs($this->admin(), 'web')
            ->post("/admin/products/{$product->id}/whm-sync")
            ->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'addpkg')
            && str_contains($r->url(), 'hasshell=y'));
    }

    /** محصولِ عادی همچنان بی‌shell می‌مانَد — پیش‌فرضِ امن دست نمی‌خورد */
    public function test_an_ordinary_package_stays_without_shell(): void
    {
        $server = Server::create(['name' => 'WHM-4', 'type' => 'whm', 'hostname' => 'w4.test', 'username' => 'root', 'api_token' => 't', 'verify_tls' => false, 'status' => 'active']);
        $product = Product::create([
            'name' => 'هاست وردپرس WP-1', 'slug' => 'wordpress-1', 'category' => 'shared',
            'server_id' => $server->id, 'price' => 150000, 'cycle' => 'monthly',
            'tax_percent' => 10, 'is_active' => true,
        ]);

        $this->actingAs($this->admin(), 'web')
            ->post("/admin/products/{$product->id}/whm-sync")
            ->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'addpkg')
            && str_contains($r->url(), 'hasshell=n'));
    }

    /** و هر محصولی که در مشخصاتش SSH/ترمینال نوشته، صرف‌نظر از نام */
    public function test_an_ssh_spec_line_turns_shell_on(): void
    {
        $server = Server::create(['name' => 'WHM-5', 'type' => 'whm', 'hostname' => 'w5.test', 'username' => 'root', 'api_token' => 't', 'verify_tls' => false, 'status' => 'active']);
        $product = Product::create([
            'name' => 'هاست توسعه‌دهنده', 'slug' => 'dev-1', 'category' => 'shared',
            'server_id' => $server->id, 'price' => 150000, 'cycle' => 'monthly',
            'tax_percent' => 10, 'is_active' => true,
            'specs' => [['label' => 'دسترسی SSH و Git', 'value' => '']],
        ]);

        $this->actingAs($this->admin(), 'web')
            ->post("/admin/products/{$product->id}/whm-sync")
            ->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'addpkg')
            && str_contains($r->url(), 'hasshell=y'));
    }

    public function test_product_without_whm_server_cannot_sync(): void
    {
        $product = Product::create(['name' => 'VPS', 'category' => 'vps', 'price' => 500000, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true]);

        $this->actingAs($this->admin(), 'web')
            ->post("/admin/products/{$product->id}/whm-sync")
            ->assertSessionHasErrors();
    }
}
