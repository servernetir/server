<?php

namespace Tests\Feature;

use App\Console\Commands\SeedBuilderProducts;
use App\Models\Product;
use Database\Seeders\LicenseProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 هر محصولی که فروخته می‌شود باید نامِ en/tr داشته باشد — نه فقط هاست.
 *
 * ═══ چرا این تست وجود دارد ═══
 *
 * ممیزی نهم گفت «۱۳۴ صفحهٔ سفارش نامِ فارسی نشان می‌دهند». من ستونِ
 * `name_en`/`name_tr` را ساختم، `SeedHostingProducts` را اصلاح کردم، تستش را
 * نوشتم، سبز شد، دیپلوی کردم — و **۲۲ صفحه هنوز فارسی بودند**.
 *
 * علتش این بود که تستِ قبلی (`ProductNameIsLocalizedTest`) محصولاتش را از
 * `config/hosting.php` می‌ساخت، یعنی دقیقاً همان منبعی که تعمیرش کرده بودم.
 * سه منبعِ **دیگر** محصول در این پروژه هست و هیچ‌کدام در دیدِ آن تست نبودند:
 *
 *     SeedHostingProducts   ← config/hosting.php        ✅ تعمیر شد
 *     LicenseProductSeeder  ← آرایهٔ سخت‌کد در خودِ seeder  ❌ جا مانده بود (۸ محصول)
 *     SeedBuilderProducts   ← config/catalog/services.php ❌ جا مانده بود (۳ محصول)
 *
 * ⚠️ **درسِ عمومی‌ترش:** تستی که فیکسچرش را از همان منبعی می‌سازد که تعمیر
 * کرده‌ای، فقط تعمیرِ خودت را می‌سنجد. برای ادعایی که دربارهٔ «همهٔ محصولات»
 * است، فیکسچر باید **همهٔ مسیرهای ساختِ محصول** را بدواند — وگرنه سبزیِ تست
 * دقیقاً به‌اندازهٔ چیزی است که از قبل می‌دانستی.
 *
 * ⚠️ و تنها راهی که واقعاً پیدایش کرد، سرکشی به هر ۱۳۴ صفحهٔ **سایتِ زنده**
 * بود. همان «کدِ ۲۰۰ یعنی هیچ» — هر ۱۳۴ صفحه ۲۰۰ می‌دادند.
 */
class EveryProductNameIsLocalizedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * هر مسیرِ ساختِ محصول را می‌دواند و بعد **کلِ جدول** را می‌سنجد.
     *
     * ⚠️ ادعا روی جدول است نه روی فهرستی که خودم نوشته‌ام: مسیرِ چهارمی که
     * فردا اضافه شود هم همین‌جا گیر می‌افتد، بی‌آنکه کسی یادش باشد این تست را
     * به‌روز کند.
     */
    public function test_no_sellable_product_is_left_without_english_and_turkish_names(): void
    {
        $this->seedEveryProductSource();

        $missing = Product::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('name_en')->orWhere('name_en', '')
                  ->orWhereNull('name_tr')->orWhere('name_tr', '');
            })
            ->pluck('slug')
            ->all();

        $this->assertSame([], $missing,
            'این محصولات نامِ en/tr ندارند، پس صفحهٔ سفارششان فارسی می‌مانَد: '
            .implode(', ', $missing));
    }

    /**
     * و نامِ ترجمه‌شده واقعاً فارسی نباشد.
     *
     * 🔴 ستونِ پرشده کافی نیست: یک seeder می‌تواند نامِ فارسی را در ستونِ
     * انگلیسی بنویسد و تستِ «خالی نیست» سبز بمانَد، در حالی که صفحه دقیقاً
     * همان چیزی را نشان می‌دهد که قرار بود درست شود.
     */
    public function test_the_translated_names_are_not_just_the_persian_one_copied(): void
    {
        $this->seedEveryProductSource();

        $persian = Product::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Product $p) => preg_match('/\p{Arabic}/u', (string) $p->name_en)
                || preg_match('/\p{Arabic}/u', (string) $p->name_tr))
            ->pluck('slug')
            ->all();

        $this->assertSame([], $persian,
            'نامِ «ترجمه‌شده»ی این محصولات هنوز حرفِ فارسی دارد: '.implode(', ', $persian));
    }

    /**
     * ⚠️ و اجرای دوباره نباید چیزی را خراب کند — این seederها روی
     * `/system/migrate` و در هر دیپلوی دوباره می‌دوند.
     */
    public function test_running_every_seeder_twice_changes_nothing(): void
    {
        $this->seedEveryProductSource();
        $before = Product::orderBy('slug')->pluck('name_en', 'slug')->all();

        $this->seedEveryProductSource();
        $after = Product::orderBy('slug')->pluck('name_en', 'slug')->all();

        $this->assertSame($before, $after, 'اجرای دومِ seederها نام‌ها را عوض کرد');
    }

    /**
     * ⚠️ نامِ انگلیسیِ ویرایش‌شدهٔ مدیر نباید با اجرای بعدی پاک شود.
     *
     * پرکردنِ «ستونِ خالی» عمدی است؛ **بازنویسی** نه. بی‌این ادعا، اولین کرونِ
     * بعدی هر ویرایشی را که مدیر در پنل کرده برمی‌گرداند.
     */
    public function test_an_admin_edited_english_name_survives_the_next_run(): void
    {
        $this->seedEveryProductSource();

        $p = Product::whereNotNull('name_en')->firstOrFail();
        $p->update(['name_en' => 'Hand Written Name']);

        $this->seedEveryProductSource();

        $this->assertSame('Hand Written Name', $p->fresh()->name_en,
            'seeder نامِ دستیِ مدیر را پاک کرد');
    }

    /** هر سه مسیرِ ساختِ محصول، به همان شکلی که در دیپلوی صدا زده می‌شوند. */
    private function seedEveryProductSource(): void
    {
        $this->artisan('products:seed-hosting')->assertSuccessful();
        $this->artisan(SeedBuilderProducts::class)->assertSuccessful();
        (new LicenseProductSeeder)->run();
    }
}
