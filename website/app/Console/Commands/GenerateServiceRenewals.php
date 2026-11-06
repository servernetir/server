<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\ServiceController;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Billing\BillingRunMonitor;
use App\Services\Billing\ComplimentaryServices;
use App\Services\Billing\RenewalTerms;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * صدور خودکار فاکتور تمدید برای سرویس‌های دوره‌ای که سررسیدشان نزدیک است.
 *
 * چند روز پیش از سررسید یک فاکتور تمدید می‌سازد تا مشتری فرصت پرداخت داشته
 * باشد. اگر همان دوره فاکتور بازِ پرداخت‌نشده دارد، دوباره صادر نمی‌کند
 * (idempotent) — وگرنه هر بار اجرای کرون یک فاکتور تکراری می‌ساخت.
 */
class GenerateServiceRenewals extends Command
{
    protected $signature = 'services:renew-due {--days=5 : چند روز پیش از سررسید}';

    protected $description = 'صدور فاکتور تمدید برای سرویس‌های دوره‌ای سررسیدشده';

    public function handle(ServiceController $services): int
    {
        if (! Schema::hasTable('services')) {
            $this->warn('جدول services وجود ندارد.');

            return self::SUCCESS;
        }

        $threshold = now(RenewalTerms::timezone())->addDays((int) $this->option('days'))->toDateString();

        // دوره‌ها از config می‌آید: با فهرستِ دستی، افزودنِ «شش‌ماهه» یعنی
        // سرویسِ شش‌ماهه هرگز فاکتورِ تمدید نمی‌گرفت و بی‌صدا رایگان می‌شد.
        $due = Service::query()
            ->whereIn('status', ['active', 'suspended'])
            ->customerRecurring(true)
            ->whereNotNull('next_due_at')
            ->whereDate('next_due_at', '<=', $threshold)
            ->get();

        $made = 0;
        $errors = 0;

        foreach ($due as $service) {
            try {
                if ($service->hasUnverifiedBillingTerms()) { continue; }
                $complimentary = app(ComplimentaryServices::class);
                if ($complimentary->applies($service)) {
                    $complimentary->renew($service, (int) $this->option('days'));

                    continue;
                }
                if ($service->isInternalInfrastructure()) {
                    continue;
                }
                // فاکتور بازِ همین سرویس؟ پس تمدید قبلاً صادر شده
                $hasOpen = Invoice::where('service_id', $service->id)
                    ->outstanding()
                    ->exists();

                if ($hasOpen) {
                    continue;
                }

                RenewalTerms::ensureInvoice($service);
                $made++;
            } catch (\Throwable $e) {
                $errors++;
                ErrorTracker::noteOnce('billing', 'صدور تمدید سرویس #'.$service->id.' ناموفق بود؛ سایر سرویس‌ها ادامه یافتند.', 900, ['service' => $service->id, 'exception' => $e::class]);
            }
        }

        $this->info("فاکتور تمدید صادرشده: {$made}");

        BillingRunMonitor::record('services:renew-due', $errors, ['invoiced' => $made]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
