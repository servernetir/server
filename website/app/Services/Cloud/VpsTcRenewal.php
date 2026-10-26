<?php

namespace App\Services\Cloud;

use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\CloudProviderOperation;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Services\ExchangeRate;
use App\Support\ErrorTracker;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A paid retail invoice is not evidence that the prepaid supplier renewed. */
class VpsTcRenewal
{
    public function applies(Service $service): bool
    {
        return $service->isCloud() && $service->activated_at !== null
            && CloudInstance::query()->where('service_id', $service->id)->where('provider', 'vpstc')->exists();
    }

    public function pending(Service $service): bool
    {
        return filled(data_get($service->provision_meta, 'vpstc_renewal.invoice_id'));
    }

    /** Called inside the payment transaction; never calls the supplier. */
    public function queue(Service $service, Invoice $invoice): void
    {
        if (! $this->applies($service) || $invoice->status !== 'paid' || $invoice->due() !== 0
            || (int) $invoice->service_id !== (int) $service->id || $service->isDead()
            || ! $this->retailInvoice($invoice)) {
            return;
        }
        $instance = CloudInstance::query()->where('service_id', $service->id)->where('provider', 'vpstc')->firstOrFail();
        $key = $this->key((int) $invoice->id);
        $operation = CloudProviderOperation::firstOrCreate(['operation_key' => $key], [
            'provider' => 'vpstc', 'action' => 'renew', 'resource_ref' => $instance->provider_ref,
            'state' => 'prepared', 'payload' => [
                'local_service_id' => $service->id, 'local_invoice_id' => $invoice->id,
                'before_local_due' => $service->next_due_at?->toDateString(),
                'target_local_due' => $service->next_due_at ? $service->nextDueFrom($service->next_due_at)?->toDateString() : null,
                'supplier_due_anchor' => data_get($instance->meta, 'vpstc_supplier_due_at'),
                'account' => $this->account(),
            ],
        ]);
        if ($operation->state === 'done') {
            return;
        }
        $meta = (array) $service->provision_meta;
        if (filled(data_get($meta, 'vpstc_renewal.invoice_id'))
            && (int) data_get($meta, 'vpstc_renewal.invoice_id') !== (int) $invoice->id) {
            $operation->forceFill(['state' => 'blocked'])->save();
            $this->problem($operation, 'another_paid_period_needs_review');
            return;
        }
        $meta['vpstc_renewal'] = ['invoice_id' => $invoice->id, 'queued_at' => now()->toIso8601String()];
        $service->forceFill(['provision_meta' => $meta])->save();
    }

    /** Network work only after payment commit. Retry submits each mutation at most once. */
    public function process(Invoice $invoice): bool
    {
        $operation = Schema::hasTable('cloud_provider_operations')
            ? CloudProviderOperation::where('operation_key', $this->key((int) $invoice->id))->first()
            : null;
        if ($operation === null) {
            return false;
        }
        if ($operation->state === 'done') {
            return true;
        }
        try {
            return (bool) Cache::lock('vpstc-resource-'.hash('sha256', (string) $operation->resource_ref), 120)->get(function () use ($operation, $invoice) {
                return $this->run($operation->fresh(), $invoice->fresh());
            });
        } catch (\Throwable) {
            $this->problem($operation, 'renewal_check_failed');
            return false;
        }
    }

