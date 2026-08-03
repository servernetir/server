<?php

namespace App\Services\Domain;

use App\Models\Currency;
use App\Models\DomainQuote;
use App\Services\ExchangeRate;
use Illuminate\Support\Str;

/**
 * جستجوی دامنه: استعلام زنده از رسیلری، تبدیل قیمت به تومان، اعمال سود.
 *
 * قاعدهٔ بنیادی: **هیچ قیمتی نمایش داده نمی‌شود مگر از یک استعلام زندهٔ
 * ذخیره‌شده آمده باشد.** اگر نتوانیم قیمت قابل‌احترام بدهیم، به‌جای عدد،
 * `orderable=false` با دلیل برمی‌گردانیم.
 *
 * این همان چیزی است که مشکل «۲۰ دلار → ۲ دلار → رسیلری نمی‌فروشد» را
 * از ریشه می‌بندد.
 */
class DomainSearch
{
    /** مدت اعتبار قیمت اعلام‌شده */
    private const QUOTE_TTL_MINUTES = 15;

    /**
     * بیشینهٔ پسوند در یک استعلام — رسیلری درخواستِ بی‌اندازه را رد می‌کند.
     *
     * ⚠️ عمداً بزرگ‌تر از فهرستِ پیشنهادی است تا آن فهرست هرگز بی‌صدا بریده
     * نشود. این سقف فقط جلوی فراخوانی را می‌گیرد که فهرستِ دلخواهِ غول‌آسا
     * بدهد.
     */
    private const MAX_TLDS = 70;

    /**
     * پسوندهایی که به کاربر پیشنهاد می‌شوند.
     *
     * ترتیب مهم است: آنچه بالاتر است زودتر دیده می‌شود. اول کلاسیک‌های
     * پرتقاضا، بعد پسوندهای فناوری و کسب‌وکار که در بازار ایران رشد کرده‌اند،
     * بعد منطقه‌ای و صنعتی.
     *
     * ⚠️ `.ir` عمداً **این‌جا نیست**. از رسیلرِ اروپایی گران درمی‌آید (ده‌ها
     * برابرِ قیمتِ مستقیمِ ایرنیک) و نشان‌دادنش با آن قیمت، به کلِ صفحه
     * می‌گوید «قیمت‌های این‌جا بی‌ربط است». تا وقتی مسیرِ ایرنیک ساخته نشده،
     * پیشنهاد نمی‌شود — ولی اگر کاربر خودش تایپش کند استعلام می‌شود.
     */
    private const SUGGEST_TLDS = [
        // کلاسیک
        'com', 'net', 'org', 'info', 'biz', 'co',
        // فناوری و توسعه
        'dev', 'app', 'io', 'ai', 'tech', 'cloud', 'digital', 'software',
        'systems', 'network', 'host', 'site', 'website', 'online', 'space',
        // کسب‌وکار و فروشگاه
        'shop', 'store', 'company', 'agency', 'group', 'team', 'services',
        'solutions', 'consulting', 'management', 'business', 'market',
        // محتوا و رسانه
        'blog', 'news', 'media', 'studio', 'design', 'art', 'photo', 'video',
        // حرفه‌ای و شخصی
        'me', 'pro', 'name', 'expert', 'academy', 'institute', 'education',
        // منطقه‌ای و پرکاربرد
        'eu', 'de', 'nl', 'uk', 'fr', 'es', 'it', 'tr', 'asia',
        // صنعتی
        'energy', 'finance', 'clinic', 'travel', 'events', 'games', 'live',
    ];

    public function __construct(
        private OpenProviderClient $op,
        private ExchangeRate $fx,
    ) {}

    /**
     * جستجوی یک دامنه در چند پسوند.
     *
     * @param  string    $query   ورودی کاربر: «example» یا «example.com»
     * @param  string[]  $tlds    پسوندهای پیشنهادی وقتی کاربر پسوند نداده
     * @return array<int,array>
     */
    /**
     * اندازهٔ هر دستهٔ استعلامِ تدریجی.
     *
     * 🔴 عمداً کمتر از سقفِ اعتبارسنجیِ روت (`tlds` حداکثر ۱۲) است. اگر
     * بزرگ‌ترش کنی، هر درخواست با خطای اعتبارسنجی برمی‌گردد و کاربر هیچ
     * نتیجه‌ای نمی‌بیند — خرابی‌ای که فقط در مرورگر پیداست، نه در تستِ سرور.
     */
    public const BATCH = 10;

    /**
     * دستهٔ اول: چیزی که کاربر بلافاصله باید ببیند.
     *
     * @return array<int,string>
     */
    public static function firstBatch(): array
    {
        return array_slice(self::SUGGEST_TLDS, 0, self::BATCH);
    }

