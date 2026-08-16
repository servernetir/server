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
    /**
     * قیمت‌هایی که **نسخهٔ قبلیِ همین seeder** روی پروداکشن نوشته بود.
     *
     * تنها کاربردش تشخیصِ «این ردیف دست‌نخورده است» هنگام به‌روزرسانیِ قیمت
     * است (پایین، در `run()`). اگر روزی قیمت‌ها را دوباره عوض کردی، این نقشه
     * را هم به مقادیرِ **فعلی** به‌روز کن، وگرنه اجرای بعدی چیزی را به‌روز
     * نمی‌کند و قیمتِ تازه هرگز روی سایت زنده نمی‌شود.
     *
     * @var array<string,int>
     */
    private const PREVIOUS_SEEDED_PRICES = [
        'license-directadmin' => 690000,
        'license-cpanel'      => 990000,
        'license-plesk'       => 890000,
        'license-litespeed'   => 790000,
    ];

    /** @return array<string, array<string, mixed>> slug => attributes */
    public static function catalog(): array
    {
        $spec = fn (string $label) => ['label' => $label, 'value' => ''];

        return [
            'license-directadmin' => [
                'name'        => 'لایسنس DirectAdmin — سرور مجازی',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 350000,
                'price_eur'   => 350,
                'cycle'       => 'monthly',
                'tax_percent' => 10,
                'description' => 'لایسنس ماهانه DirectAdmin — فعال‌سازی روی IP سرور شما پس از پرداخت، اکانت نامحدود و دریافت آپدیت‌ها.',
                'specs'       => [
                    $spec('لایسنس ماهانه'),
                    $spec('اکانت نامحدود'),
                    $spec('فعال‌سازی روی IP شما'),
                    $spec('دریافت آپدیت‌ها'),
                ],
            ],
            'license-cpanel' => [
                'name'        => 'لایسنس cPanel/WHM — سرور مجازی',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 390000,
                'price_eur'   => 390,
                'cycle'       => 'monthly',
                'tax_percent' => 10,
                'description' => 'لایسنس ماهانه cPanel/WHM تا ۱۰۰ اکانت — فعال‌سازی روی IP سرور شما پس از پرداخت و دریافت آپدیت‌ها.',
                'specs'       => [
                    $spec('لایسنس ماهانه'),
                    $spec('تا ۱۰۰ اکانت'),
                    $spec('فعال‌سازی روی IP شما'),
                    $spec('دریافت آپدیت‌ها'),
                ],
            ],
            'license-plesk' => [
                'name'        => 'لایسنس Plesk — سرور مجازی',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 450000,
                'price_eur'   => 450,
                'cycle'       => 'monthly',
                'tax_percent' => 10,
                'description' => 'لایسنس ماهانه Plesk Web Host — دامنه نامحدود، لینوکس و ویندوز، همه اکستنشن‌های پایه.',
                'specs'       => [
                    $spec('لایسنس ماهانه'),
                    $spec('دامنه نامحدود'),
                    $spec('لینوکس و ویندوز'),
                    $spec('همه اکستنشن‌های پایه'),
                ],
            ],
            'license-litespeed' => [
                'name'        => 'لایسنس LiteSpeed Enterprise',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 390000,
                'price_eur'   => 390,
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

            /*
            | ═══ ردهٔ «سرور اختصاصی» + CloudLinux ═══
            |
            | cPanel و Plesk خودشان قیمت را بر محورِ مجازی/اختصاصی می‌بندند و
            | بازارِ ایران هم همین‌طور می‌فروشد. قیمتِ تخت یعنی مشتریِ VPS —
            | اکثریتِ خریدار — عددی ببیند که برای سرورِ اختصاصی بسته شده.
            |
            | ⚠️ چهار اسلاگِ بالا عمداً دست‌نخورده ماندند و ردهٔ «مجازی» شدند:
            | روی پروداکشن از قبل ساخته شده‌اند و عوض‌کردنِ اسلاگ یعنی محصولِ
            | یتیم در دیتابیس.
            */
            'license-directadmin-ded' => [
                'name'        => 'لایسنس DirectAdmin — سرور اختصاصی',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 590000,
                'price_eur'   => 590,
                'cycle'       => 'monthly',
                'tax_percent' => 10,
                'description' => 'لایسنس ماهانه DirectAdmin برای سرور اختصاصی — فعال‌سازی روی IP سرور شما پس از پرداخت، اکانت نامحدود.',
                'specs'       => [
                    $spec('لایسنس ماهانه'),
                    $spec('مخصوص سرور اختصاصی'),
                    $spec('اکانت نامحدود'),
                    $spec('فعال‌سازی روی IP شما'),
                ],
            ],
            'license-cpanel-ded' => [
                'name'        => 'لایسنس cPanel/WHM — سرور اختصاصی',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 740000,
                'price_eur'   => 740,
                'cycle'       => 'monthly',
                'tax_percent' => 10,
                'description' => 'لایسنس ماهانه cPanel/WHM برای سرور اختصاصی — فعال‌سازی روی IP سرور شما، روی سرور داخل و خارج ایران.',
                'specs'       => [
                    $spec('لایسنس ماهانه'),
                    $spec('مخصوص سرور اختصاصی'),
                    $spec('روی سرور داخل و خارج ایران'),
                    $spec('فعال‌سازی روی IP شما'),
                ],
            ],
            'license-plesk-ded' => [
                'name'        => 'لایسنس Plesk — سرور اختصاصی',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 690000,
                'price_eur'   => 690,
                'cycle'       => 'monthly',
                'tax_percent' => 10,
                'description' => 'لایسنس ماهانه Plesk برای سرور اختصاصی — دامنه نامحدود، لینوکس و ویندوز.',
                'specs'       => [
                    $spec('لایسنس ماهانه'),
                    $spec('مخصوص سرور اختصاصی'),
                    $spec('دامنه نامحدود'),
                    $spec('لینوکس و ویندوز'),
                ],
            ],
            // خریدارِ CloudLinux دقیقاً همان نماینده‌ای است که پکیجِ نمایندگی
            // می‌خرد — بی‌LVE، «اکانت زیاد» فروختن ریسکِ نود است.
            'license-cloudlinux' => [
                'name'        => 'لایسنس CloudLinux',
                'category'    => 'license',
                'group'       => 'license',
                'price'       => 390000,
                'price_eur'   => 390,
                'cycle'       => 'monthly',
                'tax_percent' => 10,
                'description' => 'لایسنس ماهانه CloudLinux — ایزولاسیون منابع با LVE و PHP Selector چندنسخه‌ای؛ لازمهٔ فروش نمایندگی پایدار.',
                'specs'       => [
                    $spec('لایسنس ماهانه'),
                    $spec('ایزولاسیون منابع با LVE'),
                    $spec('PHP Selector چندنسخه‌ای'),
                    $spec('لازمهٔ نمایندگی پایدار'),
                ],
            ],
        ];
    }

    public function run(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        foreach (self::catalog() as $slug => $attrs) {
            $product = Product::where('slug', $slug)->first();

            if (! $product) {
                Product::create($attrs + [
                    'slug'            => $slug,
                    'currency_code'   => 'IRT',
                    'setup_fee'       => 0,
                    'requires_domain' => false,
                    'is_active'       => true,
                    'sort'            => 0,
                ]);

                continue;
            }

            /*
            | ═══ 🔴 چرا «موجود بود ⇒ رد کن» کافی نبود ═══
            |
            | صفحهٔ کاتالوگ قیمت را از **دیتابیس** می‌خواند. پس وقتی قیمتِ
            | config عوض شود ولی ردیفِ دیتابیس عددِ قدیمی را نگه دارد، اصلاحِ
            | قیمت روی سایتِ زنده **هرگز** اثر نمی‌کند — و هیچ خطایی هم
            | نمی‌دهد. دقیقاً همین باعث شد قیمت‌های تازه (cPanel از ۹۹۰k به
            | ۳۹۰k) بعد از دیپلوی روی prod دیده نشوند.
            |
            | ⚠️ ولی ویرایشِ آگاهانهٔ مدیر **هرگز** نباید پاک شود — این تضمینِ
            | نسخهٔ اولِ همین seeder بود و درست است.
            |
            | نشانه صریح است: قیمتِ فعلی **دقیقاً** همان عددی است که نسخهٔ
            | قبلیِ همین seeder نوشته بود؟ پس دستِ کسی نخورده و می‌شود
            | به‌روزش کرد. هر عددِ دیگری یعنی مدیر آگاهانه عوضش کرده.
            |
            | 🔴 دو نشانهٔ اشتباه که قبلش امتحان کردم و هر دو رد شدند:
            |   • «هنوز فروش نرفته» — ویرایشِ مدیر روی پکیجِ نفروخته را پاک
            |     می‌کرد.
            |   • `updated_at !== created_at` — هر دو در **یک ثانیه** نوشته
            |     می‌شوند، پس ویرایشِ بلافاصله را نمی‌دید.
            | تستِ خودِ همین seeder هر دو را گرفت.
            */
            $previous = self::PREVIOUS_SEEDED_PRICES[$slug] ?? null;

            if ($previous === null || (int) $product->price !== $previous) {
                continue;   // یا تازه است، یا مدیر عوضش کرده — دست نزن
            }

            $product->update([
                'name'        => $attrs['name'],
                'price'       => $attrs['price'],
                'price_eur'   => $attrs['price_eur'],
                'description' => $attrs['description'],
                'specs'       => $attrs['specs'],
                'category'    => $attrs['category'],
                'group'       => $attrs['group'],
            ]);
        }
    }
}