    private function run(CloudProviderOperation $op, Invoice $invoice): bool
    {
        $payload = (array) $op->payload;
        $service = Service::find($payload['local_service_id'] ?? null);
        $instance = $service ? CloudInstance::where('service_id', $service->id)->where('provider', 'vpstc')->first() : null;
        if (! $service || ! $instance || $service->isDead() || (string) $instance->provider_ref !== (string) $op->resource_ref
            || (int) $invoice->service_id !== (int) $service->id || $invoice->status !== 'paid' || $invoice->due() !== 0
            || ! $this->retailInvoice($invoice)
            || (string) ($payload['account'] ?? '') !== $this->account()
            || CloudProviderOperation::where('provider', 'vpstc')->where('action', 'cancel')->where('resource_ref', $op->resource_ref)->exists()) {
            return $this->problem($op, 'renewal_context_changed');
        }
        if ($service->isHourly() || Service::monthsIn((string) $service->cycle) !== 1
            || empty($payload['before_local_due']) || empty($payload['target_local_due'])) {
            return $this->problem($op, 'monthly_renewal_required');
        }
        $api = app(VpsTcApi::class);
        $sid = (string) $op->resource_ref;
        if ($op->state === 'prepared') {
            if ($service->next_due_at?->toDateString() !== $payload['before_local_due']) {
                return $this->problem($op, 'local_period_changed');
            }
            $read = $api->service($sid);
            $remote = $read['data']['service'] ?? [];
            $plan = CloudPlan::find($service->cloud_plan_id);
            $quote = $plan && $plan->provider === 'vpstc' ? app(VpsTcCatalog::class)->quote((string) $plan->provider_ref) : null;
            $nativeDue = $this->date($remote['next_due'] ?? null);
            $anchor = $this->date($payload['supplier_due_anchor'] ?? null);
            if (! $quote || ($quote['renewal_mode'] ?? null) !== 'manual_invoice_deduplicated') {
                return $this->problem($op, 'supplier_renewal_contract_not_verified');
            }
            if (! $anchor || ($nativeDue && ! $nativeDue->equalTo($anchor))) {
                return $this->problem($op, $anchor ? 'supplier_period_changed' : 'supplier_period_anchor_missing');
            }
            if (! ($read['ok'] ?? false) || ! $quote || ! $nativeDue
                || (string) ($remote['id'] ?? '') !== $sid || strtolower((string) ($remote['billingcycle'] ?? '')) !== 'monthly'
                || ! in_array(strtolower((string) ($remote['status'] ?? '')), ['active', 'suspended'], true)
                || ! $this->amountAtMost($remote['total'] ?? null, $quote['monthly_amount'] ?? null)
                || ! app(CloudPricingSnapshot::class)->current('vpstc') || ! $this->coversCost($invoice, $quote)) {
                return $this->problem($op, 'renewal_quote_not_verified');
            }
            // No overlapping renewal can reserve another period for this parent.
            if (CloudProviderOperation::where('provider', 'vpstc')->where('action', 'renew')->where('resource_ref', $sid)
                ->where('id', '<>', $op->id)->whereNotIn('state', ['done', 'rejected', 'blocked'])->exists()) {
                return $this->problem($op, 'another_renewal_pending');
            }
            $ceiling = $quote['renewal_total_amount'] ?? BigDecimal::of((string) $quote['monthly_amount'])
                ->multipliedBy(BigDecimal::of((string) Setting::get('vpstc_supplier_tax_pct'))->plus(100))
                ->dividedBy(100, 6, RoundingMode::Ceiling)->__toString();
            $balance = $api->balance();
            $account = $balance['data']['details'] ?? [];
            if (! ($balance['ok'] ?? false) || ($account['currency'] ?? null) !== ($quote['currency'] ?? null)
                || ! $this->amountAtMost($ceiling, $account['acc_credit'] ?? null)) {
                return $this->problem($op, 'supplier_credit_required');
            }
            $payload += [
                'before_supplier_due' => $nativeDue->toDateString(),
                'target_supplier_due' => $nativeDue->copy()->addMonthNoOverflow()->toDateString(),
                'currency' => $quote['currency'], 'native_ceiling' => $ceiling,
                'renewal_mode' => 'manual_invoice_deduplicated',
            ];
            // Commit the reservation before the financial request; a lost response never replays it.
            $claimed = CloudProviderOperation::whereKey($op->id)->where('state', 'prepared')->update([
                'state' => 'submitting', 'updated_at' => now(),
            ]);
            if (! $claimed) {
                return false;
            }
            $op->refresh()->forceFill(['payload' => $payload])->save();
            $result = $api->renewService($sid);
            $iid = (string) ($result['data']['invoice_id'] ?? '');
            if (! ($result['ok'] ?? false) || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', $iid)) {
                $op->forceFill(['state' => ($result['code'] ?? '') === 'mutation_rejected' ? 'rejected' : 'unknown'])->save();
                return $this->problem($op, 'supplier_renewal_needs_reconciliation');
            }
            $op->forceFill(['task_ref' => $iid, 'state' => 'accepted'])->save();
        }
        if (! filled($op->task_ref)) {
            // No receipt means no safe invoice match. Keep the paid retail period pending for review.
            return $this->problem($op, 'supplier_renewal_needs_reconciliation');
        }
        $payload = (array) $op->payload;
        if (($payload['renewal_mode'] ?? null) !== 'manual_invoice_deduplicated') {
            return $this->problem($op, 'supplier_renewal_contract_not_verified');
        }
        $read = $api->invoice((string) $op->task_ref);
        $bill = $read['data']['invoice'] ?? [];
        if (! ($read['ok'] ?? false) || ! $this->linkedInvoice($bill, $op, $payload)) {
            return $this->problem($op, 'supplier_invoice_not_verified');
        }
        if (strtolower((string) ($bill['status'] ?? '')) !== 'paid') {
            if ($op->state !== 'accepted') {
                return $this->problem($op, 'supplier_payment_pending');
            }
            $claimed = CloudProviderOperation::whereKey($op->id)->where('state', 'accepted')->update(['state' => 'credit_submitting', 'updated_at' => now()]);
            if (! $claimed) {
                return false;
            }
            $api->applyInvoiceCredit((string) $op->task_ref);
            $op->refresh();
            // Acknowledgement never replaces the read-back of Paid and the service period.
            $read = $api->invoice((string) $op->task_ref);
            $bill = $read['data']['invoice'] ?? [];
            if (! ($read['ok'] ?? false) || ! $this->linkedInvoice($bill, $op, $payload)
                || strtolower((string) ($bill['status'] ?? '')) !== 'paid') {
                return $this->problem($op, 'supplier_payment_pending');
            }
        }
        $read = $api->service($sid);
        $remote = $read['data']['service'] ?? [];
        $due = $this->date($remote['next_due'] ?? null);
        if (! ($read['ok'] ?? false) || (string) ($remote['id'] ?? '') !== $sid || ! $due
            || ! in_array(strtolower((string) ($remote['status'] ?? '')), ['active', 'suspended'], true)
            || $due->toDateString() !== ($payload['target_supplier_due'] ?? null)) {
            return $this->problem($op, 'supplier_period_not_confirmed');
        }
        return DB::transaction(function () use ($op, $payload, $service, $invoice, $instance) {
            $lockedOp = CloudProviderOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if ($lockedOp->state === 'done') {
                return true;
            }
            if ($locked->isDead() || $locked->next_due_at?->toDateString() !== $payload['before_local_due']) {
                return $this->problem($lockedOp, 'local_period_changed');
            }
            $meta = (array) $locked->provision_meta;
            if ((int) data_get($meta, 'vpstc_renewal.invoice_id') === (int) $invoice->id) {
                unset($meta['vpstc_renewal']);
            }
            $meta['vpstc_last_renewal'] = ['invoice_id' => $invoice->id, 'confirmed_at' => now()->toIso8601String()];
            $locked->forceFill(['next_due_at' => $payload['target_local_due'], 'reminder_stage' => null, 'provision_meta' => $meta])->save();
            $lockedInstance = CloudInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $instanceMeta = (array) $lockedInstance->meta;
            $instanceMeta['vpstc_supplier_due_at'] = $payload['target_supplier_due'];
            $lockedInstance->forceFill(['meta' => $instanceMeta])->save();
            $lockedOp->forceFill(['state' => 'done'])->save();
            ActivityLog::forService($locked, 'renew', 'تمدید دوره پس از تأیید صورت‌حساب و سررسید زیرساخت انجام شد.', 'system');
            return true;
        });
    }