    /**
     * بقیهٔ پسوندها، دسته‌دسته — برای بارگذاریِ تدریجی در پس‌زمینه.
     *
     * @return array<int,array<int,string>>
     */
    public static function restBatches(): array
    {
        return array_values(array_chunk(
            array_slice(self::SUGGEST_TLDS, self::BATCH),
            self::BATCH
        ));
    }

    public function search(string $query, array $tlds = []): array
    {
        [$name, $ext] = $this->split($query);

        if ($name === '') {
            return [];
        }

        /*
        | پسوندها: خواستهٔ کاربر اول، بعد پیشنهادها.
        |
        | ⚠️ حتی وقتی کاربر پسوند داده، پیشنهادها هم می‌آیند — کسی که
        | `example.com` را جستجو می‌کند و می‌بیند گرفته شده، باید همان‌جا
        | جایگزین ببیند، نه اینکه دستِ خالی برگردد.
        */
        $suggest = $tlds !== [] ? $tlds : self::SUGGEST_TLDS;

        $extensions = $ext !== ''
            ? array_values(array_unique(array_merge([$ext], $suggest)))
            : $suggest;

        // سقفِ ایمنی: هر پسوند یک ردیفِ استعلام است و رسیلری درخواستِ
        // بی‌اندازه را رد می‌کند.
        $extensions = array_slice($extensions, 0, self::MAX_TLDS);

        $payload = array_map(
            fn (string $e) => ['name' => $name, 'extension' => $e],
            $extensions
        );

        $results = $this->op->enabled() ? $this->op->check($payload) : [];

        // نگاشت پاسخ رسیلری بر اساس دامنه، تا ترتیب درخواست حفظ شود
        $byDomain = [];
        foreach ($results as $r) {
            $key = strtolower((string) ($r['domain'] ?? ''));
            if ($key !== '') {
                $byDomain[$key] = $r;
            }
        }

        $out = [];
        foreach ($extensions as $e) {
            $fqdn = $name.'.'.$e;
            $out[] = $this->shape($fqdn, $name, $e, $byDomain[strtolower($fqdn)] ?? null);
        }

        return $out;
    }

    /**
     * تبدیل پاسخ خام رسیلری به شکلی که رابط کاربری می‌فهمد.
     *
     * سه وضعیت مجزا که کارفرما خواست:
     *   available            — آزاد و قابل ثبت
     *   premium              — آزاد ولی قیمت ویژه
     *   unavailable          — گرفته‌شده یا غیرقابل فروش
     */
    private function shape(string $fqdn, string $name, string $ext, ?array $raw): array
    {
        $base = [
            'domain'      => $fqdn,
            'tld'         => $ext,
            'status'      => 'unknown',
            'available'   => false,
            'is_premium'  => false,
            'orderable'   => false,
            'price_toman'    => null,
            'renew_toman'    => null,
            'transfer_toman' => null,
            'reason'         => null,
            'quote_id'    => null,
        ];

        // ⚠️ حتماً array_merge، نه عملگر +: عملگر + کلیدهای موجود در سمت چپ را
        // بازنویسی نمی‌کند، پس status همیشه 'unknown' می‌ماند.
        if ($raw === null) {
            return array_merge($base, ['reason' => 'no_response']);
        }

        // OpenProvider: free = آزاد · active/inuse = گرفته‌شده
        $status = strtolower((string) ($raw['status'] ?? ''));
        $available = $status === 'free';
        $isPremium = (bool) ($raw['is_premium'] ?? false);

        if (! $available) {
            return array_merge($base, [
                'status' => 'unavailable',
                'reason' => $status ?: 'taken',
            ]);
        }

        // قیمت پرمیوم فقط از همین پاسخ می‌آید، نه از فهرست قیمت TLD.
        // و مهم‌تر: طبق مشخصات رسمی OpenProvider، قیمت پرمیوم در شاخهٔ جداگانهٔ
        // `premium` می‌نشیند نه در `price.reseller`. اگر آن را نخوانیم، برای
        // دامنهٔ پرمیوم قیمت پایه نشان می‌دهیم — همان فاجعه‌ای که این طراحی
        // برای جلوگیری از آن ساخته شد.
        $cost  = $this->premiumPrice($raw)
              ?? $this->extractPrice($raw, 'reseller')
              ?? $this->extractPrice($raw, 'product');
        $renew = $this->extractPrice($raw, 'reseller', 'renewal') ?? $cost;

        // انتقال هم در همان لحظه استعلام می‌شود. اگر رسیلری قیمتِ انتقال ندهد،
        // قیمتِ ثبت جایگزین می‌شود — تقریباً همیشه برابرند و «نمی‌دانم» روی
        // صفحهٔ فروش بدترین گزینه است.
        $transfer = $this->extractPrice($raw, 'reseller', 'transfer') ?? $cost;

        if ($cost === null) {
            // آزاد است ولی قیمت نداریم → قیمت نمی‌سازیم
            return array_merge($base, [
                'status'    => $isPremium ? 'premium' : 'available',
                'available' => true,
                'is_premium'=> $isPremium,
                'reason'    => 'no_price',
            ]);
        }

        $sell  = $this->toSellingToman($cost['amount'], $cost['currency'], $ext);
        $sellR = $this->toSellingToman($renew['amount'], $renew['currency'], $ext);
        $sellT = $this->toSellingToman($transfer['amount'], $transfer['currency'], $ext);

        if ($sell === null) {
            // نرخ ارز در دسترس نیست → قیمتی که نمی‌توانیم پایش بایستیم نشان نمی‌دهیم
            return array_merge($base, [
                'status'    => $isPremium ? 'premium' : 'available',
                'available' => true,
                'is_premium'=> $isPremium,
                'reason'    => 'fx_unavailable',
            ]);
        }

        $quote = DomainQuote::create([
            'domain'         => $fqdn,
            'tld'            => $ext,
            'registrar'      => 'openprovider',
            'is_premium'     => $isPremium,
            'cost_amount'    => (int) round($cost['amount'] * 100), // واحد فرعی ارز مبدأ
            'cost_currency'  => $cost['currency'],
            'sell_toman'     => $sell,
            'renew_toman'    => $sellR,
            'honour_until'   => now()->addMinutes(self::QUOTE_TTL_MINUTES),
            'raw'            => $raw,
        ]);

        return [
            'domain'      => $fqdn,
            'tld'         => $ext,
            'status'      => $isPremium ? 'premium' : 'available',
            'available'   => true,
            'is_premium'  => $isPremium,
            'orderable'   => true,
            'price_toman'    => $sell,
            'renew_toman'    => $sellR,
            'transfer_toman' => $sellT,
            'reason'      => null,
            'quote_id'    => $quote->id,
        ];
    }

