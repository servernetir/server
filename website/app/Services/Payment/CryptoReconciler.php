<?php

namespace App\Services\Payment;

use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تطبیقِ واریزیِ زنجیره با پرداختِ باز، و تسویه.
 *
 * ═══ قاعدهٔ حاکم بر کلِ این کلاس ═══
 *
 * در ابهام، **تأیید نکن**. پولی که آمده و ما ندیدیم، با یک صفِ بازبینیِ دستی
 * قابل جبران است؛ سرویسی که فعال شده و پولش نیامده، قابل جبران نیست. پس هر
 * حالتِ نامطمئن به `unmatched` یا `manual` می‌رود، نه به `confirmed`.
 * همان الگویی که `CloudFraudGuard` دارد.
 */
class CryptoReconciler
{
    /**
     * حاشیهٔ اختلافِ ساعتِ سرور با زمانِ بلاکِ زنجیره.
     *
     * ⚠️ عمداً کوچک: هر ثانیه‌اش پنجره‌ای است که یک واریزیِ قدیمی می‌تواند
     * پرداختِ تازه را تسویه کند.
     */
    private const CLOCK_SKEW_SECONDS = 120;

    public function __construct(
        private readonly TronWatcher $tron,
        private readonly PaymentService $payments,
    ) {}

    /** یک دور پایش روی همهٔ پرداخت‌های باز. خروجی: شمارشِ کارهای انجام‌شده */
    public function sweep(): array
    {
        $stat = ['checked' => 0, 'confirmed' => 0, 'expired' => 0, 'unmatched' => 0];

        foreach (CryptoPayment::watchable()->where('chain', 'tron')->get() as $cp) {
            $stat['checked']++;

            foreach ($this->tron->deposits($cp->address, $cp->asset) as $d) {
                $r = $this->apply($cp, $d);
                if ($r !== null) {
                    $stat[$r]++;
                }
            }
        }

        $stat['expired'] += $this->expireStale();
        $stat['orphan_freed'] = $this->freeOrphanedWallets();

        return $stat;
    }

    /**
     * 🔴 خودترمیمیِ استخر: ولتی که «مشغول» مانده ولی پرداختِ گرفتارکننده‌اش
     * دیگر باز نیست، آزاد می‌شود.
     *
     * رخدادِ واقعی (۷ شهریور ۱۴۰۵): /system/crypto-status نشان داد `busy:1`
     * با `open_payments:0` — یک ادعای قدیمی روی ولت جا مانده بود (کرش/ری‌استارت
     * بینِ تغییرِ وضعیتِ پرداخت و release) و **هیچ مسیری** آزادش نمی‌کرد؛ آن
     * آدرس برای همیشه از استخر کم شده بود. با استخرِ کوچک، چند تا از این‌ها
     * یعنی «رمزارز موقتاً در دسترس نیست»ِ دائمی.
     *
     * ⚠️ آزادسازی با همان `release()` است، یعنی **با** دورهٔ خنک‌شدن — چون
     * نمی‌دانیم پرداختِ قدیمی چگونه بسته شده و مشتری‌اش شاید هنوز آدرس را دارد.
     */
    private function freeOrphanedWallets(): int
    {
        $n = 0;

        $wallets = \App\Models\CryptoWallet::whereNotNull('busy_payment_id')->get();

        foreach ($wallets as $w) {
            $holder = CryptoPayment::find($w->busy_payment_id);

            if ($holder === null || ! in_array($holder->status, ['pending', 'seen'], true)) {
                $w->release();
                $n++;
            }
        }

        return $n;
    }

