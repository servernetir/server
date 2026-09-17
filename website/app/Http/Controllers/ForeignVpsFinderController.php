<?php

namespace App\Http\Controllers;

use App\Models\CloudLocation;
use App\Services\Cloud\CloudCountry;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * «کدام کشور برای سرور مجازی خارج؟» — /vps/compare-locations (fa / en / tr).
 *
 * ═══ چرا صفحهٔ جدا و نه بخشی از /vps/international ═══
 *
 * نیتِ جست‌وجوی «خرید سرور مجازی خارج» مالِ /vps/international است. این صفحه
 * نیتِ **تصمیم** را می‌گیرد («بهترین کشور»، «آلمان یا هلند») که پیش از خرید
 * می‌آید و رقبا با فهرستِ ثابت و عددِ پینگِ ساختگی جوابش را می‌دهند. برتریِ
 * ما: دادهٔ زنده از فروشگاه + پیشنهاددهندهٔ تعاملی که کاربر را روی صفحه نگه
 * می‌دارد و مستقیم به صفحهٔ همان کشور می‌فرستد.
 *
 * ═══ قاعده‌ها ═══
 *
 * ۱) هیچ عددی ساخته نمی‌شود: قیمت/پلن/شهر از `CloudCountry::served()` — همان
 *    منبعی که منو و صفحهٔ کشور می‌خوانند.
 * ۲) کشوری که امروز پلنِ فروختنی ندارد نمایش داده نمی‌شود، حتی اگر در
 *    `config/foreign_vps.php` ردیف دارد (وعدهٔ بی‌پشتوانه).
 * ۳) ایران و کدِ GPU (`XX`) عمداً بیرون‌اند: این صفحه «خارج» است.
 * ۴) کاتالوگِ خالی خطا نیست — صفحه با پیامِ مشاوره بالا می‌آید، نه ۵۰۰.
 */
class ForeignVpsFinderController extends Controller
{
    private const EXCLUDED = ['IR', 'XX'];

    public function show(): View
    {
        $locale = app()->getLocale();
        $cfg = config('foreign_vps.countries', []);
        $served = Schema::hasTable('cloud_plans') && Schema::hasTable('cloud_locations')
            ? CloudCountry::served()
            : [];

        $rows = [];

        foreach ($served as $iso => $s) {
            if (in_array($iso, self::EXCLUDED, true)) {
                continue;
            }

            $probe = (new CloudLocation)->forceFill(['country' => $iso]);
            $meta = $cfg[$iso] ?? null;

            $rows[] = [
                'iso'      => $iso,
                'label'    => $probe->countryLabel($locale),
                'flag'     => $probe->flagEmoji(),
                'flag_svg' => $probe->flagSvg(),
                'url'      => CloudCountry::url($iso),
                'plans'    => (int) $s['plans'],
                'cities'   => $this->cityLabels($iso, $s['cities'], $locale),
                'price'    => $this->priceLabel($s, $locale),
                'price_raw' => (int) $s['cheapest_irt'],
                'region'   => $meta['region'] ?? 'other',
                'uses'     => $meta['uses'] ?? [],
                'why'      => $meta[$locale] ?? ($meta['en'] ?? ''),
            ];
        }

        // ارزان‌ترین اول در جدول؛ رتبه‌ها برای اولویت‌های پیشنهاددهنده
        usort($rows, fn ($a, $b) => [$a['price_raw'] <= 0, $a['price_raw']] <=> [$b['price_raw'] <= 0, $b['price_raw']]);
        $n = count($rows);
        foreach ($rows as $i => &$r) {
            $r['price_rank'] = $i;
        }
        unset($r);
        $byPlans = $rows;
        usort($byPlans, fn ($a, $b) => $b['plans'] <=> $a['plans']);
        $planRank = array_flip(array_column($byPlans, 'iso'));
        foreach ($rows as &$r) {
            $r['plans_rank'] = $planRank[$r['iso']];
        }
        unset($r);

        $uses = [];
        foreach (config('foreign_vps.uses', []) as $key => $u) {
            $top = array_values(array_filter($rows, fn ($r) => ($r['uses'][$key] ?? 0) >= 3));
            $uses[] = [
                'key'  => $key,
                'icon' => $u['icon'],
                't'    => $u[$locale]['t'] ?? $u['en']['t'],
                'd'    => $u[$locale]['d'] ?? $u['en']['d'],
                'top'  => array_map(fn ($r) => ['label' => $r['label'], 'url' => $r['url']], $top),
            ];
        }

        $cheapest = collect($rows)->first(fn ($r) => $r['price_raw'] > 0);

        return view('pages.vps-compare', [
            'rows'      => $rows,
            'count'     => $n,
            'planTotal' => array_sum(array_column($rows, 'plans')),
            'fromPrice' => $cheapest['price'] ?? null,
            'uses'      => $uses,
            'meta'      => config("foreign_vps.meta.$locale", config('foreign_vps.meta.en')),
            'ui'        => config("foreign_vps.ui.$locale", config('foreign_vps.ui.en')),
            'faq'       => config("foreign_vps.faq.$locale", config('foreign_vps.faq.en')),
        ]);
    }

    /** @param array<int,string> $cities نامِ خامِ شهرها از served() */
    private function cityLabels(string $iso, array $cities, string $locale): array
    {
        if ($cities === []) {
            return [];
        }

        return CloudLocation::where('country', $iso)->where('is_active', true)
            ->whereIn('city', $cities)->get()
            ->map(fn (CloudLocation $l) => $l->cityLabel($locale))
            ->filter()->unique()->values()->all();
    }

    private function priceLabel(array $s, string $locale): ?string
    {
        if ($locale !== 'fa' && (int) $s['cheapest_eur_cents'] > 0) {
            return '€'.number_format($s['cheapest_eur_cents'] / 100, 2);
        }

        return (int) $s['cheapest_irt'] > 0 ? cloud_price((int) $s['cheapest_irt']) : null;
    }
}
