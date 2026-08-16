<?php

namespace App\Services\Provisioning;

use App\Models\Product;
use App\Models\Service;

/**
 * سقفِ منابعِ یک نماینده — «چند اکانت، چقدر دیسک، چقدر پهنای‌باند».
 *
 * ═══ 🔴 چرا این کلاس وجود دارد ═══
 *
 * `createacct` با `reseller=1` نماینده‌ای می‌سازد که **هیچ سقفی ندارد**. یعنی
 * کسی که پلنِ ۱۰ اکانتیِ RDA-10 خریده، می‌تواند تا پرشدنِ کلِ نود اکانت بسازد.
 * خرابی هیچ خطایی تولید نمی‌کند — نه در تحویل، نه بعدش — و قربانی‌اش
 * **مشتریانِ دیگرِ همان نود** هستند، نه خودِ نماینده. پس تا وقتی کسی شکایت
 * نکند، هیچ‌کس نمی‌فهمد.
 *
 * ═══ سقف از کجا می‌آید ═══
 *
 * از مشخصاتِ همان پکیجی که فروخته‌ایم (`products.specs`), با تطبیقِ
 * `services.plan` → `products.plan`. آن کلید همان `sn_<slug>` است که
 * `Product::packageName()` می‌سازد و در لحظهٔ سفارش روی سرویس کپی می‌شود، پس
 * یک‌به‌یک و پایدار است.
 *
 * ⚠️ **«پیدا نشد» هرگز به «نامحدود» ترجمه نمی‌شود.** اگر پکیج حذف یا
 * تغییرِنام داده باشد، `resolve()` مقدارِ `null` می‌دهد و فراخوان موظف است
 * صدایش را دربیاورد. سقوطِ بی‌صدا به نامحدود دقیقاً همان خرابیِ بالاست، فقط
 * با یک مرحله تأخیر.
 */
class ResellerLimits
{
    /**
     * @return array{accounts:?int,disk_mb:?int,bw_mb:?int,source:string}
     *         source: 'product' یعنی از مشخصات درآمد، 'unknown' یعنی درنیامد
     */
    public static function forService(Service $service): array
    {
        $none = ['accounts' => null, 'disk_mb' => null, 'bw_mb' => null, 'source' => 'unknown'];

        if (blank($service->plan)) {
            return $none;
        }

        $product = Product::query()->where('plan', $service->plan)->first();

        if (! $product || ! is_array($product->specs)) {
            return $none;
        }

        $out = self::fromSpecs($product->specs);

        // اگر هیچ‌کدام از سه عدد درنیامد، «پیدا شد ولی چیزی نگفت» با «پیدا
        // نشد» یکی است: در هر دو حالت سقفی برای گذاشتن نداریم.
        $out['source'] = ($out['accounts'] === null && $out['disk_mb'] === null && $out['bw_mb'] === null)
            ? 'unknown' : 'product';

        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>>  $specs
     * @return array{accounts:?int,disk_mb:?int,bw_mb:?int,source:string}
     */
    public static function fromSpecs(array $specs): array
    {
        $accounts = null;
        $diskMb = null;
        $bwMb = null;

        foreach ($specs as $spec) {
            $t = self::latinDigits(mb_strtolower((string) ($spec['label'] ?? '')));

            // ── تعدادِ اکانت ────────────────────────────────────────────────
            // «۲۵ اکانت هاست» / «25 hosting accounts» / «250 hosting hesabı»
            if ($accounts === null && preg_match('/(اکانت|account|hesab)/u', $t)) {
                if (preg_match('/(نامحدود|unlimited|sınırsız|sinirsiz)/u', $t)) {
                    // «نامحدود» یک تصمیمِ صریحِ فروش است، نه ندانستن.
                    $accounts = 0;                       // 0 = سقف نگذار
                } elseif (preg_match('/(\d+)/', $t, $m)) {
                    $accounts = (int) $m[1];
                }
            }

            // ── دیسک ───────────────────────────────────────────────────────
            // همان منطقِ ProductController::parseLimits: چیزی که «فضا» نیست
            // نباید با quota اشتباه شود (رم، هسته، ترافیک، تعدادِ سایت…).
            $notDisk = preg_match('/(پهنای|ترافیک|bandwidth|transfer|trafik|ram|رم|cpu|core|هسته|vcpu|صندوق|mailbox|سایت|اکانت|account|hesab|دامنه|domain)/u', $t);
            $hasDiskWord = preg_match('/(فضا|گیگابایت|disk|storage|space|ssd|nvme|هارد)/u', $t);
            $bareSize = preg_match('/^\s*\d+(?:\.\d+)?\s*(tb|gb|mb|ترابایت|گیگ|مگ)\b/u', $t);

            if ($diskMb === null && ! $notDisk && ($hasDiskWord || $bareSize)) {
                $diskMb = self::sizeToMb($t);
            }

            // ── پهنای‌باند ──────────────────────────────────────────────────
            if ($bwMb === null && preg_match('/(پهنای|ترافیک|bandwidth|transfer|trafik)/u', $t)) {
                $bwMb = preg_match('/(نامحدود|unlimited|sınırsız|sinirsiz)/u', $t)
                    ? 0                                   // 0 = سقف نگذار
                    : self::sizeToMb($t);
            }
        }

        return ['accounts' => $accounts, 'disk_mb' => $diskMb, 'bw_mb' => $bwMb, 'source' => 'product'];
    }

    /** «۵۰ گیگابایت» → 51200 مگابایت. برنگشتن = الگویی نخورد. */
    private static function sizeToMb(string $t): ?int
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(tb|ترابایت)/u', $t, $m)) {
            return (int) round(((float) $m[1]) * 1024 * 1024);
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(gb|گیگ)/u', $t, $m)) {
            return (int) round(((float) $m[1]) * 1024);
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(mb|مگ)/u', $t, $m)) {
            return (int) round((float) $m[1]);
        }

        return null;
    }

    private static function latinDigits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
