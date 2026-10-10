<?php

namespace App\Services\Cloud;

use App\Models\ActivityLog;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\Wallet;
use App\Support\ErrorTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** First delivery only. Cancellation and its refund commit together; provider stop is retried. */
class GpuDeliveryRecovery
{
    // Site policy, not a Salad delivery SLA. Cold starts have no fixed provider ETA.
    public const EXPECTED_MINUTES = 60;
    public const DEADLINE_MINUTES = 120;

    public function __construct(private SaladClient $provider, private Wallet $wallet) {}

    public function candidates(int $limit = 10, int $afterId = 0): \Illuminate\Support\Collection
    {
        $query = Service::query()->whereIn('status', Service::ACTIVE_STATUSES)
            ->where('cloud_image_key', 'like', 'gpu-%')
            ->whereHas('cloudPlan', fn ($q) => $q->where('provider', 'salad'))
            ->whereHas('customer')
            ->where(function ($q) {
                $q->whereHas('invoices', fn ($i) => $i->where('paid', '>', 0))
                    ->orWhereExists(fn ($ledger) => $ledger->selectRaw('1')->from('credit_ledger')
                        ->whereColumn('credit_ledger.source_id', 'services.id')->where('source_type', Service::class)
                        ->whereColumn('credit_ledger.customer_id', 'services.customer_id')
                        ->where('amount', '<', 0)->whereIn('reason', ['cloud_hourly', 'cloud_hourly_convert', 'cloud_hourly_hold']));
            })
            ->whereNull('provision_meta->gpu_first_ready_at')
            ->whereDoesntHave('cloudInstance', fn ($q) => $q->whereNotNull('ready_notified_at')
                ->orWhereNotNull('meta->gpu_first_ready_at'))
            ->where(function ($q) {
                $q->where('provision_status', 'failed')
                    ->orWhereHas('cloudInstance', fn ($i) => $i->where('status', 'error'))
                    ->orWhereHas('invoices', fn ($i) => $i->where('paid', '>', 0)
                        ->where('paid_at', '<=', now()->subMinutes(self::DEADLINE_MINUTES)))
                    ->orWhereExists(fn ($ledger) => $ledger->selectRaw('1')->from('credit_ledger')
                        ->whereColumn('credit_ledger.source_id', 'services.id')->where('source_type', Service::class)
                        ->whereColumn('credit_ledger.customer_id', 'services.customer_id')->where('amount', '<', 0)
                        ->whereIn('reason', ['cloud_hourly', 'cloud_hourly_convert'])
                        ->where('created_at', '<=', now()->subMinutes(self::DEADLINE_MINUTES)))
                    ->orWhereRaw('COALESCE(activated_at, created_at) <= ?', [now()->subMinutes(self::DEADLINE_MINUTES)]);
            })
            ->whereNotIn('id', (array) config('billing.internal_service_ids', []))
            ->with(['cloudInstance', 'cloudPlan'])->orderBy('id');
        $rows = (clone $query)->where('id', '>', $afterId)->limit($limit)->get();
        return $rows->isEmpty() && $afterId > 0 ? $query->limit($limit)->get() : $rows;
    }

