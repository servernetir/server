<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 🔴 پنجره‌ی بین «کد رفت بالا» و «مدیر مهاجرت را زد».
 *
 * دیپلویِ این پروژه فایل‌به‌فایل است و مهاجرتِ پروداکشن دستی با کلیکِ مدیر
 * اجرا می‌شود — پس این پنجره **همیشه** وجود دارد، حتی اگر چند دقیقه باشد.
 *
 * بی‌نگهبان، در آن پنجره هر `Service::create` یک INSERT با ستونِ ناموجود
 * می‌زند و **ثبتِ سفارشِ همهٔ مشتریان** ۵۰۰ می‌دهد — نه فقط خریدارِ لایسنس.
 * یعنی یک قابلیتِ تازه، کلِ فروش را می‌خواباند.
 *
 * ⚠️ این تست ستون‌ها را **واقعاً حذف می‌کند** تا همان سرور را شبیه‌سازی کند.
 * تستی که فقط `config` را دست‌کاری کند، این را هرگز نمی‌گیرد.
 */
class DeployBeforeMigrateTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'm'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** سروری که هنوز مهاجرت نخورده */
    private function unmigrate(): void
    {
        Schema::table('services', function ($t) {
            $t->dropColumn(['is_reseller', 'server_ip']);
        });
        Schema::table('products', function ($t) {
            $t->dropColumn('requires_server_ip');
        });

        // ⚠️ کشِ درون‌پروسه‌ای باید پاک شود، وگرنه مدل هنوز «ستون هست» را
        // می‌گوید و تست بی‌صدا سبز می‌شود بی‌آنکه چیزی سنجیده باشد.
        // (`refreshApplication()` این‌جا جواب نمی‌دهد: دیتابیسِ تست in-memory
        // است و ری‌استارت، همهٔ جدول‌ها را هم می‌بَرد.)
        Service::flushColumnCache();
        Product::flushColumnCache();
    }

    public function test_ordinary_hosting_can_still_be_ordered_before_the_migration_runs(): void
    {
        $this->artisan('products:seed-hosting');
        $this->unmigrate();

        $this->assertFalse(Schema::hasColumn('services', 'is_reseller'), 'شبیه‌سازی درست انجام نشد');

        $product = Product::where('slug', 'linux-1')->firstOrFail();
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}",
                ['cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'shop.ir'])
            ->assertRedirect();

        $this->assertSame(1, Service::where('customer_id', $customer->id)->count(),
            'روی سرورِ مهاجرت‌نخورده، ثبتِ سفارشِ هاستِ عادی شکست — یعنی دیپلوی کلِ فروش را خوابانده');
    }

    /** و پکیجِ نمایندگی هم — فقط بی‌علامتِ نمایندگی، ولی سفارش ثبت می‌شود. */
    public function test_a_reseller_package_still_orders_before_the_migration_runs(): void
    {
        $this->artisan('products:seed-hosting');
        $this->unmigrate();

        $product = Product::where('slug', 'reseller-linux-1')->firstOrFail();
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}",
                ['cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'agency.ir'])
            ->assertRedirect();

        $this->assertSame(1, Service::where('customer_id', $customer->id)->count());
    }

    /** صفحاتِ فروش هم نباید ۵۰۰ بدهند. */
    public function test_the_sales_pages_do_not_500_before_the_migration_runs(): void
    {
        $this->unmigrate();

        foreach (['/services/licenses', '/hosting/reseller-directadmin', '/hosting/reseller-linux'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /** و seederِ لایسنس به‌جای استثنا، صریح می‌گوید «اول مهاجرت». */
    public function test_the_license_seeder_refuses_cleanly_instead_of_throwing(): void
    {
        $this->unmigrate();

        $this->artisan('products:seed-licenses')->assertExitCode(1);
        $this->assertSame(0, Product::where('group', 'licenses')->count());
    }
}