    /**
     * یک واریزی را روی یک پرداخت اعمال می‌کند.
     *
     * 🔴 یکتاییِ `(chain, txid)` در دیتابیس، **آخرین** خطِ دفاع در برابرِ
     * حسابِ دوبارهٔ یک تراکنش است. کرون هر دقیقه می‌دود و TronGrid همان
     * تراکنش را بارها برمی‌گرداند؛ بدونِ این قید، هر دقیقه یک بار به مبلغِ
     * رسیده اضافه می‌شد و فاکتور با یک واریزِ کوچک تسویه می‌شد.
     */
    private function apply(CryptoPayment $cp, array $d): ?string
    {
        // این تراکنش قبلاً هر جا ثبت شده؟ دوباره حساب نمی‌شود
        if (CryptoPayment::where('chain', $cp->chain)->where('txid', $d['txid'])->exists()) {
            return null;
        }

        if ($d['asset'] !== $cp->asset || $d['decimals'] !== $cp->decimals) {
            return null;
        }

        /*
        | ═══ 🔴 واریزیِ **پیش از** این پرداخت، پولِ این پرداخت نیست ═══
        |
        | رخدادِ واقعی (۸ شهریور ۱۴۰۵): مشتریِ تازه سرور خرید، سفارشش
        | «پرداخت‌شده» شد و سرویس فعال — ولی هیچ پولی نیامده بود. `txid`ِ
        | ثبت‌شده روی پرداختش، تراکنشی از **هفتهٔ پیش** بود.
        |
        | چرا رخ داد: `TronWatcher` ۵۰ تراکنشِ آخرِ آدرس را برمی‌گرداند، بی‌هیچ
        | فیلترِ زمانی. آدرس‌ها هم از استخر بازاستفاده می‌شوند. پس هر واریزیِ
        | قدیمیِ همان آدرس که در جدولِ ما ثبت **نشده** بود — واریزیِ دیرهنگامِ
        | بعد از انقضا، جابه‌جاییِ داخلیِ خودمان، هر چیزی — روی اولین پرداختِ
        | بازِ بعدی می‌نشست و فاکتورش را تسویه می‌کرد.
        |
        | 🔴 گاردِ `txid` تکراری این را **نمی‌گرفت**: آن فقط جلوی حسابِ دوبارهٔ
        | تراکنشی را می‌گیرد که قبلاً ثبت شده. تراکنشی که هرگز ثبت نشده بود از
        | کنارش رد می‌شد. گاردِ درست، زمان است نه یکتایی.
        |
        | ⚠️ حاشیهٔ ۱۲۰ ثانیه فقط برای اختلافِ ساعتِ سرور با زمانِ بلاک است، نه
        | ارفاق: مشتری آدرس را تازه **پس از** ساختِ پرداخت می‌بیند، پس واریزیِ
        | واقعی همیشه بعد از آن است.
        */
        $bornAt = ($cp->created_at?->timestamp ?? 0) - self::CLOCK_SKEW_SECONDS;

        if ((int) ($d['timestamp'] ?? 0) > 0 && (int) $d['timestamp'] < $bornAt) {
            return null;
        }

        /*
        | ⚠️ زمانِ ناشناخته = بازبینیِ دستی، نه تأییدِ خوش‌بینانه.
        |
        | اگر روزی درایورِ زنجیره‌ای تازه‌ای `timestamp` ندهد، گاردِ بالا بی‌صدا
        | بی‌اثر می‌شود و همین حفره برمی‌گردد. این شاخه آن حالت را به صفِ آدم
        | می‌فرستد: پولِ دیده‌نشده جبران‌پذیر است، سرویسِ پول‌نگرفته نه.
        */
        /*
        | ═══ گاردِ دوم: واریزیِ **تک‌مرحله‌ای** باید مبلغِ همین پرداخت باشد ═══
        |
        | آدرس بازاستفاده می‌شود، پس یک واریزیِ دیرهنگام (بعد از ساختِ پرداختِ
        | بعدی، یعنی جایی که گاردِ زمانی نمی‌گیرد) می‌تواند به فاکتورِ نفرِ
        | بعدی بنشیند. مبلغِ هر پرداخت حالا «دمِ» یکتا دارد (fingerprint در
        | CryptoIssuer)، پس واریزی‌ای که مبلغش با این پرداخت جور نیست، مالِ این
        | پرداخت نیست.
        |
        | ⚠️ فقط وقتی اعمال می‌شود که هنوز چیزی دریافت نشده باشد: پرداختِ
        | چندتکه (مشتری دو بار می‌فرستد) حقِ زندگی دارد و مبلغِ تکه‌ها با کل
        | برابر نیست. آن حالت مثل قبل جمع می‌شود و اگر کم بیاید به بازبینیِ
        | دستی می‌رود.
        |
        | ⚠️ سخت‌گیری فقط رد نمی‌کند، به **صفِ آدم** می‌فرستد: پولی که واقعاً
        | رسیده نباید بی‌صدا دور ریخته شود.
        */
        if ((int) $cp->received_atomic === 0) {
            $expected = (int) $cp->amount_atomic;
            $got = (int) $d['amount'];
            $slack = (int) ceil($expected * CryptoPayment::TOLERANCE_BP / 10_000);

            if ($got > $expected + $slack) {
                $cp->forceFill(['status' => 'manual',
                    'note' => 'مبلغِ واریزی با این پرداخت جور نیست ('.$got.' در برابرِ '.$expected.') — بازبینیِ دستی'])->save();

                return 'unmatched';
            }
        }

        if ((int) ($d['timestamp'] ?? 0) <= 0) {
            $cp->forceFill(['status' => 'manual',
                'note' => 'زمانِ واریزی از زنجیره نیامد — تأییدِ خودکار انجام نشد (txid '.$d['txid'].')'])->save();

            return 'unmatched';
        }

        return DB::transaction(function () use ($cp, $d) {
            /** @var CryptoPayment $row */
            $row = CryptoPayment::whereKey($cp->id)->lockForUpdate()->first();

            if ($row === null || ! $row->isOpen()) {
                return null;
            }

            $row->received_atomic += (int) $d['amount'];
            $row->txid = $d['txid'];
            $row->confirmations = TronWatcher::MIN_CONFIRMATIONS;
            $row->status = 'seen';
            $row->save();

            if (! $row->isPaidEnough()) {
                // کم آمده — نه تأیید می‌کنیم نه دور می‌ریزیم
                $row->forceFill(['status' => 'manual',
                    'note' => 'کم‌پرداخت: '.$row->received_atomic.' از '.$row->amount_atomic])->save();

                return 'unmatched';
            }

            return $this->settle($row) ? 'confirmed' : 'unmatched';
        });
    }