    /**
     * قیمت پرمیوم، طبق مشخصات رسمی OpenProvider:
     *   premium: { currency: "USD", price: { create: 2500.0 } }
     *
     * این شاخه از price.reseller جداست و برای دامنهٔ پرمیوم حرف آخر را می‌زند.
     */
    private function premiumPrice(array $raw): ?array
    {
        $amount = data_get($raw, 'premium.price.create');

        if (! is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }

        return [
            'amount'   => (float) $amount,
            'currency' => strtoupper((string) (data_get($raw, 'premium.currency') ?: 'USD')),
        ];
    }

    /**
     * قیمت را از ساختار تودرتوی OpenProvider بیرون می‌کشد.
     * شکل رسمی: price.reseller.price / price.reseller.currency
     */
    private function extractPrice(array $raw, string $who, string $kind = 'create'): ?array
    {
        /*
         * 🔴 مسیرهای **نوع‌محور اول**، مسیرِ عمومی آخر.
         *
         * قبلاً `price.$who.price` اولین الگو بود و چون در پاسخ همیشه وجود دارد،
         * برای هر `$kind`ی همان را برمی‌گرداند — یعنی پارامترِ `$kind` عملاً
         * مرده بود و قیمتِ **تمدید** و **انتقال** همیشه برابرِ قیمتِ ثبت
         * می‌شد، حتی وقتی رسیلری عددِ متفاوتی می‌داد.
         *
         * ⚠️ چرا این خطرناک است: قیمتِ تمدید معمولاً از قیمتِ سالِ اولِ تبلیغاتی
         * **بالاتر** است. اگر تمدید را به قیمتِ ثبت بفروشیم، روی هر تمدید ضرر
         * می‌کنیم — و چون تمدید سالانه تکرار می‌شود، ضرر انباشته می‌شود.
         *
         * ⚠️ پاسخِ `/domains/check` معمولاً فقط قیمتِ ثبت دارد؛ پس برگشت به
         * مسیرِ عمومی رفتارِ درست و مورد انتظار است، نه پوششِ خطا.
         */
        $paths = [];

        if ($kind !== 'create') {
            $paths[] = ["price.$who.$kind.price", "price.$who.$kind.currency"];
            $paths[] = ["price.$kind.$who.price", "price.$kind.$who.currency"];
            $paths[] = ["{$kind}_price.$who.price", "{$kind}_price.$who.currency"];
        }

        $paths[] = ["price.$who.price", "price.$who.currency"];

        foreach ($paths as [$pKey, $cKey]) {
            $amount = data_get($raw, $pKey);
            if (is_numeric($amount)) {
                return [
                    'amount'   => (float) $amount,
                    'currency' => strtoupper((string) (data_get($raw, $cKey) ?: 'EUR')),
                ];
            }
        }

        return null;
    }

