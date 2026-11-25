<?php

namespace App\Console\Commands;

use App\Http\Middleware\PageCache;
use App\Services\Domain\TldPriceBook;
use App\Services\Domain\DomainProcurement;
use App\Services\MaintenanceReceipt;
use Illuminate\Console\Command;

/** Explicit complete refresh. Public pages only read the published catalogue. */
class RefreshTldPriceBook extends Command
{
    // Retain --strict for existing operational callers; incomplete refreshes always fail.
    protected $signature = 'domains:price-book {--strict : فقط انتشار دفترچهٔ کامل}';
    protected $description = 'همگام‌سازی قیمت واقعی تمام پسوندهای قابل‌فروش از حساب‌های واجد شرایط';

    public function handle(TldPriceBook $book): int
    {
        $monitor = app(MaintenanceReceipt::class);
        $run = $monitor->start('domain_catalogue');
        try {
            $summary = $book->refreshAll();
        } catch (\Throwable $e) {
            $monitor->finish('domain_catalogue', $run, false);
            report($e);
            $this->error('همگام‌سازی کامل پسوندها انجام نشد؛ دفترچهٔ معتبر قبلی حفظ شد.');
            return self::FAILURE;
        }
        $metrics = ['count' => (int) ($summary['count'] ?? 0), 'total' => (int) ($summary['total'] ?? 0)];
        try {
            if (app(DomainProcurement::class)->enabled()) {
                $metrics['excluded_accounts'] = (int) ($summary['excluded'] ?? 0);
            }
        } catch (\Throwable) {
            // Telemetry cannot invalidate a completed price publication.
        }
        $monitor->finish('domain_catalogue', $run, true, $metrics);
        PageCache::purge();
        $this->info(sprintf('%d پسوند قابل‌فروش از %d ردیف رجیسترار همگام شد؛ %d ردیف غیرفعال یا خارج از فروش سایت.',
            $summary['count'], $summary['total'], $summary['excluded']));
        return self::SUCCESS;
    }
}
