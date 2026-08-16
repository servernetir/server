<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * ساختِ پکیج‌های **فروشِ لایسنس** از کاتالوگِ `config/catalog/services.licenses`.
 *
 * ═══ 🔴 چرا لازم شد ═══
 *
 * صفحهٔ `/services/licenses` قیمت و دکمهٔ خرید داشت، ولی هیچ ردیفی در جدولِ
 * `products` نبود: `products:seed-hosting` فقط `config/hosting.php` را می‌خواند.
 * یعنی دکمهٔ «خرید» به سبدِ **WHMCSِ بیرونی** با pidهایی می‌رفت که خودِ فایلِ
 * کاتالوگ صریح می‌گوید placeholderاند. مشتری روی خرید کلیک می‌کرد و به
 * محصولی می‌رسید که وجود نداشت.
 *
 * ═══ تفاوت‌های لایسنس با هاست ═══
 *
 *  • `requires_domain = false` — لایسنس روی IP فعال می‌شود، نه روی دامنه.
 *  • `requires_server_ip = true` — و همان IP **هنگام سفارش** گرفته می‌شود؛
 *    شرطِ اینکه بشود «تحویل آنی پس از پرداخت» گفت.
 *  • `server_id = null` — هیچ سرورِ تحویلی ندارد. پس `needsProvisioning()`
 *    نادرست است و کرونِ تحویل هرگز برش نمی‌دارد. **این عمدی است**: فعال‌سازی
 *    لایسنس کارِ اپراتور است، و ساختنِ یک درایورِ الکی فقط «تحویل شد»ِ دروغین
 *    می‌ساخت.
 *
 * idempotent: `firstOrCreate` روی slug، پس ویرایشِ بعدیِ مدیر پاک نمی‌شود.
 * `--force` فقط قیمت/مشخصات را تازه می‌کند.
 */
class SeedLicenseProducts extends Command
{
    protected $signature = 'products:seed-licenses {--force : بازنویسیِ قیمت و مشخصاتِ پکیج‌های موجود}';

    protected $description = 'ساختِ پکیج‌های فروشِ لایسنس از کاتالوگِ خدمات';

    public function handle(): int
    {
        if (! Schema::hasTable('products')) {
            $this->warn('جدول products هنوز ساخته نشده — اول مهاجرت را بزنید.');

            return self::SUCCESS;
        }

        $hasIpColumn = Schema::hasColumn('products', 'requires_server_ip');
        if (! $hasIpColumn) {
            // بی‌این ستون، پکیج ساخته می‌شود ولی فرمِ سفارش IP نمی‌پرسد و
            // اپراتور سفارشی می‌گیرد که نمی‌داند روی چه سروری فعالش کند.
            $this->warn('ستونِ requires_server_ip نیست — اول مهاجرت را بزنید، وگرنه فرمِ سفارش IP نمی‌پرسد.');

            return self::FAILURE;
        }

        $plans = (array) config('catalog.services.licenses.plans', []);

        if ($plans === []) {
            $this->warn('کاتالوگِ لایسنس خالی است.');

            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;

        foreach ($plans as $i => $plan) {
            $name = (string) ($plan['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $slug = 'license-'.($i + 1);

            $attrs = [
                'name'               => 'لایسنس '.$name,
                'category'           => 'other',
                'group'              => 'licenses',
                'currency_code'      => 'IRT',
                'price'              => (int) ($plan['irt'] ?? 0),
                // یورو به **سنت** — همان قاعدهٔ products.price_eur
                'price_eur'          => isset($plan['eur']) ? (int) round(((float) $plan['eur']) * 100) : null,
                'setup_fee'          => 0,
                'cycle'              => 'monthly',
                'tax_percent'        => 10,
                'specs'              => $this->specs((array) ($plan['specs'] ?? [])),
                'server_id'          => null,
                'plan'               => null,
                'requires_domain'    => false,
                'requires_server_ip' => true,
                'is_active'          => true,
                'sort'               => $i,
            ];

            $existing = Product::where('slug', $slug)->first();

            if ($existing) {
                if ($this->option('force')) {
                    $existing->update([
                        'name' => $attrs['name'], 'price' => $attrs['price'],
                        'price_eur' => $attrs['price_eur'], 'specs' => $attrs['specs'],
                        'group' => $attrs['group'],
                        'requires_domain' => false, 'requires_server_ip' => true,
                    ]);
                    $updated++;
                }

                continue;
            }

            Product::create($attrs + ['slug' => $slug]);
            $created++;
        }

        $this->info("لایسنس‌ها: {$created} تازه، {$updated} به‌روزرسانی.");

        return self::SUCCESS;
    }

    /**
     * مشخصاتِ کاتالوگ → [{label}]. ورودی یا رشتهٔ خام است یا آرایهٔ fa/en/tr.
     *
     * @param  array<int,mixed>  $raw
     * @return array<int,array{label:string}>
     */
    private function specs(array $raw): array
    {
        $out = [];

        foreach ($raw as $spec) {
            $label = is_array($spec) ? ($spec['fa'] ?? reset($spec)) : $spec;
            $label = trim((string) $label);

            if ($label !== '') {
                $out[] = ['label' => $label];
            }
        }

        return $out;
    }
}