    /**
     * قیمت خرید (ارز رسیلری) → قیمت فروش (تومان).
     * نرخ زندهٔ ارز × مبلغ + درصد سود، سپس گرد کردن به پلهٔ ارز.
     */
    private function toSellingToman(float $amount, string $currency, string $tld): ?int
    {
        $rate = $this->rateFor($currency);
        if ($rate === null || $rate <= 0) {
            return null;
        }

        $costToman = $amount * $rate;

        $marginPct = $this->marginFor($tld);
        $withMargin = $costToman * (1 + $marginPct / 100);

        $irt = Currency::find('IRT');
        $step = $irt?->rounding_step ?: 1000;

        return (int) (ceil($withMargin / $step) * $step);
    }

    /**
     * نرخِ یک واحدِ ارزِ رسیلری به تومان.
     *
     * 🔴 منبعِ نرخ باید **همانِ بقیهٔ سایت** باشد. قبلاً این‌جا مستقیم
     * `ExchangeRate` صدا زده می‌شد و نرخِ مبنایی که مدیر در `/admin/settings`
     * می‌گذارد (`pricing_rate_override`) اصلاً دیده نمی‌شد — یعنی سرورِ ابری با
     * نرخِ مدیر قیمت می‌خورد و دامنه با نرخِ اسکرپ‌شده. دو قیمتِ ناهماهنگ روی
     * یک سایت، و مدیری که نرخ را عوض می‌کند و می‌بیند دامنه‌ها تکان نمی‌خورند.
     *
     * یورو از `CloudPricing` می‌آید (همان منبعِ یگانه). ارزِ دیگر — رسیلری
     * گاهی دلاری قیمت می‌دهد — با نرخِ زندهٔ خودش، چون نرخِ مبنا فقط یورویی است.
     */
    private function rateFor(string $currency): ?int
    {
        $currency = strtoupper($currency);

        if ($currency === 'EUR') {
            $rate = app(\App\Services\Cloud\CloudPricing::class)->eurToToman();

            // صفر یعنی «نمی‌دانیم» — نه اینکه دامنه رایگان است
            return $rate > 0 ? $rate : null;
        }

        return $this->fx->toToman($currency);
    }

    /**
     * درصد سود دامنه.
     *
     * 🔴 اولویت با تنظیماتِ مدیر است (`domain_margin_pct` در `/admin/settings`)،
     * بعد config، و در نهایت **صفر**.
     *
     * ⚠️ پیش‌فرضِ قبلی ۲۵٪ بود و از `.env` می‌آمد — یعنی مدیر نه می‌دید نه
     * می‌توانست عوضش کند، و روی یک دامنهٔ ۲ میلیونی نیم میلیون تومان اضافه
     * می‌کرد بی‌آنکه کسی تصمیمش را گرفته باشد. صفر یعنی «تا وقتی آگاهانه
     * تصمیم نگرفته‌ای، به بهای تمام‌شده بفروش» — که برای دامنه، که محصولِ
     * جذبِ مشتری است، انتخابِ بهتری از حدسِ ۲۵٪ است.
     */
    private function marginFor(string $tld): float
    {
        $override = \App\Models\Setting::get('domain_margin_pct');

        if ($override !== null && $override !== '') {
            return max(0, (float) $override);
        }

        $cfg = (array) config('services.openprovider.margin', []);

        return max(0, (float) ($cfg[$tld] ?? $cfg['default'] ?? 0));
    }

    /** «example.com» یا «Example.COM» یا «example» → [name, ext] */
    private function split(string $query): array
    {
        $q = trim(mb_strtolower($query));
        $q = preg_replace('#^https?://#', '', $q);
        $q = preg_replace('#[/?].*$#', '', $q);
        $q = ltrim($q, '.');
        $q = preg_replace('/\s+/u', '', $q) ?? '';

        if ($q === '') {
            return ['', ''];
        }

        if (! str_contains($q, '.')) {
            return [$this->sanitizeLabel($q), ''];
        }

        $parts = explode('.', $q, 2);

        return [$this->sanitizeLabel($parts[0]), trim($parts[1], '.')];
    }

    private function sanitizeLabel(string $s): string
    {
        // فقط حروف/رقم/خط تیره — دامنهٔ یونیکد بعداً با punycode اضافه می‌شود
        return trim(preg_replace('/[^\p{L}\p{N}-]/u', '', $s) ?? '', '-');
    }
}
