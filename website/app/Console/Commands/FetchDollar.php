<?php

namespace App\Console\Commands;

use App\Http\Middleware\PageCache;
use App\Services\Cloud\CloudCatalogSync;
use App\Services\ExchangeRate;
use App\Services\MaintenanceReceipt;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/** Refresh the procurement currencies together, then apply them to the catalog. */
class FetchDollar extends Command
{
    // Keep the existing command name for operational compatibility.
    protected $signature = 'fx:dollar {--show : فقط نمایش مقدار فعلی بدون دریافت}';

    protected $description = 'دریافت دلار، یورو، تتر و حواله لیر از نوسان و همگام‌سازی قیمت محصولات';

    public function handle(ExchangeRate $fx, CloudCatalogSync $catalog): int
    {
        if ($this->option('show')) {
            foreach (array_keys(ExchangeRate::SOURCE_ITEMS) as $currency) {
                $row = $fx->current($currency);
                $rate = is_array($row) ? ($row['rate_toman'] ?? null) : null;

                $this->info($currency.': '.(is_numeric($rate)
                    ? number_format((int) $rate).' تومان — '.($row['at'] ?? '؟')
                    : 'هنوز نرخی ذخیره نشده'));
            }

            return self::SUCCESS;
        }

        // Supplier reads can take minutes. Keep the accepted FX/catalog pair
        // visible until both new rate rows and the guarded price batch are ready.
        $monitor = app(MaintenanceReceipt::class);
        $run = $monitor->start('fx_refresh');
        $count = null;
        try {
            $rates = app(\App\Services\Cloud\CatalogRefreshRequests::class)->exclusive(
                function () use ($fx, $catalog, &$count): ?array {
                    return $fx->refreshAll(function (array $rows) use ($catalog, &$count): \Closure {
                        $apply = $catalog->prepareReprice();
                        return static function () use ($apply, &$count): void { $count = $apply(); };
                    });
                }
            );
        } catch (\Throwable $e) {
            report($e);
            $rates = null;
        }
        if ($rates === null || $count === null) {
            $monitor->finish('fx_refresh', $run, false);
            ErrorTracker::note('pricing', 'دریافت نرخ و آماده‌سازی قیمت محصولات کامل نشد؛ جفت نرخ و قیمت قبلی حفظ شد.');
            $this->error('نرخ و قیمت تازه منتشر نشد — نسخهٔ پذیرفته‌شدهٔ قبلی حفظ شد.');
            return self::FAILURE;
        }

        // The FX + guarded cloud-price generation is independently accepted;
        // the registrar child reports its own completion or failure.
        $monitor->finish('fx_refresh', $run, true, ['currencies' => count($rates), 'plans' => (int) $count]);

        $labels = [];
        foreach (array_keys(ExchangeRate::SOURCE_ITEMS) as $currency) {
            if (! isset($rates[$currency])) {
                $this->warn($currency.': quote rejected; dependent sales remain guarded.');
                continue;
            }
            $labels[] = $currency.': '.number_format($rates[$currency]['rate_toman']).' تومان';
        }
        $this->info('نرخ به‌روز شد — '.implode(' · ', $labels));
        $this->info("قیمتِ {$count} پلن بازمحاسبه شد.");
        $failed = false;

        // Refresh the registrar catalogue after the published Navasan batch.
        // Keep this large second workload in a fresh CLI process: retaining the
        // cloud preparation's PHP heap alongside both registrar catalogues can
        // exhaust the shared host's normal 128 MiB worker limit. The bounded
        // override applies only to this catalogue process, never to web workers.
        try {
            $result = Process::path(base_path())->timeout(900)->run([
                PHP_BINARY, '-d', 'memory_limit=256M', base_path('artisan'),
                'domains:price-book', '--strict',
            ]);
            if (! $result->successful()) {
                throw new \RuntimeException('Domain price refresh was incomplete.');
            }
            $this->output->write($result->output());
        } catch (\Throwable $e) {
            report($e);
            ErrorTracker::note('pricing', 'نرخ نوسان دریافت شد، اما همگام‌سازی قیمت دامنه‌ها کامل نشد.');
            $this->error('همگام‌سازی قیمت دامنه‌ها کامل نشد.');
            $failed = true;
        } finally {
            // Hosting and domain prices are calculated at read time, so their
            // public page caches also need to follow the new exchange rates.
            PageCache::purge();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
