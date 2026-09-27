<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\AiReservation;
use App\Models\AiUsage;
use App\Models\Customer;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletException;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\DB;

/**
 * تسویهٔ پولِ یک تماس — تنها جایی که پولِ AI از کیفِ مشتری بیرون می‌رود (m5-spec §4.C، §4.G).
 *
 * ═══ دو قدم، عمداً ═══
 *
 *  ۱) **ثبتِ اول، بیرون از تراکنشِ پول** (C8): همهٔ عددهای محاسبه‌شده روی ردیفِ
 *     `ai_usage` با وضعیتِ `settle_pending` می‌نشینند.
 *  ۲) **تراکنشِ پول** (C9): قفلِ مشتری ← رزرو ← ردیف؛ یک سطرِ دفتر؛ رزرو و ردیف بسته.
 *
 * اگر قدمِ ۲ پس از سه تلاش هم نشد (بن‌بست/قفل)، ردیف `settle_pending` با پولِ هنوز
 * نگه‌داشته می‌مانَد و آشتی‌دهنده از روی همان عددهای ثبت‌شده تمامش می‌کند — و
 * مشتری **پاسخش را می‌گیرد** (هرگز ۵xx پس از موفقیتِ بالادست، G5).
 *
 * ═══ خطِ قرمز ═══
 *
 * شارژ = قیمت × مصرفِ واقعی، به تومان، همه رو به بالا (`AiPricing::charge`). اگر
 * مصرف از سقف بیرون زد (فقط اگر توکن‌شماریِ ورودی از حدِ بایتی بگذرد)، شارژ **سقف
 * نمی‌خورد**: مازاد دومین برداشت است، مدل معلق می‌شود و فقط اگر کیف خالی باشد
 * باقی‌مانده «وصول‌نشده» ثبت می‌شود — رخدادِ اعتباری، نه فروشِ زیرِ بها (MS-1).
 * فروش < بها با اثباتِ §3 ناممکن است؛ اگر روزی رخ داد، مدل معلق و خبر می‌شود.
 *
 * هر گذار یک UPDATE ِ شرطی روی وضعیتِ قبلی است — درخواست و آشتی‌دهنده هرگز یک
 * ردیف را دو بار تسویه نمی‌کنند؛ `credit_ledger_id` یکتاست.
 */
final class AiSettlement
{
    public function __construct(
        private readonly AiPricing $pricing,
        private readonly Wallet $wallet,
    ) {}

    /** reserved ⇒ sending. صفر ردیف یعنی آشتی‌دهنده آزادش کرده: **نفرست** (B1) */
    public function markSending(AiUsage $u): bool
    {
        $n = AiUsage::whereKey($u->id)->where('status', AiUsage::STATUS_RESERVED)
            ->update(['status' => AiUsage::STATUS_SENDING, 'sent_at' => now(), 'updated_at' => now()]);

        return $n === 1;
    }

    /** ⇒ unknown_pending: پول نگه داشته می‌شود تا بازیابی یا سقف (G1) */
    public function markUnknown(AiUsage $u, string $code, ?int $status = null): void
    {
        AiUsage::whereKey($u->id)
            ->whereIn('status', [AiUsage::STATUS_RESERVED, AiUsage::STATUS_SENDING, AiUsage::STATUS_STREAMING])
            ->update([
                'status' => AiUsage::STATUS_UNKNOWN_PENDING, 'error_code' => $code,
                'upstream_status' => $status, 'recover_attempts' => 0, 'updated_at' => now(),
            ]);
    }

