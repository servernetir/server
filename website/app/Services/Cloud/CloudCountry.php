<?php

namespace App\Services\Cloud;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * پلِ بینِ «کشور» و کاتالوگِ زندهٔ سرورِ ابری.
 *
 * ═══ چرا این کلاس لازم شد ═══
 *
 * منو با ۶ لوکیشن شهری شلوغ شده بود و با ۱۵ لوکیشن ناخوانا می‌شد. تصمیمِ
 * کارفرما: منو در سطحِ **کشور** باشد و شهرها و تفکیکِ اشتراکی/اختصاصی و
 * قیمت‌ها **داخلِ صفحهٔ همان کشور**.
 *
 * ⚠️ و یک تصمیمِ سئویی که مهم‌تر از ظاهر است: صفحاتِ کشوری از قبل وجود دارند
 * (`/vps/germany` و …) و ممکن است ایندکس شده باشند. پس **صفحهٔ تازه نمی‌سازیم**؛
 * همان URLها را زنده می‌کنیم. ساختنِ `/cloud/germany` کنارِ `/vps/germany` یعنی
 * دو صفحه برای یک نیتِ جست‌وجو، و نتیجه‌اش رقابتِ خودمان با خودمان و افتِ
 * رتبهٔ هر دو (cannibalization).
 *
 * این کلاس فقط **می‌داند و می‌شمارد**؛ هیچ HTMLای نمی‌سازد و هیچ نامِ زیرساختی
 * بیرون نمی‌دهد.
 */
class CloudCountry
{
    private const TTL = 600;

    /**
     * نگاشتِ کدِ کشور ← اسلاگِ صفحهٔ بازاریابیِ موجود در `config/catalog/vps.php`.
     *
     * فقط کشورهایی که **واقعاً صفحه دارند** این‌جا هستند. کشوری که صفحه ندارد
     * عمداً نیست تا `marketingSlug()` بتواند صریح بگوید «صفحه ندارد» و به
     * صفحهٔ مکان برگردد — نه اینکه به آدرسِ ۴۰۴ لینک بدهیم.
     */
    public const MARKETING_SLUG = [
        'IR' => 'iran',
        'DE' => 'germany',
        'FI' => 'finland',
        'FR' => 'france',
        'US' => 'usa',
        // کشورهایی که پلنِ زنده داشتیم ولی صفحهٔ فروش نداشتیم — حالا هرکدام یک
        // صفحهٔ سه‌زبانه در config/catalog/vps.php دارند تا جستجوی «سرور مجازی
        // + نامِ کشور» به ما برسد، نه به رقیب.
        'RU' => 'russia',
        'SG' => 'singapore',
        'SE' => 'sweden',
        'NL' => 'netherlands',
        'AT' => 'austria',
        'GB' => 'england',
    ];

    /** اسلاگِ صفحهٔ بازاریابیِ این کشور، یا null اگر صفحه‌ای ندارد */
    public static function marketingSlug(string $iso): ?string
    {
        return self::MARKETING_SLUG[strtoupper(trim($iso))] ?? null;
    }

    /** کدِ کشورِ یک اسلاگِ بازاریابی */
    public static function isoForSlug(string $slug): ?string
    {
        $iso = array_search(strtolower(trim($slug)), self::MARKETING_SLUG, true);

        return $iso === false ? null : $iso;
    }

    /**
     * کشورهایی که **همین حالا** پلنِ قابلِ فروش دارند، با خلاصه‌شان.
     *
     * @return array<string, array{
     *   iso:string, plans:int, locations:int, cheapest_irt:int,
     *   cheapest_eur_cents:int, cities:array<int,string>
     * }>  کلید = کدِ کشور
     */
    public static function served(): array
    {
        return Cache::remember('cloud.countries.served', self::TTL, function () {
            if (! Schema::hasTable('cloud_locations') || ! Schema::hasTable('cloud_plans')) {
                return [];
            }

            // عرضه‌ها و نه ردیف‌های خام: مشتری همان‌ها را می‌بیند و شمارشِ
            // «۲۴ پلن» باید با آنچه در صفحه است بخواند، وگرنه عددِ منو دروغ است.
            $offers = CloudPlan::offers();

            if ($offers->isEmpty()) {
                return [];
            }

            $byCode = CloudLocation::whereIn('code', $offers->pluck('location_code')->unique())
                ->where('is_active', true)
                ->get()
                ->keyBy('code');

            $out = [];

            foreach ($offers as $offer) {
                $loc = $byCode[$offer->location_code] ?? null;

                if ($loc === null) {
                    continue;                    // مکانِ غیرفعال — در صفحه هم نیست
                }

                $iso = strtoupper((string) $loc->country);

                if ($iso === '') {
                    continue;
                }

                $row = $out[$iso] ?? [
                    'iso' => $iso, 'plans' => 0, 'locations' => 0,
                    'cheapest_irt' => 0, 'cheapest_eur_cents' => 0, 'cities' => [],
                ];

                $row['plans']++;

                $city = trim((string) $loc->city);

                if ($city !== '' && ! in_array($city, $row['cities'], true)) {
                    $row['cities'][] = $city;
                    $row['locations']++;
                }

                foreach ([['cheapest_irt', 'price_irt'], ['cheapest_eur_cents', 'price_eur_cents']] as [$k, $col]) {
                    $v = (int) $offer->{$col};

                    if ($v > 0 && ($row[$k] === 0 || $v < $row[$k])) {
                        $row[$k] = $v;
                    }
                }

                $out[$iso] = $row;
            }

            // پرشمارترین کشور اول — همان چیزی که مشتری بیشتر می‌خواهد
            uasort($out, fn ($a, $b) => $b['plans'] <=> $a['plans']);

            return $out;
        });
    }

