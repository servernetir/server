<?php

namespace App\Console\Commands;

use App\Models\AiCall;
use App\Models\AiUsage;
use App\Services\Ai\AiNativeResponseRecovery;
use App\Services\Ai\AiSettlement;
use App\Services\Ai\AiUsageParser;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * بازیابیِ مصرفِ تماس‌های نامعلوم (m5-spec §4.G) — هر ۵ دقیقه؛ `--late` روزانه.
 *
 * تصمیمِ مالک: تماسی که به ارائه‌دهنده رسید و پاسخش نیامد، **به بهای واقعی** شارژ
 * می‌شود و تا سقفِ رزرو؛ هزینهٔ بالادست هرگز جذب نمی‌شود. پس:
 *
 *   G2 تا ۳ بار: مصرفِ واقعی از نشانیِ `usage_lookup_url` ِ ارائه‌دهنده (اگر تعریف شده و
 *      شناسهٔ درخواست داریم). پیدا شد ⇒ تسویه به همان مصرف، نه سقف.
 *   G3 پس از ۳ بار (~۱۵ دقیقه): شارژ تا سقفِ رزرو، `needs_review='unknown'`.
 *   G4 (`--late`، تا ۷ روز): اگر مصرفِ واقعی بعداً پیدا شد، **بازپرداختِ خودکار** تا همان
 *      مقدار — هرگز برداشتِ اضافه.
 *
 * ⚠️ نشانیِ جست‌وجوی مصرفِ DeepInfra تأیید نشده است؛ ستونش NULL می‌مانَد تا آزمایشِ
 * زنده ثابتش کند، و آن‌وقت مسیرِ نامعلوم مستقیم به سقف می‌رود (m5-spec، فرض‌های تأییدنشده).
 */
class AiRecoverUsage extends Command
{
    protected $signature = 'ai:recover-usage {--late : بازپرداختِ خودکارِ ردیف‌های سقف‌خورده تا ۷ روز}';

    protected $description = 'بازیابیِ مصرفِ واقعیِ تماس‌های نامعلوم؛ سپس شارژ تا سقف یا بازپرداختِ اختلاف';

    public function handle(AiSettlement $settlement, AiNativeResponseRecovery $stored): int
    {
        // کد پیش از مهاجرتِ 000110 روی سرور می‌نشیند و زمان‌بند هر دقیقه صدایش می‌زند
        if (! \Illuminate\Support\Facades\Schema::hasTable('ai_usage')) {
            return self::SUCCESS;
        }

        return $this->option('late') ? $this->late($settlement, $stored) : $this->pending($settlement, $stored);
    }

    private function pending(AiSettlement $settlement, AiNativeResponseRecovery $stored): int
    {
        $max = (int) config('ai.unknown_recovery_max_attempts', 3);
        $recovered = $capped = 0;

        AiUsage::where(function ($q) {
            $q->where('status', AiUsage::STATUS_UNKNOWN_PENDING)->orWhere(function ($q) {
                // A crash after settlement must still promote its retained response later.
                $q->where('operation', 'native')->whereIn('status', [AiUsage::STATUS_SETTLE_PENDING, ...AiUsage::CHARGED])
                    ->whereIn('ai_reservation_id', AiCall::where('ok', false)
                        ->where('created_at', '>=', AiNativeResponseRecovery::expiresBefore())->select('ai_reservation_id'));
            });
        })->with('provider')
            ->orderByRaw("CASE status WHEN 'unknown_pending' THEN 0 WHEN 'settle_pending' THEN 1 ELSE 2 END")
            ->orderBy('id')->limit(200)->get()
            ->each(function (AiUsage $u) use ($settlement, $stored, $max, &$recovered, &$capped) {
                try {
                    if ($stored->recover($u)) {
                        $recovered++;
                        return;
                    }
                    if ($u->status !== AiUsage::STATUS_UNKNOWN_PENDING) return;
                    $usage = $this->probe($u);
                    if ($usage !== null) {
                        $settlement->settleFromUsage($u, $usage, $u->upstream_request_id);
                        $recovered++;

                        return;
                    }

                    $u->increment('recover_attempts');
                    if ((int) $u->recover_attempts >= $max) {
                        $settlement->settleCap($u->fresh());
                        $capped++;
                    }
                } catch (\Throwable $e) {
                    ErrorTracker::noteOnce('ai', "بازیابیِ مصرفِ AI {$u->public_id} شکست خورد: ".$e->getMessage(), 3600);
                }
            });

        $this->line("recovered={$recovered} capped={$capped}");

        return self::SUCCESS;
    }

    private function late(AiSettlement $settlement, AiNativeResponseRecovery $stored): int
    {
        $refunded = 0;

        AiUsage::where('status', AiUsage::STATUS_UNKNOWN_CHARGED)->where('usage_source', 'cap')
            ->where('settled_at', '>=', now()->subDays((int) config('ai.unknown_recovery_days', 7)))
            ->with('provider')->orderBy('id')->limit(500)->get()
            ->each(function (AiUsage $u) use ($settlement, $stored, &$refunded) {
                try {
                    if ($stored->recover($u)) {
                        $refunded += (int) $u->fresh()->refunded_irt - (int) $u->refunded_irt;
                        return;
                    }
                    $usage = $this->probe($u);
                    if ($usage !== null) $refunded += $settlement->refundDown($u, $usage);
                } catch (\Throwable $e) {
                    ErrorTracker::noteOnce('ai', "بازیابیِ دیرهنگامِ AI {$u->public_id} شکست خورد: ".get_class($e), 3600);
                }
            });

        $this->line("refunded_irt={$refunded}");

        return self::SUCCESS;
    }

    /** مصرفِ واقعی از ارائه‌دهنده، یا null اگر راهی نیست/پیدا نشد */
    private function probe(AiUsage $u): ?array
    {
        $template = $u->provider?->usage_lookup_url;
        if (blank($template) || blank($u->upstream_request_id)) {
            return null;
        }

        try {
            if ($u->provider->slug === 'deepinfra' && $template === 'https://api.deepinfra.com/v1/request-costs') {
                $res = Http::withToken((string) $u->provider->apiKey())->timeout(15)->acceptJson()
                    ->post($template, ['requestIds' => [(string) $u->upstream_request_id]]);
                if (! $res->successful()) return null;
                $requests = $res->json('requests');
                if (! is_array($requests) || ! array_is_list($requests) || count($requests) !== 1) return null;
                $item = $requests[0];
                if (($item['requestId'] ?? null) !== $u->upstream_request_id || ! is_int($item['costNanoUsd'] ?? null)
                    || $item['costNanoUsd'] < 0) return null;
                return ['cost_only' => true, 'prompt' => null, 'cached' => null, 'completion' => null, 'reasoning' => null,
                    'estimated_cost' => (string) \Brick\Math\BigDecimal::ofUnscaledValue($item['costNanoUsd'], 9)];
            }
            $res = Http::withToken((string) $u->provider->apiKey())->timeout(15)->acceptJson()
                ->get(str_replace('{id}', rawurlencode((string) $u->upstream_request_id), (string) $template));
        } catch (\Throwable) {
            return null;
        }

        return $res->successful() && is_array($res->json()) ? AiUsageParser::parse($res->json(), $res->body(), $u->operation ?? 'chat') : null;
    }
}
