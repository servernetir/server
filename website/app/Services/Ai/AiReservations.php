<?php

namespace App\Services\Ai;

use App\Models\AiReservation;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletException;
use Illuminate\Support\Facades\DB;

/**
 * رزروِ اعتبار برای درخواست‌های AI — نگه‌داشتهٔ هم‌زمان-امن.
 *
 * ═══ حسابِ موجودی ═══
 *
 * موجودیِ در دسترس = جمعِ دفتر − جمعِ رزروهایِ نگه‌دارنده (pending و
 * مهلت‌دار). رزرو خودش پول جابه‌جا نمی‌کند؛ فقط این کمین را «قفل‌شده»
 * نگه می‌دارد تا دو درخواستِ هم‌زمان یک موجودی را دو بار خرج نکنند.
 *
 * ═══ ترتیبِ قفل (همهٔ عملیات، یکی) ═══
 *
 *   ۱) ردیفِ مشتری (`lockForUpdate`) — همان الگویِ `payCredit` و
 *      سفارشِ نمایندگی؛ سرِ این قفل دو رزروِ هم‌زمان سریالی می‌شوند.
 *   ۲) ردیفِ رزرو (`lockForUpdate`) — سرِ این قفل settle-vs-release
 *      هم‌زمان یکی برنده می‌شود و دیگری «قبلاً» می‌گیرد.
 *
 * مشتری همیشه اول است؛ مسیرهایِ پولیِ دیگرِ سیستم هم مشتری را اول
 * می‌گیرند، پس تلاقی deadlock نمی‌سازد.
 *
 * ═══ ماشینِ حالت (کوچک‌ترین) ═══
 *
 *   pending → settled   (تسویه: دقیقاً یک سطرِ منفیِ دفتر)
 *   pending → released  (آزادسازی: پول برمی‌گردد به در دسترس — بی‌دفتر)
 *   pending → expired   (انقضا: پولِ مهلت‌گذشته آزاد می‌شود — بی‌دفتر)
 *
 * هر گذار با یک UPDATEِ شرطی روی `status='pending'` است — تکرارش
 * هیچ اثرِ مالیِ دومی ندارد. settled/released/expired پایانی‌اند.
 *
 * ═══ چه چیزی این‌جا نیست (عمداً) ═══
 *
 * هیچ تماسِ ارائه‌دهنده‌ای، هیچ مسیرِ عمومی، هیچ محاسبهٔ قیمتِ مدل.
 * این کلاس فقط ظرفِ پولِ درخواستِ AI است؛ M4 صدازدن و مسیرسازی رویش
 * می‌نشیند.
 */
class AiReservations
{
    public function __construct(private Wallet $wallet) {}

    /** جمعِ رزروهایِ نگه‌دارندهٔ پول همین لحظه — تعریفِ واحد در Wallet */
    public function heldOf(int $customerId, string $currency = 'IRT'): int
    {
        return $this->wallet->reservedOf($customerId, $currency);
    }

    /** موجودیِ در دسترس = دفتر − نگه‌داشته‌ها — تعریفِ واحد در Wallet */
    public function availableOf(int $customerId, string $currency = 'IRT'): int
    {
        return $this->wallet->availableOf($customerId, $currency);
    }

