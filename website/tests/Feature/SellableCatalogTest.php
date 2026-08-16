<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «می‌شود این را خرید؟» — تنها سؤالی که برای فروش اهمیت دارد.
 *
 * ═══ 🔴 خرابی‌ای که این تست‌ها می‌بندند ═══
 *
 * صفحهٔ محصول می‌تواند ۲۰۰ بدهد، قیمت درست نشان دهد، و دکمهٔ «انتخاب» هم
 * داشته باشد — و باز هم **هیچ‌کس نتواند بخرد**، چون آن دکمه به سبدِ WHMCSِ
 * بیرونی با pidِ placeholder می‌رود. دقیقاً همین برای لایسنس و نمایندگیِ
 * دایرکت‌ادمین اتفاق افتاده بود.
 *
 * پس ادعا روی **مقصدِ دکمه** است، نه روی وضعیتِ صفحه.
 */
class SellableCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): void
    {
        $this->artisan('products:seed-hosting');
        $this->artisan('products:seed-licenses');
    }

    /** @return list<string> */
    private function pages(): array
    {
        return [
            '/services/licenses',
            '/hosting/reseller-directadmin',
            '/hosting/reseller-linux',
            '/hosting/reseller-windows',
            '/hosting/reseller-wordpress',
        ];
    }

    public function test_every_reseller_and_license_plan_links_to_our_own_checkout(): void
    {
        $this->seedCatalog();

        foreach ($this->pages() as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('my.servernet', $html,
                "صفحهٔ {$url} هنوز به سبدِ WHMCSِ بیرونی لینک می‌دهد — قابلِ خرید نیست");
            $this->assertMatchesRegularExpression('#/account/order/#', $html,
                "صفحهٔ {$url} هیچ دکمهٔ خریدِ داخلی ندارد");
        }
    }

    /**
     * ⚠️ اسلاگی که ویو می‌سازد باید با اسلاگی که seeder می‌نویسد **یکی** باشد.
     * یک حرف اختلاف («license» به‌جای «licenses») یعنی محصول در دیتابیس هست
     * و دکمه پیدایش نمی‌کند.
     */
    public function test_the_slug_the_page_builds_matches_the_slug_the_seeder_writes(): void
    {
        $this->seedCatalog();

        foreach (['licenses' => 8, 'reseller-directadmin' => 4, 'reseller-linux' => 4] as $key => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $this->assertNotNull(Product::where('slug', $key.'-'.$i)->first(),
                    "پکیجِ «{$key}-{$i}» وجود ندارد — دکمهٔ خریدش کار نمی‌کند");
            }
        }
    }

    /** خریدِ واقعیِ یک لایسنس تا صدورِ پیش‌فاکتور. */
    public function test_a_license_can_actually_be_bought_end_to_end(): void
    {
        $this->seedCatalog();

        $product = Product::where('slug', 'licenses-3')->firstOrFail();   // cPanel — سرور مجازی
        $customer = Customer::create([
            'email' => 'buyer@x.com', 'phone' => '09121234567',
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);

        $this->actingAs($customer, 'customer')
            ->get("/account/order/{$product->slug}")->assertOk();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'server_ip' => '203.0.113.10'])
            ->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('203.0.113.10', $service->server_ip);

        $invoice = Invoice::where('service_id', $service->id)->firstOrFail();
        $this->assertSame('unpaid', $invoice->status);
        $this->assertGreaterThan(0, $invoice->total);
    }

    /** خریدِ واقعیِ یک پکیجِ نمایندگیِ دایرکت‌ادمین. */
    public function test_a_directadmin_reseller_package_can_actually_be_bought(): void
    {
        $this->seedCatalog();

        $product = Product::where('slug', 'reseller-directadmin-1')->firstOrFail();
        $customer = Customer::create([
            'email' => 'agency@x.com', 'phone' => '09129876543',
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}",
                ['cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'agency.ir'])
            ->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertTrue((bool) $service->is_reseller, 'پکیجِ نمایندگی، سرویسِ نمایندگی نساخت');
        $this->assertNotNull(Invoice::where('service_id', $service->id)->first());
    }

    /**
     * ⚠️ پکیجِ لایسنس نباید هیچ‌وقت به WHM تماس بزند — نه موقعِ ساخت، نه
     * ویرایش، نه همگام‌سازی. وسطِ کارِ دستیِ مدیر، خطای WHM فقط سردرگمی است.
     */
    public function test_a_license_package_never_calls_whm(): void
    {
        $this->seedCatalog();

        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $admin = \App\Models\User::create([
            'name' => 'مدیر', 'email' => 'a@x.com', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $product = Product::where('slug', 'licenses-1')->firstOrFail();

        // اگر گارد نباشد، این تماسِ واقعی به WHM می‌زند و preventStrayRequests می‌ترکد
        $this->actingAs($admin)->post("/admin/products/{$product->id}/whm-sync")
            ->assertRedirect();
    }

    /**
     * 🔴 روتِ `/system/migrate` باید پکیجِ **نبوده** را روی دیتابیسِ پر هم بسازد.
     *
     * شرطِ قبلی `Product::count() === 0` بود، یعنی seeder فقط روی دیتابیسِ خالی
     * می‌دوید — و پروداکشن هرگز خالی نیست. نتیجه‌اش این بود که هر خطِ محصولِ
     * تازه بعد از دیپلوی روی سایتِ زنده **ساخته نمی‌شد**: صفحه قیمت را نشان
     * می‌داد و دکمهٔ خرید به سبدِ WHMCSِ بیرونی برمی‌گشت. این تست همان
     * سناریو را می‌سازد: کاتالوگِ پر، یک خطِ گم‌شده، و انتظارِ بازسازی.
     */
    public function test_seeding_creates_missing_packages_even_when_the_catalog_is_not_empty(): void
    {
        $this->seedCatalog();
        $full = Product::count();
        $this->assertGreaterThan(0, $full);

        // یک خطِ محصول غیب می‌شود — مثلِ خطِ تازه‌ای که روی prod هنوز seed نشده
        Product::where('group', 'licenses')->delete();
        $this->assertLessThan($full, Product::count());

        $this->seedCatalog();

        $this->assertSame($full, Product::count(),
            'seeder روی دیتابیسِ پر پکیجِ نبوده را نساخت — روی پروداکشن یعنی محصول هرگز زنده نمی‌شود');
    }
}
