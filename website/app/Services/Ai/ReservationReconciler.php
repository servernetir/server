<?php

namespace App\Services\Ai;

use App\Models\AiReservation;
use Illuminate\Support\Facades\Log;

/**
 * درزِ ممیزیِ رزروها — M3 فقط «درز» می‌سازد، نه خطِ ممیزی.
 *
 * 🔴 این کلاس عمداً کوچک است. ممیزیِ کامل (گزارشِ خرج/تسویه/ناهم‌خوانیِ
 *    AI، داشبوردِ ادمین، تطبیقِ با ارائه‌دهنده) کارِ M5 است. فعلاً فقط
 *    دو کارِ ایمن که هیچ‌کدام پول جابه‌جا نمی‌کنند:
 *
 *   ۱) `sweepExpired()` — pendingهایِ مهلت‌گذشته را expired می‌کند تا
 *      وضعیتِ سطرها با «در دسترس» هم‌گام شود. پولِ هیچ‌کس را آزاد
 *      نمی‌کند (پولِ مهلت‌گذشته از قبل در `holding()` حساب نمی‌شود)؛
 *      فقط سطر را صادق می‌کند.
 *   ۲) `driftReport()` — شمارشِ خامِ ناهم‌خوانی‌هایِ ساختاری (settled
 *      بی‌ردیفِ دفتر، ترمینال بی‌مُهرِ زمان) برای چشمِ آدم در ممیزی.
 *
 * کرونِ آینده هر ساعت/روز `sweepExpired()` را صدا می‌زند؛ فعلاً
 * فراخوانِ دستی/تست است.
 */
class ReservationReconciler
{
    public function __construct(private AiReservations $reservations) {}

    /** انقضایِ دسته‌ایِ ایمن — تکرار-آمیز، بی‌جابه‌جاییِ پول */
    public function sweepExpired(): int
    {
        $n = $this->reservations->expirePending();

        if ($n > 0) {
            Log::info('رزروهایِ منقضی علامت خوردند', ['count' => $n]);
        }

        return $n;
    }

    /**
     * گزارشِ خامِ ناهم‌خوانی — فقط شمارش، بدونِ هیچ اصلاحِ خودکار.
     *
     * @return array<string,int>
     */
    public function driftReport(): array
    {
        return [
            'settled_without_ledger' => AiReservation::query()
                ->where('status', AiReservation::STATUS_SETTLED)
                ->whereNull('ledger_entry_id')->count(),
            'terminal_without_stamp' => AiReservation::query()
                ->whereIn('status', AiReservation::STATUS_TERMINAL)
                ->where(fn ($q) => $q
                    ->where(fn ($w) => $w->where('status', AiReservation::STATUS_SETTLED)->whereNull('settled_at'))
                    ->orWhere(fn ($w) => $w->where('status', AiReservation::STATUS_RELEASED)->whereNull('released_at'))
                    ->orWhere(fn ($w) => $w->where('status', AiReservation::STATUS_EXPIRED)->whereNull('expired_at')))
                ->count(),
        ];
    }
}
