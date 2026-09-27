<?php

namespace App\Console\Commands;

use App\Models\AiUsage;
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

    public function handle(AiSettlement $settlement): int
    {
        return $this->option('late') ? $this->late($settlement) : $this->pending($settlement);
    }

    private function pending(AiSettlement $settlement): int
    {
        $max = (int) config('ai.unknown_recovery_max_attempts', 3);
        $recovered = $capped = 0;

        AiUsage::where('status', AiUsage::STATUS_UNKNOWN_PENDING)->with('provider')->orderBy('id')->limit(200)->get()
            ->each(function (AiUsage $u) use ($settlement, $max, &$recovered, &$capped) {
                $usage = $this->probe($u);

                try {
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

    private function late(AiSettlement $settlement): int
    {
        $refunded = 0;

        AiUsage::where('status', AiUsage::STATUS_UNKNOWN_CHARGED)->where('usage_source', 'cap')
            ->where('settled_at', '>=', now()->subDays((int) config('ai.unknown_recovery_days', 7)))
            ->with('provider')->orderBy('id')->limit(500)->get()
            ->each(function (AiUsage $u) use ($settlement, &$refunded) {
                $usage = $this->probe($u);
                if ($usage !== null) {
                    $refunded += $settlement->refundDown($u, $usage);
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
            $res = Http::withToken((string) $u->provider->apiKey())->timeout(15)->acceptJson()
                ->get(str_replace('{id}', rawurlencode((string) $u->upstream_request_id), (string) $template));
        } catch (\Throwable) {
            return null;
        }

        return $res->successful() && is_array($res->json()) ? AiUsageParser::parse($res->json(), $res->body()) : null;
    }
}
