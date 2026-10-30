<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\BankTransferReceipt;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit, single-receipt recovery; never called by approval or a scheduler. */
class ApprovedBankReceiptRecovery
{
    public function run(int $receiptId, int $customerId, int $invoiceId, int $amount, bool $apply = false): array
    {
        return DB::transaction(function () use ($receiptId, $customerId, $invoiceId, $amount, $apply) {
            $receipt = BankTransferReceipt::whereKey($receiptId)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            if ($amount <= 0 || $receipt->status !== 'approved' || $receipt->reviewed_at === null
                || $receipt->reviewed_by === null
                || (int) $receipt->customer_id !== $customerId
                || (int) $invoice->customer_id !== $customerId
                || (int) $receipt->invoice_id !== $invoiceId
                || (int) $receipt->amount !== $amount || (int) $invoice->total !== $amount
                || $invoice->kind !== 'topup' || $invoice->service_id !== null
                || $invoice->currency_code !== 'IRT') {
                throw new RuntimeException('Receipt/invoice does not match the explicitly approved top-up recovery.');
            }

            $key = 'bank-receipt-recovery:'.$receipt->id;
            $recovery = Payment::where('external_ref', $key)->lockForUpdate()->first();
            if ($recovery !== null && ! $this->matches($recovery, $customerId, $invoiceId, $amount)) {
                throw new RuntimeException('Recovery payment identity conflict.');
            }

            $credited = (int) CreditEntry::where('source_type', Invoice::class)
                ->where('source_id', $invoiceId)->where('reason', 'topup')->sum('amount');

            if ($recovery?->isPaid() && $invoice->status === 'paid'
                && (int) $invoice->paid === $amount && $credited === $amount) {
                return ['status' => 'already_recovered', 'receipt_id' => $receiptId,
                    'invoice_id' => $invoiceId, 'payment_id' => $recovery->id];
            }

            if (! $invoice->isPayable() || (int) $invoice->paid !== 0 || $credited !== 0
                || Payment::where('invoice_id', $invoiceId)->where('status', 'paid')->exists()) {
                throw new RuntimeException('Invoice already has financial effects; manual reconciliation is required.');
            }

            // Restrict this tool to the proven legacy collision: an older paid bank
            // payment for the same customer and a different invoice. Preserve it.
            $legacy = Payment::where('external_ref', $receipt->reference)->lockForUpdate()->first();
            if ($legacy === null || ! $legacy->isPaid() || $legacy->gateway !== 'bank_transfer'
                || (int) $legacy->customer_id !== $customerId || (int) $legacy->invoice_id === $invoiceId
                || strlen($receipt->reference) > 64) {
                throw new RuntimeException('The approved receipt is not the supported legacy reference-collision case.');
            }

            if (! $apply) {
                return ['status' => 'eligible', 'receipt_id' => $receiptId,
                    'invoice_id' => $invoiceId, 'amount_irt' => $amount];
            }

            $recovery ??= Payment::create([
                'external_ref' => $key, 'invoice_id' => $invoiceId, 'customer_id' => $customerId,
                'gateway' => 'bank_transfer', 'currency_code' => 'IRT', 'amount' => $amount,
                'status' => 'redirected',
            ]);
            $outcome = app(PaymentService::class)->settleConfirmed($recovery, $receipt->reference);
            $credited = (int) CreditEntry::where('source_type', Invoice::class)
                ->where('source_id', $invoiceId)->where('reason', 'topup')->sum('amount');
            if (! $outcome->ok || ! $recovery->fresh()->isPaid()
                || $invoice->fresh()->status !== 'paid' || $credited !== $amount) {
                throw new RuntimeException('Recovery did not settle and credit the expected invoice; rolled back.');
            }

            ActivityLog::record($customerId, 'bank_receipt_reconciled',
                'بازیابی رسید تأییدشده #'.$receiptId.' برای فاکتور #'.$invoiceId
                .' با پرداخت #'.$recovery->id.'؛ پرداخت قدیمی دست‌نخورده ماند.', null, 'staff');

            return ['status' => 'recovered', 'receipt_id' => $receiptId,
                'invoice_id' => $invoiceId, 'payment_id' => $recovery->id, 'amount_irt' => $amount];
        });
    }

    private function matches(Payment $payment, int $customerId, int $invoiceId, int $amount): bool
    {
        return (int) $payment->customer_id === $customerId && (int) $payment->invoice_id === $invoiceId
            && $payment->gateway === 'bank_transfer' && $payment->currency_code === 'IRT'
            && (int) $payment->amount === $amount;
    }
}