    /**
     * آزادسازی — شارژِ صفر، بی‌سطرِ دفتر، کلیدِ هم‌ارزی آزاد (تکرار مجاز).
     * فقط از حالت‌های باز؛ ردیفِ تسویه‌شده هرگز «آزاد» نمی‌شود.
     */
    public function release(AiUsage $u, string $code, ?int $upstreamStatus = null): void
    {
        DB::transaction(function () use ($u, $code, $upstreamStatus) {
            Customer::whereKey($u->customer_id)->lockForUpdate()->first();

            $row = AiUsage::whereKey($u->id)->lockForUpdate()->first();
            if ($row === null || ! in_array($row->status, [
                AiUsage::STATUS_RESERVED, AiUsage::STATUS_SENDING, AiUsage::STATUS_STREAMING, AiUsage::STATUS_UNKNOWN_PENDING,
            ], true)) {
                return;
            }

            AiReservation::whereKey($row->ai_reservation_id)
                ->where('status', AiReservation::STATUS_PENDING)
                ->update([
                    'status' => AiReservation::STATUS_RELEASED, 'released_at' => now(),
                    'idempotency_key' => null, 'charged_irt' => 0, 'updated_at' => now(),
                ]);

            $row->forceFill([
                'status' => AiUsage::STATUS_RELEASED, 'error_code' => $code,
                'upstream_status' => $upstreamStatus ?? $row->upstream_status,
                'charged_irt' => 0, 'settled_at' => now(),
            ])->save();
        }, 3);
    }

    /**
     * تسویه از مصرفِ واقعی (C2–C9).
     *
     * @param  array{prompt:int,completion:int,cached:int,reasoning:?int,estimated_cost:?string}  $usage
     */
    public function settleFromUsage(AiUsage $u, array $usage, ?string $requestId = null, ?int $latencyMs = null): AiUsage
    {
        $fresh = $u->fresh();

        // آشتی‌دهنده پیش‌تر سقف را شارژ کرده؟ مصرفِ واقعی آمد ⇒ بازپرداختِ اختلاف (G4)
        if ($fresh?->status === AiUsage::STATUS_UNKNOWN_CHARGED) {
            $this->refundDown($fresh, $usage, 'provider');

            return $fresh->fresh();
        }

        $calc = $this->pricing->charge($u->quote(), $usage['prompt'], $usage['cached'], $usage['completion'],
            (int) $u->vat_bp, $usage['estimated_cost']);

        [$review, $reason] = $this->review($calc, (int) $u->hold_irt);

        $n = AiUsage::whereKey($u->id)
            ->whereIn('status', [AiUsage::STATUS_SENDING, AiUsage::STATUS_STREAMING, AiUsage::STATUS_UNKNOWN_PENDING])
            ->update([
                'status' => AiUsage::STATUS_SETTLE_PENDING,
                'prompt_tokens' => $usage['prompt'],
                'cached_tokens' => $calc['c'],
                'completion_tokens' => $usage['completion'],
                'reasoning_tokens' => $usage['reasoning'],
                'usage_source' => $u->status === AiUsage::STATUS_UNKNOWN_PENDING ? 'recovered' : 'provider',
                'cost_micro' => $calc['cost_micro'],
                'provider_cost_micro' => $calc['estimated_micro'],
                'cost_irt' => $calc['cost_irt'],
                'sell_irt' => $calc['sell'],
                'tax_irt' => $calc['tax'],
                'charged_irt' => $calc['charged'],
                'needs_review' => $review,
                'review_reason' => $reason,
                'upstream_status' => 200,
                'upstream_request_id' => $requestId !== null ? mb_substr($requestId, 0, 120) : null,
                'latency_ms' => $latencyMs,
                'updated_at' => now(),
            ]);

        if ($n !== 1) {
            return $u->fresh();      // کسِ دیگری تصمیم گرفته (آزاد/تسویه) — هیچ اثرِ دومی نه
        }

        return $this->finalize($u->id);
    }

    /**
     * نامعلوم پس از پنجرهٔ بازیابی ⇒ شارژ تا سقفِ رزرو (G3؛ تصمیمِ مالک: «هرگز جذب نکن»).
     * بهای ثبت‌شده بدترین حالت است: I·r_in + O·r_out. بازپرداختِ خودکار تا ۷ روز.
     */
    public function settleCap(AiUsage $u): AiUsage
    {
        $calc = $this->pricing->charge($u->quote(), (int) $u->max_input_tokens, 0, (int) $u->max_output_tokens, (int) $u->vat_bp);

        $n = AiUsage::whereKey($u->id)->where('status', AiUsage::STATUS_UNKNOWN_PENDING)->update([
            'status' => AiUsage::STATUS_SETTLE_PENDING,
            'usage_source' => 'cap',
            'cost_micro' => $calc['cost_micro'],
            'cost_irt' => $calc['cost_irt'],
            'sell_irt' => $u->hold_sell_irt,
            'tax_irt' => $u->hold_tax_irt,
            'charged_irt' => $u->hold_irt,
            'needs_review' => true,
            'review_reason' => 'unknown',
            'updated_at' => now(),
        ]);

        return $n === 1 ? $this->finalize($u->id) : $u->fresh();
    }

