<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\CatalogPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تغییرِ قیمتِ گروهی — روی پول کار می‌کند، پس ادعاها با عددِ واقعی‌اند.
 *
 * قاعدهٔ کارفرما: نتیجه همیشه **رو به بالا** و مضربِ پلهٔ انتخابی باشد تا
 * عددها تمیز بمانند (۲۴۰٬۰۰۰ نه ۲۳۷٬۴۵۰). گردکردنِ پایین یعنی تخفیفِ ناخواسته.
 */
class GroupRepriceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function product(string $slug, string $group, int $price, ?int $eur = null): Product
    {
        return Product::create([
            'name' => 'پکیج '.$slug, 'slug' => $slug, 'group' => $group, 'category' => 'shared',
            'price' => $price, 'price_eur' => $eur, 'cycle' => 'monthly', 'tax_percent' => 10,
            'is_active' => true,
        ]);
    }

    // ───────────────────────── گردکردن ─────────────────────────

    public function test_rounding_is_always_upward(): void
    {
        $this->assertSame(240000, Product::roundUpToman(237450));      // مثالِ خودِ کارفرما
        $this->assertSame(240000, Product::roundUpToman(240000));      // ازقبل رند = دست‌نخورده
        $this->assertSame(250000, Product::roundUpToman(240001));      // یک تومان بیشتر = پلهٔ بعد
        $this->assertSame(250000, Product::roundUpToman(237450, 50000));
        $this->assertSame(490, Product::roundUpEur(487));              // ۴٫۸۷ → ۴٫۹۰
    }

    /** هیچ‌وقت صفر یا منفی ذخیره نشود */
    public function test_rounding_never_goes_below_zero(): void
    {
        $this->assertSame(0, Product::roundUpToman(0));
        $this->assertSame(10000, Product::roundUpToman(1));
    }

    // ───────────────────────── اعمالِ گروهی ─────────────────────────

    public function test_percent_increase_applies_to_whole_group_only(): void
    {
        $a = $this->product('wp-1', 'wordpress', 250000);
        $b = $this->product('wp-2', 'wordpress', 690000);
        $other = $this->product('lx-1', 'linux', 149000);

        $this->actingAs($this->admin(), 'web')->post('/admin/products-reprice', [
            'group' => 'wordpress', 'mode' => 'percent', 'value' => 10, 'round' => 10000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(280000, $a->fresh()->price);   // ۲۷۵٬۰۰۰ → رو به بالا
        $this->assertSame(760000, $b->fresh()->price);   // ۷۵۹٬۰۰۰ → رو به بالا
        $this->assertSame(149000, $other->fresh()->price, 'گروهِ دیگر نباید دست بخورد');
    }

    public function test_percent_decrease_works(): void
    {
        $a = $this->product('wp-1', 'wordpress', 300000);

        $this->actingAs($this->admin(), 'web')->post('/admin/products-reprice', [
            'group' => 'wordpress', 'mode' => 'percent', 'value' => -10, 'round' => 10000,
        ])->assertRedirect();

        $this->assertSame(270000, $a->fresh()->price);
    }

    public function test_fixed_amount_and_set_modes(): void
    {
        $a = $this->product('wp-1', 'wordpress', 250000);

        $this->actingAs($this->admin(), 'web')->post('/admin/products-reprice', [
            'group' => 'wordpress', 'mode' => 'amount', 'value' => 55000, 'round' => 10000,
        ])->assertRedirect();
        $this->assertSame(310000, $a->fresh()->price);   // ۳۰۵٬۰۰۰ → رو به بالا

        $this->actingAs($this->admin(), 'web')->post('/admin/products-reprice', [
            'group' => 'wordpress', 'mode' => 'set', 'value' => 199000, 'round' => 10000,
        ])->assertRedirect();
        $this->assertSame(200000, $a->fresh()->price);
    }

    public function test_eur_scales_only_on_percent_mode(): void
    {
        $a = $this->product('wp-1', 'wordpress', 250000, 249);

        $this->actingAs($this->admin(), 'web')->post('/admin/products-reprice', [
            'group' => 'wordpress', 'mode' => 'percent', 'value' => 10, 'round' => 10000, 'also_eur' => 1,
        ])->assertRedirect();

        $this->assertSame(280, $a->fresh()->price_eur);  // ۲۷۳٫۹ → ۲۸۰ سنت

        // در حالتِ مبلغِ ثابت، یورو دست نمی‌خورد (نسبت معنا ندارد)
        $before = $a->fresh()->price_eur;
        $this->actingAs($this->admin(), 'web')->post('/admin/products-reprice', [
            'group' => 'wordpress', 'mode' => 'amount', 'value' => 10000, 'round' => 10000, 'also_eur' => 1,
        ])->assertRedirect();
        $this->assertSame($before, $a->fresh()->price_eur);
    }

    /** نویسنده (نقشِ غیرمدیر) حق تغییرِ قیمت ندارد */
    public function test_non_admin_cannot_reprice(): void
    {
        $a = $this->product('wp-1', 'wordpress', 250000);
        $author = User::create([
            'name' => 'نویسنده', 'email' => 'w'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'author',
        ]);

        $this->actingAs($author, 'web')->post('/admin/products-reprice', [
            'group' => 'wordpress', 'mode' => 'percent', 'value' => 50, 'round' => 10000,
        ])->assertForbidden();

        $this->assertSame(250000, $a->fresh()->price);
    }

    public function test_unknown_group_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'web')->post('/admin/products-reprice', [
            'group' => 'no-such-group', 'mode' => 'percent', 'value' => 10,
        ])->assertSessionHasErrors();
    }

    // ─────────── یک منبعِ حقیقت: قیمتِ سایت = قیمتِ دیتابیس ───────────

    /**
     * قبلاً قیمتِ صفحاتِ بازاریابی از config می‌آمد و قیمتِ تسویه از دیتابیس؛
     * تغییر در پنل روی سایت اثر نداشت. این تست همان شکاف را می‌بندد.
     */
    public function test_catalog_price_comes_from_database(): void
    {
        $this->product('wordpress-1', 'wordpress', 777000, 999);

        CatalogPricing::forget();
        $plans = app(CatalogPricing::class)->applyToPlans('wordpress', [
            ['name' => 'WP-5', 'irt' => 250000, 'eur' => 2.49],
        ]);

        $this->assertSame(777000, $plans[0]['irt']);
        $this->assertSame(9.99, $plans[0]['eur']);
    }

    /** پکیجی که در دیتابیس نیست، مقدارِ config را نگه می‌دارد (پشتیبان) */
    public function test_missing_product_falls_back_to_config(): void
    {
        CatalogPricing::forget();
        $plans = app(CatalogPricing::class)->applyToPlans('nogroup', [
            ['name' => 'X', 'irt' => 123000, 'eur' => 1.5],
        ]);

        $this->assertSame(123000, $plans[0]['irt']);
    }
}
