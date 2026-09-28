<?php

namespace App\Console\Commands;

use App\Models\AiReservation;
use App\Models\AiUsage;
use App\Services\Ai\AiReservations;
use App\Services\Ai\AiSettlement;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * آشتی‌دهندهٔ پولِ AI — هر دقیقه (m5-spec §4.D).
 *
 *   D1 reserved و گذشته از decide_by      ⇒ آزاد (هرگز فرستاده نشد؛ B1 مانعِ ارسالِ دیرهنگام است)
 *   D2 sending/streaming و گذشته از decide_by ⇒ نامعلوم (بازیابی و سقف با ai:recover-usage)
 *   D4 settle_pending                    ⇒ تراکنشِ پول از روی عددهای ثبت‌شده
 *   D5 رزروهای قدیمیِ میکرو (pricing_version=0) ⇒ فقط منقضی، هرگز تسویه
 *   D6 هشدار: ردیفِ باز که ۱۰ دقیقه از decide_by گذشته، یا رزروی نزدیکِ پشتوانهٔ ۲۴ ساعته
 *
 * هر گذار یک UPDATE ِ شرطی است، پس با مسیرِ درخواست مسابقه نمی‌دهد و اجرای دوباره
 * بی‌اثر است. زنده‌بودنش در کش ثبت می‌شود تا نبودنش دیده شود.
 */
class AiReconcile extends Command
{
    protected $signature = 'ai:reconcile';

    protected $description = 'آشتی‌دهندهٔ پولِ AI: آزادسازی/تسویهٔ ردیف‌های گیرکرده (بی‌تماسِ شبکه)';

    public function handle(AiSettlement $settlement, AiReservations $legacy): int
    {
        // کد پیش از مهاجرتِ 000110 روی سرور می‌نشیند و زمان‌بند هر دقیقه صدایش می‌زند
        if (! \Illuminate\Support\Facades\Schema::hasTable('ai_usage')) {
            return self::SUCCESS;
        }

        $released = $unknown = $finalized = $failed = 0;

        AiUsage::where('status', AiUsage::STATUS_RESERVED)->where('decide_by', '<', now())
            ->orderBy('id')->limit(500)->get()
            ->each(function (AiUsage $u) use ($settlement, &$released) {
                $settlement->release($u, 'decide_by_passed');
                $released++;
            });

        AiUsage::whereIn('status', [AiUsage::STATUS_SENDING, AiUsage::STATUS_STREAMING])->where('decide_by', '<', now())
            ->orderBy('id')->limit(500)->get()
            ->each(function (AiUsage $u) use ($settlement, &$unknown) {
                $settlement->markUnknown($u, 'decide_by_passed');
                $unknown++;
            });

        AiUsage::where('status', AiUsage::STATUS_SETTLE_PENDING)->orderBy('id')->limit(500)->pluck('id')
            ->each(function (int $id) use ($settlement, &$finalized, &$failed) {
                try {
                    $settlement->finalize($id);
                    $finalized++;
                } catch (\Throwable $e) {
                    $failed++;
                    ErrorTracker::noteOnce('ai', "تسویهٔ معوقِ ردیفِ مصرفِ AI #{$id} باز هم نشد: ".$e->getMessage(), 3600);
                }
            });

        $expired = $legacy->expirePending();     // فقط ردیف‌های مهلت‌دارِ قدیمی؛ M5 مهلت ندارد

        $stuck = AiUsage::whereIn('status', AiUsage::OPEN)->where('decide_by', '<', now()->subMinutes(10))->count();
        if ($stuck > 0) {
            ErrorTracker::noteOnce('ai', "{$stuck} تماسِ AI بیش از ۱۰ دقیقه از مهلتِ تصمیم گذشته و هنوز باز است — /admin/ai را ببینید.", 3600);
        }

        $nearBackstop = AiReservation::where('status', AiReservation::STATUS_PENDING)
            ->where('pricing_version', AiReservation::PRICING_M5)
            ->where('created_at', '<', now()->subHours(max(1, (int) config('ai.hold_backstop_h', 24) - 1)))->count();
        if ($nearBackstop > 0) {
            ErrorTracker::noteOnce('ai', "{$nearBackstop} رزروِ AI نزدیکِ پشتوانهٔ ۲۴ ساعته است؛ پس از آن پولش بی‌تصمیم آزاد می‌شود.", 3600);
        }

        Cache::put('ai:reconcile:heartbeat', now()->toIso8601String(), now()->addHours(2));

        $this->line("released={$released} unknown={$unknown} finalized={$finalized} failed={$failed} legacy_expired={$expired} stuck={$stuck}");

        return self::SUCCESS;
    }
}