    /**
     * C9 — تراکنشِ پول از روی عددهای ثبت‌شده. هم مسیرِ درخواست و هم آشتی‌دهنده (D4)
     * صدایش می‌زنند؛ فقط ردیفِ `settle_pending` را تمام می‌کند.
     */
    public function finalize(int $usageId): AiUsage
    {
        $customerId = (int) AiUsage::whereKey($usageId)->value('customer_id');

        /** @var array{0:AiUsage,1:bool} $out */
        $out = DB::transaction(function () use ($usageId, $customerId): array {
            Customer::whereKey($customerId)->lockForUpdate()->first();

            /** @var AiUsage $u */
            $u = AiUsage::whereKey($usageId)->lockForUpdate()->first();
            if ($u->status !== AiUsage::STATUS_SETTLE_PENDING) {
                return [$u, false];
            }

            $r = AiReservation::whereKey($u->ai_reservation_id)->lockForUpdate()->first();
            if ($r === null || $r->status !== AiReservation::STATUS_PENDING || (int) $r->pricing_version !== AiReservation::PRICING_M5) {
                throw new \RuntimeException("رزروِ ردیفِ مصرفِ {$u->public_id} باز نیست؛ تسویه برای بازبینی ماند.");
            }

            $charged = (int) $u->charged_irt;
            $base = min($charged, (int) $u->hold_irt);
            $over = $charged - $base;
            $note = 'AI '.$u->model_slug.' '.$u->public_id;

            $entry = $base > 0
                ? $this->wallet->debit($customerId, 'IRT', $base, 'ai_usage', $u, $note,
                    requireAvailable: true, excludingReservationId: $r->id)
                : null;

            [$overEntry, $uncollected] = $over > 0 ? $this->collectOverage($customerId, $u, $r, $over) : [null, 0];

            $r->forceFill([
                'status' => $charged > 0 ? AiReservation::STATUS_SETTLED : AiReservation::STATUS_RELEASED,
                'settled_at' => $charged > 0 ? now() : null,
                'released_at' => $charged > 0 ? null : now(),
                'charged_irt' => $charged,
                'ledger_entry_id' => $entry?->id,
            ])->save();

            $u->forceFill([
                'status' => $u->usage_source === 'cap' ? AiUsage::STATUS_UNKNOWN_CHARGED : AiUsage::STATUS_SETTLED,
                'credit_ledger_id' => $entry?->id,
                'overage_ledger_id' => $overEntry?->id,
                'uncollected_irt' => $uncollected,
                'settled_at' => now(),
                'day' => now()->setTimezone('Asia/Tehran')->toDateString(),
            ])->save();

            return [$u, true];
        }, 3);

        [$u, $done] = $out;

        if ($done && in_array($u->review_reason, ['over_bound', 'below_cost'], true)) {
            $this->suspendModel($u);
        }
        if ($done && $u->review_reason === 'cost_drift') {
            ErrorTracker::noteOnce('ai', "بهای گزارش‌شدهٔ ارائه‌دهنده برای «{$u->model_slug}» بیش از ۱٪ بالاتر از سطرِ قیمتِ ماست — سطرِ قیمت احتمالاً کهنه است.", 3600);
        }

        return $u;
    }

