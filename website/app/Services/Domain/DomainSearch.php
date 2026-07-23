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
    public function search(string $query, array $tlds = []): array
    {
        [$name, $ext] = $this->split($query);

        if ($name === '') {
            return [];
        }

        // اگر کاربر پسوند داده، همان اول؛ بعد پیشنهادها
        $extensions = $ext !== ''
            ? array_values(array_unique(array_merge([$ext], $tlds)))
            : ($tlds ?: config('services.openprovider.suggest_tlds', ['com', 'net', 'org', 'ir']));

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
            'price_toman' => null,
            'renew_toman' => null,
            'reason'      => null,
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
            'price_toman' => $sell,
            'renew_toman' => $sellR,
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
        foreach ([
            ["price.$who.price", "price.$who.currency"],
            ["price.$kind.$who.price", "price.$kind.$who.currency"],
            ["{$kind}_price.$who.price", "{$kind}_price.$who.currency"],
        ] as [$pKey, $cKey]) {
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
        $rate = $this->fx->toToman($currency);
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

    /** درصد سود — پیش‌فرض عمومی، با امکان تنظیم per-TLD */
    private function marginFor(string $tld): float
    {
        $cfg = config('services.openprovider.margin', []);

        return (float) ($cfg[$tld] ?? $cfg['default'] ?? 25);
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
