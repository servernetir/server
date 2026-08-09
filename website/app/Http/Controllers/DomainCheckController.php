<?php

namespace App\Http\Controllers;

use App\Services\Domain\DomainSearch;
use App\Services\Domain\TldPriceBook;
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

    /**
     * برای پیشنهاد فقط چند پسوندِ پرتقاضا — نه هر ۶۴ تا.
     *
     * ⚠️ `public` است تا `domains:price-book` از **همین** فهرست کش را گرم کند.
     * دو فهرستِ موازی یعنی روزی یکی‌شان کهنه می‌شود و آن پسوند قیمتش را از
     * دست می‌دهد، بی‌آنکه کسی بفهمد.
     */
    public const SUGGEST = ['com', 'net', 'org', 'io', 'co', 'dev', 'shop', 'online'];

    public function __invoke(Request $request, DomainSearch $search, TldPriceBook $book): JsonResponse
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
        /*
        | 🔴 `state` اضافه شد و همین یک فیلد، بدترین باگِ باقی‌ماندهٔ این مسیر را
        | می‌بندد.
        |
        | تا امروز پاکت فقط `available: true|false` داشت و
        | `public/assets/js/site.js` همان دو حالت را رندر می‌کرد: یا «آزاد است»
        | یا «قبلاً ثبت شده است». پس یک قطعیِ رجیسترار — یا حتی نبودِ
        | اعتبارنامه — روی **پرترافیک‌ترین** ورودیِ فروشِ دامنه به مشتری
        | می‌گفت نامش گرفته شده. دقیقاً همان دروغی که در `DomainSearch` رفع
        | شده بود، یک لایه بالاتر دست‌نخورده مانده بود.
        |
        | ⚠️ `available` عمداً حذف نشد: مصرف‌کنندهٔ کهنه (کشِ مرورگر وسطِ
        | دیپلوی) باید همچنان کار کند. فیلدِ تازه اضافه است، نه جایگزین.
        */
        $result = [
            'domain'    => $fqdn,
            'available' => (bool) ($primary['available'] ?? false),
            'state'     => $primary !== null
                ? ($primary['state'] ?? DomainSearch::stateOf($primary))
                : DomainSearch::STATE_UNCHECKED,
            'price'     => $this->priceLabel($primary),
            'cart_url'  => $this->buyUrl($fqdn),
        ];

        /*
        | 🔴 قیمتِ پیشنهادها از **کش** می‌آید، نه از پاسخِ زنده.
        |
        | `TldPriceBook` هر ۶ ساعت یک نامِ بلندِ قطعاً-آزاد استعلام می‌گیرد و
        | قیمتِ پایهٔ هر پسوند را نگه می‌دارد. سه سود:
        |
        |  ۱) **یکسانی** — همان عددی که در `/domain/popular-tlds` نشان می‌دهیم
        |     این‌جا هم می‌آید. دو قیمتِ متفاوت برای یک پسوند در یک سایت،
        |     اعتماد را می‌بَرد.
        |  ۲) **مقاومت** — اگر پاسخِ زنده برای پسوندی فیلدِ قیمت نداشته باشد،
        |     پیشنهاد بی‌قیمت نمی‌مانَد.
        |  ۳) کلیدِ کش شاملِ حاشیهٔ سود و نرخِ ارز است، پس تغییرِ تنظیمات
        |     بلافاصله اثر می‌کند و قیمتِ کهنه نمی‌مانَد.
        |
        | ⚠️ برای دامنهٔ **اصلی** عمداً قیمتِ زنده مقدم است: اگر خودِ آن نام
        | پرمیوم باشد، قیمتش ده‌ها برابرِ قیمتِ پایه است و نشان‌دادنِ عددِ پایه
        | یعنی قیمتی که مشتری نمی‌تواند بخرد.
        */
        $book = $this->priceBook($book);

        if ($result['price'] === null && ! ($primary['is_premium'] ?? false)) {
            $result['price'] = isset($book[$primaryTld]) ? cloud_price($book[$primaryTld]) : null;
        }

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

                $tld = (string) ($r['tld'] ?? '');

                $suggestions[] = [
                    'domain'    => $r['domain'],
                    'available' => true,
                    // کش اول، پاسخِ زنده به‌عنوانِ پشتیبان
                    'price'     => isset($book[$tld]) ? cloud_price($book[$tld]) : $this->priceLabel($r),
                    'cart_url'  => $this->buyUrl((string) $r['domain']),
                ];
            }
        }

        return response()->json([
            'result'      => $result,
            'suggestions' => $suggestions,
            // آیا رجیسترار اصلاً جواب داد — برای اینکه رابط بتواند «نمی‌دانیم»
            // را از «گرفته‌شده» جدا کند بی‌آنکه ردیف‌به‌ردیف حدس بزند.
            'lookup_ok'   => $search->lookupOk(),
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

    /**
     * دفترچهٔ قیمتِ کش‌شده.
     *
     * ⚠️ در try/catch: اگر رجیسترار در لحظهٔ تازه‌سازیِ کش نخوابد، جعبهٔ جستجوی
     * صفحهٔ اول نباید خطا بدهد. قیمتِ نبود بدتر از صفحهٔ شکسته نیست — نتیجهٔ
     * زنده هنوز سرِ جایش است.
     *
     * @return array<string,int>
     */
    private function priceBook(TldPriceBook $book): array
    {
        try {
            return $book->forTlds(self::SUGGEST);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('domain', $e, ['area' => 'price-book']);

            return [];
        }
    }
}
