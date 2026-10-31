<?php

namespace App\Services\Finance;

use App\Models\BusinessEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceDocument;
use App\Models\FinanceVoucher;
use App\Models\User;
use App\Support\ErrorTracker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Documents a confirmed supplier debit against an existing automatic cost.
 * This service never calls a supplier or repeats a purchase.
 */
final class SupplierCreditReconciliation
{
    public function __construct(private DocumentedAccounting $journal) {}

    private function check(bool $condition, string $key = 'supplier_review_required'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['accounting' => __('finance.'.$key)]);
        }
    }

    public function reconcile(array $input, int $actor, UploadedFile $evidence): FinanceVoucher
    {
        abort_unless(User::find($actor)?->isAdmin(), 403);
        $this->check($this->journal->ready() && app(BusinessLedger::class)->ready(), 'not_ready');
        $d = Validator::make($input, [
            'idempotency_key' => ['required', 'uuid'],
            'funding_account_id' => ['required', 'integer', 'min:1'],
            'business_entry_id' => ['required', 'integer', 'min:1'],
            'occurred_at' => ['required', 'date_format:Y-m-d'],
            'original_currency' => ['required', Rule::in(array_keys(AccountingMoney::EXPONENTS))],
            'original_minor' => ['required', 'integer', 'min:1', 'max:'.AccountingMoney::MAX],
            'service_line' => ['required', Rule::in(DocumentedAccounting::SERVICES)],
            'bank_reference' => ['required', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:2000'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'invoice_number' => ['nullable', 'string', 'max:150'],
            'related_voucher_id' => ['nullable', 'integer', 'min:1'],
            'source_type' => ['nullable', 'string', 'max:150'],
            'source_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $reference = strtr(trim($d['bank_reference']), array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $reference = preg_replace('/\s+/u', '', $reference);
        $this->check((bool) preg_match('/^[a-zA-Z0-9_.\/:\-]{4,150}$/D', $reference));
        $this->check($evidence->isValid() && in_array($evidence->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png'], true)
            && $evidence->getSize() > 0 && $evidence->getSize() <= 10 * 1024 * 1024, 'supplier_receipt_invalid');
        $receiptHash = hash_file('sha256', $evidence->getRealPath());
        $this->check(is_string($receiptHash) && $receiptHash !== '', 'supplier_receipt_invalid');

        // A replay must compare the submitted proof and identity before recomputing a changed weighted balance.
        $request = [
            'idempotency_key' => strtolower($d['idempotency_key']), 'funding_account_id' => (int) $d['funding_account_id'],
            'business_entry_id' => (int) $d['business_entry_id'], 'occurred_at' => $d['occurred_at'],
            'original_currency' => $d['original_currency'], 'original_minor' => (int) $d['original_minor'],
            'service_line' => $d['service_line'], 'bank_reference' => $reference,
            'note' => trim((string) ($d['note'] ?? '')), 'vendor' => trim((string) ($d['vendor'] ?? '')),
            'invoice_number' => trim((string) ($d['invoice_number'] ?? '')),
            'related_voucher_id' => empty($d['related_voucher_id']) ? null : (int) $d['related_voucher_id'],
            'source_type' => $d['source_type'] ?? null, 'source_id' => empty($d['source_id']) ? null : (int) $d['source_id'],
            'document_sha256' => $receiptHash,
        ];
        $requestHash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
        $path = $evidence->store('finance/supplier-debits/'.$request['idempotency_key'], 'local');
        $this->check(is_string($path) && $path !== '', 'supplier_receipt_invalid');

        try {
            return DB::transaction(function () use ($request, $actor, $evidence, $receiptHash, $requestHash, $path) {
                // Same global lock as DocumentedAccounting::post; protects reference and weighted-cost decisions.
                $control = DB::table('finance_controls')->where('id', 1)->lockForUpdate()->first();
                $this->check($control !== null, 'not_ready');
                $existing = FinanceVoucher::where('idempotency_key', $request['idempotency_key'])->lockForUpdate()->first();
                if ($existing) {
                    $event = DB::table('finance_audit_events')->where('action', 'supplier_credit_reconciled')
                        ->where('subject_id', $existing->id)->orderBy('id')->first();
                    $details = $event ? json_decode($event->details, true) : null;
                    $this->check(is_array($details) && is_string($details['request_hash'] ?? null)
                        && hash_equals($details['request_hash'], $requestHash), 'duplicate_mismatch');

                    return $existing;
                }

                $this->check(! $control->locked_through || $request['occurred_at'] > $control->locked_through, 'period_locked');
                $this->check($request['occurred_at'] <= now('Asia/Tehran')->toDateString(), 'future_date');
                $account = FinanceAccount::whereKey($request['funding_account_id'])->lockForUpdate()->first();
                $entry = BusinessEntry::whereKey($request['business_entry_id'])->lockForUpdate()->first();
                $this->check($account !== null && $account->type === 'vendor' && $account->currency_code === $request['original_currency']);
                $this->check($entry !== null && $entry->kind === 'expense' && $entry->currency_code === 'IRT'
                    && (int) $entry->amount > 0 && $entry->created_by === null
                    && $entry->occurred_at->toDateString() === $request['occurred_at']);
                $hasMorphSource = filled($entry->source_type) && (int) $entry->source_id > 0;
                $hasPeriodSource = filled($entry->period) && (int) $entry->ref_id > 0;
                $this->check($hasMorphSource || $hasPeriodSource);
                if ($request['source_type'] !== null || $request['source_id'] !== null) {
                    $this->check($hasMorphSource && $request['source_type'] === $entry->source_type
                        && $request['source_id'] === (int) $entry->source_id);
                }

                // A confirmed external debit remains unique even if someone later reverses its accounting voucher.
                $this->check(! FinanceVoucher::where('funding_account_id', $account->id)
                    ->whereRaw('LOWER(bank_reference) = ?', [strtolower($request['bank_reference'])])->exists(), 'supplier_reference_used');
                if ($request['related_voucher_id']) {
                    $topup = FinanceVoucher::whereKey($request['related_voucher_id'])->lockForUpdate()->first();
                    $this->check($topup !== null && $topup->type === 'vendor_topup' && ! $topup->reversal_of
                        && (int) $topup->target_account_id === (int) $account->id
                        && $topup->occurred_at->toDateString() <= $request['occurred_at']
                        && ! FinanceVoucher::where('reversal_of', $topup->id)->exists());
                }

                // Repricing a past debit after later pool movements can leave native units and IRT carrying value inconsistent.
                // Reconcile new debits in chronological order; unchanged replays returned before this guard.
                $this->check(! DB::table('finance_lines as supplier_line')
                    ->join('finance_vouchers as supplier_voucher', 'supplier_voucher.id', '=', 'supplier_line.voucher_id')
                    ->where('supplier_line.account_id', $account->id)
                    ->whereDate('supplier_voucher.occurred_at', '>', $request['occurred_at'])->exists());

                $balance = $this->journal->balance($account, $request['occurred_at']);
                $current = $this->journal->balance($account);
                $native = $request['original_minor'];
                $this->check($balance['native'] >= $native && $current['native'] >= $native
                    && $balance['native'] > 0 && $balance['irt'] > 0, 'insufficient_account');
                $carrying = (int) bcdiv(bcmul((string) $balance['irt'], (string) $native, 0), (string) $balance['native'], 0);
                $this->check($carrying > 0 && $carrying <= AccountingMoney::MAX, 'carrying_cost');
                $voucher = $this->journal->post([
                    'idempotency_key' => $request['idempotency_key'],
                    'type' => $carrying === (int) $entry->amount ? 'expense' : 'cost_adjustment',
                    'occurred_at' => $request['occurred_at'], 'service_line' => $request['service_line'],
                    'category' => $entry->category, 'amount_irt' => $carrying,
                    'original_currency' => $account->currency_code, 'original_minor' => $native,
                    'settlement_currency' => $account->currency_code, 'settlement_minor' => $native,
                    'funding_account_id' => (int) $account->id, 'business_entry_id' => (int) $entry->id,
                    'related_voucher_id' => $request['related_voucher_id'],
                    // Reserved identity ensures a different submission key cannot duplicate this provider debit.
                    'vendor' => 'supplier-account:'.$account->id,
                    'invoice_number' => 'debit:'.hash('sha256', strtolower($request['bank_reference'])),
                    'bank_reference' => $request['bank_reference'],
                    'fx_basis' => 'Historical weighted supplier-credit carrying value; verified debit.',
                    'note' => $request['note'], 'tax_status' => 'pending', 'vat_status' => 'none',
                ], $actor);
                $document = FinanceDocument::create([
                    'voucher_id' => $voucher->id, 'path' => $path,
                    'name' => mb_substr(basename($evidence->getClientOriginalName()), 0, 200),
                    'mime' => $evidence->getMimeType(), 'size' => $evidence->getSize(), 'sha256' => $receiptHash,
                ]);
                $this->journal->audit('supplier_credit_reconciled', $actor, $voucher->id, [
                    'request_hash' => $requestHash, 'business_entry_id' => (int) $entry->id,
                    'source_type' => $entry->source_type, 'source_id' => $entry->source_id,
                    'period' => $entry->period, 'ref_id' => $entry->ref_id,
                    'upstream_reference' => $request['bank_reference'],
                    'original_currency' => $account->currency_code, 'original_minor' => $native,
                    'amount_irt' => $carrying, 'estimated_amount_irt' => (int) $entry->amount,
                    'funding_account_id' => (int) $account->id,
                    'funding_account_snapshot' => $account->only(['id', 'name', 'type', 'currency_code', 'owner_name']),
                    'related_voucher_id' => $request['related_voucher_id'],
                    'supplier_name' => $request['vendor'], 'supplier_invoice_number' => $request['invoice_number'],
                    'document_id' => (int) $document->id, 'document_sha256' => $receiptHash,
                ]);

                return $voucher;
            }, 3);
        } finally {
            // A late exception after a successful commit must retain the original supporting document.
            try {
                if (! FinanceDocument::where('path', $path)->exists()) {
                    Storage::disk('local')->delete($path);
                }
            } catch (\Throwable $e) {
                ErrorTracker::note('finance', $e, ['area' => 'supplier-receipt-retention']);
            }
        }
    }
}