    /** خلاصهٔ یک کشور، یا null اگر پلنِ قابلِ فروشی ندارد */
    public static function summary(string $iso): ?array
    {
        return self::served()[strtoupper(trim($iso))] ?? null;
    }

    /**
     * عرضه‌های یک کشور، گروه‌شده بر اساسِ **مکان** و بعد نوعِ پردازنده.
     *
     * ساختارِ خروجی همان چیزی است که صفحهٔ کشور می‌خواهد نشان دهد:
     * شهر → اشتراکی/اختصاصی → فهرستِ پلن‌ها (از کوچک به بزرگ).
     *
     * @return array<int, array{
     *   location: CloudLocation,
     *   shared: \Illuminate\Support\Collection,
     *   dedicated: \Illuminate\Support\Collection
     * }>
     */
    public static function offersFor(string $iso): array
    {
        $iso = strtoupper(trim($iso));

        if (! Schema::hasTable('cloud_plans')) {
            return [];
        }

        $locations = CloudLocation::where('country', $iso)->where('is_active', true)
            ->orderBy('sort')->orderBy('city')->get();

        if ($locations->isEmpty()) {
            return [];
        }

        $offers = CloudPlan::offers()->groupBy('location_code');
        $out = [];

        foreach ($locations as $loc) {
            $rows = $offers[$loc->code] ?? collect();

            if ($rows->isEmpty()) {
                continue;                        // مکانی که چیزی برای فروش ندارد
            }

            $sorted = $rows->sortBy([['vcpu', 'asc'], ['ram_mb', 'asc'], ['disk_gb', 'asc']]);

            $out[] = [
                'location'  => $loc,
                'shared'    => $sorted->where('cpu_kind', 'shared')->values(),
                'dedicated' => $sorted->where('cpu_kind', 'dedicated')->values(),
            ];
        }

        return $out;
    }

    /**
     * آدرسِ صفحهٔ این کشور.
     *
     * اولویت با صفحهٔ بازاریابیِ **موجود** است (سئو دارد). اگر کشور صفحه‌ای
     * ندارد، به صفحهٔ اولین مکانش می‌رود — بهتر از لینکِ ۴۰۴، و در
     * `withoutMarketingPage()` هم گزارش می‌شود تا صفحه‌اش ساخته شود.
     */
    public static function url(string $iso): string
    {
        $slug = self::marketingSlug($iso);

        if ($slug !== null) {
            return lroute('catalog', ['category' => 'vps', 'slug' => $slug]);
        }

        // کدهای legacy (گروهِ محصول به‌جای شهر) صفحهٔ مکان ندارند — ۳۰۱اند
        // (ممیزی ۷)؛ مقصدِ بازگشتی باید یک صفحهٔ واقعی باشد وگرنه حلقهٔ
        // «۳۰۱ به ۳۰۱ به خودش» ساخته می‌شد.
        $first = CloudLocation::where('country', strtoupper(trim($iso)))
            ->where('is_active', true)->orderBy('sort')->orderBy('code')->pluck('code')
            ->first(fn ($code) => ! CloudLocation::isLegacyCode((string) $code));

        return $first
            ? lroute('cloud.location', ['location' => $first])
            : lroute('cloud.index');
    }

    /**
     * کشورهایی که می‌فروشیم ولی صفحهٔ بازاریابی ندارند.
     *
     * ⚠️ این یک شکافِ **سئویی و درآمدی** است، نه نکتهٔ زیبایی‌شناسی: «سرور مجازی
     * سنگاپور» کلیدواژه‌ای است که ترافیک دارد و ما محصولش را داریم ولی صفحه‌ای
     * برای رتبه‌گرفتن نداریم. پنلِ مدیریت نشانش می‌دهد تا ساخته شود.
     *
     * @return array<int, string> کدِ کشورها
     */
    public static function withoutMarketingPage(): array
    {
        return array_values(array_filter(
            array_keys(self::served()),
            fn (string $iso) => self::marketingSlug($iso) === null
        ));
    }

    public static function forget(): void
    {
        Cache::forget('cloud.countries.served');
    }
}