    /**
     * بازپرداختِ خودکار تا مصرفِ واقعی (G4) — هرگز برداشتِ اضافه: مشتری H را مجاز
     * کرده بود و بیش از آن نه.
     *
     * @return int مبلغِ بازپرداخت‌شده
     */
    public function refundDown(AiUsage $u, array $usage, string $source = 'recovered'): int
    {
        if ($u->status !== AiUsage::STATUS_UNKNOWN_CHARGED || $u->usage_source !== 'cap') {
            return 0;
        }

        $calc = $this->pricing->charge($u->quote(), $usage['prompt'], $usage['cached'], $usage['completion'],
            (int) $u->vat_bp, $usage['estimated_cost'] ?? null);
        $diff = (int) $u->charged_irt - (int) $u->refunded_irt - $calc['charged'];

        return DB::transaction(function () use ($u, $usage, $calc, $diff, $source): int {
            Customer::whereKey($u->customer_id)->lockForUpdate()->first();
            $row = AiUsage::whereKey($u->id)->lockForUpdate()->first();
            if ($row->usage_source !== 'cap') {
                return 0;                          // کسِ دیگری پیش‌تر بازپرداخت کرده
            }

            if ($diff > 0) {
                $this->wallet->credit($row->customer_id, 'IRT', $diff, 'ai_refund', $row,
                    'بازپرداختِ خودکارِ AI '.$row->public_id.' (مصرفِ واقعی پیدا شد)');
            }

            $row->forceFill([
                'usage_source' => $source === 'provider' ? 'provider' : 'recovered',
                'prompt_tokens' => $usage['prompt'], 'cached_tokens' => $calc['c'],
                'completion_tokens' => $usage['completion'], 'reasoning_tokens' => $usage['reasoning'] ?? null,
                'cost_micro' => $calc['cost_micro'], 'cost_irt' => $calc['cost_irt'],
                'sell_irt' => $calc['sell'], 'tax_irt' => $calc['tax'],
                'refunded_irt' => (int) $row->refunded_irt + max(0, $diff),
                'review_reason' => $diff > 0 ? 'recovered_refund' : 'unknown',
            ])->save();

            return max(0, $diff);
        }, 3);
    }

    /* ─────────────────────────────────────────────────────────── */

    /** @return array{0:bool,1:?string} */
    private function review(array $calc, int $hold): array
    {
        return match (true) {
            $calc['sell'] < $calc['cost_irt'] => [true, 'below_cost'],
            $calc['charged'] > $hold => [true, 'over_bound'],
            $calc['cost_drift'] => [true, 'cost_drift'],
            default => [false, null],
        };
    }

    /**
     * مازاد بر سقف (C6): برداشتِ دوم از «در دسترس»؛ اگر کیف کم بود، هر چه هست، و
     * باقی «وصول‌نشده». کیفِ مشترک (دامنه، سرور، نمایندگی) هرگز منفی نمی‌شود.
     *
     * @return array{0:?\App\Models\CreditEntry,1:int}
     */
    private function collectOverage(int $customerId, AiUsage $u, AiReservation $r, int $over): array
    {
        $note = 'مازادِ AI '.$u->model_slug.' '.$u->public_id;

        try {
            return [$this->wallet->debit($customerId, 'IRT', $over, 'ai_usage_overage', $u, $note,
                requireAvailable: true, excludingReservationId: $r->id), 0];
        } catch (WalletException) {
            $available = $this->wallet->balanceOf($customerId, 'IRT')
                - $this->wallet->reservedOf($customerId, 'IRT', $r->id);
            $take = max(0, min($over, $available));

            $entry = $take > 0
                ? $this->wallet->debit($customerId, 'IRT', $take, 'ai_usage_overage', $u, $note,
                    requireAvailable: true, excludingReservationId: $r->id)
                : null;

            return [$entry, $over - $take];
        }
    }

    private function suspendModel(AiUsage $u): void
    {
        AiModel::whereKey($u->ai_model_id)->whereNull('suspended_at')->update([
            'suspended_at' => now(),
            'suspended_reason' => mb_substr('خودکار: '.$u->review_reason.' در '.$u->public_id, 0, 120),
            'updated_at' => now(),
        ]);

        ErrorTracker::noteOnce('ai', "مدلِ «{$u->model_slug}» خودکار معلق شد ({$u->review_reason}، {$u->public_id}). "
            .'پیش از برداشتنِ تعلیق سطرِ قیمت و سقفِ رزرو را بررسی کنید.', 3600);
    }
}
