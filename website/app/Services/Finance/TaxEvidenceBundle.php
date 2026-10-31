<?php

namespace App\Services\Finance;

use App\Models\BusinessEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceAsset;
use App\Models\FinanceDocument;
use App\Models\FinanceLine;
use App\Models\FinanceVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** A private evidence package, not a submitted return or official accounting certification. */
final class TaxEvidenceBundle
{
    public function build(FinanceFilter $scope): string
    {
        if (! $scope->from || ! $scope->to) {
            throw ValidationException::withMessages(['bundle' => __('finance.bundle_dates')]);
        }
        $disk = Storage::disk('local');
        $disk->makeDirectory('finance/exports');
        $path = tempnam($disk->path('finance/exports'), 'tax-');
        if ($path === false) {
            throw new \RuntimeException('Private export storage unavailable');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Evidence archive unavailable');
        }
        $manifest = [];
        $size = 0;
        $csv = function (array $header, iterable $rows): string {
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($stream, array_map(fn ($x) => is_string($x) && preg_match('/^\s*[=+@\-]/u', $x) ? "'".$x : $x, $row), escape: '');
            }
            rewind($stream);
            $data = stream_get_contents($stream);
            fclose($stream);

            return $data;
        };
        $add = function (string $name, string $data) use ($zip, &$manifest) {
            if (! $zip->addFromString($name, $data)) {
                throw new \RuntimeException('Archive write failed');
            }$manifest[] = ['file' => $name, 'sha256' => hash('sha256', $data), 'bytes' => strlen($data)];
        };
        try {
            $s = new FinanceFilter([], $scope->from, $scope->to);
            $report = app(AccountingReport::class)->report($s);
            $add('report.json', json_encode(['generated_utc' => gmdate('c'), 'from_tehran' => $scope->from->toDateString(), 'to_tehran' => $scope->to->toDateString(),
                'base_currency' => 'IRT', 'rials_per_toman' => 10, 'status' => 'management_estimate_not_filed', 'report' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $ledgerRows = (function () use ($s) {
                foreach ($s->apply(BusinessEntry::query())->orderBy('id')->lazyById(500) as $e) {
                    yield [$e->id, $e->occurred_at->toDateString(), $e->kind, $e->category, $e->currency_code, $e->amount,
                        $e->currency_code === 'IRT' ? bcmul((string) $e->amount, '10', 0) : '', $e->direction, $e->source_type, $e->source_id, $e->note];
                }
            })();
            $add('ledger.csv', $csv(['Entry', 'Date Tehran', 'Kind', 'Category', 'Currency', 'Amount minor', 'Equivalent IRR if IRT', 'Direction', 'Source type', 'Source ID', 'Note'], $ledgerRows));
            $vouchers = FinanceVoucher::whereDate('occurred_at', '>=', $scope->from->toDateString())
                ->whereDate('occurred_at', '<=', $scope->to->toDateString())->orderBy('id')->get();
            $ids = $vouchers->pluck('id')->merge($vouchers->pluck('reversal_of')->filter())->unique();
            foreach ($vouchers as $v) {
                if ($v->asset_period && preg_match('/^asset-(\d+)-/', $v->asset_period, $m)) {
                    $purchaseId = FinanceAsset::whereKey((int) $m[1])->value('voucher_id');
                    if ($purchaseId) {
                        $ids->push($purchaseId);
                    }
                }
            }
            $ids = $ids->unique();
            $add('vouchers.csv', $csv(['ID', 'Date Tehran', 'Type', 'Service', 'Gross IRT', 'Gross IRR', 'Input VAT IRT', 'Vendor', 'Invoice', 'Bank reference', 'FX basis', 'Original currency', 'Original minor', 'Settlement currency', 'Settlement minor', 'Tax proposal', 'Reversal of', 'Existing cost corrected', 'Funding account ID', 'Target account ID', 'Related voucher ID', 'Existing business entry ID'],
                $vouchers->map(fn ($v) => [$v->id, $v->occurred_at->toDateString(), $v->type, $v->service_line, $v->amount_irt, bcmul((string) $v->amount_irt, '10', 0), $v->input_vat_irt, $v->vendor, $v->invoice_number, $v->bank_reference, $v->fx_basis, $v->original_currency, $v->original_minor, $v->settlement_currency, $v->settlement_minor, $v->tax_status, $v->reversal_of, $v->adjusts_entry_id, $v->funding_account_id, $v->target_account_id, $v->related_voucher_id, $v->business_entry_id])));
            $add('journal.csv', $csv(['Voucher', 'Account code', 'Account ID', 'Debit IRT', 'Credit IRT', 'Debit native minor', 'Credit native minor'],
                FinanceLine::whereIn('voucher_id', $ids)->orderBy('id')->get()->map(fn ($l) => [$l->voucher_id, $l->code, $l->account_id, $l->debit_irt, $l->credit_irt, $l->native_debit, $l->native_credit])));
            $add('tax-review-history.csv', $csv(['Voucher', 'Status', 'Reference', 'Reviewer ID', 'Reviewed UTC'],
                DB::table('finance_tax_reviews')->whereIn('voucher_id', $ids)->orderBy('id')->get()->map(fn ($r) => [$r->voucher_id, $r->status, $r->reference, $r->created_by, $r->created_at])));
            if (Schema::hasTable('wallet_refund_requests')) {
                $refunds = \App\Models\WalletRefundRequest::where(function ($q) use ($ids, $scope) {
                    $q->whereIn('voucher_id', $ids)->orWhereBetween('created_at', [
                        $scope->from->copy()->startOfDay()->utc(), $scope->to->copy()->endOfDay()->utc()]);
                })->orderBy('id')->get();
                $relatedVoucherIds = $refunds->pluck('voucher_id')->filter()->unique();
                $relatedVouchers = FinanceVoucher::whereIn('id', $relatedVoucherIds)->get()->keyBy('id');
                $fundingAccounts = FinanceAccount::whereIn('id', $relatedVouchers->pluck('funding_account_id')->filter()->unique())->get()->keyBy('id');
                $receiptDocuments = FinanceDocument::whereIn('id', $refunds->pluck('document_id')->filter()->unique())->get()->keyBy('id');
                $events = Schema::hasTable('wallet_refund_events') ? DB::table('wallet_refund_events')->where(function ($q) use ($refunds) {
                    $q->whereIn('request_id', $refunds->pluck('id'))->orWhere(function ($q) use ($refunds) {
                        $q->whereNull('request_id')->whereIn('customer_id', $refunds->pluck('customer_id'));
                    });
                })->orderBy('id')->get() : collect();
                $paidEvents = $events->where('action', 'paid')->unique('request_id')->keyBy('request_id');
                foreach ($refunds as $refund) {
                    $source = $refund->source_payment_id ? \App\Models\Payment::find($refund->source_payment_id) : null;
                    $voucher = $relatedVouchers->get($refund->voucher_id);
                    $fundingSnapshot = WalletRefunds::fundingAccountSnapshot($paidEvents->get($refund->id));
                    $fundingAccountId = $fundingSnapshot['id'] ?? $voucher?->funding_account_id;
                    $fundingLegacyFallback = $refund->status === 'paid' && $fundingSnapshot === null;
                    $fundingCurrent = $fundingLegacyFallback
                        ? $fundingAccounts->get($fundingAccountId)?->only(['id', 'name', 'type', 'currency_code', 'owner_name']) : null;
                    $receipt = $receiptDocuments->get($refund->document_id);
                    $add('wallet-refunds/'.$refund->public_id.'.json', json_encode([
                        'request' => $refund->public_id, 'customer' => $refund->customer->code, 'amount_irt' => $refund->amount_irt,
                        'status' => $refund->status, 'requested_utc' => $refund->created_at->toIso8601String(),
                        'bank_destination_at_request' => $refund->bank_snapshot, 'customer_note' => $refund->customer_note,
                        'review_note' => $refund->review_note, 'source_note' => $refund->source_note,
                        'reviewed_by' => $refund->reviewed_by, 'reviewed_utc' => $refund->reviewed_at?->toIso8601String(),
                        'payout_date_tehran' => $refund->payout_date?->toDateString(), 'bank_reference' => $refund->bank_reference,
                        'paid_by' => $refund->paid_by, 'paid_utc' => $refund->paid_at?->toIso8601String(),
                        'voucher' => $refund->voucher_id, 'receipt_document' => $refund->document_id, 'wallet_entry' => $refund->credit_entry_id,
                        'funding_account_id' => $fundingAccountId, 'funding_account_snapshot' => $fundingSnapshot,
                        'funding_account_current' => $fundingCurrent, 'funding_account_legacy_fallback' => $fundingLegacyFallback,
                        'bank_transaction_id' => $voucher?->bank_transaction_id,
                        'receipt_sha256' => $receipt && (int) $receipt->voucher_id === (int) $refund->voucher_id ? $receipt->sha256 : null,
                        'source_payment' => $source?->only(['id', 'invoice_id', 'gateway', 'currency_code', 'amount', 'status', 'ref_id', 'paid_at']),
                        'accounting_treatment' => 'wallet_liability_settlement_no_additional_revenue_or_vat_deduction',
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                }
                $add('wallet-refund-related-journal.json', json_encode([
                    'vouchers' => $relatedVouchers->values(),
                    'lines' => FinanceLine::whereIn('voucher_id', $relatedVoucherIds)->get(),
                    'note' => 'Related records may fall outside the selected period; they support refund audit history.',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $ids = $ids->merge($relatedVoucherIds)->unique();
                $add('wallet-refund-audit.csv', $csv(['ID', 'Customer ID', 'Request ID', 'Actor type', 'Actor ID', 'Actor name at action', 'Action', 'Details', 'UTC'],
                    $events->map(fn ($e) => [$e->id, $e->customer_id, $e->request_id, $e->actor_type, $e->actor_id, $e->actor_name, $e->action, $e->details, $e->created_at])));
            }
            // Follow explicit funding parents, including proof outside the reporting window.
            // Report totals above stay date-scoped; these records are supporting evidence only.
            $ids = $ids->filter()->unique()->values();
            if (Schema::hasColumn('finance_vouchers', 'related_voucher_id')) {
                $frontier = $ids;
                for ($depth = 0; $frontier->isNotEmpty(); $depth++) {
                    if ($depth >= 128) {
                        throw ValidationException::withMessages(['bundle' => __('finance.bundle_chain_limit')]);
                    }
                    $parents = FinanceVoucher::whereIn('id', $frontier)->get(['related_voucher_id', 'reversal_of'])
                        ->flatMap(fn ($row) => [$row->related_voucher_id, $row->reversal_of])->filter()->unique();
                    $frontier = $parents->diff($ids)->values();
                    $ids = $ids->merge($frontier)->unique()->values();
                }
            }
            $flowVouchers = FinanceVoucher::whereIn('id', $ids)->orderBy('id')->get();
            $flowLines = FinanceLine::whereIn('voucher_id', $ids)->orderBy('id')->get();
            $flowAudit = DB::table('finance_audit_events')->whereIn('subject_id', $ids)
                ->whereIn('action', ['voucher_posted', 'supplier_credit_reconciled', 'voucher_reversed'])
                ->orderBy('id')->get();
            $accountIds = $flowLines->pluck('account_id')->merge($flowVouchers->pluck('funding_account_id'))
                ->merge($flowVouchers->pluck('target_account_id'))->filter()->unique();
            $currentAccounts = FinanceAccount::whereIn('id', $accountIds)->orderBy('id')->get()->keyBy('id');
            $masked = static fn ($account) => $account ? (method_exists($account, 'maskedBankIdentity')
                ? $account->maskedBankIdentity() : $account->only(['id', 'name', 'type', 'currency_code', 'owner_name'])) : null;
            $identity = $flowVouchers->map(function ($voucher) use ($flowAudit, $currentAccounts, $masked) {
                $event = $flowAudit->first(fn ($event) => (int) $event->subject_id === (int) $voucher->id && $event->action === 'voucher_posted');
                $details = $event ? json_decode($event->details, true) : null;
                $funding = is_array($details) ? ($details['funding_account_snapshot'] ?? null) : null;
                $target = is_array($details) ? ($details['target_account_snapshot'] ?? null) : null;
                $legacy = (! $funding && $voucher->funding_account_id) || (! $target && $voucher->target_account_id);

                return ['voucher_id' => $voucher->id, 'captured_utc' => $event?->created_at,
                    'funding_account_snapshot' => $funding, 'target_account_snapshot' => $target,
                    'legacy_current_identity_only' => (bool) $legacy,
                    'funding_account_current' => ! $funding ? $masked($currentAccounts->get($voucher->funding_account_id)) : null,
                    'target_account_current' => ! $target ? $masked($currentAccounts->get($voucher->target_account_id)) : null];
            });
            $add('funding-flow-vouchers.json', json_encode(['scope' => 'supporting_chain_may_include_other_periods',
                'vouchers' => $flowVouchers, 'identity' => $identity], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $add('funding-flow-journal.csv', $csv(['Voucher', 'Account code', 'Account ID', 'Debit IRT', 'Credit IRT', 'Debit native minor', 'Credit native minor'],
                $flowLines->map(fn ($line) => [$line->voucher_id, $line->code, $line->account_id, $line->debit_irt, $line->credit_irt, $line->native_debit, $line->native_credit])));
            $add('funding-flow-audit.json', json_encode($flowAudit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $add('accounts-current-masked.json', json_encode(['historical_identity' => false,
                'accounts' => $currentAccounts->values()->map($masked)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $documents = FinanceDocument::whereIn('voucher_id', $ids)->orderBy('id')->get();
            foreach ($documents as $doc) {
                if (! $disk->exists($doc->path) || ! hash_equals($doc->sha256, hash_file('sha256', $disk->path($doc->path)))) {
                    throw new \RuntimeException('Evidence missing or hash mismatch for document '.$doc->id);
                }
                $size += (int) $doc->size;
                if ($size > 250 * 1024 * 1024) {
                    throw ValidationException::withMessages(['bundle' => __('finance.bundle_size')]);
                }
                $ext = match ($doc->mime) {
                    'application/pdf' => 'pdf','image/png' => 'png',default => 'jpg'
                };
                $name = 'evidence/voucher-'.$doc->voucher_id.'/document-'.$doc->id.'.'.$ext;
                $zip->addFile($disk->path($doc->path), $name);
                $manifest[] = ['file' => $name, 'original_name' => $doc->name, 'sha256' => $doc->sha256, 'bytes' => $doc->size];
            }
            $bank = DB::table('finance_bank_transactions')->whereBetween('occurred_at', [$scope->from->toDateString(), $scope->to->toDateString()])->orderBy('id')->get();
            $add('bank-transactions.csv', $csv(['ID', 'Account', 'Date Tehran', 'Currency', 'Credit minor', 'Debit minor', 'Reference', 'Fingerprint', 'Import ID'],
                $bank->map(fn ($b) => [$b->id, $b->account_id, $b->occurred_at, $b->currency_code, $b->credit_minor, $b->debit_minor, $b->reference, $b->fingerprint, $b->import_id])));
            $add('bank-matches.csv', $csv(['Match ID', 'Bank row', 'Payment', 'Net bank minor', 'Allocated fee minor', 'Evidence reference', 'Voided UTC', 'Void reason'],
                DB::table('finance_bank_matches')->whereIn('bank_transaction_id', $bank->pluck('id'))->get()->map(fn ($m) => [$m->id, $m->bank_transaction_id, $m->payment_id, $m->amount_minor, $m->fee_minor, $m->reference, $m->voided_at, $m->void_reason])));
            $matchedPayments = DB::table('finance_bank_matches')->whereIn('bank_transaction_id', $bank->pluck('id'))->pluck('payment_id')->unique();
            $add('bank-settlement-payments.csv', $csv(['Payment ID', 'Invoice ID', 'Gateway', 'Currency', 'Gross payment native minor', 'Verified fee native minor', 'Fee bearer', 'External reference', 'Paid UTC'],
                \App\Models\Payment::whereIn('id', $matchedPayments)->orderBy('id')->get()->map(fn ($payment) => [
                    $payment->id, $payment->invoice_id, $payment->gateway, $payment->currency_code, $payment->amount,
                    $payment->fee, $payment->fee_type, $payment->ref_id, $payment->paid_at])));
            foreach (DB::table('finance_bank_imports')->whereIn('id', $bank->pluck('import_id')->unique())->get() as $i) {
                if (! $disk->exists($i->path) || ! hash_equals($i->sha256, hash_file('sha256', $disk->path($i->path)))) {
                    throw new \RuntimeException('Bank evidence missing or changed');
                }
                $bytes = $disk->size($i->path);
                $size += $bytes;
                if ($size > 250 * 1024 * 1024) {
                    throw ValidationException::withMessages(['bundle' => __('finance.bundle_size')]);
                }
                $name = 'evidence/bank/import-'.$i->id.'.csv';
                $zip->addFile($disk->path($i->path), $name);
                $manifest[] = ['file' => $name, 'sha256' => $i->sha256, 'bytes' => $bytes];
            }
            $add('README.txt', "ServerNet financial evidence package\nManagement estimates only: not filed with the tax authority.\nDates are Gregorian calendar days in Asia/Tehran.\nIRT is toman; IRR equals IRT x 10. Foreign amounts are minor units with explicit currencies.\nSales, external collections and wallet liabilities are distinct. Wallet payouts settle customer liabilities and are not an additional expense, sales reversal or automatic VAT deduction. Related refund evidence can include supporting vouchers outside the selected period; period report totals remain date-scoped. Company-funded payouts reduce bank assets; partner-funded payouts increase the partner current-account payable. The original customer payment is separate from the outgoing funding account. funding_account_snapshot records identity at payout; funding_account_current is current data only when funding_account_legacy_fallback is true and does not establish historical identity. Supplier topups and fixed-asset purchases are not immediate expenses. Company business advances are partner receivables, not personal bank cash. A private personal currency conversion does not itself post company revenue, cost or another partner claim. funding-flow files follow explicit parent vouchers and retain masked identity captured at posting; current account data is explicitly labelled and never establishes historical identity. Verified supplier debits consume prepaid credit and link the existing automatic cost; estimated costs without debit proof remain reconciliation candidates. Bank match allocations retain the bank row currency; IRT and IRR are reconciled exactly at 1:10 and gateway fees are not expensed again by matching.\nThe supplemental double-entry journal covers documented transactions, not a certified full balance sheet.\nTax eligibility proposals require review of current law, electronic invoices, foreign service/import tax and the actual taxpayer file.\nProof files can contain an entire statement, including dates outside the selected window. Keep this archive private.\nMissing costs, refunds or untranslated foreign receipts must be reconciled before filing.\n");
            $zip->addFromString('manifest-sha256.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $zip->close()) {
                throw new \RuntimeException('Evidence archive finalization failed');
            }

            return $path;
        } catch (\Throwable $e) {
            try {
                $zip->close();
            } catch (\Throwable) {
            } if (is_file($path)) {
                unlink($path);
            } throw $e;
        }
    }
}
