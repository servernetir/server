<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * پکیج‌های فروشِ «سایت‌ساز» از روی config/catalog/services.php.
 *
 * تا امروز پلن‌های سایت‌ساز pid های placeholder داشتند (۲۱۰-۲۱۲) و دکمهٔ
 * استقرار به سبدِ WHMCSِ بیرونی می‌رفت. تسویهٔ خودِ کنسول
 * (BuilderCheckoutController) پکیجِ واقعی می‌خواهد — این فرمان همان را می‌سازد.
 *
 * همان قراردادِ SeedHostingProducts: idempotent (firstOrCreate روی slug)، قیمت
 * از خودِ کاتالوگ، و `plan` = نامِ packageِ WHM که تحویلِ خودکار می‌فرستد.
 */
class SeedBuilderProducts extends Command
{
    protected $signature = 'products:seed-builder {--force : بازنویسیِ قیمت/مشخصاتِ پکیج‌های موجود}';

    protected $description = 'ساختِ پکیج‌های سایت‌ساز از config/catalog/services.php';

    /** slug پکیج به‌ازای هر پلنِ کاتالوگ — ترتیب همان ترتیبِ کاتالوگ است */
    public const SLUG_PREFIX = 'site-builder-';

    public function handle(): int
    {
        if (! Schema::hasTable('products')) {
            $this->warn('جدول products هنوز ساخته نشده — اول مهاجرت را بزنید.');

            return self::SUCCESS;
        }

        $cfg = (array) config('catalog.services.site-builder', []);
        $title = $cfg['fa']['t'] ?? 'سایت‌ساز';
        $created = 0;
        $updated = 0;

        foreach (($cfg['plans'] ?? []) as $i => $plan) {
            $pkgSlug = self::SLUG_PREFIX.($i + 1);
            $attrs = [
                'name'            => $title.' — '.($plan['name'] ?? ('پلن '.($i + 1))),
                'category'        => 'shared',
                'group'           => 'site-builder',
                'currency_code'   => 'IRT',
                'price'           => (int) ($plan['irt'] ?? 0),
                'price_eur'       => isset($plan['eur']) ? (int) round(((float) $plan['eur']) * 100) : null,
                'setup_fee'       => 0,
                'cycle'           => 'monthly',
                'tax_percent'     => 10,
                'specs'           => $this->specs($plan['specs'] ?? []),
                'plan'            => 'sn_'.substr(preg_replace('/[^a-z0-9]+/i', '_', $pkgSlug), 0, 40),
                'requires_domain' => true,
                'is_active'       => true,
                'sort'            => $i,
            ];

            $existing = Product::where('slug', $pkgSlug)->first();
            if ($existing) {
                if ($this->option('force')) {
                    $existing->update([
                        'name' => $attrs['name'], 'price' => $attrs['price'],
                        'specs' => $attrs['specs'], 'plan' => $attrs['plan'],
                        'group' => $attrs['group'], 'price_eur' => $attrs['price_eur'],
                    ]);
                    $updated++;
                }
            } else {
                Product::create($attrs + ['slug' => $pkgSlug]);
                $created++;
            }
        }

        $this->info("پکیج‌های سایت‌ساز: {$created} تازه، {$updated} به‌روزرسانی.");

        return self::SUCCESS;
    }

    /** مشخصاتِ پلن → [{label,value}] — همان قراردادِ SeedHostingProducts */
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
