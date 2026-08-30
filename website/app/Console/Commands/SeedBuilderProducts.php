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
        /*
        | 🔴 همان یک‌خطیِ SeedHostingProducts: تا امروز فقط `fa.t` برداشته
        | می‌شد و `en.t`/`tr.t` — که در همین config موجودند — دور ریخته
        | می‌شدند. نتیجه‌اش سه صفحهٔ `/en/order/site-builder-*` با نامِ فارسی.
        */
        $titleEn = $cfg['en']['t'] ?? null;
        $titleTr = $cfg['tr']['t'] ?? null;
        $created = 0;
        $updated = 0;

        foreach (($cfg['plans'] ?? []) as $i => $plan) {
            $pkgSlug = self::SLUG_PREFIX.($i + 1);
            $attrs = [
                'name'            => $title.' — '.($plan['name'] ?? ('پلن '.($i + 1))),
                // ⚠️ نامِ پلن (Personal/Business/Shop) ترجمه نمی‌شود — شناسه است
                'name_en'         => $titleEn !== null ? trim($titleEn.' '.($plan['name'] ?? '')) : null,
                'name_tr'         => $titleTr !== null ? trim($titleTr.' '.($plan['name'] ?? '')) : null,
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
                /*
                | 🔴 بدونِ --force. ستونِ تازه: `null` یعنی «هرگز ست نشده»، نه
                | «مدیر پاکش کرده». بی‌این، هر سه ردیفِ موجودِ پروداکشن تا ابد
                | بی‌ترجمه می‌مانند و کدِ تازه هیچ اثری نمی‌گذارد.
                */
                $fill = [];
                foreach (['name_en', 'name_tr'] as $col) {
                    if (blank($existing->{$col}) && filled($attrs[$col])) {
                        $fill[$col] = $attrs[$col];
                    }
                }
                if ($fill !== [] && ! $this->option('force')) {
                    $existing->update($fill);
                    $updated++;
                }

                if ($this->option('force')) {
                    $existing->update([
                        'name' => $attrs['name'], 'price' => $attrs['price'],
                        // ⚠️ `?:` نه `??` — کاتالوگِ بی‌ترجمه نامِ دستیِ مدیر را پاک نکند
                        'name_en' => $attrs['name_en'] ?: $existing->name_en,
                        'name_tr' => $attrs['name_tr'] ?: $existing->name_tr,
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
