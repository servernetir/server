<?php

namespace App\Services\Marketing;

use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Read-only navigation to an existing order, never a new payment or reservation. */
final class CheckoutRecovery
{
    private const LIMIT = 3;

    private const MIN_AGE_MINUTES = 15;

    public function forCustomer(int $customerId): Collection
    {
        if ($customerId < 1 || ! $this->available()) {
            return collect();
        }
        $cutoff = now()->subHours(max(1, (int) config('billing.order_expiry_hours', 72)));
        $query = Invoice::query()->select(['id', 'number', 'service_id', 'currency_code', 'total', 'created_at', 'due_at'])
            ->where('customer_id', $customerId)->where('kind', 'service')->where('status', 'unpaid')
            ->where('paid', 0)->where('total', '>', 0)->whereNull('domain_id')
            ->whereNotIn('service_id', array_map('intval', (array) config('billing.internal_service_ids', [])))
            ->where('created_at', '>', $cutoff)->where('created_at', '<=', now()->subMinutes(self::MIN_AGE_MINUTES))
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '>', now()))
            ->whereHas('service', fn ($q) => $q->where('customer_id', $customerId)->where('status', 'pending')
                ->whereNull('activated_at')->whereNull('created_by')->whereNull('cancelled_at')
                ->where(fn ($p) => $p->whereNull('provision_status')->orWhereIn('provision_status', ['none', 'pending']))
                ->whereColumn('services.currency_code', 'invoices.currency_code'))
            ->whereNotExists(fn ($q) => $q->from('invoices as sibling')->whereColumn('sibling.service_id', 'invoices.service_id')
                ->where(fn ($p) => $p->where('sibling.paid', '>', 0)->orWhereIn('sibling.status', ['paid', 'partial'])))
            ->whereNotExists(fn ($q) => $q->from('invoices as newer')->whereColumn('newer.service_id', 'invoices.service_id')
                ->whereColumn('newer.id', '>', 'invoices.id')->whereIn('newer.status', ['unpaid', 'draft']))
            // Even an expired pending attempt remains uncertain until the payment engine resolves it.
            ->whereDoesntHave('payments', fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['failed', 'canceled', 'cancelled', 'expired', 'rejected']))
            ->with(['service' => fn ($q) => $q->select(['id', 'name', 'cycle'])])
            ->withExists(['payments as has_failed_payment' => fn ($q) => $q->whereIn('status', ['failed', 'rejected'])]);

        if (Schema::hasTable('bank_transfer_receipts')) {
            $query->whereNotExists(fn ($q) => $q->from('bank_transfer_receipts')->whereColumn('bank_transfer_receipts.invoice_id', 'invoices.id')
                ->where(fn ($s) => $s->whereNull('status')->orWhereNotIn('status', ['rejected', 'canceled', 'cancelled'])));
        }
        if (Schema::hasTable('crypto_payments')) {
            $query->whereNotExists(fn ($q) => $q->from('crypto_payments')->whereColumn('crypto_payments.invoice_id', 'invoices.id')
                ->where(fn ($s) => $s->where('received_atomic', '>', 0)->orWhereNull('status')
                    ->orWhereNotIn('status', ['expired', 'canceled', 'cancelled', 'failed'])));
        }
        if (Schema::hasTable('snapppay_orders')) {
            $query->whereNotExists(fn ($q) => $q->from('snapppay_orders')->whereColumn('snapppay_orders.invoice_id', 'invoices.id')
                ->where(fn ($s) => $s->whereNull('state')->orWhereNotIn('state', ['failed', 'canceled', 'cancelled'])));
        }
        if (Schema::hasTable('marketing_payment_reconciliations')) {
            $query->whereNotExists(fn ($q) => $q->from('marketing_payment_reconciliations')
                ->whereColumn('marketing_payment_reconciliations.invoice_id', 'invoices.id'));
        }
        if (Schema::hasTable('marketing_redemptions')) {
            $query->whereNotExists(fn ($q) => $q->from('marketing_redemptions')->whereColumn('marketing_redemptions.invoice_id', 'invoices.id')
                ->where(fn ($s) => $s->whereNotIn('status', ['released', 'reserved'])
                    ->orWhere(fn ($r) => $r->where('status', 'reserved')->where('expires_at', '<=', now()))));
        }

        return $query->orderByDesc('id')->limit(self::LIMIT)->get()->map(fn (Invoice $invoice) => [
            'invoice_id' => (int) $invoice->id,
            'number' => (string) $invoice->number,
            'service_name' => (string) $invoice->service->name,
            'cycle_label' => Service::labelFor((string) $invoice->service->cycle),
            'currency_code' => (string) $invoice->currency_code,
            'total' => (int) $invoice->total,
            'created_at' => $invoice->created_at,
            'due_at' => $invoice->due_at,
            'failed_payment' => (bool) $invoice->has_failed_payment,
        ]);
    }

    private function available(): bool
    {
        foreach (['invoices', 'services', 'payments'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }
        // Fail closed on older schemas; never infer that an unknown activation state is a fresh order.
        foreach (['activated_at', 'created_by', 'cancelled_at', 'provision_status', 'cycle'] as $column) {
            if (! Schema::hasColumn('services', $column)) {
                return false;
            }
        }

        return Schema::hasColumn('invoices', 'domain_id');
    }
}
