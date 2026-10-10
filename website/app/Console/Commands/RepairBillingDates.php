<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Billing\RenewalTerms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairBillingDates extends Command
{
    protected $signature = 'billing:repair-dates {--apply : ثبت اصلاحات؛ پیش‌فرض فقط پیش‌نمایش است}';

    protected $description = 'پیش‌نمایش و ترمیم سررسید فاکتورهای باز، با حفظ پرداخت‌ها و معافیت زیرساخت';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $count = 0;
        foreach (Invoice::outstanding()->with(['service', 'domain'])->orderBy('id')->cursor() as $invoice) {
            $service = $invoice->service;
            if ($service?->isInternalInfrastructure()) {
                continue;
            }
            $due = null;
            if ($service && $service->isRecurring() && $service->billing_mode !== 'hourly'
                && in_array($service->status, ['active', 'suspended'], true)) {
                $due = RenewalTerms::serviceDeadline($service);
            } elseif ($invoice->domain && $invoice->domain->status === 'active') {
                $due = RenewalTerms::domainDeadline($invoice->domain);
            } elseif ($invoice->due_at === null && $invoice->issued_at) {
                $due = $invoice->issued_at->copy()->addHours(max(1, (int) config('billing.invoice_hold_hours', 48)));
            }
            // Only repair missing values or the known legacy default; keep deliberate staff deadlines.
            $legacy = $invoice->due_at === null || ($invoice->issued_at
                && $invoice->due_at->equalTo($invoice->issued_at->copy()->addHours((int) config('billing.invoice_hold_hours', 48))));
            if (! $due || ! $legacy || $invoice->due_at?->equalTo($due)) {
                continue;
            }
            $before = $invoice->due_at?->format('Y-m-d H:i:s');
            $this->line(json_encode(['invoice' => $invoice->id, 'before' => $before, 'after' => $due->format('Y-m-d H:i:s'), 'apply' => $apply]));
            if ($apply) {
                DB::transaction(function () use ($invoice, $due, $before) {
                    $locked = Invoice::outstanding()->whereKey($invoice->id)->lockForUpdate()->first();
                    if (! $locked || $locked->due_at?->format('Y-m-d H:i:s') !== $before) {
                        return;
                    }
                    $locked->update(['due_at' => $due]);
                    ActivityLog::record($locked->customer_id, 'invoice',
                        'ترمیم سررسید فاکتور #'.$locked->id.': '.($before ?? 'بدون تاریخ').' → '.$due->format('Y-m-d H:i:s').' UTC', null, 'system');
                });
            }
            $count++;
        }
        $missing = Service::customerRecurring()->whereIn('status', ['active', 'suspended'])->whereNull('next_due_at')->pluck('id');
        $this->line(json_encode(['invoice_dates' => $count, 'services_needing_due_review' => $missing->all(), 'applied' => $apply]));

        return self::SUCCESS;
    }
}