    /**
     * تسویه از **همان** مسیرِ درگاه‌های دیگر.
     *
     * ⚠️ عمداً `settleConfirmed` صدا زده می‌شود نه منطقِ جداگانه: فعال‌سازیِ
     * سرویس، شارژِ اعتبار و ثبتِ درآمد همه آن‌جا هستند. مسیرِ موازی یعنی روزی
     * یکی‌شان به‌روز شود و دیگری نه.
     */
    private function settle(CryptoPayment $row): bool
    {
        $invoice = $row->invoice;

        if ($invoice === null || ! $invoice->isPayable()) {
            $row->forceFill(['status' => 'unmatched',
                'note' => 'فاکتور دیگر قابل پرداخت نیست — بازبینی دستی'])->save();

            return false;
        }

        $payment = Payment::firstOrCreate(
            ['external_ref' => $row->chain.':'.$row->txid],
            [
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'gateway' => 'crypto',
                'currency_code' => $invoice->currency_code,
                'amount' => $invoice->due(),
                'status' => 'redirected',
            ],
        );

        $out = $this->payments->settleConfirmed($payment, $row->txid);

        if (! $out->ok) {
            Log::warning('crypto settle failed', ['crypto_payment' => $row->id]);
            $row->forceFill(['status' => 'manual', 'note' => 'تسویه ناموفق — بازبینی دستی'])->save();

            return false;
        }

        $row->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
        $row->wallet?->release();

        return true;
    }

    /**
     * پرداختِ منقضی.
     *
     * ⚠️ آدرس با **دورهٔ خنک‌شدن** آزاد می‌شود، نه بلافاصله — وگرنه واریزیِ
     * دیرهنگامِ همان مشتری به فاکتورِ نفرِ بعدی می‌نشست.
     */
    private function expireStale(): int
    {
        $n = 0;

        foreach (CryptoPayment::where('status', 'pending')->where('expires_at', '<', now())->get() as $cp) {
            // ⚠️ پولی که رسیده ولی هنوز تأیید نشده را منقضی نمی‌کنیم
            if ($cp->received_atomic > 0) {
                continue;
            }

            $cp->forceFill(['status' => 'expired'])->save();
            $cp->wallet?->release();
            $n++;
        }

        return $n;
    }
}
