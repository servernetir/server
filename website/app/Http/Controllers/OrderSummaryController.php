<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Service;
use App\Support\Funnel;
use App\Support\OrderHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * خلاصهٔ سفارشِ **پیش از ورود** — ساخته در ممیزی ۴، بازطراحی در ممیزی ۶.
 *
 * ═══ چرا این صفحه وجود دارد ═══
 *
 * چهار دور ممیزی یک چیز را تکرار کرد: کاربر برای دیدنِ قیمتِ نهایی مجبور به
 * ورود بود. این صفحه روی **خودِ سایت** است، بی‌نشست: قیمتِ همهٔ دوره‌ها،
 * جمعِ با مالیات، ضمانت — و فقط در گامِ پرداخت به console تحویل می‌دهد.
 *
 * ═══ آنچه ممیزی ۶ عوض کرد ═══
 *
 * · **انتخابِ دوره همین‌جا** (رادیوگروپ، پیش‌فرض سالانه) و لینکِ پرداختِ
 *   **امضاشده به ازای هر دوره** (`OrderHandoff`) — console همان دوره را از
 *   پیش انتخاب می‌کند. SN-ORDER-001 بسته، و اولین کامیتِ متقابلِ دو مرز.
 * · اسکیمای Product/AggregateOffer با هر چهار Offer، **به ارزِ زبانِ صفحه**
 *   (فارسی ریال، en/tr یورو — همان عددی که کاربر می‌بیند؛ شورا/سئو: قیمتِ
 *   اسکیما و قیمتِ صفحه باید یکی باشند وگرنه Rich Result رد می‌شود)،
 *   priceValidUntil میلادی، MerchantReturnPolicy فقط برای پکیجِ قابلِ بازگشت.
 * · ضمانتِ ۱۴روزه فقط روی دسته‌های قابلِ بازگشت (حقوقی).
 * · جملهٔ «۱۰٪ مالیات» فقط وقتی مدیر ثبت‌نامِ ارزش افزوده را در پنل تأیید
 *   کرده باشد (`company_vat_verified`) — وگرنه عبارتِ خنثی. مبلغ در هر دو حالت
 *   همان مبلغِ فاکتور است (نه یک منبعِ حقیقتِ دوم).
 * · هزینهٔ راه‌اندازی (اگر هست) در «پرداختِ اول» جمع می‌شود — UX: کاربر نباید
 *   در console عددی بزرگ‌تر از آنچه این‌جا دید ببیند.
 *
 * ═══ آنچه ممیزی ۷ عوض کرد ═══
 *
 * · «فقط پرچم‌دار در sitemap»ِ ممیزی ۶ برگشت: **همهٔ** صفحاتِ سفارشِ فعال
 *   ایندکس و در sitemap‌اند (معیارِ پذیرش: ۶۴ از ۶۴) و همه عنوان/توضیحِ
 *   تراکنشیِ یکتا می‌گیرند. پرچم‌دارها فقط برای llms.txt مانده‌اند.
 * · CTA به /go/pay می‌رود (متد pay همین کلاس): شمارشِ هر کلیک در اکسس‌لاگ و
 *   Funnel + امضای تازه در لحظهٔ کلیک — قلم ۳ رودمپِ ممیزی ۷.
 *
 * ⚠️ قیمت از همان `Product::priceForCycle()` می‌آید که فاکتورِ واقعی را
 * می‌سازد — و در لینکِ تحویل **هیچ قیمتی** نیست. sid/ref را مرورگر می‌سازد
 * (صفحه کش می‌شود؛ sidِ سمتِ سرور بینِ بازدیدکننده‌ها مشترک می‌شد).
 */
