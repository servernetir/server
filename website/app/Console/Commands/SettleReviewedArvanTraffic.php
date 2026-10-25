<?php

namespace App\Console\Commands;

use App\Http\Controllers\Account\CloudStoreController;
use App\Services\Cloud\CloudPricing;
use App\Services\Finance\ArvanTrafficSettlement;
use Illuminate\Console\Command;

final class SettleReviewedArvanTraffic extends Command
{
    protected $signature = 'finance:settle-reviewed-traffic {snapshot} {--source-hash=} {--markup-bp=} {--tax-bp=} {--apply} {--plan-hash=} {--authorization=}';

    protected $description = 'Preview or apply one explicitly owner-authorized, attributed traffic snapshot; can create wallet debt';

    public function handle(ArvanTrafficSettlement $settlement): int
    {
        try {
            foreach (['markup-bp', 'tax-bp'] as $key) {
                if (! preg_match('/^\d{1,6}$/D', (string) $this->option($key))) {
                    throw new \RuntimeException('Explicit integer markup-bp and tax-bp required');
                }
            }
            $path = realpath($this->argument('snapshot'));
            if (! $path || ! is_file($path) || filesize($path) > 10_000_000) {
                throw new \RuntimeException('Snapshot missing or too large');
            }
            $plan = $settlement->plan(file_get_contents($path), (string) $this->option('source-hash'),
                (int) $this->option('markup-bp'), (int) $this->option('tax-bp'));
            if ($this->option('apply')) {
                if ((int) round(app(CloudPricing::class)->marginPct('arvan') * 100) !== $plan['margin_bp']
                    || CloudStoreController::taxPercent() * 100 !== $plan['tax_bp']) {
                    throw new \RuntimeException('Live markup/tax changed; review the plan again');
                }
                $out = $settlement->apply($plan, (string) $this->option('plan-hash'), (string) $this->option('authorization'));
            } else {
                $out = collect($plan['lines'])->groupBy('customer_id')->map(fn ($ls, $cid) => [
                    'customer_id' => $cid, 'services' => $ls->pluck('service_id')->all(),
                    'cost_irr' => $ls->sum('cost_irr'), 'subtotal_irt' => $ls->sum('sell_irt'),
                    'tax_irt' => $ls->sum('tax_irt'), 'total_irt' => $ls->sum('sell_irt') + $ls->sum('tax_irt'),
                ])->values()->all();
            }
            $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'preview',
                'plan_hash' => $plan['hash'], 'source_hash' => $plan['source_hash'], 'rows' => $out], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
