<?php

namespace App\Console\Commands;

use App\Services\Crm\LeadDiscovery;
use Illuminate\Console\Command;

/**
 * پیدا کردنِ سرنخِ تازه از Google Places.
 *
 * روزی یک اجرا کافی است: در هر اجرا حدود ۲۰ کسب‌وکار دیده می‌شود و بیشترشان
 * رد می‌شوند. هدف حجم نیست — کلینیکی است که هم پول دارد هم سایتش مشکلِ واقعی.
 */
class CrmDiscover extends Command
{
    protected $signature = 'crm:discover {--limit= : حداکثر سرنخِ تازه در این اجرا}';

    protected $description = 'یافتن سرنخ‌های تازه (Google Places) و ثبتشان در قیف';

    public function handle(LeadDiscovery $discovery): int
    {
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $r = $discovery->run($limit);

        if (($r['error'] ?? null) === 'no_places_key') {
            $this->warn('کلیدِ GOOGLE_PLACES_KEY تنظیم نشده — کشفِ خودکار خاموش است.');
            $this->line('تا وقتی کلید نگذاشته‌ای، سرنخ را از پنل دستی وارد کن: /admin/crm');

            return self::SUCCESS;
        }

        $this->info("دیده‌شده: {$r['seen']} · افزوده‌شده: {$r['added']}");

        foreach ($r['skipped'] as $reason => $count) {
            $this->line("  رد شد ({$reason}): {$count}");
        }

        return self::SUCCESS;
    }
}