class OrderSummaryController extends Controller
{
    public function show(string $slug): View
    {
        abort_unless(Schema::hasTable('products'), 404);

        $product = Product::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $setup = $product->setup_fee > 0 ? $product->effectiveSetup() : 0;
        $setupTax = (int) round($setup * $product->tax_percent / 100);

        $monthlyGrand = null;
        $rows = [];

        foreach (Service::cycles() as $cycle) {
            $months = Service::monthsIn($cycle);

            if ($months <= 0) {
                continue;
            }

            $total = $product->priceForCycle($cycle);
            $tax = (int) round($total * $product->tax_percent / 100);
            $grand = $total + $tax;

            if ($cycle === 'monthly') {
                $monthlyGrand = $grand;
            }

            $rows[] = [
                'cycle'   => $cycle,
                'label'   => Service::labelFor($cycle),
                'months'  => $months,
                'monthly' => $product->monthlyEquivalent($cycle),
                'saving'  => $product->savingPct($cycle),
                'total'   => $total,
                'grand'   => $grand,
                'first'   => $grand + $setup + $setupTax,
                // ممیزی ۷ (قلم ۳ رودمپ): CTA از /go/pay می‌گذرد، نه لینکِ مستقیمِ
                // console — هر کلیک در اکسس‌لاگ و Funnel شمرده می‌شود و امضا در
                // لحظهٔ کلیک ساخته می‌شود نه در لحظهٔ رندرِ صفحهٔ کش‌شده.
                'href'    => route('go.pay', ['sku' => $product->slug, 'cycle' => $cycle, 'src' => 'order']),
            ];
        }

        if ($rows === []) {
            $total = $product->effectivePrice();
            $tax = (int) round($total * $product->tax_percent / 100);

            $rows[] = [
                'cycle'   => 'once',
                'label'   => Service::labelFor('once'),
                'months'  => 0,
                'monthly' => $total,
                'saving'  => 0,
                'total'   => $total,
                'grand'   => $total + $tax,
                'first'   => $total + $tax + $setup + $setupTax,
                'href'    => route('go.pay', ['sku' => $product->slug, 'cycle' => 'once', 'src' => 'order']),
            ];
        }

        /*
        | «برچسبِ صرفه‌جویی» (UX): نه درصد، بلکه تومانِ واقعی نسبت به پرداختِ
        | ماهانه در همان مدت — «۱٬۸۴۸٬۰۰۰ تومان کمتر از پرداخت ماهانه در یک سال».
        | چشم عددِ بزرگِ سالانه را «گران» می‌خوانَد؛ این برچسب لنگر را درست می‌کند.
        */
        foreach ($rows as &$r) {
            $r['saved'] = ($monthlyGrand !== null && $r['months'] > 1)
                ? max(0, $monthlyGrand * $r['months'] - $r['grand'])
                : 0;
        }
        unset($r);

        // پیش‌فرضِ انتخاب: سالانه اگر هست (بزرگ‌ترین اهرمِ قیمتی)، وگرنه اولی
        $cycles = array_column($rows, 'cycle');
        $default = in_array('yearly', $cycles, true) ? 'yearly' : ($cycles[0] ?? 'monthly');
        $defaultRow = collect($rows)->firstWhere('cycle', $default) ?? $rows[0];

        $lowest = min(array_column($rows, 'grand'));
        $lowestMonthly = min(array_column($rows, 'monthly'));

        // order_summary_view از مرورگر ثبت می‌شود (صفحه کش می‌شود و HIT به این‌جا نمی‌رسد)

        return view('pages.order-summary', [
            'product'     => $product,
            'rows'        => $rows,
            'default'     => $default,
            'defaultRow'  => $defaultRow,
            'setup'       => $setup,
            'vatVerified' => $this->vatVerified(),
            'schema'      => $this->schema($product, $rows),
            /*
            | ممیزی ۷ (قلم ۲ رودمپ) تصمیمِ «فقط پرچم‌دارها ایندکس» ممیزی ۶ را
            | برگرداند: معیارِ پذیرش «۶۴ از ۶۴ در sitemap» است — تنها قلمی که
            | مستقیم روی درآمد اثر دارد. عنوان/توضیحِ هر SKU تراکنشی و یکتاست
            | (نام + قیمت)، پس شرطِ new_page_gate (عنوانِ یکتا) هم برقرار است.
            */
            'metaTitle'   => __('ui.os_meta_title', ['name' => $product->name, 'price' => cloud_price($lowestMonthly)]),
            'metaDesc'    => __('ui.os_meta_desc', ['name' => $product->name, 'price' => cloud_price($lowest)]),
        ]);
    }

    /**
     * GET /go/pay — گذرگاهِ شمارش‌پذیرِ «سفارش ← پرداخت» (ممیزی ۷، قلم ۳).
     *
     * ۳۰۲ به همان روتِ سفارشِ console، با امضای HMACِ تازه (OrderHandoff).
     * مقصد ثابت است و فقط sku/cycle واردش می‌شوند — open redirect ممکن نیست.
     * هر کلیک با Funnel::log شمرده می‌شود؛ شمارشِ روزانه:
     * `php artisan tinker` یا `grep '"event":"pay_redirect"' storage/app/funnel/*.jsonl | wc -l`
     * و مستقل از آن، اکسس‌لاگِ وب‌سرور روی مسیرِ /go/pay.
     */
    public function pay(Request $request): RedirectResponse
    {
        $sku = strtolower((string) $request->query('sku', ''));

        abort_unless(preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $sku) === 1, 404);

        $cycles = array_keys((array) config('billing.cycles', []));
        $cycle = (string) $request->query('cycle', '');

        if (! in_array($cycle, array_merge($cycles, ['once']), true)) {
            $cycle = (string) config('billing.default_cycle', 'monthly');
        }

