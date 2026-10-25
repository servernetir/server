<?php

namespace App\Services\Finance;

use App\Models\BusinessEntry;
use App\Models\FinanceAsset;
use App\Models\FinanceDocument;
use App\Models\FinanceLine;
use App\Models\FinanceVoucher;
use Illuminate\Support\Facades\DB;
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
            $vouchers = FinanceVoucher::whereBetween('occurred_at', [$scope->from->toDateString(), $scope->to->toDateString()])->orderBy('id')->get();
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
            $add('vouchers.csv', $csv(['ID', 'Date Tehran', 'Type', 'Service', 'Gross IRT', 'Gross IRR', 'Input VAT IRT', 'Vendor', 'Invoice', 'Bank reference', 'FX basis', 'Original currency', 'Original minor', 'Settlement currency', 'Settlement minor', 'Tax proposal', 'Reversal of', 'Existing cost corrected'],
                $vouchers->map(fn ($v) => [$v->id, $v->occurred_at->toDateString(), $v->type, $v->service_line, $v->amount_irt, bcmul((string) $v->amount_irt, '10', 0), $v->input_vat_irt, $v->vendor, $v->invoice_number, $v->bank_reference, $v->fx_basis, $v->original_currency, $v->original_minor, $v->settlement_currency, $v->settlement_minor, $v->tax_status, $v->reversal_of, $v->adjusts_entry_id])));
            $add('journal.csv', $csv(['Voucher', 'Account code', 'Account ID', 'Debit IRT', 'Credit IRT', 'Debit native minor', 'Credit native minor'],
                FinanceLine::whereIn('voucher_id', $ids)->orderBy('id')->get()->map(fn ($l) => [$l->voucher_id, $l->code, $l->account_id, $l->debit_irt, $l->credit_irt, $l->native_debit, $l->native_credit])));
            $add('tax-review-history.csv', $csv(['Voucher', 'Status', 'Reference', 'Reviewer ID', 'Reviewed UTC'],
                DB::table('finance_tax_reviews')->whereIn('voucher_id', $ids)->orderBy('id')->get()->map(fn ($r) => [$r->voucher_id, $r->status, $r->reference, $r->created_by, $r->created_at])));
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
            $add('README.txt', "ServerNet financial evidence package\nManagement estimates only: not filed with the tax authority.\nDates are Gregorian calendar days in Asia/Tehran.\nIRT is toman; IRR equals IRT x 10. Foreign amounts are minor units with explicit currencies.\nSales, external collections and wallet liabilities are distinct. Supplier topups and fixed-asset purchases are not immediate expenses.\nThe supplemental double-entry journal covers documented transactions, not a certified full balance sheet.\nTax eligibility proposals require review of current law, electronic invoices, foreign service/import tax and the actual taxpayer file.\nProof files can contain an entire statement, including dates outside the selected window. Keep this archive private.\nMissing costs, refunds or untranslated foreign receipts must be reconciled before filing.\n");
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
