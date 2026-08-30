<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * یک منبعِ حقیقت برای قیمت‌های کاتالوگ.
 *
 * ═══ مشکلی که حل می‌کند ═══
 *
 * قیمت در دو جا بود:
 *   • config/hosting.php  → صفحاتِ بازاریابی (چیزی که مشتری هنگام گشتن می‌بیند)
 *   • جدولِ products      → تسویه و فاکتور (چیزی که واقعاً پرداخت می‌کند)
 *
 * یعنی تغییرِ قیمت در پنلِ مدیریت، صفحهٔ محصول را عوض نمی‌کرد و برعکس. همان
 * کلاسِ خطایی که یک‌بار باعث شد سایت ۲۰٪ تخفیف تبلیغ کند و تسویه ۱۵٪ بگیرد.
 *
 * حالا اگر پکیجی در جدول باشد، قیمتش **همان** است که نمایش داده می‌شود؛ config
 * فقط پشتیبان است (برای پکیجی که هنوز در دیتابیس نیست یا سروری که مهاجرت
 * نکرده). پس تغییرِ گروهیِ قیمت در پنل، بلافاصله در کلِ سایت دیده می‌شود.
 */
class CatalogPricing
{
    /** ۵ دقیقه کافی است: تغییرِ قیمت باید زود دیده شود، ولی هر بازدید پرس‌وجو نزند */
    private const TTL = 300;

    /**
     * نگاشتِ اسلاگ → ['irt' => تومان, 'eur' => یورو(عدد اعشاری)]
     *
     * @return array<string, array{irt:int, eur:float|null}>
     */
    public function map(): array
    {
        if (! Schema::hasTable('products')) {
            return [];
        }

        return Cache::remember('catalog.prices', self::TTL, function () {
            return Product::query()
                ->where('is_active', true)
                ->get(['slug', 'price', 'price_eur'])
                ->mapWithKeys(fn (Product $p) => [$p->slug => [
                    'irt' => (int) $p->price,
                    // سنت → یورو، فقط برای نمایش
                    'eur' => $p->price_eur !== null ? $p->price_eur / 100 : null,
                ]])
                ->all();
        });
    }

    /**
     * قیمت‌های دیتابیس را روی پلن‌های config سوار می‌کند.
     *
     * اسلاگِ هر پلن «<گروه>-<شمارهٔ ردیف+۱>» است — همان قراردادی که seeder
     * ساخته. اگر پکیجی در دیتابیس نبود، مقدارِ config دست‌نخورده می‌ماند.
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @return array<int, array<string, mixed>>
     */
    public function applyToPlans(string $group, array $plans): array
    {
        $map = $this->map();

        foreach ($plans as $i => $plan) {
            $slug = $group.'-'.($i + 1);

            if (! isset($map[$slug])) {
                continue;
            }

            $plans[$i]['irt'] = $map[$slug]['irt'];

            if ($map[$slug]['eur'] !== null) {
                $plans[$i]['eur'] = $map[$slug]['eur'];
            }
        }

        return $plans;
    }

    /** بعد از هر تغییرِ قیمت صدا زده می‌شود تا سایت فوراً عددِ تازه را نشان دهد */
    public static function forget(): void
    {
        Cache::forget('catalog.prices');
    }
}