    private function linkedInvoice(array $invoice, CloudProviderOperation $op, array $payload): bool
    {
        if ((string) ($invoice['id'] ?? '') !== (string) $op->task_ref
            || ($invoice['currency'] ?? null) !== ($payload['currency'] ?? null)
            || ! $this->amountAtMost($invoice['total'] ?? null, $payload['native_ceiling'] ?? null)) {
            return false;
        }
        $items = $invoice['items'] ?? null;
        if (! is_array($items) || $items === [] || count($items) > 10) {
            return false;
        }
        $linked = 0;
        foreach ($items as $item) {
            if (! is_array($item) || (string) ($item['item_id'] ?? '') !== (string) $op->resource_ref
                || strtolower((string) ($item['type'] ?? '')) !== 'hosting'
                || ! $this->amountAtMost($item['qty'] ?? null, '1') || (float) ($item['qty'] ?? 0) !== 1.0) {
                return false;
            }
            $linked++;
        }
        return $linked === 1;
    }

    private function coversCost(Invoice $invoice, array $quote): bool
    {
        $cost = $quote['cost_eur_cents'] ?? null;
        if (! is_int($cost) || $cost <= 0) {
            return false;
        }
        $net = max(0, (int) $invoice->subtotal - (int) $invoice->discount);
        if ($invoice->currency_code === 'EUR') {
            return $net >= $cost;
        }
        if ($invoice->currency_code !== 'IRT') {
            return false;
        }
        $rate = app(ExchangeRate::class)->toToman('EUR');
        return is_numeric($rate) && $rate > 0 && BigDecimal::of($cost)->multipliedBy((string) $rate)
            ->dividedBy(100, 0, RoundingMode::Ceiling)->compareTo($net) <= 0;
    }

