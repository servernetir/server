<?php

namespace App\Console\Commands;

use App\Http\Middleware\PageCache;
use App\Services\Domain\TldPriceBook;
use Illuminate\Console\Command;

/** Explicit complete refresh. Public pages only read the published catalogue. */
class RefreshTldPriceBook extends Command
{
    // Retain --strict for existing operational callers; incomplete refreshes always fail.
    protected $signature = 'domains:price-book {--strict : فقط انتشار دفترچهٔ کامل}';
    protected $description = 'همگام‌سازی قیمت واقعی تمام پسوندهای قابل‌فروش Openprovider';

    public function handle(TldPriceBook $book): int
    {
        try {
            $summary = $book->refreshAll();
        } catch (\Throwable $e) {
            report($e);
            $this->error('همگام‌سازی کامل پسوندها انجام نشد؛ دفترچهٔ معتبر قبلی حفظ شد.');
            return self::FAILURE;
        }
        PageCache::purge();
        $this->info(sprintf('%d پسوند قابل‌فروش از %d ردیف رجیسترار همگام شد؛ %d ردیف غیرفعال یا خارج از فروش سایت.',
            $summary['count'], $summary['total'], $summary['excluded']));
        return self::SUCCESS;
    }
}
