<?php

namespace App\Services\Billing;

use App\Http\Controllers\Admin\ServiceController;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RenewalTerms
{
    public static function timezone(): string
    {
        return (string) config('billing.timezone', 'Asia/Tehran');
    }

    /** A date-only service remains paid through the end of its local due day. */
    public static function serviceDeadline(Service $service): ?Carbon
    {
        return $service->next_due_at
            ? Carbon::parse($service->next_due_at->toDateString(), self::timezone())->endOfDay()->utc()
            : null;
    }

    public static function domainDeadline(Domain $domain): ?Carbon
    {
        return $domain->expires_at?->copy()->utc();
    }

    public static function openInvoice(Service $service): ?Invoice
    {
        return $service->invoices()->outstanding()->oldest('id')->first();
    }

    /** Both renewal jobs use the same service lock to avoid duplicate invoices. */
    public static function ensureInvoice(Service $service): ?Invoice
    {
        return DB::transaction(function () use ($service) {
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if ($locked->isInternalInfrastructure() || $locked->billing_mode === 'hourly'
                || ! $locked->isRecurring() || ! in_array($locked->status, ['active', 'suspended'], true)) {
                return null;
            }

            return self::openInvoice($locked)
                ?? app(ServiceController::class)->issueInvoice($locked);
        });
    }
}