        $sid = OrderHandoff::clean($request->query('sid'), OrderHandoff::SID_RE);
        $ref = OrderHandoff::clean($request->query('ref'), OrderHandoff::REF_RE);
        $src = OrderHandoff::clean($request->query('src'), OrderHandoff::REF_RE);

        Funnel::log('pay_redirect', [
            'sku'   => $sku,
            'cycle' => $cycle,
            'sid'   => $sid,
            'ref'   => $ref,
            'src'   => $src,
            'lang'  => app()->getLocale(),
        ]);

        $url = OrderHandoff::url($sku, $cycle);
        $extra = array_filter(['sid' => $sid, 'ref' => $ref]);

        if ($extra !== []) {
            $url .= '&'.http_build_query($extra);
        }

        return redirect()->away($url, 302, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag'  => 'noindex',
        ]);
    }

    /**
     * «۱۰٪ مالیات لحاظ شده» یعنی اعلامِ مؤدیِ ثبت‌شده — حقوقی (ممیزی ۶): تا
     * گواهیِ ارزش افزوده تأیید نشده، این جمله روی صفحه نیاید. مدیر پس از تأیید
     * حسابدارِ رسمی، کلیدِ `company_vat_verified` را در پنل پر می‌کند.
     */
    private function vatVerified(): bool
    {
        try {
            return filled(\App\Models\Setting::get('company_vat_verified', ''));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Product + AggregateOffer (ممیزی ۶ — سئو): صفحه‌ای که چهار قیمت و تخفیف و
     * مالیات دارد باید همه را ماشین‌خوان اعلام کند.
     *
     * ارز = ارزِ نمایشِ همان زبان (شورا/سئو): فارسی ریال (IRR، واحدِ ISO؛ تومان×۱۰)،
     * en/tr یورو با همان نرخی که `cloud_price` نشان می‌دهد. اگر نرخِ یورو در
     * دسترس نبود، صفحهٔ en/tr عددِ بی‌واحد نشان می‌دهد و اسکیما **بدونِ offers**
     * می‌رود — Offerِ بی‌ارزِ درست بدتر از نبودنش است.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function schema(Product $product, array $rows): array
    {
        $url = lroute('order.summary', $product->slug);
        $validUntil = date('Y-m-d', strtotime('+30 days'));

        $data = [
            'name'  => $product->name,
            'sku'   => $product->slug,
            'brand' => ['@type' => 'Brand', 'name' => 'ServerNet'],
            'url'   => $url,
        ];

        $fa = app()->getLocale() === 'fa';
        $rate = $fa ? 0 : cloud_eur_rate();

        if (! $fa && $rate <= 0) {
            return $data;
        }

        $currency = $fa ? 'IRR' : 'EUR';
        $price = fn (int $toman): string => $fa ? schema_price_irr($toman) : number_format($toman / $rate, 2, '.', '');

        $offers = [];
        $prices = [];

        foreach ($rows as $r) {
            $prices[] = (int) $r['grand'];

            $offer = [
                '@type'           => 'Offer',
                'name'            => $r['label'],
                'price'           => $price((int) $r['grand']),
                'priceCurrency'   => $currency,
                'availability'    => 'https://schema.org/InStock',
                // لنگرِ همان رادیو روی همین صفحه — نه ?cycle= که رشتهٔ کوئری است و
                // PageCache آن را BYPASS می‌کرد (و صفحهٔ «دوم»ی برای گوگل می‌ساخت)
                'url'             => $url.'#cy-'.$r['cycle'],
                'priceValidUntil' => $validUntil,
            ];

            if ($r['months'] > 0) {
                $spec = [
                    '@type'           => 'UnitPriceSpecification',
                    'price'           => $price((int) $r['grand']),
                    'priceCurrency'   => $currency,
                    'billingDuration' => $r['months'],
                    'unitCode'        => 'MON',
                ];

                // فقط وقتی مؤدیِ تأییدشده‌ایم ادعای «شاملِ VAT» ماشین‌خوان می‌شود (حقوقی)
                if ($product->tax_percent > 0 && $this->vatVerified()) {
                    $spec['valueAddedTaxIncluded'] = true;
                }

                $offer['priceSpecification'] = $spec;
            }

            $offers[] = $offer;
        }

        $data['offers'] = [
            '@type'           => 'AggregateOffer',
            'priceCurrency'   => $currency,
            'lowPrice'        => $price(min($prices)),
            'highPrice'       => $price(max($prices)),
            'offerCount'      => count($offers),
            'priceValidUntil' => $validUntil,
            'offers'          => $offers,
        ];

        if ($product->isRefundable()) {
            $data['hasMerchantReturnPolicy'] = [
                '@type'                => 'MerchantReturnPolicy',
                'applicableCountry'    => 'IR',
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                'merchantReturnDays'   => 14,
            ];
        }

        return $data;
    }
}
