<?php

namespace App\Services\Crypto;

use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Invoice;
use App\Services\Cloud\CloudPricing;
use App\Services\ExchangeRate;

/**
 * صدورِ یک پرداختِ رمزارز: نرخ را قفل می‌کند، آدرس می‌گیرد، مهلت می‌گذارد.
 */
class CryptoIssuer
{
    /** دارایی‌های فاز ۱ */
    public const ASSETS = [
        'USDT' => ['chain' => 'tron', 'network' => 'TRC20', 'decimals' => 6, 'label' => 'USDT (TRC20)'],
        'TRX' => ['chain' => 'tron', 'network' => 'TRC20', 'decimals' => 6, 'label' => 'TRX'],
    ];

    public function __construct(private readonly ExchangeRate $fx) {}

    /** دارایی‌هایی که واقعاً قابلِ عرضه‌اند — یعنی آدرسِ آزاد دارند */
    public function available(): array
    {
        return array_filter(self::ASSETS, fn ($a) => CryptoWallet::free($a['chain'])->exists());
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
            'expires_at' => now()->addMinutes(CryptoPayment::WINDOW_MINUTES),
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
     * ⚠️ USDT را ۱ دلار **فرض نمی‌کنیم** برای تومان: مشتریِ ایرانی با نرخِ
     * آزادِ دلار حساب می‌کند، نه نرخِ رسمی. برای همین از همان `ExchangeRate`
     * زنده‌ای می‌آید که بقیهٔ سایت استفاده می‌کند.
     */
    private function rateMicro(string $asset, string $currency): int
    {
        try {
            if ($asset === 'USDT') {
                $usd = $currency === 'IRT'
                    ? (int) $this->fx->toToman('USD')
                    : 1;   // EUR≈USD تقریب نمی‌زنیم؛ پایین اصلاح می‌شود

                if ($currency === 'EUR') {
                    // یک تتر ≈ یک دلار؛ دلار→یورو از نرخِ زنده
                    $usdToman = (int) $this->fx->toToman('USD');
                    $eurToman = (int) app(CloudPricing::class)->eurToToman();

                    return $eurToman > 0 && $usdToman > 0
                        ? (int) round($usdToman / $eurToman * 1_000_000)
                        : 0;
                }

                return $usd > 0 ? $usd * 1_000_000 : 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        // TRX قیمتِ زنده لازم دارد و در فاز ۱ منبعش را نداریم
        return 0;
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
