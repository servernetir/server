<?php

namespace App\Services\Cloud;

use Illuminate\Support\Collection;

/**
 * حذفِ پلن‌هایی که **هیچ‌کس نباید بخرد**.
 *
 * ═══ مسئله ═══
 *
 * کاتالوگ از چند زیرساخت پر می‌شود و هرکدام نردبانِ قیمتیِ خودش را دارد. وقتی
 * روی هم می‌ریزند، ترکیب‌های بی‌معنا می‌سازند. نمونهٔ واقعی از صفحهٔ آلمان:
 *
 *     ۲ هسته · ۲ گیگ رم  →  ۱٬۳۷۰٬۰۰۰ تومان
 *     ۱ هسته · ۲ گیگ رم  →  ۲٬۷۴۰٬۰۰۰ تومان   ← نصفِ پردازنده، دو برابرِ قیمت
 *
 * ردیفِ دوم برای هیچ مشتری‌ای انتخابِ درستی نیست. ولی روی صفحه بود، و هر
 * بازدیدکننده‌ای که آن را می‌دید یک قضاوت می‌کرد: «قیمت‌های این‌جا حساب‌وکتاب
 * ندارد». یعنی یک ردیفِ بی‌فروش، به کلِ کاتالوگ آسیب می‌زد.
 *
 * ═══ قاعده ═══
 *
 * پلنِ «ب» حذف می‌شود اگر پلنِ «الف»ای باشد که در **هر بُعدی** دستِ‌کم به‌اندازهٔ
 * آن خوب باشد، قیمتش بیشتر نباشد، و دستِ‌کم در یک چیز بهتر باشد. این همان
 * تسلطِ پارتو است و نتیجهٔ عملی‌اش این است که مشتری هرچه ببیند، بهترینِ آن
 * قیمت است — و ما در برابر رقبا سبدِ تمیزتر و ارزان‌تری نشان می‌دهیم.
 *
 * ═══ چه چیزی عمداً مقایسه **نمی‌شود** ═══
 *
 * ⚠️ **معماریِ پردازنده (ARM/x86)**: نرم‌افزارِ مشتری ممکن است روی ARM اصلاً
 * اجرا نشود. یک پلنِ ARMِ ارزان‌ترِ قوی‌تر، جایگزینِ x86 نیست — پس این دو هرگز
 * همدیگر را حذف نمی‌کنند.
 *
 * ⚠️ **نوعِ پردازنده (اشتراکی/اختصاصی)**: در دو جدولِ جدا نمایش داده می‌شوند و
 * مشتری آگاهانه بینشان انتخاب می‌کند. اگر اجازه می‌دادیم اختصاصی‌ها
 * اشتراکی‌ها را حذف کنند، جدولِ اول می‌توانست خالی شود.
 *
 * ⚠️ **کشور**: مقایسه فقط درونِ صفحهٔ یک کشور معنا دارد. سرورِ آلمان جایگزینِ
 * سرورِ ایران نیست، حتی اگر ارزان‌تر و قوی‌تر باشد.
 */
class CloudDominance
{
    /** دیسکِ بهتر = عددِ بزرگ‌تر. ناشناس پایین‌ترین رتبه را می‌گیرد. */
    private const DISK_RANK = ['nvme' => 3, 'ssd' => 2, 'hdd' => 1];

    /**
     * پلن‌های مغلوب را بیرون می‌گذارد.
     *
     * @param  Collection<int|string, \App\Models\CloudPlan>  $plans
     * @return Collection<int, \App\Models\CloudPlan>
     */
    public static function prune(Collection $plans): Collection
    {
        $list = $plans->values();
        $selection = new CloudOfferSelection;

        if ($list->count() < 2) {
            return $list;
        }

        // These immutable display/resource facts are local to this prune call.
        // Eloquent would otherwise cast them again for every candidate pair.
        // Money, eligibility and live contract checks are deliberately absent.
        $facts = [];
        foreach ($list as $plan) {
            if ($plan instanceof \App\Models\CloudPlan) {
                $facts[spl_object_id($plan)] ??= self::comparisonFacts($plan);
            }
        }
        // Every field in the bucket is a necessary equality condition in the
        // unchanged contract predicate. Canonical city aliases stay together.
        $bucket = static fn ($plan) => $plan instanceof \App\Models\CloudPlan
            ? $facts[spl_object_id($plan)]['bucket'] : '';
        $candidates = $list->groupBy($bucket);

        return $list
            ->reject(fn ($b) => $candidates->get($bucket($b))->contains(fn ($a) => self::compare(
                $a, $b, $selection,
                $a instanceof \App\Models\CloudPlan ? $facts[spl_object_id($a)] : null,
                $b instanceof \App\Models\CloudPlan ? $facts[spl_object_id($b)] : null,
            )))
            ->values();
    }

