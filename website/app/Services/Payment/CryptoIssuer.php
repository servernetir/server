<?php

namespace App\Services\Payment;

use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Invoice;
use App\Services\ExchangeRate;

/**
 * صدورِ یک پرداختِ رمزارز: نرخ را قفل می‌کند، آدرس می‌گیرد، مهلت می‌گذارد.
 */
class CryptoIssuer
{
    /**
     * دارایی‌های فاز ۱.
     *
     * ⚠️ `window` عمداً به‌ازای هر دارایی است. تا وقتی مهلت باز است نرخ قفل
     * مانده، پس استیبل‌کوین می‌تواند مهلتِ بلند داشته باشد ولی دارایی نوسانی
     * نه. اگر روزی بیت‌کوین یا اتریوم اضافه شد، `window` کوتاه‌تری بگذار —
     * نه اینکه پیش‌فرضِ ۵۹ دقیقه را برایش هم بردار.
     */
    public const ASSETS = [
        'USDT' => ['chain' => 'tron', 'network' => 'TRC20', 'decimals' => 6, 'label' => 'USDT (TRC20)', 'window' => 59],
        'TRX' => ['chain' => 'tron', 'network' => 'TRC20', 'decimals' => 6, 'label' => 'TRX', 'window' => 25],
    ];

    /** آدرسِ آزاد دارد و قیمت هم داریم ⇒ همین حالا قابلِ صدور */
    public const READY = 'ready';

    /** آدرس داریم ولی همه‌شان مشغول‌اند ⇒ «چند دقیقهٔ دیگر» */
    public const BUSY = 'busy';

    /** پرداختِ بازِ خودِ همین مشتری ⇒ باید دیده شود، حتی اگر استخر ته کشیده */
    public const OPEN = 'open';

    public function __construct(
        private readonly ExchangeRate $fx,
        private readonly CryptoPrice $prices,
    ) {}

    /** دارایی‌هایی که همین حالا قابلِ صدورند */
    public function available(string $currency = 'IRT'): array
    {
        return array_filter(
            $this->offers($currency),
            fn ($a) => $a['state'] === self::READY,
        );
    }

    /**
     * وضعیتِ هر دارایی برای این ارز.
     *
     * ═══ مرزِ «بگو» و «نگو» ═══
     *
     * «موقتاً در دسترس نیست» یک **وعده** است: یعنی کمی بعد برگرد. پس فقط جایی
     * گفته می‌شود که نبودن واقعاً گذرا باشد:
     *
     *   · هیچ آدرسِ فعالی در استخر نیست  → هیچ نمی‌گوییم. گذرا نیست؛ کارِ مدیر است.
     *   · قیمتِ بازارِ این دارایی را نداریم → هیچ نمی‌گوییم. شاید هرگز نداشته
     *     باشیم (منبع از این شبکه در دسترس نباشد) و وعدهٔ دروغ بدتر از سکوت است.
     *   · آدرس‌ها همه مشغول‌اند، یا نرخِ ساعتیِ ارز لحظه‌ای سرد است → **busy**.
     *     هر دو ظرفِ دقایقی خودشان درست می‌شوند.
     *
     * @return array<string, array<string, mixed>>
     */
    public function offers(string $currency): array
    {
        $out = [];

        foreach (self::ASSETS as $code => $spec) {
            // آدرسی نداریم ⇒ اصلاً گزینه‌ای در کار نیست
            if (! CryptoWallet::where('chain', $spec['chain'])->where('is_active', true)->exists()) {
                continue;
            }

            // 🔴 قیمتِ بازار نداریم ⇒ نه عرضه، نه وعده. هرگز عددِ حدسی.
            if ($this->prices->isPriceable($code) && $this->prices->cachedUsd($code) === null) {
                continue;
            }

            $ready = $this->rateMicro($code, $currency, live: false) > 0
                && CryptoWallet::free($spec['chain'])->exists();

            $out[$code] = $spec + ['state' => $ready ? self::READY : self::BUSY];
        }

        return $out;
    }

