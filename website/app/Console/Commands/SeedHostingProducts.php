<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * ساختِ پکیج‌های فروش از روی کاتالوگِ config/hosting.php.
 *
 * ۱۱ محصول × پلن‌هایشان = ~۵۲ پکیجِ قابلِ‌خرید در فروشگاه. قیمتِ پایه = همان
 * تومانِ سایت (که با نرخِ یورو مقیاس می‌شود). idempotent: فقط پکیجِ نبوده را
 * می‌سازد (firstOrCreate روی slug) تا ویرایش‌های بعدیِ مدیر پاک نشوند. سرورِ
 * تحویل و نامِ package در WHM را بعداً مدیر تعیین می‌کند.
 */
class SeedHostingProducts extends Command
{
    protected $signature = 'products:seed-hosting {--force : بازنویسیِ قیمت/مشخصاتِ پکیج‌های موجود}';

    protected $description = 'ساختِ پکیج‌های فروش از کاتالوگِ config/hosting.php';

    public function handle(): int
    {
        if (! Schema::hasTable('products')) {
            $this->warn('جدول products هنوز ساخته نشده — اول مهاجرت را بزنید.');

            return self::SUCCESS;
        }

        $products = (array) config('hosting.products', []);
        $created = 0;
        $updated = 0;

        foreach ($products as $slug => $prod) {
            $group = $prod['group'] ?? 'use';
            $category = $group === 'reseller' ? 'reseller' : 'shared';
            $title = $prod['fa']['t'] ?? $slug;
            /*
            | 🔴 ممیزی نهم: تا امروز فقط همین یک خطِ فارسی برداشته می‌شد و
            | `en.t`/`tr.t` — که در config موجودند — دور ریخته می‌شدند. از آن
            | لحظه جدولِ products تنها منبعِ نام بود و صفحهٔ `/en/order/*` هیچ
            | راهی به عقب نداشت: ۱۳۴ صفحه با نامِ فارسی در عنوان.
            */
            $titleEn = $prod['en']['t'] ?? null;
            $titleTr = $prod['tr']['t'] ?? null;

            foreach (($prod['plans'] ?? []) as $i => $plan) {
                $pkgSlug = $slug.'-'.($i + 1);
                $attrs = [
                    'name'            => $title.' — '.($plan['name'] ?? ('پلن '.($i + 1))),
                    // ⚠️ SKU (LX-2, BK-1T…) هرگز ترجمه نمی‌شود — شناسهٔ محصول است
                    'name_en'         => $titleEn !== null ? trim($titleEn.' '.($plan['name'] ?? '')) : null,
                    'name_tr'         => $titleTr !== null ? trim($titleTr.' '.($plan['name'] ?? '')) : null,
                    'category'        => $category,
                    // گروه = کلیدِ کاتالوگ (wordpress، backup، reseller-linux…)
                    // تا تغییرِ قیمتِ گروهی در پنل روی همان دسته کار کند.
                    'group'           => $slug,
                    'currency_code'  => 'IRT',
                    'price'           => (int) ($plan['irt'] ?? 0),
                    // یورو به **سنت** — نسخهٔ انگلیسی/ترکی همین را نشان می‌دهد و
                    // با این ستون، جدولِ products تنها منبعِ حقیقتِ قیمت می‌شود.
                    'price_eur'       => isset($plan['eur']) ? (int) round(((float) $plan['eur']) * 100) : null,
                    'setup_fee'       => 0,
                    'cycle'           => 'monthly',
                    'tax_percent'     => 10,
                    'specs'           => $this->specs($plan['specs'] ?? []),
                    // نامِ package در WHM — همان چیزی که createacct می‌فرستد.
                    // قبلاً نامِ پلنِ کاتالوگ («WP-5») نوشته می‌شد که هیچ packageای
                    // در WHM با آن نام نبود و تحویلِ خودکار شکست می‌خورد.
                    'plan'            => 'sn_'.substr(preg_replace('/[^a-z0-9]+/i', '_', $pkgSlug), 0, 40),
                    'requires_domain' => true,
                    'is_active'       => true,
                    'sort'            => $i,
                ];

                $existing = Product::where('slug', $pkgSlug)->first();
                if ($existing) {
                    /*
                    | 🔴 پرکردنِ ترجمهٔ نام، **بدونِ** --force.
                    |
                    | این seeder insert-missing است و ردیفِ موجود را جز با
                    | --force دست نمی‌زند — قاعدهٔ درستی است، چون قیمت و
                    | مشخصات را مدیر در پنل ویرایش می‌کند.
                    |
                    | ولی `name_en`/`name_tr` ستون‌های **تازه**اند: `null` یعنی
                    | «هرگز ست نشده»، نه «مدیر پاکش کرده». بی‌این پرکردن، هر ۶۷
                    | محصولِ موجودِ پروداکشن تا ابد بی‌ترجمه می‌مانند و ۱۳۴ صفحهٔ
                    | سفارشِ en/tr همان نامِ فارسی را نشان می‌دهند — یعنی مهاجرت
                    | و کدِ تازه هیچ اثری نمی‌گذارند و کسی هم خطایی نمی‌بیند.
                    |
                    | ⚠️ فقط وقتی خالی است. اگر مدیر روزی نامِ انگلیسی را دستی
                    | عوض کند، اجرای بعدی پاکش نمی‌کند.
                    */
                    $fill = [];
                    if (blank($existing->name_en) && filled($attrs['name_en'])) {
                        $fill['name_en'] = $attrs['name_en'];
                    }
                    if (blank($existing->name_tr) && filled($attrs['name_tr'])) {
                        $fill['name_tr'] = $attrs['name_tr'];
                    }
                    if ($fill !== [] && ! $this->option('force')) {
                        $existing->update($fill);
                        $updated++;
                    }

                    if ($this->option('force')) {
                        // فقط قیمت/مشخصات را تازه کن، سرورِ انتخاب‌شده را دست نزن.
                        // plan هم اصلاح می‌شود چون ردیف‌های قدیمی نامِ پلنِ کاتالوگ
                        // را دارند و با آن، تحویلِ خودکار روی WHM شکست می‌خورد.
                        $existing->update([
                            'name' => $attrs['name'],
                            // ⚠️ `?:` نه `??` — کاتالوگِ بی‌ترجمه نباید نامِ
                            //    دستیِ مدیر را با null پاک کند.
                            'name_en' => $attrs['name_en'] ?: $existing->name_en,
                            'name_tr' => $attrs['name_tr'] ?: $existing->name_tr,
                            'price' => $attrs['price'],
                            'specs' => $attrs['specs'], 'category' => $attrs['category'],
                            'plan' => $attrs['plan'], 'group' => $attrs['group'],
                            'price_eur' => $attrs['price_eur'],
                        ]);
                        $updated++;
                    }
                } else {
                    Product::create($attrs + ['slug' => $pkgSlug]);
                    $created++;
                }
            }
        }

        $this->info("پکیج‌ها: {$created} تازه، {$updated} به‌روزرسانی.");

        return self::SUCCESS;
    }

    /** مشخصاتِ پلن → [{label,value}] (per-locale را به فارسی حل می‌کند) */
    private function specs(array $raw): array
    {
        $out = [];
        foreach ($raw as $spec) {
            $text = is_array($spec) ? ($spec['fa'] ?? (string) reset($spec)) : (string) $spec;
            $text = trim($text);
            if ($text !== '') {
                $out[] = ['label' => $text, 'value' => ''];
            }
        }

        return $out;
    }
}
