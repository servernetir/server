<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\ServiceController;
use App\Models\Invoice;
use App\Models\Service;
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

        $threshold = now()->addDays((int) $this->option('days'))->toDateString();

        $due = Service::query()
            ->where('status', 'active')
            ->whereIn('cycle', ['monthly', 'quarterly', 'yearly'])
            ->whereNotNull('next_due_at')
            ->whereDate('next_due_at', '<=', $threshold)
            ->get();

        $made = 0;

        foreach ($due as $service) {
            // فاکتور بازِ همین سرویس؟ پس تمدید قبلاً صادر شده
            $hasOpen = Invoice::where('service_id', $service->id)
                ->whereIn('status', ['unpaid', 'draft', 'partial'])
                ->exists();

            if ($hasOpen) {
                continue;
            }

            $services->issueInvoice($service);
            $made++;
        }

        $this->info("فاکتور تمدید صادرشده: {$made}");

        return self::SUCCESS;
    }
}