    /**
     * فهرستی که صفحهٔ فاکتور باید نشان دهد.
     *
     * 🔴 باگی که این متد برای تکرارنشدنش نوشته شد:
     *
     * کارت‌ها **و** پنل‌های صفحهٔ فاکتور هر دو از `available()` می‌آمدند، یعنی
     * فقط دارایی‌هایی که آدرسِ **آزاد** دارند. با استخرِ تک‌آدرسی، همان لحظه که
     * مشتری «دریافت آدرس» را می‌زد آن تنها آدرس مشغول می‌شد و در رفرشِ بعدی
     * **کلِ گزینهٔ رمزارز ناپدید می‌شد** — از جمله جعبه‌ای که آدرس و مبلغ و
     * شمارشِ معکوسِ خودِ او را داشت. مشتری آدرسی داشت که باید به آن پول
     * می‌فرستاد و صفحه دیگر نشانش نمی‌داد. کارفرما همین را دید و گزارش کرد
     * «گزینهٔ رمزارز اصلاً تو فاکتور ظاهر نشد».
     *
     * پس پرداختِ باز **همیشه** جای خودش را دارد، مستقل از وضعیتِ استخر.
     *
     * @return array<string, array<string, mixed>>
     */
    public function checkout(Invoice $invoice, ?CryptoPayment $open = null): array
    {
        $out = $this->offers($invoice->currency_code);

        if ($open !== null && isset(self::ASSETS[$open->asset])) {
            $out[$open->asset] = self::ASSETS[$open->asset] + ['state' => self::OPEN];
        }

        return $out;
    }

