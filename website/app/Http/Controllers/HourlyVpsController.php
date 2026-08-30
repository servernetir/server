<?php

namespace App\Http\Controllers;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\CloudCountry;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * صفحهٔ فرودِ «سرور مجازی ساعتی» — /vps/hourly (fa / en / tr).
 *
 * ═══ چرا این صفحه وجود دارد ═══
 *
 * فروشِ ساعتی از مرداد ۱۴۰۵ یک محصولِ **واقعی و زنده** است (`CloudPlan::hourlyIrt()`،
 * مترِ `cloud:meter`، تیکِ «ساعتی» در فروشگاه)، ولی تا امروز هیچ صفحهٔ عمومی‌ای
 * نداشت: عبارتِ «سرور مجازی ساعتی» حتی یک بار در سایتِ بازاریابی نیامده بود.
 * Search Console همان عبارت را با ۷۵٪ CTR در جایگاهِ ۶ نشان می‌داد — با فقط ۴
 * نمایش در سه ماه، چون صفحه‌ای برای رتبه‌گرفتن نبود. رقبا (server.ir، آروان،
 * کلون، کایا) هر کدام صفحهٔ اختصاصی دارند.
 *
 * ═══ قاعده‌ها ═══
 *
 * ۱) **هیچ عددی این‌جا ساخته نمی‌شود.** نرخِ ساعتی، کفِ اعتبارِ شروع و قیمتِ
 *    ماهانه همه از همان مدلی می‌آیند که فروشگاه و مترِ ساعتی از آن می‌خوانند.
 *    اگر روزی ضریبِ ۷۲۰ یا کفِ ۲۴ ساعت عوض شود، این صفحه خودبه‌خود درست می‌مانَد.
 *
 * ۲) **کاتالوگِ خالی خطا نیست.** روی سرورِ مهاجرت‌نخورده یا پیش از همگام‌سازی،
 *    صفحه با متنِ توضیحی و لینک به فروشگاه بالا می‌آید، نه ۵۰۰.
 *
 * ۳) **سفیدبرچسبی.** هیچ نامِ زیرساختی به ویو نمی‌رسد (ستون‌های provider در
 *    `$hidden` مدل‌اند و این‌جا فقط اعدادِ نمایشی ساخته می‌شود).
 *
 * ۴) لینکِ خرید مستقیم به فروشگاهِ کنسول می‌رود با `billing_mode=hourly` تا
 *    تیکِ ساعتی از پیش خورده باشد — مشتری که از صفحهٔ «ساعتی» آمده نباید دوباره
 *    دنبالِ گزینهٔ ساعتی بگردد.
 */
class HourlyVpsController extends Controller
{
    /** کشورهایی که کارت‌های «نمونه پلن» از آن‌ها پر می‌شود — به ترتیبِ اولویت */
    private const FEATURED_COUNTRIES = ['IR', 'DE', 'FI', 'NL'];

    /** حداکثر پلن در هر کشورِ نمونه */
    private const FEATURED_PER_COUNTRY = 3;

    public function show(): View
    {
        $locale = app()->getLocale();
        $isFa = $locale === 'fa';

        $countries = [];     // iso => ردیفِ جدولِ نرخ‌ها
        $featured = [];      // کارت‌های نمونه
        $fromHourly = null;  // ارزان‌ترین نرخِ ساعتیِ کلِ کاتالوگ (برچسب)
        $fromHourlyRaw = 0;
        $minStart = null;    // کفِ اعتبارِ شروع برای ارزان‌ترین پلن (برچسب)
        $irCities = [];      // شهرهای ایران — برای پاسخِ «سرور ساعتی ایران دارید؟»

        if (Schema::hasTable('cloud_plans') && Schema::hasTable('cloud_locations')) {
            $offers = CloudPlan::offers();
            $locations = CloudLocation::query()
                ->where('is_active', true)
                ->orderBy('sort')
                ->get()
                ->keyBy('code');

            $cheapestAll = null;

            foreach ($offers as $offer) {
                $loc = $locations->get($offer->location_code);

                if ($loc === null) {
                    continue;
                }

                $hourly = $offer->hourlyIrt();

                if ($hourly <= 0) {
                    continue;
                }

                $iso = strtoupper((string) $loc->country);

                if ($iso === '') {
                    continue;
                }

                if ($cheapestAll === null || $hourly < $cheapestAll->hourlyIrt()) {
                    $cheapestAll = $offer;
                }

                if ($iso === 'IR') {
                    $city = $loc->cityLabel();

                    if ($city !== '' && ! in_array($city, $irCities, true)) {
                        $irCities[] = $city;
                    }
                }

                if (! isset($countries[$iso])) {
                    $countries[$iso] = [
                        'iso'        => $iso,
                        'label'      => $loc->countryLabel(),
                        'flag'       => $loc->flagEmoji(),
                        'flag_svg'   => $loc->flagSvg(),
                        'url'        => CloudCountry::url($iso),
                        'plans'      => 0,
                        'hourly_raw' => PHP_INT_MAX,
                        'hourly'     => '',
                        'monthly'    => '',
                    ];
                }

                $countries[$iso]['plans']++;

                if ($hourly < $countries[$iso]['hourly_raw']) {
                    $countries[$iso]['hourly_raw'] = $hourly;
                    $countries[$iso]['hourly'] = $this->hourlyLabel($offer, $locale);
                    $countries[$iso]['monthly'] = $offer->priceLabel($locale);
                }
            }

            if ($cheapestAll !== null) {
                $fromHourly = $this->hourlyLabel($cheapestAll, $locale);
                $fromHourlyRaw = $cheapestAll->hourlyIrt();
                $minStart = $isFa
                    ? fa_num(number_format($cheapestAll->hourlyStartMinIrt())).' تومان'
                    : '€'.number_format($cheapestAll->hourlyEurCents() * CloudPlan::HOURLY_START_MIN_HOURS / 100, 2);
            }

            // ایران اول (کاربرِ فارسی بیشتر همین را می‌خواهد)، بعد ارزان‌ترین‌ها
            uasort($countries, function (array $a, array $b) {
                if ($a['iso'] === 'IR') {
                    return -1;
                }
                if ($b['iso'] === 'IR') {
                    return 1;
                }

                return $a['hourly_raw'] <=> $b['hourly_raw'];
            });

            $featured = $this->featured($offers, $locations, $locale);
        }

        $countries = array_values($countries);

        return view('pages.vps-hourly', [
            'countries'     => $countries,
            'featured'      => $featured,
            'fromHourly'    => $fromHourly,
            'fromHourlyRaw' => $fromHourlyRaw,
            'minStart'      => $minStart,
            'minHours'      => CloudPlan::HOURLY_START_MIN_HOURS,
            'irCities'      => $irCities,
            'storeUrl'      => lroute('account.cloud.store').'?billing_mode=hourly',
            'cloudUrl'      => lroute('cloud.index'),
        ]);
    }