    public function recover(Service $service, bool $dryRun = false): array
    {
        $service->refresh()->load(['cloudInstance', 'cloudPlan']);
        if (! $this->eligible($service)) {
            if (! $dryRun && $service->cloudInstance?->isGpuApp() && $service->cloudInstance->readyForNotice()) {
                $service->cloudInstance->save(); // Backfill observed readiness on legacy rows.
            }
            return ['action' => 'skip'];
        }

        $state = $this->provider->gpuDeliveryState($this->reference($service));
        if (($state['ready'] ?? false) === true) {
            if (! $dryRun && $service->cloudInstance) {
                $service->cloudInstance->update(['status' => 'running', 'hostname' => $state['hostname'], 'synced_at' => now()]);
            }
            return ['action' => 'ready'];
        }

        $failed = ($state['ok'] ?? false) && (($state['state'] ?? '') === 'failed'
            || (($state['exists'] ?? true) === false && $service->provision_status === 'failed'));
        $expired = $this->paidSince($service)->lte(now()->subMinutes(self::DEADLINE_MINUTES));
        if (! $failed && ! $expired) {
            return ['action' => 'waiting'];
        }
        $reason = $failed ? 'failed' : 'timeout';
        if ($dryRun) {
            return ['action' => 'would_cancel', 'reason' => $reason, 'refunds' => $this->refundAmounts($service)];
        }

        // Same lock order as hourly metering and checkout: customer, service, invoice/ledger.
        $result = DB::transaction(function () use ($service, $reason) {
            if (! Customer::whereKey($service->customer_id)->lockForUpdate()->first()) {
                return ['action' => 'skip'];
            }
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $locked->load(['cloudInstance', 'cloudPlan']);
            if (! $this->eligible($locked)) {
                return ['action' => 'skip'];
            }
            $invoices = Invoice::where('service_id', $locked->id)->where('paid', '>', 0)->lockForUpdate()->get();
            $refunds = $this->refundAmounts($locked);
            $note = 'بازگشت خودکار مبلغ سرویس GPU #'.$locked->id.' — تحویل اولیه '.($reason === 'failed' ? 'شکست خورد.' : 'تا پایان مهلت دو ساعته انجام نشد.');
            foreach ($refunds as $currency => $amount) {
                if ($amount > 0) {
                    $this->wallet->credit($locked->customer_id, $currency, $amount, 'refund', $locked, $note);
                }
            }
            foreach ($invoices as $invoice) {
                if ($invoice->status === 'refunded') {
                    continue;
                }
                $already = $this->invoiceRefunded($invoice);
                $invoice->update(['status' => 'refunded']);
                app(BusinessLedger::class)->recordInvoiceRefund($invoice, max(0, (int) $invoice->paid - $already), $note);
            }
            Invoice::where('service_id', $locked->id)->where('paid', '<=', 0)
                ->whereIn('status', Invoice::OPEN_STATUSES)->update(['status' => 'canceled']);
            $meta = $locked->provision_meta ?? [];
            $meta['gpu_delivery_recovery'] = [
                'reason' => $reason, 'cancelled_at' => now()->toIso8601String(),
                'refunds' => $refunds, 'pending_stop' => true, 'provider_ref' => $this->reference($locked),
            ];
            $locked->forceFill([
                'status' => 'cancelled', 'cancelled_at' => now(), 'provision_status' => 'none',
                'provision_error' => $note, 'provision_meta' => $meta,
                'hold_rate_irt' => 0, 'hold_reserve_irt' => 0, 'last_metered_at' => now(),
            ])->save();
            ActivityLog::forService($locked, 'terminate', $note, 'system');
            return ['action' => 'cancelled', 'reason' => $reason, 'refunds' => $refunds];
        }, 3);

        if ($result['action'] === 'cancelled') {
            $result['stop_confirmed'] = $this->retryStop($service->fresh());
        }
        return $result;
    }

