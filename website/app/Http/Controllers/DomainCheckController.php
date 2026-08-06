<?php

namespace App\Http\Controllers;

use App\Services\Domain\DomainSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * استعلامِ آنیِ دامنه — جعبهٔ جستجوی **صفحهٔ اول**.
 *
 * ═══ چه چیزی عوض شد و چرا ═══
 *
 * 🔴 این کنترلر تا امروز روی **WHMCSِ بیرونی** بود: قیمت را از `GetTLDPricing`
 * می‌گرفت و دکمهٔ خرید را به `cart.php` سبدِ WHMCS می‌فرستاد. یعنی پرکاربردترین
 * ورودیِ فروشِ دامنه — جعبهٔ وسطِ صفحهٔ اول — مشتری را از سامانهٔ خودمان بیرون
 * می‌بُرد، با قیمتی که با قیمتِ صفحهٔ `/domains` **نمی‌خواند** (آن یکی از
 * رسیلری می‌آید و حاشیهٔ سودِ تنظیماتِ ما را می‌خورد).
 *
 * دو قیمتِ متفاوت برای یک دامنه در یک سایت بدترین حالت است: یا مشتری بی‌اعتماد
 * می‌شود، یا ما به قیمتِ کمترِ نمایش‌داده‌شده متعهد می‌شویم.
 *
 * حالا همان `DomainSearch`ای را صدا می‌زند که صفحهٔ `/domains` می‌زند — یک منبعِ
 * قیمت، یک منبعِ موجودی، و خریدی که در کنسولِ خودمان تمام می‌شود.
 *
 * ⚠️ شکلِ JSON عمداً **دست‌نخورده** ماند (`result` / `suggestions` / `more_url`)
 * چون `public/assets/js/site.js` همین را می‌خوانَد. عوض‌کردنش یعنی یک خرابیِ
 * جاوااسکریپتیِ بی‌صدا روی صفحهٔ اول — همان الگوی «کدِ ۲۰۰ ولی صفحهٔ مرده» که
 * این پروژه بارها خورده.
 */
class DomainCheckController extends Controller
{
    private const MAX_SUGGESTIONS = 3;

    /** برای پیشنهاد فقط چند پسوندِ پرتقاضا — نه هر ۶۴ تا */
    private const SUGGEST = ['com', 'net', 'org', 'io', 'co', 'dev', 'shop', 'online'];

    public function __invoke(Request $request, DomainSearch $search): JsonResponse
    {
        $data = $request->validate(['domain' => 'required|string|max:100']);

        [$name, $ext] = $this->split($this->normalise($data['domain']));

        if ($name === '') {
            return response()->json(['message' => 'invalid'], 422);
        }

        /*
        | ⚠️ فهرستِ پسوند **صریح** داده می‌شود.
        |
        | بی‌آن، `DomainSearch` هر ۶۴ پسوندِ پیشنهادی‌اش را استعلام می‌گیرد. روی
        | صفحهٔ `/domains` این درست است (آن‌جا دسته‌دسته و با lazy-loading می‌آید)،
        | ولی این‌جا یک درخواستِ همزمانِ کاربر است: ۶۴ ردیف یعنی چند ثانیه انتظار
        | و ترافیکِ بی‌مورد به رجیستراری که حسابش قبلاً علامت خورده.
        */
        $primaryTld = $ext !== '' ? $ext : 'com';
        $tlds = array_values(array_unique(array_merge([$primaryTld], self::SUGGEST)));

        $rows = $search->search($name, $tlds);

        $byDomain = [];
        foreach ($rows as $r) {
            $byDomain[strtolower((string) ($r['domain'] ?? ''))] = $r;
        }

        $fqdn = $name.'.'.$primaryTld;
        $primary = $byDomain[$fqdn] ?? null;

        /*
        | اگر رسیلری جواب ندهد، `available` همان `false` می‌مانَد.
        |
        | ⚠️ عمداً به DNS برنمی‌گردیم. پاسخِ DNS «رکورد ندارد» را با «ثبت‌نشده»
        | اشتباه می‌گیرد و به مشتری می‌گوید دامنه آزاد است؛ بعد سرِ پرداخت
        | رجیسترار ردش می‌کند. «نمی‌دانم» صادقانه‌تر از حدسِ اشتباه است.
        */
        $result = [
            'domain'    => $fqdn,
            'available' => (bool) ($primary['available'] ?? false),
            'price'     => $this->priceLabel($primary),
            'cart_url'  => $this->buyUrl($fqdn),
        ];

        $suggestions = [];

        if (! $result['available']) {
            foreach ($rows as $r) {
                if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                    break;
                }

                if (! ($r['available'] ?? false) || ($r['domain'] ?? '') === $fqdn) {
                    continue;
                }

                // ⚠️ پرمیوم در پیشنهادِ سه‌تاییِ صفحهٔ اول نمی‌آید: قیمتش ده‌ها
                //    برابر است و کنارِ قیمت‌های عادی گمراه‌کننده می‌شود.
                if ($r['is_premium'] ?? false) {
                    continue;
                }

                $suggestions[] = [
                    'domain'    => $r['domain'],
                    'available' => true,
                    'price'     => $this->priceLabel($r),
                    'cart_url'  => $this->buyUrl((string) $r['domain']),
                ];
            }
        }

