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

            foreach (($prod['plans'] ?? []) as $i => $plan) {
                $pkgSlug = $slug.'-'.($i + 1);
                $attrs = [
                    'name'            => $title.' — '.($plan['name'] ?? ('پلن '.($i + 1))),
                    'category'        => $category,
                    'currency_code'  => 'IRT',
                    'price'           => (int) ($plan['irt'] ?? 0),
                    'setup_fee'       => 0,
                    'cycle'           => 'monthly',
                    'tax_percent'     => 10,
                    'specs'           => $this->specs($plan['specs'] ?? []),
                    'plan'            => (string) ($plan['name'] ?? ''),   // نامِ package در WHM (قابلِ‌ویرایش)
                    'requires_domain' => true,
                    'is_active'       => true,
                    'sort'            => $i,
                ];

                $existing = Product::where('slug', $pkgSlug)->first();
                if ($existing) {
                    if ($this->option('force')) {
                        // فقط قیمت/مشخصات را تازه کن، سرورِ انتخاب‌شده را دست نزن
                        $existing->update([
                            'name' => $attrs['name'], 'price' => $attrs['price'],
                            'specs' => $attrs['specs'], 'category' => $attrs['category'],
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
