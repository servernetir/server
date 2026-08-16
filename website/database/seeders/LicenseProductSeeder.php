<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * پکیج‌های لایسنس نرم‌افزار — **insert-missing**، مثل PhysicalServerSeeder.
 *
 * قیمت‌ها دقیقاً همان اعدادی است که صفحه‌ی /services/licenses از config
 * تبلیغ می‌کند (۶۹۰/۹۹۰/۸۹۰/۷۹۰ هزار تومان) — قیمتِ اعلام‌شده تعهد است و
 * seeder حق ندارد عدد تازه اختراع کند. تغییرِ قیمت کارِ مدیر در /admin/products
 * است؛ صفحه‌ی کاتالوگ از همان DB می‌خواند پس دو منبعِ حقیقت نمی‌شود.
 *
 * اسلاگ‌ها با کلید `product` پلن‌های config/catalog/services.php جفت‌اند؛
 * جفتِ شکسته یعنی دکمه‌ی خرید «تماس بگیرید» می‌شود (نه لینکِ مرده) و تستِ
 * LicenseSalesTest همین جفت‌بودن را قفل می‌کند.
 */
class LicenseProductSeeder extends Seeder
{
    /** @return array<string, array<string, mixed>> slug => attributes */
    public static function catalog(): array
    {
        $spec = fn (string $label) => ['label' => $label, 'value' => ''];

        return [
            'license-directadmin' => [
                'name'        => 'لایسنس DirectAdmin',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 690000,
                'price_eur'   => 690,
                'cycle'       => 'monthly',
                'tax_percent' => 10,
                'description' => 'لایسنس اورجینال ماهانه DirectAdmin — فعال‌سازی روی IP سرور شما، اکانت نامحدود و آپدیت مستقیم رسمی.',
                'specs'       => [
                    $spec('لایسنس اورجینال ماهانه'),
                    $spec('اکانت نامحدود'),
                    $spec('فعال‌سازی روی IP شما'),
                    $spec('آپدیت مستقیم رسمی'),
                ],
            ],
            'license-cpanel' => [
                'name'        => 'لایسنس cPanel/WHM',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 990000,
                'price_eur'   => 990,
                'cycle'       => 'monthly',
                'tax_percent' => 10,
                'description' => 'لایسنس اورجینال ماهانه cPanel/WHM تا ۱۰۰ اکانت — فعال‌سازی روی IP سرور شما و آپدیت مستقیم رسمی.',
                'specs'       => [
                    $spec('لایسنس اورجینال ماهانه'),
                    $spec('تا ۱۰۰ اکانت'),
                    $spec('فعال‌سازی روی IP شما'),
                    $spec('آپدیت مستقیم رسمی'),
                ],
            ],
            'license-plesk' => [
                'name'        => 'لایسنس Plesk Web Host',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 890000,
                'price_eur'   => 890,
                'cycle'       => 'monthly',
                'tax_percent' => 10,
                'description' => 'لایسنس اورجینال ماهانه Plesk Web Host — دامنه نامحدود، لینوکس و ویندوز، همه اکستنشن‌های پایه.',
                'specs'       => [
                    $spec('لایسنس اورجینال ماهانه'),
                    $spec('دامنه نامحدود'),
                    $spec('لینوکس و ویندوز'),
                    $spec('همه اکستنشن‌های پایه'),
                ],
            ],
            'license-litespeed' => [
                'name'        => 'لایسنس LiteSpeed',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 790000,
                'price_eur'   => 790,
                'cycle'       => 'monthly',
                'tax_percent' => 10,
                'description' => 'لایسنس ماهانه LiteSpeed Web Server Enterprise تا ۸ هسته — LSCache برای همه CMSها، جایگزین مستقیم Apache.',
                'specs'       => [
                    $spec('Web Server Enterprise'),
                    $spec('تا ۸ هسته CPU'),
                    $spec('LSCache همه CMSها'),
                    $spec('جایگزین مستقیم Apache'),
                ],
            ],
        ];
    }

    public function run(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $existing = Product::whereIn('slug', array_keys(self::catalog()))->pluck('slug')->all();

        foreach (self::catalog() as $slug => $attrs) {
            if (in_array($slug, $existing, true)) {
                continue;   // موجود است — قیمت/ویرایشِ مدیر را پاک نکن
            }

            Product::create($attrs + [
                'slug'            => $slug,
                'currency_code'   => 'IRT',
                'setup_fee'       => 0,
                'requires_domain' => false,
                'is_active'       => true,
                'sort'            => 0,
            ]);
        }
    }
}
