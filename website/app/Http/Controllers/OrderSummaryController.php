<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Service;
use App\Support\OrderHandoff;
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
 * · فقط SKUهای پرچم‌دار ایندکس/در sitemap؛ بقیه noindex,follow. پرچم‌دارها
 *   عنوان/توضیحِ تراکنشی می‌گیرند (نام + کمترین قیمت) نه «خلاصهٔ سفارش».
 * · هزینهٔ راه‌اندازی (اگر هست) در «پرداختِ اول» جمع می‌شود — UX: کاربر نباید
 *   در console عددی بزرگ‌تر از آنچه این‌جا دید ببیند.
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
                'href'    => OrderHandoff::url($product->slug, $cycle),
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
                'href'    => OrderHandoff::url($product->slug, 'once'),
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

        $flagship = in_array($product->slug, Product::flagshipSlugs(), true);

        $lowest = min(array_column($rows, 'grand'));
        $lowestMonthly = min(array_column($rows, 'monthly'));

        // order_summary_view از مرورگر ثبت می‌شود (صفحه کش می‌شود و HIT به این‌جا نمی‌رسد)

        return view('pages.order-summary', [
            'product'     => $product,
            'rows'        => $rows,
            'default'     => $default,
            'defaultRow'  => $defaultRow,
            'setup'       => $setup,
            'flagship'    => $flagship,
            'vatVerified' => $this->vatVerified(),
            'schema'      => $this->schema($product, $rows),
            /*
            | عنوانِ تراکنشی برای پرچم‌دارها (سئو): «نام — از ماهی X» به‌جای
            | «خلاصهٔ سفارش — نام». بقیه noindexاند و عنوانِ عمومی کافی است.
            */
            'metaTitle'   => $flagship
                ? __('ui.os_meta_title', ['name' => $product->name, 'price' => cloud_price($lowestMonthly)])
                : __('ui.os_badge').' — '.$product->name,
            'metaDesc'    => $flagship
                ? __('ui.os_meta_desc', ['name' => $product->name, 'price' => cloud_price($lowest)])
                : __('ui.os_sub'),
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