    /**
     * کارت‌های نمونه: از هر کشورِ اولویت‌دار که پلنِ زنده دارد، چند پلنِ ارزان
     * (از کوچک به بزرگ) با نرخِ ساعتی و لینکِ خریدِ ساعتی.
     *
     * @param  \Illuminate\Support\Collection<string, CloudPlan>  $offers
     * @param  \Illuminate\Support\Collection<string, CloudLocation>  $locations
     * @return array<int, array<string, mixed>>
     */
    private function featured($offers, $locations, string $locale): array
    {
        $isFa = $locale === 'fa';
        $byCountry = [];

        foreach ($offers as $offer) {
            $loc = $locations->get($offer->location_code);

            if ($loc === null || $offer->hourlyIrt() <= 0) {
                continue;
            }

            $iso = strtoupper((string) $loc->country);
            $byCountry[$iso][] = [$offer, $loc];
        }

        $out = [];

        foreach (self::FEATURED_COUNTRIES as $iso) {
            if (empty($byCountry[$iso])) {
                continue;
            }

            $rows = $byCountry[$iso];

            // ارزان‌ترین‌ها اول؛ پلنِ ارزان همان چیزی است که خریدارِ ساعتی می‌خواهد
            usort($rows, function (array $a, array $b) {
                return (int) $a[0]->price_irt <=> (int) $b[0]->price_irt;
            });

            $rows = array_slice($rows, 0, self::FEATURED_PER_COUNTRY);

            foreach ($rows as $pair) {
                /** @var CloudPlan $offer */
                [$offer, $loc] = $pair;

                $out[] = [
                    'country'   => $loc->countryLabel(),
                    'flag'      => $loc->flagEmoji(),
                    'flag_svg'  => $loc->flagSvg(),
                    'city'      => $loc->cityLabel() !== '' ? $loc->cityLabel() : $loc->countryLabel(),
                    'name'      => (string) $offer->public_name,
                    'vcpu'      => (int) $offer->vcpu,
                    'ram'       => $offer->ramLabel(),
                    'disk'      => $offer->diskLabel(),
                    'traffic'   => $offer->trafficLabel($locale),
                    'cpu_kind'  => $offer->cpuKindLabel($locale),
                    'hourly'    => $this->hourlyLabel($offer, $locale),
                    // عددِ خامِ نشانه‌گذاری: ریال برای fa (IRR = ریال، نه تومان)، یورو
                    // برای بقیه؛ نبودِ قیمتِ ارزی یعنی null و ویو Offer نمی‌سازد.
                    'ld_price'  => $isFa
                        ? (int) schema_price_irr($offer->hourlyIrt())
                        : ($offer->hourlyEurCents() > 0 ? $offer->hourlyEurCents() / 100 : null),
                    'monthly'   => $offer->priceLabel($locale),
                    'min_start' => $isFa
                        ? fa_num(number_format($offer->hourlyStartMinIrt())).' تومان'
                        : '€'.number_format($offer->hourlyEurCents() * CloudPlan::HOURLY_START_MIN_HOURS / 100, 2),
                    'buy_url'   => lroute('account.cloud.store')
                        .'?location='.urlencode((string) $loc->code)
                        .'&plan='.urlencode((string) $offer->slug)
                        .'&billing_mode=hourly',
                ];
            }
        }

        return $out;
    }

    /** برچسبِ نرخِ ساعتی در ارزِ زبانِ جاری — همان منطقِ `priceLabel()` ولی برای ساعت */
    private function hourlyLabel(CloudPlan $offer, string $locale): string
    {
        if ($locale === 'fa') {
            return fa_num(number_format($offer->hourlyIrt())).' تومان';
        }

        $cents = $offer->hourlyEurCents();

        // زیرِ یک سنت هم باید خوانا باشد: €0.007 نه €0.01 (که گران‌تر نشان می‌دهد)
        return $cents < 100
            ? '€'.rtrim(rtrim(number_format($cents / 100, 3, '.', ''), '0'), '.')
            : '€'.number_format($cents / 100, 2);
    }
}