    /**
     * رزروِ تازه — یا همانِ قبلی با همان کلید.
     *
     * 🔴 چکِ موجودی داخلِ قفلِ مشتری است، نه بیرونش: دو درخواستِ هم‌زمان
     *    سریالی می‌شوند و دومی موجودیِ منهایِ رزروِ اولی را می‌بیند.
     *
     * @param  \DateTimeInterface|null  $expiresAt  نال = بدونِ مهلت
     */
    public function reserve(
        int $customerId,
        int $amountIrt,
        ?string $idempotencyKey = null,
        ?\DateTimeInterface $expiresAt = null,
        string $purpose = 'ai',
        ?string $reference = null,
    ): ReserveOutcome {
        if ($amountIrt <= 0) {
            return ReserveOutcome::fail('invalid_amount', 'مبلغِ رزرو باید مثبت باشد.');
        }

        $idempotencyKey = ($idempotencyKey === '') ? null : $idempotencyKey;

        /*
        | idempotency روی قیدِ یکتای دیتابیس، نه `if` کوئری‌محور — همان
        | درسِ سفارشِ نمایندگی: بینِ خواندن و نوشتن پنجرهٔ رقابت نیست،
        | ولی کلیدِ تکراری به دیوارِ یکتاییِ دیتابیس می‌خورد و همین‌جا
        | سطرِ موجود برگردانده می‌شود.
        */
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = AiReservation::where('customer_id', $customerId)
                ->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return new ReserveOutcome(true, 'duplicate', $existing, true);
            }
        }

        try {
            $reservation = DB::transaction(function () use (
                $customerId, $amountIrt, $idempotencyKey, $expiresAt, $purpose, $reference
            ): AiReservation {
                // قفلِ ۱: مشتری — سرِ این قفلِ دو رزروِ هم‌زمان یکی می‌بَرد
                \App\Models\Customer::whereKey($customerId)->lockForUpdate()->first();

                $available = $this->availableOf($customerId);

                if ($available < $amountIrt) {
                    throw new WalletException('insufficient_funds',
                        'اعتبارِ در دسترس کافی نیست (در دسترس: '.number_format($available).').');
                }

                return AiReservation::create([
                    'customer_id'     => $customerId,
                    'currency_code'   => 'IRT',
                    'amount_irt'      => $amountIrt,
                    'status'          => AiReservation::STATUS_PENDING,
                    'idempotency_key' => $idempotencyKey,
                    'purpose'         => $purpose,
                    'reference'       => $reference,
                    'expires_at'      => $expiresAt,
                ]);
            });
        } catch (WalletException $e) {
            return ReserveOutcome::fail($e->errorCode, $e->getMessage());
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // دو reserveِ هم‌زمان با یک کلید — برندهٔ دیگری را برگردان
            $existing = AiReservation::where('customer_id', $customerId)
                ->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return new ReserveOutcome(true, 'duplicate', $existing, true);
            }

            return ReserveOutcome::fail('idempotency_conflict', 'کلیدِ تکراری با نتیجهٔ نامعلوم.');
        }

        return new ReserveOutcome(true, 'reserved', $reservation, false);
    }

    /**
     * تسویه — تبدیلِ نگه‌داشته به خرجِ واقعی.
     *
     * دقیقاً یک سطرِ منفیِ دفتر می‌نویسد (Wallet::debit با گاردِ رزروآگاهِ
     * «در دسترس» + exclusionِ رزروِ خودش — پولِ رزروشده پیش‌خرج است)
     * و `ledger_entry_id` ردش را نگه می‌دارد.
     * تکرارش هیچ اثرِ دومی ندارد.
     */
    public function settle(AiReservation $reservation): TransitionOutcome
    {
        return $this->transition($reservation, function (AiReservation $r): AiReservation {
            if ($r->status === AiReservation::STATUS_SETTLED) {
                return $r;   // idempotent — دفتر دو بار خرج نمی‌شود
            }

            if ($r->status === AiReservation::STATUS_RELEASED) {
                throw new WalletException('reservation_released',
                    'رزروِ آزادشده قابلِ تسویه نیست — پولش برگشته است.');
            }

            if ($r->status === AiReservation::STATUS_EXPIRED) {
                throw new WalletException('reservation_expired',
                    'مهلتِ رزرو گذشته و پولش آزاد شده — تسویه‌اش کارِ ممیزی است.');
            }

            /*
            | pending ولی مهلت‌گذشته: پولش از «در دسترس» بیرون آمده و ممکن
            | است رزروِ دیگری نشسته باشد؛ تسویهٔ دیرهنگام می‌توانست موجودی را
            | منفی کند. رد — خرجِ واقعیِ دیرهنگام از درزِ ممیزی (M5) می‌گذرد.
            */
            if ($r->expires_at !== null && $r->expires_at->isPast()) {
                throw new WalletException('reservation_expired',
                    'مهلتِ رزرو گذشته است — تسویهٔ دیرهنگام پذیرفته نیست.');
            }

            /*
            | 🔴 گاردِ تسویه — نه فرارِ عمومی از چک، بلکه چکِ درست:
            | `excludingReservationId` رزروِ خودش را از «نگه‌داشته» کنار
            | می‌گذارد (دوبار کم نمی‌شود) ولی گارد روی بقیهٔ نگه‌دارنده‌ها
            | می‌مانَد. چون از M3-correct همهٔ برداشت‌هایِ عادی از
            | `available` می‌خورند، هیچ مسیری نمی‌توانسته پولِ این رزرو را
            | خرج کند — و اگر باگِ آینده‌ای بتواند، این گارد تسویه را رد
            | می‌کند، نه اینکه منفی بنویسد.
            */
            $entry = $this->wallet->debit($r->customer_id, $r->currency_code, $r->amount_irt,
                'ai_reservation', $r,
                'تسویهٔ رزروِ AI #'.$r->id,
                requireAvailable: true, excludingReservationId: $r->id);

            return $r->forceFill([
                'status'          => AiReservation::STATUS_SETTLED,
                'settled_at'      => now(),
                'ledger_entry_id' => $entry->id,
            ]);
        });
    }

    /**
     * آزادسازی — پولِ نگه‌دارنده برمی‌گردد به «در دسترس».
     *
     * هیچ سطری در دفتر نمی‌نویسد (پول هیچ‌وقت از دفتر خارج نشده بود) پس
     * تکرارش هم هیچ اثرِ مالیِ دومی ندارد. رزروِ تسویه‌شده آزاد نمی‌شود:
     | پولش خرجِ واقعی شده — release نتواند پولِ خرج‌شده را «بازگرداند».
     */
    public function release(AiReservation $reservation): TransitionOutcome
    {
        return $this->transition($reservation, function (AiReservation $r): AiReservation {
            if ($r->status === AiReservation::STATUS_RELEASED) {
                return $r;   // idempotent — دوباره آزاد نمی‌کند
            }

            if ($r->status === AiReservation::STATUS_SETTLED) {
                throw new WalletException('reservation_settled',
                    'رزروِ تسویه‌شده آزاد نمی‌شود — پولش خرج شده است.');
            }

            if ($r->status === AiReservation::STATUS_EXPIRED) {
                throw new WalletException('reservation_expired',
                    'مهلتِ رزرو گذشته و پولش از قبل آزاد شده است.');
            }

            return $r->forceFill([
                'status'    => AiReservation::STATUS_RELEASED,
                'released_at' => now(),
            ]);
        });
    }

    /**
     * انقضایِ دسته‌ای — برای کرونِ آینده و درزِ ممیزی.
     *
     * فقط pendingهایِ مهلت‌گذشته را expired می‌کند؛ settled/released
     * را دست نمی‌زند (پولِ تسویه‌شده آزاد نمی‌شود). تکرار-آمیز است.
     *
     * @return int شمارِ ردیف‌هایی که این بار منقضی شدند
     */
    public function expirePending(): int
    {
        return AiReservation::query()
            ->where('status', AiReservation::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status'     => AiReservation::STATUS_EXPIRED,
                'expired_at' => now(),
            ]);
    }

    /**
     * قلبِ هر گذار — تراکنش + قفلِ مشتری + قفلِ ردیفِ رزرو.
     *
     * تکرارِ settle/release از هر دو محافظت می‌کند: شرطِ داخلِ قفلِ
     * `status` را **دوباره** می‌خواند (بینِ بررسیِ بیرونی و رسیدن به
     * این‌جا، یک فراخوانِ موازی می‌تواند گذار را کرده باشد) و برندهٔ
     * مسابقه حکمِ «قبلاً» می‌گیرد.
     *
     * @param  callable(AiReservation): AiReservation  $step
     */
    private function transition(AiReservation $reservation, callable $step): TransitionOutcome
    {
        try {
            [$r, $already] = DB::transaction(function () use ($reservation, $step): array {
                // قفلِ ۱: مشتری — همان ترتیبِ reserve، سرِ هم‌گراییِ دو مسیر
                \App\Models\Customer::whereKey($reservation->customer_id)->lockForUpdate()->first();

                // قفلِ ۲: ردیفِ رزرو — سرِ settle-vs-release
                /** @var AiReservation $r */
                $r = AiReservation::whereKey($reservation->id)->lockForUpdate()->first();

                if ($r === null) {
                    throw new WalletException('reservation_missing', 'رزرو پیدا نشد.');
                }

                $before = $r->status;
                $next = $step($r);

                if ($next->isDirty()) {
                    $next->save();
                }

                // «قبلاً» = وضعیت عوض نشد: یا تکرارِ همان گذار بود یا هیچ
                return [$next, $before === $next->status];
            });

            return new TransitionOutcome(true, 'ok', $r, $already);
        } catch (WalletException $e) {
            return TransitionOutcome::fail($e->errorCode, $e->getMessage());
        }
    }
}

/** نتیجهٔ رزرو — ok یعنی رزروِ pending یا همانِ قبلیِ موجود */
final readonly class ReserveOutcome
{
    public function __construct(
        public bool $ok,
        public string $code,                 // reserved | duplicate | insufficient_funds | ...
        public ?AiReservation $reservation = null,
        public bool $already = false,        // کلیدِ تکراری: همانِ قبلی برگشت
        public string $message = '',
    ) {}

    public static function fail(string $code, string $message): self
    {
        return new self(false, $code, null, false, $message);
    }
}

/** نتیجهٔ گذار — settle/release؛ already یعنی هیچ اثرِ مالیِ دومی نبود */
final readonly class TransitionOutcome
{
    public function __construct(
        public bool $ok,
        public string $code,
        public ?AiReservation $reservation = null,
        public bool $already = false,
        public string $message = '',
    ) {}

    public static function fail(string $code, string $message): self
    {
        return new self(false, $code, null, false, $message);
    }
}