    private function retailInvoice(Invoice $invoice): bool
    {
        if ($invoice->kind !== 'service' || (int) $invoice->customer_id !== (int) $invoice->service?->customer_id) {
            return false;
        }
        $items = $invoice->items()->get();
        $item = $items->count() === 1 ? $items->first() : null;
        return $item && (int) $item->quantity === 1 && (int) $item->line_total === (int) $invoice->subtotal
            && data_get($item->marketing_context, 'operation') === 'renewal'
            && data_get($item->marketing_context, 'provider') === 'vpstc';
    }

    private function amountAtMost(mixed $amount, mixed $ceiling): bool
    {
        try {
            if (! is_scalar($amount) || ! is_scalar($ceiling) || ! preg_match('/\A[0-9]+(?:\.[0-9]{1,6})?\z/D', (string) $amount)
                || ! preg_match('/\A[0-9]+(?:\.[0-9]{1,6})?\z/D', (string) $ceiling)) {
                return false;
            }
            return BigDecimal::of((string) $amount)->isPositive()
                && BigDecimal::of((string) $amount)->compareTo(BigDecimal::of((string) $ceiling)) <= 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function date(mixed $date): ?Carbon
    {
        if (! is_string($date) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $date)) {
            return null;
        }
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);
            return $parsed && $parsed->toDateString() === $date ? $parsed : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function account(): string
    {
        return app(VpsTcApi::class)->accountFingerprint();
    }

    private function key(int $invoiceId): string
    {
        return hash('sha256', 'vpstc:renew:invoice:'.$invoiceId);
    }

    private function problem(CloudProviderOperation $op, string $code): bool
    {
        $payload = (array) $op->payload;
        if (($payload['last_issue'] ?? null) !== $code) {
            $payload['last_issue'] = $code;
            $op->forceFill(['payload' => $payload])->save();
        }
        ErrorTracker::noteOnce('billing', 'تمدید پرداخت‌شدهٔ زیرساخت در انتظار تطبیق است: '.$code, 3600,
            ['invoice' => $payload['local_invoice_id'] ?? null, 'service' => $payload['local_service_id'] ?? null]);
        return false;
    }
}