    /**
     * پرداختِ بازِ همین فاکتور را برمی‌گرداند یا تازه می‌سازد.
     *
     * ⚠️ اگر پرداختِ بازی هست، **همان** برگردانده می‌شود. وگرنه رفرشِ صفحه هر
     * بار یک آدرسِ تازه می‌گرفت و استخر ظرفِ چند دقیقه ته می‌کشید — و بدتر،
     * مشتری نمی‌دانست به کدام آدرس بفرستد.
     */
    public function issue(Invoice $invoice, string $asset): ?CryptoPayment
    {
        $open = CryptoPayment::where('invoice_id', $invoice->id)->where('asset', $asset)
            ->whereIn('status', ['pending', 'seen'])->where('expires_at', '>', now())
            ->latest('id')->first();

        if ($open !== null) {
            return $open;
        }

        $spec = self::ASSETS[$asset] ?? null;

        if ($spec === null) {
            return null;
        }

        $rateMicro = $this->rateMicro($asset, $invoice->currency_code);

        /*
        | 🔴 نرخ که به دست نیامد، پرداخت **صادر نمی‌شود**.
        |
        | همان قاعدهٔ `cloud_plans`: قیمتِ حدسی از نبودِ قیمت بدتر است. اگر
        | این‌جا عددِ پیش‌فرض بگذاریم، ممکن است سرویسِ ۵۰ دلاری با ۵ دلار تتر
        | تسویه شود و هیچ خطایی هم تولید نشود.
        */
        if ($rateMicro <= 0) {
            return null;
        }

        $due = (int) $invoice->due();
        $atomic = $this->atomicFor($due, $invoice->currency_code, $rateMicro, $spec['decimals']);

        if ($atomic <= 0) {
            return null;
        }

        $cp = CryptoPayment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'chain' => $spec['chain'],
            'asset' => $asset,
            'network' => $spec['network'],
            'address' => '',                       // بلافاصله پر می‌شود
            'amount_atomic' => $atomic,
            'decimals' => $spec['decimals'],
            'invoice_amount' => $due,
            'invoice_currency' => $invoice->currency_code,
            'rate_micro' => $rateMicro,
            'status' => 'pending',
            'expires_at' => now()->addMinutes($spec['window'] ?? CryptoPayment::WINDOW_MINUTES),
        ]);

        $wallet = CryptoWallet::claim($spec['chain'], $cp->id);

        if ($wallet === null) {
            // استخر خالی — پرداخت را باز نمی‌گذاریم تا مشتری آدرسِ خالی نبیند
            $cp->forceFill(['status' => 'expired', 'note' => 'آدرس آزاد در استخر نبود'])->save();

            return null;
        }

        $cp->forceFill(['crypto_wallet_id' => $wallet->id, 'address' => $wallet->address])->save();

        return $cp->fresh();
    }

    /**
     * قیمتِ یک واحدِ دارایی به ارزِ فاکتور، ×1e6.
     *
     * ═══ چرا همه‌چیز از تومان رد می‌شود ═══
     *
     * تنها ارزی که برایش نرخِ **زنده و بازارِ آزاد** داریم، تومان است
     * (`ExchangeRate` هم USD و هم EUR را به تومان می‌دهد). پس یک قیف داریم:
     *
     *     دارایی → تومان → ارزِ فاکتور
     *
     * نسخهٔ قبلی برای یورو مسیرِ جداگانه‌ای داشت (دلار→تومان→یورو، با یک
     * وابستگیِ اضافه به `CloudPricing`) و برای TRX هیچ مسیری. حالا هر دو
     * ارز و هر دو دارایی از یک راه می‌آیند.
     *
     * ⚠️ USDT را ۱ دلار **فرض نمی‌کنیم** به تومان: مشتریِ ایرانی با نرخِ آزادِ
     * دلار حساب می‌کند، نه نرخِ رسمی.
     *
     * @param  bool  $live  اجازهٔ تماسِ شبکه‌ای. مسیرِ رندرِ صفحه `false` می‌دهد
     *                      تا یک منبعِ کُند صفحهٔ فاکتور را معلق نکند.
     */
    private function rateMicro(string $asset, string $currency, bool $live = true): int
    {
        try {
            $toman = $this->tomanPerUnit($asset, $live);

            if ($toman <= 0) {
                return 0;
            }

            $currency = strtoupper($currency);

            if ($currency === 'IRT') {
                return (int) round($toman * 1_000_000);
            }

            // یک واحدِ ارزِ فاکتور چند تومان است؟ (یورو امروز، هر ارزِ دیگری فردا)
            $unit = $this->tomanPerCurrency($currency, $live);

            return $unit > 0 ? (int) round($toman / $unit * 1_000_000) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * قیمتِ یک واحدِ دارایی به تومان.
     *
     * 🔴 استیبل‌کوین لنگرِ تعریفی دارد (۱ تتر ≈ ۱ دلار) ولی TRX ندارد؛ قیمتش
     * باید از بازار بیاید و اگر نیامد **هیچ عددی جایش نمی‌گذاریم**.
     */
    private function tomanPerUnit(string $asset, bool $live): float
    {
        $usd = $this->tomanPerCurrency('USD', $live);

        if ($usd <= 0) {
            return 0.0;
        }

        if ($asset === 'USDT') {
            return $usd;
        }

        $inUsd = (float) (($live ? $this->prices->usd($asset) : $this->prices->cachedUsd($asset)) ?? 0);

        return $inUsd > 0 ? $usd * $inUsd : 0.0;
    }

    /**
     * نرخِ یک ارز به تومان.
     *
     * ⚠️ در حالتِ غیرزنده فقط کش خوانده می‌شود. کرونِ ساعتیِ `fx:dollar` تنها
     * نویسندهٔ آن کش است، پس در پروداکشن همیشه گرم است؛ و اگر روزی نبود،
     * نتیجه «عرضه نکن» است نه «حدس بزن».
     */
    private function tomanPerCurrency(string $currency, bool $live): float
    {
        /*
        | 🔴 نرخِ دستیِ مدیر مقدم است — همان کلیدهایی که قیمت‌گذاری می‌خواند.
        |
        | رخدادِ واقعی (۶ شهریور ۱۴۰۵): کشِ نرخِ دلار سرد بود (منبعِ زنده
        | نمی‌آمد) و هر دو داراییِ رمزارز روی فاکتور «موقتاً در دسترس نیست»
        | شدند — با استخرِ سالمِ ۴ آدرسِ آزاد. مدیر «نرخِ دستیِ دلار» را در
        | تنظیمات داشت، ولی فقط قیمت‌گذاری GPU آن را می‌خواند و این‌جا نه؛
        | یعنی روشِ پرداختِ اصلیِ مشتریِ خارجی گروگانِ یک منبعِ نرخِ ایرانی بود.
        */
        $override = (int) \App\Models\Setting::get(match (strtoupper($currency)) {
            'USD' => 'pricing_usd_rate_override',
            'EUR' => 'pricing_rate_override',
            default => '',
        }, '0');

        if ($override > 0) {
            return (float) $override;
        }

        if ($live) {
            return (float) ($this->fx->toToman($currency) ?? 0);
        }

        return (float) ($this->fx->current($currency)['rate_toman'] ?? 0);
    }

    /** مبلغِ فاکتور → مقدارِ اتمیِ دارایی، همیشه گرد رو به **بالا** */
    private function atomicFor(int $due, string $currency, int $rateMicro, int $decimals): int
    {
        if ($due <= 0 || $rateMicro <= 0) {
            return 0;
        }

        // فاکتورِ یورویی در واحدِ فرعی (سنت) است، تومانی نیست
        $major = $currency === 'IRT' ? $due : $due / 100;

        return (int) ceil($major / ($rateMicro / 1_000_000) * (10 ** $decimals));
    }
}