    /**
     * آیا «الف» بر «ب» غالب است؟
     *
     * ⚠️ ترتیبِ شرط‌ها مهم است: اول ناهم‌سنخی‌ها رد می‌شوند (معماری، نوعِ
     * پردازنده)، بعد ابعادِ کیفی، و آخر قیمت. اگر قیمت را اول می‌سنجیدیم،
     * پلنِ ارزان‌ترِ ARM می‌توانست x86 را حذف کند.
     */
    public static function dominates(mixed $a, mixed $b): bool
    {
        return self::compare($a, $b, new CloudOfferSelection);
    }

    private static function compare(mixed $a, mixed $b, CloudOfferSelection $selection, ?array $aFacts = null, ?array $bFacts = null): bool
    {
        if ($a === $b || ! $a instanceof \App\Models\CloudPlan || ! $b instanceof \App\Models\CloudPlan) {
            return false;
        }

        // Resource counts alone cannot make another city, licence, billing
        // contract or access feature interchangeable. Never prune that value.
        if (! $a->is_active || ! $a->in_stock || $a->admin_disabled) { return false; }
        $aFacts ??= self::comparisonFacts($a);
        $bFacts ??= self::comparisonFacts($b);

        // ناهم‌سنخ: هیچ‌کدام جایگزینِ دیگری نیست
        if ($aFacts['arch'] !== $bFacts['arch']) {
            return false;
        }

        if ($aFacts['cpu'] !== $bFacts['cpu']) {
            return false;
        }
        // Hourly traffic allowances and fixed-bandwidth contracts are not
        // interchangeable, even when CPU, RAM and total storage match.
        if ($aFacts['network'] != $bFacts['network']) {
            return false;
        }

        /*
        | کارتِ گرافیک: RTX 4090 و GTX 1650 دو محصولِ متفاوت‌اند حتی با هسته/
        | رم/دیسکِ یکسان — قدرتِ واقعیِ این خط در خودِ GPU است که این کلاس
        | نمی‌سنجد. بی‌این گارد، ورودِ یک GPUِ ارزان همهٔ GPUهای گران‌ترِ
        | هم‌مشخصات را بی‌صدا از فروشگاه حذف می‌کرد (۵ شهریور ۱۴۰۵: RTX 4090
        | با آمدنِ GTX 1650ِ هم‌مشخصات و ارزان‌تر از ویترین غیب شد).
        */
        if ($aFacts['gpu'] !== $bFacts['gpu'] || $aFacts['gpu_count'] !== $bFacts['gpu_count']) {
            return false;
        }

        $pa = (int) $a->price_irt;
        $pb = (int) $b->price_irt;

        // قیمتِ نامعلوم (صفر) یعنی پلن اصلاً فروختنی نیست؛ نه غالب می‌شود نه مغلوب
        if ($pa <= 0 || $pb <= 0) {
            return false;
        }

        if ($pa > $pb) {
            return false;
        }

        $betterResources = false;
        foreach ($aFacts['dims'] as $dimension => $av) {
            $bv = $bFacts['dims'][$dimension];
            if ($av < $bv) {
                return false;                    // «الف» در این بُعد بدتر است
            }
            $betterResources = $betterResources || $av > $bv;
        }

        // A candidate with fewer resources or a dearer monthly price cannot
        // dominate regardless of its OS/billing contract. Resolve those
        // expensive contracts only for candidates that pass the cheap checks.
        if (! $selection->sameContract($a, $b) || ! $selection->pricesNoWorse($a, $b)) {
            return false;
        }

        // تا این‌جا «الف» در هیچ بُعدی بدتر نیست و گران‌تر هم نیست.
        // برای حذف، باید دستِ‌کم در یک چیز **واقعاً** بهتر باشد — وگرنه دو پلنِ
        // کاملاً یکسان در دو شهر همدیگر را حذف می‌کنند و هر دو غیب می‌شوند.
        if ($selection->priceDominates($a, $b)) {
            return true;
        }

        return $betterResources;
    }

    private static function comparisonFacts(\App\Models\CloudPlan $plan): array
    {
        $city = \App\Models\CloudLocation::canonicalCode((string) $plan->location_code);
        $arch = (string) $plan->arch;
        $cpu = (string) $plan->cpu_kind;
        $gpu = strtolower(trim((string) $plan->gpu_model));
        $gpuCount = (int) $plan->gpu_count;

        return [
            'bucket' => serialize([$city, $arch, $cpu, $gpu, $gpuCount]),
            'arch' => $arch, 'cpu' => $cpu, 'gpu' => $gpu, 'gpu_count' => $gpuCount,
            'network' => $plan->network_terms ?? null,
            'dims' => [(int) $plan->vcpu, (int) $plan->ram_mb, (int) $plan->disk_gb,
                (int) $plan->traffic_gb, self::diskRank($plan)],
        ];
    }

    private static function diskRank(mixed $plan): int
    {
        return self::DISK_RANK[strtolower((string) $plan->disk_type)] ?? 0;
    }
}
