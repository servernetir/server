<?php

namespace App\Services\Finance;

use App\Models\AiReservation;
use App\Models\CreditEntry;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * کیفِ پول — تنها مسیرِ نوشتنِ دفترِ اعتبار، آگاه از رزروها (M3).
 *
 * ═══ ناوردایِ مرکزی ═══
 *
 * داخلِ قفلِ ردیفِ مشتری:
 *
 *      available = SUM(ledger) − نگه‌داشتهٔ رزروهایِ زنده
 *
 * برداشتِ عادی فقط از `available` می‌خورد — پولِ رزروشدهٔ یک درخواستِ AI
 * متعلق به همان درخواست است و هیچ مسیرِ دیگری (فاکتور، نمایندگی، ساعتی،
 * تنظیمِ مدیر) نمی‌تواندش بخورد. تسویهٔ رزرو، رزروِ **خودش** را مصرف
 * می‌کند و آن را یک‌بار از معادله کنار می‌گذارد (`$excludingReservationId`)
 * تا دوبار کم نشود.
 *
 * ═══ قراردادِ موجودی ═══
 *
 * `credit_ledger` افزایشی است؛ حقیقت همیشه `SUM(amount)` است. `balance_after`
 * **مشاوره‌ای** است — هیچ تصمیمی رویش گرفته نمی‌شود.
 *
 * ═══ قراردادِ قفل (ترتیبِ سراسری) ═══
 *
 * همهٔ مسیرهایِ پولیِ این کلاس و `AiReservations` و مسیرهایِ مهاجرت‌یافته:
 * **اول ردیفِ مشتری، بعد منبعِ صورت‌حساب (فاکتور/سرویس/رزرو)، بعد درجِ
 * دفتر.** مسیرهایِ مشتری→منبع موجود (payCredit، سفارشِ نمایندگی) همین
 * ترتیب را داشتند؛ مترِ ساعتی برایِ هم‌گرایی با آن‌ها عمداً مشتری را
 * پیش از claim خودِ سرویس می‌گیرد — تا تلاقیِ «فاکتور از اعتبار» و
 * «کسرِ ساعتیِ همان سرویس» deadlock نسازد.
 *
 * ═══ اعداد ═══
 *
 * فقط صحیح. تومانِ صحیح؛ هیچ float در پول.
 */
class Wallet
{
    /** موجودیِ حقیقت — جمعِ دفتر، نه ستونِ مشاوره‌ای */
    public function balanceOf(int $customerId, string $currency = 'IRT'): int
    {
        return (int) CreditEntry::where('customer_id', $customerId)
            ->where('currency_code', $currency)
            ->sum('amount');
    }

    /**
     * نگه‌داشتهٔ رزروهایِ زنده (pending و مهلت‌دار).
     *
     * `$excludingReservationId` برای تسویه است: رزروی که در همین تراکنش
     * مصرف می‌شود نباید هم «نگه‌داشته» حساب شود هم خرج — دوبار کسر نشود.
     */
    public function reservedOf(int $customerId, string $currency = 'IRT', ?int $excludingReservationId = null): int
    {
        $q = AiReservation::where('customer_id', $customerId)
            ->where('currency_code', $currency)
            ->holding();

        if ($excludingReservationId !== null) {
            $q->where('id', '!=', $excludingReservationId);
        }

        return (int) $q->sum('amount_irt');
    }

    /** در دسترس = دفتر − نگه‌دارنده‌ها */
    public function availableOf(int $customerId, string $currency = 'IRT'): int
    {
        return $this->balanceOf($customerId, $currency)
            - $this->reservedOf($customerId, $currency);
    }

    /**
     * افزایشِ اعتبار — topup، مازادِ پرداخت، بازگشتِ وجه.
     *
     * داخلِ تراکنشِ فراخوان هم می‌نشیند و بیرون از تراکنش خودش می‌بازد.
     */
    public function credit(int $customerId, string $currency, int $amount, string $reason, ?object $source, ?string $note = null): CreditEntry
    {
        if ($amount <= 0) {
            throw new WalletException('invalid_amount', 'مبلغِ افزایش باید مثبت باشد.');
        }

        return $this->entry($customerId, $currency, $amount, $reason, $source, $note, requireAvailable: false);
    }

    /**
     * برداشتِ عادی — گارد روی «در دسترس»، نه روی جمعِ خام.
     *
     * 🔴 گارد از رزروها کم می‌کند: پولِ نگه‌داشتهٔ یک درخواستِ AI متعلق به
     *    همان است؛ این متُد هرگز نمی‌تواندش بخورد، حتی اگر جمعِ دفتر
     *    بزرگ‌تر باشد. پیش‌فرضِ `requireAvailable` را عوض نکنید — تنها
     *    استثنای مجاز، تسویهٔ رزرو با `excludingReservationId` است که
     *    رزروِ خودش را مصرف می‌کند.
     */
    public function debit(
        int $customerId,
        string $currency,
        int $amount,
        string $reason,
        ?object $source,
        ?string $note = null,
        bool $requireAvailable = true,
        ?int $excludingReservationId = null,
    ): CreditEntry {
        if ($amount <= 0) {
            throw new WalletException('invalid_amount', 'مبلغِ برداشت باید مثبت باشد.');
        }

        return $this->entry($customerId, $currency, -$amount, $reason, $source, $note,
            requireAvailable: $requireAvailable, excludingReservationId: $excludingReservationId);
    }