    public function retryStop(Service $service): bool
    {
        if (! in_array($service->status, Service::DEAD_STATUSES, true)
            || data_get($service->provision_meta, 'gpu_delivery_recovery.pending_stop') !== true) {
            return false;
        }
        $ref = (string) data_get($service->provision_meta, 'gpu_delivery_recovery.provider_ref');
        if ($ref === '') {
            return false;
        }
        $state = $this->provider->gpuDeliveryState($ref);
        if (! ($state['stopped'] ?? false)) {
            $this->provider->power($ref, 'off');
            $state = $this->provider->gpuDeliveryState($ref);
        }
        $stopped = ($state['ok'] ?? false) && ($state['stopped'] ?? false);
        DB::transaction(function () use ($service, $stopped) {
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, Service::DEAD_STATUSES, true)
                || data_get($locked->provision_meta, 'gpu_delivery_recovery.pending_stop') !== true) {
                return;
            }
            $meta = $locked->provision_meta ?? [];
            $meta['gpu_delivery_recovery']['last_stop_attempt_at'] = now()->toIso8601String();
            if ($stopped) {
                $meta['gpu_delivery_recovery']['pending_stop'] = false;
                $meta['gpu_delivery_recovery']['stop_confirmed_at'] = now()->toIso8601String();
                $locked->cloudInstance?->update(['status' => 'off', 'synced_at' => now()]);
            }
            $locked->update(['provision_meta' => $meta]);
        });
        if (! $stopped) {
            ErrorTracker::noteOnce('cloud',
                'سفارش GPU #'.$service->id.' لغو و مبلغ برگردانده شد، اما توقف زیرساخت هنوز تأیید نشده؛ تلاش خودکار ادامه دارد.', 900, ['service' => $service->id]);
        }
        return $stopped;
    }

    private function eligible(Service $service): bool
    {
        $instance = $service->cloudInstance;
        return in_array($service->status, Service::ACTIVE_STATUSES, true)
            && ! $service->isInternalInfrastructure()
            && str_starts_with((string) $service->cloud_image_key, 'gpu-')
            && $service->cloudPlan?->provider === 'salad'
            && ($instance === null || $instance->provider === 'salad')
            && ! data_get($service->provision_meta, 'gpu_first_ready_at')
            // An in-flight create must finish before stop can be confirmed; a late response
            // must not create a new group after we have already observed a 404.
            && ! ($service->provision_status === 'running' && $service->updated_at->gt(now()->subMinutes(15)))
            && ! ($instance?->readyForNotice() ?? false)
            && $instance?->ready_notified_at === null
            && ! data_get($instance?->meta, 'gpu_first_ready_at')
            && $this->charged($service) > 0;
    }

    private function paidSince(Service $service): Carbon
    {
        // Creation of a replacement instance must not reset the initial delivery deadline.
        $paid = Invoice::where('service_id', $service->id)->where('paid', '>', 0)->min('paid_at');
        $hourly = $this->serviceLedger($service)->where('amount', '<', 0)
            ->whereIn('reason', ['cloud_hourly', 'cloud_hourly_convert'])->min('created_at');
        $dates = collect([$paid, $hourly])->filter()->map(fn ($at) => Carbon::parse($at))->sort();
        return $dates->first() ?? $service->activated_at ?? $service->created_at;
    }

    private function reference(Service $service): string
    {
        $ref = (string) $service->cloudInstance?->provider_ref;
        return $ref !== '' && ! str_starts_with($ref, 'order:') ? $ref : 'sn-svc-'.$service->id;
    }

    private function charges(Service $service): array
    {
        $amounts = [];
        foreach (Invoice::where('service_id', $service->id)->where('paid', '>', 0)->get() as $invoice) {
            $currency = (string) ($invoice->currency_code ?: 'IRT');
            $amounts[$currency] = ($amounts[$currency] ?? 0) + (int) $invoice->paid;
        }
        foreach ($this->serviceLedger($service)->where('amount', '<', 0)
            ->whereIn('reason', ['cloud_hourly', 'cloud_hourly_convert', 'cloud_hourly_hold'])->get() as $entry) {
            $amounts[$entry->currency_code] = ($amounts[$entry->currency_code] ?? 0) - (int) $entry->amount;
        }
        return $amounts;
    }

    private function charged(Service $service): int
    {
        return array_sum($this->charges($service));
    }

    private function serviceLedger(Service $service): \Illuminate\Database\Eloquent\Builder
    {
        return CreditEntry::where('customer_id', $service->customer_id)
            ->where('source_type', Service::class)->where('source_id', $service->id);
    }

    private function invoiceRefunded(Invoice $invoice): int
    {
        if ($invoice->status === 'refunded') {
            return (int) $invoice->paid;
        }
        return (int) CreditEntry::where('customer_id', $invoice->customer_id)
            ->where('source_type', Invoice::class)->where('source_id', $invoice->id)
            ->whereIn('reason', ['undelivered_refund', 'refund'])->where('amount', '>', 0)->sum('amount');
    }

    private function refundAmounts(Service $service): array
    {
        $amounts = $this->charges($service);
        foreach ($this->serviceLedger($service)->where('amount', '>', 0)
            ->whereIn('reason', ['refund', 'cloud_hourly_refund'])->get() as $entry) {
            $amounts[$entry->currency_code] = ($amounts[$entry->currency_code] ?? 0) - (int) $entry->amount;
        }
        foreach (Invoice::where('service_id', $service->id)->where('paid', '>', 0)->get() as $invoice) {
            $currency = (string) ($invoice->currency_code ?: 'IRT');
            $amounts[$currency] = ($amounts[$currency] ?? 0) - $this->invoiceRefunded($invoice);
        }
        return array_map(fn ($amount) => max(0, $amount), $amounts);
    }
}
