<?php

namespace App\Console\Commands;

use App\Http\Middleware\PageCache;
use App\Services\Cloud\CloudCatalogSync;
use App\Services\ExchangeRate;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;

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

        // A single validated source snapshot prevents mixing a fresh currency
        // with a failed currency during repricing.
        $rates = $fx->refreshAll();

        if ($rates === null) {
            ErrorTracker::note('pricing', 'دریافت نرخ دلار، یورو، تتر و حواله لیر از نوسان ناموفق بود؛ همگام‌سازی قیمت محصولات انجام نشد.');
            $this->error('نرخ ارز تازه نشد — نرخ‌ها و قیمت‌های قبلی حفظ شدند.');

            return self::FAILURE;
        }

        $labels = [];
        foreach (array_keys(ExchangeRate::SOURCE_ITEMS) as $currency) {
            $labels[] = $currency.': '.number_format($rates[$currency]['rate_toman']).' تومان';
        }
        $this->info('نرخ به‌روز شد — '.implode(' · ', $labels));

        $failed = false;
        try {
            $count = $catalog->reprice();
            $this->info("قیمتِ {$count} پلن بازمحاسبه شد.");
        } catch (\Throwable $e) {
            report($e);
            ErrorTracker::note('pricing', 'نرخ نوسان دریافت شد، اما همگام‌سازی قیمت محصولات ناموفق بود.');
            $this->error('نرخ ارز به‌روز شد، اما همگام‌سازی قیمت محصولات ناموفق بود.');

            $failed = true;
        }

        // Refresh every sellable registrar extension after the same Navasan batch,
        // even when a cloud supplier is temporarily unavailable.
        try {
            if ($this->call('domains:price-book', ['--strict' => true]) !== self::SUCCESS) {
                throw new \RuntimeException('Domain price refresh was incomplete.');
            }
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
