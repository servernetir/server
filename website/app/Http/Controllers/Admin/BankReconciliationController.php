<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinanceAccount;
use App\Services\Finance\AccountingMoney;
use App\Services\Finance\BankReconciliation;
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\FinanceFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class BankReconciliationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(app(DocumentedAccounting::class)->ready(), 409);
        $f = FinanceFilter::fromRequest($request);
        $q = DB::table('finance_bank_transactions as b')->leftJoin('finance_accounts as a', 'a.id', '=', 'b.account_id');
        if ($f->from) {
            $q->whereDate('b.occurred_at', '>=', $f->from->toDateString());
        }
        if ($f->to) {
            $q->whereDate('b.occurred_at', '<=', $f->to->toDateString());
        }
        $request->validate(['account_id' => ['nullable', 'integer']]);
        if ($request->filled('account_id')) {
            $q->where('b.account_id', $request->integer('account_id'));
        }
        if (! empty($f->values['q'])) {
            $q->where('b.reference', 'like', '%'.$f->values['q'].'%');
        }
        $matched = DB::table('finance_bank_matches')->whereNull('voided_at')->selectRaw('bank_transaction_id,SUM(amount_minor) as matched,SUM(fee_minor) as fees')->groupBy('bank_transaction_id');
        $q->leftJoinSub($matched, 'm', fn ($join) => $join->on('m.bank_transaction_id', '=', 'b.id'));
        $summary = (clone $q)->selectRaw('b.account_id,a.name,b.currency_code,SUM(b.credit_minor) as credits,SUM(b.debit_minor) as debits,SUM(COALESCE(m.matched,0)) as matched,SUM(b.credit_minor-COALESCE(m.matched,0)) as unmatched')->groupBy('b.account_id', 'a.name', 'b.currency_code')->get();
        $rows = (clone $q)->select('b.*', 'a.name as account_name')->orderByDesc('b.occurred_at')->orderByDesc('b.id')->paginate(50)->withQueryString();
        foreach ($rows as $row) {
            $row->matches = DB::table('finance_bank_matches')->where('bank_transaction_id', $row->id)->whereNull('voided_at')->get();
            $row->unmatched = (int) $row->credit_minor - (int) $row->matches->sum('amount_minor');
        }

        return view('admin.bank-reconciliation', ['accounts' => FinanceAccount::where('type', 'bank')->get(), 'rows' => $rows, 'summary' => $summary]);
    }

    public function import(Request $request, BankReconciliation $service)
    {
        abort_unless(app(DocumentedAccounting::class)->ready(), 409);
        $d = $request->validate(['account_id' => ['required', 'integer'], 'statement' => ['required', 'file', 'max:5120']]);
        $account = FinanceAccount::findOrFail($d['account_id']);
        $csv = file_get_contents($request->file('statement')->getRealPath());
        $path = $request->file('statement')->store('finance/bank-statements', 'local');
        try {
            $count = $service->import($account, $csv, $path, $request->user()->id);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        if (! DB::table('finance_bank_imports')->where('path', $path)->exists()) {
            Storage::disk('local')->delete($path);
        }

        return back()->with('ok', __('finance.saved').' ('.$count.')');
    }

    public function download(int $import)
    {
        $row = DB::table('finance_bank_imports')->where('id', $import)->first();
        abort_unless($row && Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->download($row->path, 'bank-statement-'.$import.'.csv', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function postCollection(Request $request, DocumentedAccounting $journal)
    {
        $d = $request->validate(['bank_id' => ['required', 'integer'], 'amount_irt' => ['required', 'string', 'max:24'], 'fx_basis' => ['nullable', 'string', 'max:255']]);
        $b = DB::table('finance_bank_transactions')->where('id', $d['bank_id'])->first();
        abort_unless($b, 404);
        $v = $journal->post(['idempotency_key' => (string) Str::uuid(), 'type' => 'bank_collection',
            'occurred_at' => $b->occurred_at, 'service_line' => 'other', 'category' => 'other', 'amount_irt' => AccountingMoney::minor($d['amount_irt'], 'IRT'),
            'original_currency' => $b->currency_code, 'original_minor' => (int) $b->credit_minor, 'settlement_minor' => 0, 'funding_account_id' => null,
            'target_account_id' => (int) $b->account_id, 'bank_transaction_id' => $b->id, 'bank_reference' => $b->reference, 'fx_basis' => $d['fx_basis'] ?? null,
            'tax_status' => 'pending', 'vat_status' => 'none', 'input_vat_irt' => 0, 'note' => 'Bank collection #'.$b->id], $request->user()->id);

        return redirect()->route('admin.accounting.voucher', $v)->with('ok', __('finance.saved'));
    }

    public function match(Request $request, BankReconciliation $service)
    {
        $d = $request->validate(['bank_id' => ['required', 'integer'], 'payment_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:32'], 'fee' => ['required', 'string', 'max:32'], 'reference' => ['required', 'string', 'min:3', 'max:255']]);
        $b = DB::table('finance_bank_transactions')->where('id', $d['bank_id'])->first();
        abort_unless($b, 404);
        $service->match($b->id, $d['payment_id'], AccountingMoney::minor($d['amount'], $b->currency_code), AccountingMoney::minor($d['fee'], $b->currency_code), $d['reference'], $request->user()->id);

        return back()->with('ok', __('finance.saved'));
    }

    public function void(Request $request, int $match, BankReconciliation $service)
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        $service->void($match, $d['reason'], $request->user()->id);

        return back()->with('ok', __('finance.saved'));
    }
}
