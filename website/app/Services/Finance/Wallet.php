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
        /*
        | 🔴 روی نصبی که جدولِ رزروِ AI را ندارد بی‌صدا رد شو — نه ۵۰۰.
        |
        | سرورِ زنده مهاجرت‌های AI را ندارد (خطای کلیدِ خارجیِ MariaDB) و این
        | متُد سرِ **هر نوشتنِ پولی** صدا زده می‌شود: فاکتور، دامنه، کسرِ ساعتی.
        | بی‌این گارد، نخستین انتشارِ کیفِ پول روی آن سرور کلِ مسیرِ پول را
        | می‌خواباند. همان قاعدهٔ `CloudMeterHourly` که روی نصبِ مهاجرت‌نکرده
        | بی‌صدا برمی‌گردد.
        */
        if (! self::aiReservationsTable()) {
            return $currency === 'IRT' ? \App\Services\Cloud\HourlyHold::heldOf($customerId) : 0;
        }

        $q = AiReservation::where('customer_id', $customerId)
            ->where('currency_code', $currency)
            ->holding();

        if ($excludingReservationId !== null) {
            $q->where('id', '!=', $excludingReservationId);
        }

        /*
        | 🔴 ذخیرهٔ نگهداریِ ۲۴ساعتهٔ سرورهای ساعتی (`HourlyHold`) هم نگه‌دارنده
        | است: پولی که برای نگهداریِ ماشینِ خاموش کنار گذاشته شده، نه فاکتور
        | می‌خوردش، نه دامنه، نه AI. کسرِ خودِ نگهداری پیش از برداشت همان‌قدر
        | از ذخیره کم می‌کند، پس دوبار شمرده نمی‌شود.
        */
        $hold = $currency === 'IRT' ? \App\Services\Cloud\HourlyHold::heldOf($customerId) : 0;

        return (int) $q->sum('amount_irt') + $hold;
    }

    /** جدولِ رزروِ AI یک بار در هر پروسه پرسیده می‌شود (سرِ راهِ هر کسر است) */
    private static ?bool $aiTable = null;

    private static function aiReservationsTable(): bool
    {
        return self::$aiTable ??= \Illuminate\Support\Facades\Schema::hasTable('ai_reservations');
    }

    /** برای تست پس از مهاجرت در همان پروسه */
    public static function flushSchemaCache(): void
    {
        self::$aiTable = null;
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

        return DB::transaction($write);
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
