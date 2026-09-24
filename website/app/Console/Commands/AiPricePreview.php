<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Services\Ai\AiFx;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiPricingException;
use App\Services\Ai\AiVat;
use Illuminate\Console\Command;

/**
 * پیش‌نمایشِ قیمتِ فروشِ AI — راستی‌آزماییِ پس از انتشارِ M5.1a روی سرور.
 *
 * فقط می‌خوانَد (نرخ از کش، بی‌اسکرپِ زنده) و چیزی جز نشانِ بالاترین نرخ
 * نمی‌نویسد. کدِ خروج همیشه ۰ است مگر `--strict`: «هیچ مدلی فروختنی نیست» تا
 * پیش از جوابِ مالک وضعیتِ درست است، نه خطا.
 *
 *   php artisan ai:price-preview
 *   php artisan ai:price-preview --model=llama-3.3-70b
 *   php artisan ai:price-preview --reset-fx-hw=USD     # فقط اگر نشان به‌خطا بالا رفته
 */
class AiPricePreview extends Command
{
    protected $signature = 'ai:price-preview
        {--model= : فقط همین اسلاگ}
        {--reset-fx-hw= : پاک‌کردنِ نشانِ بالاترین نرخِ این ارز (USD یا EUR)}
        {--strict : اگر هیچ مدلی قیمت نگرفت، کدِ خروجِ ۱}';

    protected $description = 'قیمتِ فروشِ مدل‌های AI از همان فرمولی که شارژ می‌کند (بی‌تماسِ شبکه)';

    public function handle(AiPricing $pricing, AiFx $fx, AiVat $vat): int
    {
        if ($cur = $this->option('reset-fx-hw')) {
            $cur = strtoupper((string) $cur);
            if (! AiFx::supports($cur)) {
                $this->error('فقط USD یا EUR.');

                return self::FAILURE;
            }
            $before = $fx->highWater($cur);
            $fx->resetHighWater($cur);
            $this->warn("نشانِ بالاترین نرخِ {$cur} پاک شد (قبلی: ".($before['rate'] ?? '—').').');
        }

        foreach (['USD', 'EUR'] as $c) {
            $q = $fx->quote($c);
            $this->line(sprintf('FX %s: %s', $c, $q
                ? "{$q->rate} Toman · {$q->source}".($q->at ? " · at {$q->at}" : '').($q->highWater ? " · hw {$q->highWater}" : '')
                : 'UNAVAILABLE'));
        }

        $margin = AiPricing::globalMarginBp();
        $this->line('ai_margin_pct: '.($margin === null ? 'UNSET (sales stay closed)' : AiPricing::bpToPercent($margin).'%'));
        $this->line('VAT (IR): '.AiPricing::bpToPercent($vat->iranRateBp()).'%');
        $this->newLine();

        $models = AiModel::query()->with('provider')
            ->when($this->option('model'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get();

        $priced = 0;
        $rows = [];

        foreach ($models as $m) {
            $gates = $pricing->saleGates($m);

            try {
                $q = $pricing->quote($m);
                $priced++;
                $rows[] = [
                    $m->slug, $q->currency, $q->fx->rate,
                    AiPricing::bpToPercent($q->feeBp), AiPricing::bpToPercent($q->marginBp).($q->marginSource === 'model' ? '*' : ''),
                    $q->pIn, $q->pCached, $q->pOut,
                    $gates ? implode(',', $gates) : 'SELLABLE',
                ];
            } catch (AiPricingException $e) {
                $rows[] = [$m->slug, '—', '—', '—', '—', '—', '—', '—', $e->errorCode.': '.implode(',', $e->reasons)];
            }
        }

        $this->table(['model', 'cur', 'R', 'fee%', 'margin%', 'P_in/1M', 'P_cached/1M', 'P_out/1M', 'status'], $rows);
        $this->line('P = whole Toman per 1,000,000 tokens, ex-VAT. * = model-specific margin.');

        return $this->option('strict') && $priced === 0 ? self::FAILURE : self::SUCCESS;
    }
}