    /** ردیفِ دفتر + گاردِ در دسترس + `balance_after`ِ مشاوره‌ای */
    private function entry(
        int $customerId,
        string $currency,
        int $signed,
        string $reason,
        ?object $source,
        ?string $note,
        bool $requireAvailable = false,
        ?int $excludingReservationId = null,
    ): CreditEntry {
        $write = function () use (
            $customerId, $currency, $signed, $reason, $source, $note, $requireAvailable, $excludingReservationId
        ): CreditEntry {
            /*
            | قفلِ ردیفِ مشتری — سرِ هر نوشتنِ پولی و اولین قفلِ هر مسیر.
            | بدونِ این، دو مسیرِ هم‌زمان یک جمعِ دفتر را می‌خوانند و هر دو
            | سطر می‌نویسند: برداشتِ دوم روی موجودیِ اول غلط می‌نشیند.
            */
            $fresh = Customer::whereKey($customerId)->lockForUpdate()->first();

            if ($fresh === null) {
                throw new WalletException('account_missing', 'حساب پیدا نشد.');
            }

            $balance = (int) CreditEntry::where('customer_id', $customerId)
                ->where('currency_code', $currency)
                ->sum('amount');

            $reserved = $this->reservedOf($customerId, $currency, $excludingReservationId);

            if ($requireAvailable && ($balance + $signed - $reserved) < 0) {
                throw new WalletException('insufficient_funds',
                    'اعتبارِ در دسترس کافی نیست (در دسترس: '.number_format($balance - $reserved).').');
            }

            return CreditEntry::create([
                'customer_id'   => $customerId,
                'currency_code'  => $currency,
                'amount'         => $signed,
                'balance_after'  => $balance + $signed,   // مشاوره‌ای — حقیقت جمع است
                'reason'         => $reason,
                'source_type'    => $source === null ? null : $source::class,
                'source_id'      => $source?->id ?? null,
                'note'           => $note,
            ]);
        };

        $entry = DB::transaction($write);

        /*
        | 🔴 درآمدِ مصرفِ اعتبار، در **تنها** نقطه‌ای که هر برداشتی از آن رد
        | می‌شود.
        |
        | تا ممیزیِ شهریور ۱۴۰۵ هیچ مصرفِ اعتباری به دفترِ مالی نمی‌رسید:
        | `recordPayment` به پرداختِ متصل‌به‌فاکتور نیاز دارد و مترِ ساعتی
        | هیچ‌کدام را نمی‌سازد. نسخهٔ اولِ رفع، هر سه نقطهٔ کسر را جدا وصل
        | می‌کرد؛ با آمدنِ کیفِ پول آن‌ها یکی شدند و وصل‌کردنِ همین‌جا هم
        | کوتاه‌تر است هم مسیرِ بعدی (توکنِ AI) را از روزِ اول می‌گیرد.
        |
        | ⚠️ فیلتر داخلِ `recordCreditSpend` است، نه این‌جا: شارژ، بازگشتِ وجه
        | و پرداختِ فاکتور از مسیرِ رسمیِ خودشان ثبت می‌شوند و ثبتِ دوباره‌شان
        | یعنی درآمدِ دو برابر.
        |
        | ⚠️ بیرونِ تراکنش و بلعیده: کسر قبلاً قطعی شده و خطای دفتر نباید
        | برش گرداند — نبودِ یک ردیفِ دفتر بد است، نکسرشدنِ پولِ مصرف‌شده بدتر.
        */
        try {
            app(BusinessLedger::class)->recordCreditSpend($entry);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('finance', $e,
                ['area' => 'wallet-revenue', 'entry' => $entry->id]);
        }

        return $entry;
    }
}

/** خطای کیفِ پول — کد ماشین‌خوان برای API و تست */
class WalletException extends \RuntimeException
{
    /*
    | 🔴 نامِ خاصیتِ دامنه عمداً `errorCode` است، نه `code`: Exception خودش
    | `$code` (int) دارد و PHP اجازهٔ redeclare-کردنش به‌صورتِ readonly
    | string را نمی‌دهد (خطایِ fatal در ۸.۳/۸.۴). کدِ دامنهٔ رشته‌ای ما
    | هم همان `Exception::$code` بومی نیست و نباید داخلش برود.
    */
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
