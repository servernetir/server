<?php

namespace App\Console\Commands;

use App\Models\AiUsage;
use App\Services\Ai\AiPricing;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;

/**
 * ریزِ محاسبهٔ یک شارژ — از روی عددهای منجمدِ خودِ ردیف (m5-spec §6.4).
 *
 *   php artisan ai:explain 01j8…
 *
 * شارژ دوباره با همان `AiPricing` حساب می‌شود و باید **بیت‌به‌بیت** با ثبت‌شده برابر باشد
 * (PASS)؛ هر اختلافی FAIL و کدِ خروجِ ۱ است. گامِ ۳ ِ آزمایشِ زنده (M5.7) همین است.
 */
class AiExplain extends Command
{
    protected $signature = 'ai:explain {public_id}';

    protected $description = 'نمایشِ ریزِ محاسبهٔ شارژِ یک تماسِ AI و بازسنجیِ آن';

    public function handle(AiPricing $pricing): int
    {
        $u = AiUsage::where('public_id', strtolower((string) $this->argument('public_id')))->first();
        if ($u === null) {
            $this->error('چنین تماسی پیدا نشد.');

            return self::FAILURE;
        }

        $this->table(['field', 'value'], collect([
            'status' => $u->status, 'model' => $u->model_slug.' → '.$u->upstream_model,
            'rate R' => $u->fx_rate_toman.' ('.$u->fx_source.')', 'fee/margin/vat bp' => "{$u->fee_bp} / {$u->margin_bp} / {$u->vat_bp} ({$u->vat_basis})",
            'cost r_in/r_cached/r_out µ/1M' => $u->input_rate_micro.' / '.($u->cached_rate_micro ?? '=in').' / '.$u->output_rate_micro,
            'P_in/P_cached/P_out Toman/1M' => "{$u->p_input_irt_m} / {$u->p_cached_irt_m} / {$u->p_output_irt_m}",
            'I / O (bound)' => "{$u->max_input_tokens} / {$u->max_output_tokens}",
            'hold (sell+tax)' => "{$u->hold_sell_irt} + {$u->hold_tax_irt} = {$u->hold_irt}",
            'tokens p/cached/o/reasoning' => "{$u->prompt_tokens} / {$u->cached_tokens} / {$u->completion_tokens} / ".($u->reasoning_tokens ?? '-'),
            'usage source' => (string) $u->usage_source,
            'sell + tax = charged' => "{$u->sell_irt} + {$u->tax_irt} = {$u->charged_irt}",
            'cost (Toman / µ)' => "{$u->cost_irt} / {$u->cost_micro}",
            'refunded / uncollected' => "{$u->refunded_irt} / {$u->uncollected_irt}",
            'review' => $u->needs_review ? (string) $u->review_reason : '-',
        ])->map(fn ($v, $k) => [$k, $v])->values()->all());

        if (! in_array($u->status, AiUsage::CHARGED, true) && $u->status !== AiUsage::STATUS_SETTLE_PENDING) {
            $this->line('تسویه نشده — چیزی برای بازسنجی نیست.');

            return self::SUCCESS;
        }

        $problems = [];

        if ($u->usage_source === 'cap') {
            if ((int) $u->charged_irt !== (int) $u->hold_irt) {
                $problems[] = 'سقف: charged ≠ hold';
            }
        } else {
            $c = $pricing->charge($u->quote(), (int) $u->prompt_tokens, (int) $u->cached_tokens,
                (int) $u->completion_tokens, (int) $u->vat_bp,
                $u->provider_cost_micro !== null ? (string) BigDecimal::ofUnscaledValue((int) $u->provider_cost_micro, 6) : null);
            foreach (['sell' => 'sell_irt', 'tax' => 'tax_irt', 'cost_irt' => 'cost_irt'] as $calc => $col) {
                if ($c[$calc] !== (int) $u->{$col}) {
                    $problems[] = "{$col}: ثبت‌شده {$u->{$col}}، بازسنجی {$c[$calc]}";
                }
            }
            if ((int) $u->charged_irt !== (int) $u->sell_irt + (int) $u->tax_irt) {
                $problems[] = 'charged ≠ sell + tax';
            }
        }
        if ((int) $u->sell_irt < (int) $u->cost_irt && $u->review_reason !== 'below_cost') {
            $problems[] = 'فروش زیرِ بها بی علامتِ بازبینی';
        }

        if ($problems === []) {
            $this->info('PASS');

            return self::SUCCESS;
        }

        foreach ($problems as $p) {
            $this->error('FAIL: '.$p);
        }

        return self::FAILURE;
    }
}