        return response()->json([
            'result'      => $result,
            'suggestions' => $suggestions,
            // «…» → صفحهٔ کاملِ جستجوی خودمان، نه دامنه‌چکرِ WHMCS
            'more_url'    => $this->buyUrl($name),
        ]);
    }

    // ───────────────────────── کمکی ─────────────────────────

    private function normalise(string $raw): string
    {
        $q = strtolower(trim($raw));
        $q = preg_replace('~^https?://~', '', $q) ?? '';
        $q = preg_replace('~^www\.~', '', $q) ?? '';

        return trim($q, "./ \t");
    }

    /**
     * «example.com» → ['example', 'com'].
     *
     * ⚠️ حروفِ فارسی/عربی نگه داشته می‌شوند (دامنهٔ IDN)، ولی همه‌چیزِ دیگر
     * پاک می‌شود تا ورودیِ کاربر مستقیم به رجیسترار نرود.
     */
    private function split(string $q): array
    {
        $q = preg_replace('/[^a-z0-9.\x{0600}-\x{06FF}-]/u', '', $q) ?? '';
        $q = trim($q, '.');

        if (! str_contains($q, '.')) {
            return [$q, ''];
        }

        [$name, $ext] = explode('.', $q, 2);

        return [$name, $ext];
    }

    /**
     * برچسبِ قیمت به ارزِ زبانِ جاری.
     *
     * ⚠️ `cloud_price()` و **نه** `site_price()`. دومی آرایهٔ `['irt','eur']`
     * می‌گیرد و مقدارِ تومانی را در `price_toman()` — یعنی ضریبِ قیمتِ **هاست** —
     * ضرب می‌کند. قیمتِ دامنه از قبل حاشیهٔ سودِ خودش (`domain_margin_pct`) را
     * خورده، پس آن ضرب یعنی قیمتی که با صفحهٔ `/domains` نمی‌خوانَد — دقیقاً
     * همان دوگانگی‌ای که این بازنویسی برای رفعش انجام شد.
     *
     * ⚠️ `null` یعنی «قیمت نداریم» و جاوااسکریپت حذفش می‌کند. عددِ صفر یا
     * «تماس بگیرید» ننویس — مشتری روی قیمتِ نمایش‌داده‌شده حساب باز می‌کند.
     */
    private function priceLabel(?array $row): ?string
    {
        $toman = (int) ($row['price_toman'] ?? 0);

        return $toman > 0 ? cloud_price($toman) : null;
    }

    /** خرید در کنسولِ خودمان تمام می‌شود، نه سبدِ WHMCS */
    private function buyUrl(string $query): string
    {
        return lroute('domain.search').'?q='.urlencode($query);
    }
}
