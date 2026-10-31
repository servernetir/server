<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinanceAccount;
use App\Models\FinanceAsset;
use App\Models\FinanceDocument;
use App\Models\FinanceLine;
use App\Models\FinanceTaxProfile;
use App\Models\FinanceVoucher;
use App\Services\Finance\AccountingMoney;
use App\Services\Finance\AccountingReport;
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\FinanceFilter;
use App\Services\Finance\FinanceAccountRegistry;
use App\Services\Finance\SupplierCreditReconciliation;
use App\Services\Finance\InvestmentAnalysis;
use App\Services\Finance\TaxEvidenceBundle;
use App\Support\FinanceDates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccountingController extends Controller
{
    public function __construct(private DocumentedAccounting $journal, private AccountingReport $reports) {}

    public function index(Request $request)
    {
        $filter = FinanceFilter::fromRequest($request);
        $request->validate(['service_line' => ['nullable', Rule::in(DocumentedAccounting::SERVICES)]]);
        $ready = $this->journal->ready();
        $q = $ready ? FinanceVoucher::query() : null;
        if ($filter->from) {
            $q?->whereDate('occurred_at', '>=', $filter->from->toDateString());
        }
        if ($filter->to) {
            $q?->whereDate('occurred_at', '<=', $filter->to->toDateString());
        }
        if ($request->filled('service_line')) {
            $q?->where('service_line', $request->string('service_line')->toString());
        }
        if ($request->filled('q')) {
            $term = $request->string('q')->toString();
            $q?->where(fn ($w) => $w->where('note', 'like', '%'.$term.'%')->orWhere('vendor', 'like', '%'.$term.'%')
                ->orWhere('invoice_number', 'like', '%'.$term.'%')->orWhere('bank_reference', 'like', '%'.$term.'%'));
        }

        return view('admin.accounting', [
            'ready' => $ready, 'filters' => $filter->values, 'report' => $this->reports->report($filter),
            'vouchers' => $q?->orderByDesc('occurred_at')->orderByDesc('id')->paginate(30)->withQueryString() ?? collect(),
            'accounts' => $ready ? FinanceAccount::orderBy('name')->get() : collect(),
            'assets' => $ready ? FinanceAsset::whereNotIn('voucher_id', FinanceVoucher::whereNotNull('reversal_of')->select('reversal_of'))->orderByDesc('id')->paginate(20, ['*'], 'assets_page')->withQueryString() : collect(),
            'closedThrough' => $ready ? DB::table('finance_controls')->where('id', 1)->value('locked_through') : null,
        ]);
    }

    public function account(Request $request)
    {
        abort_unless($this->journal->ready(), 409);
        try {
            $d = $request->validate([
                'account_id' => ['nullable', 'integer', 'min:1'],
                'name' => ['required', 'string', 'max:150'], 'type' => ['required', 'in:bank,partner,vendor'],
                'currency_code' => ['required', Rule::in(array_keys(AccountingMoney::EXPONENTS))],
                'owner_name' => ['nullable', 'string', 'max:150'], 'partner_key' => ['nullable', 'string', 'max:80'],
                'bank_name' => ['nullable', 'string', 'max:150'],
                'iban' => ['nullable', 'string', 'max:80'], 'card_number' => ['nullable', 'string', 'max:80'],
            ]);
            app(FinanceAccountRegistry::class)->createOrUpdate($d, $request->user()->id);
        } catch (ValidationException $e) {
            // Bank identifiers must never enter flashed form data, including validation failures.
            if ($request->expectsJson()) {
                return response()->json(['message' => __('finance.invalid_bank_identity'), 'errors' => $e->errors()], 422);
            }

            return back()->withErrors($e->errors(), $e->errorBag)
                ->withInput($request->except(['iban', 'card_number', 'bank_details']));
        }

        return back()->with('ok', __('finance.saved'));
    }

    public function store(Request $request)
    {
        FinanceDates::normalize($request, ['occurred_at', 'in_service_at']);
        if ($request->input('workflow') === 'supplier_debit') {
            $d = $request->validate([
                'idempotency_key' => ['required', 'uuid'], 'funding_account_id' => ['required', 'integer', 'min:1'],
                'business_entry_id' => ['required', 'integer', 'min:1'], 'occurred_at' => ['required', 'date_format:Y-m-d'],
                'service_line' => ['required', Rule::in(DocumentedAccounting::SERVICES)],
                'original_currency' => ['required', Rule::in(array_keys(AccountingMoney::EXPONENTS))],
                'original_amount' => ['required', 'string', 'max:32'], 'bank_reference' => ['required', 'string', 'min:4', 'max:150'],
                'vendor' => ['nullable', 'string', 'max:150'], 'invoice_number' => ['nullable', 'string', 'max:150'],
                'note' => ['nullable', 'string', 'max:2000'], 'related_voucher_id' => ['nullable', 'integer', 'min:1'],
                'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            ]);
            $d['original_minor'] = AccountingMoney::minor($d['original_amount'], $d['original_currency'], 'original_amount');
            unset($d['original_amount'], $d['evidence']);
            $v = app(SupplierCreditReconciliation::class)->reconcile($d, $request->user()->id, $request->file('evidence'));

            return redirect()->route('admin.accounting.voucher', $v)->with('ok', __('finance.saved'));
        }
        $d = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'type' => ['required', Rule::in(DocumentedAccounting::TYPES)],
            'occurred_at' => ['required', 'date_format:Y-m-d'], 'service_line' => ['required', Rule::in(DocumentedAccounting::SERVICES)],
            'category' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/D'],
            'vendor' => ['nullable', 'string', 'max:150'], 'invoice_number' => ['nullable', 'string', 'max:150'],
            'bank_reference' => ['nullable', 'string', 'max:150'], 'fx_basis' => ['nullable', 'string', 'max:255'],
            'note' => ['required', 'string', 'min:3', 'max:2000'], 'amount_irt' => ['required', 'string', 'max:24'],
            'original_currency' => ['required', Rule::in(array_keys(AccountingMoney::EXPONENTS))],
            'original_amount' => ['required', 'string', 'max:32'], 'settlement_currency' => ['nullable', Rule::in(array_keys(AccountingMoney::EXPONENTS))],
            'settlement_amount' => ['nullable', 'string', 'max:32'], 'funding_account_id' => ['nullable', 'integer'],
            'funding_mode' => ['nullable', Rule::in(['personal', 'advance'])], 'related_voucher_id' => ['nullable', 'integer', 'min:1'],
            'target_account_id' => ['nullable', 'integer'], 'bank_transaction_id' => ['nullable', 'integer'], 'business_entry_id' => ['nullable', 'integer'],
            'tax_status' => ['required', 'in:pending,rejected'], 'tax_reference' => ['nullable', 'string', 'max:255'],
            'input_vat_irt' => ['nullable', 'string', 'max:24'], 'vat_status' => ['required', 'in:none,eligible'],
            'asset_name' => ['nullable', 'string', 'max:150'], 'asset_serial' => ['nullable', 'string', 'max:150'],
            'in_service_at' => ['nullable', 'date_format:Y-m-d'], 'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'residual_irt' => ['nullable', 'string', 'max:24'],
        ]);
        $d['amount_irt'] = AccountingMoney::minor($d['amount_irt'], 'IRT', 'amount_irt');
        $d['original_minor'] = AccountingMoney::minor($d['original_amount'], $d['original_currency'], 'original_amount');
        $d['settlement_minor'] = empty($d['settlement_amount']) ? 0 : AccountingMoney::minor($d['settlement_amount'], $d['settlement_currency'] ?? 'IRT', 'settlement_amount');
        $d['input_vat_irt'] = AccountingMoney::minor(($d['input_vat_irt'] ?? '0') ?: '0', 'IRT', 'input_vat_irt');
        $d['residual_irt'] = AccountingMoney::minor(($d['residual_irt'] ?? '0') ?: '0', 'IRT', 'residual_irt');
        unset($d['original_amount'],$d['settlement_amount']);
        $v = $this->journal->post($d, $request->user()->id);

        return redirect()->route('admin.accounting.voucher', $v)->with('ok', __('finance.saved'));
    }

    public function show(FinanceVoucher $voucher)
    {
        $postedEvent = DB::table('finance_audit_events')->where('action', 'voucher_posted')->where('subject_id', $voucher->id)->orderBy('id')->first();
        $postedDetails = $postedEvent ? (json_decode($postedEvent->details, true) ?: []) : [];
        $accountSnapshots = ['funding' => $postedDetails['funding_account_snapshot'] ?? null, 'target' => $postedDetails['target_account_snapshot'] ?? null];
        $currentAccountIdentities = [];
        foreach (['funding' => $voucher->funding_account_id, 'target' => $voucher->target_account_id] as $role => $id) {
            $currentAccountIdentities[$role] = ! $accountSnapshots[$role] && $id ? FinanceAccount::find($id)?->maskedBankIdentity() : null;
        }
        $relatedId = $postedDetails['related_voucher_id'] ?? null;

        return view('admin.accounting-voucher', ['voucher' => $voucher,
            'postedDetails' => $postedDetails, 'accountSnapshots' => $accountSnapshots, 'currentAccountIdentities' => $currentAccountIdentities,
            'relatedVoucher' => $relatedId ? FinanceVoucher::find($relatedId) : null,
            'lines' => FinanceLine::where('voucher_id', $voucher->id)->get(),
            'documents' => FinanceDocument::where('voucher_id', $voucher->id)->get(),
            'reversal' => FinanceVoucher::where('reversal_of', $voucher->id)->first(),
            'reviews' => DB::table('finance_tax_reviews')->where('voucher_id', $voucher->id)->orderByDesc('id')->get()]);
    }

    public function reverse(Request $request, FinanceVoucher $voucher)
    {
        FinanceDates::normalize($request, ['occurred_at']);
        $d = $request->validate(['occurred_at' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $r = $this->journal->reverse($voucher, $d['occurred_at'], $d['reason'], $request->user()->id);

        return redirect()->route('admin.accounting.voucher', $r)->with('ok', __('finance.saved'));
    }

    public function evidence(Request $request, FinanceVoucher $voucher)
    {
        $request->validate(['evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $file = $request->file('evidence');
        $path = $file->store('finance/'.$voucher->id, 'local');
        try {
            DB::transaction(function () use ($request, $file, $path, $voucher) {
                FinanceDocument::create(['voucher_id' => $voucher->id, 'path' => $path,
                    'name' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'mime' => $file->getMimeType(),
                    'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())]);
                $this->journal->audit('evidence_added', $request->user()->id, $voucher->id);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return back()->with('ok', __('finance.saved'));
    }

    public function download(FinanceDocument $document)
    {
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->name,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function depreciate(Request $request, FinanceAsset $asset)
    {
        if ($request->exists('period_date')) {
            FinanceDates::normalize($request, ['period_date']);
            $date = $request->validate(['period_date' => ['required', 'date_format:Y-m-d']]);
            $request->merge(['period' => substr($date['period_date'], 0, 7)]);
        }
        $d = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        $v = $this->journal->depreciate($asset, $d['period'], $request->user()->id);

        return redirect()->route('admin.accounting.voucher', $v)->with('ok', __('finance.saved'));
    }

    public function taxProfile(Request $request)
    {
        FinanceDates::normalize($request, ['from_date', 'to_date']);
        abort_unless($this->journal->ready(), 409);
        $d = $request->validate(['from_date' => ['required', 'date_format:Y-m-d'], 'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'legal_name' => ['required', 'string', 'max:150'], 'entity_type' => ['required', 'in:corporate,individual'],
            'income_rate' => ['required', 'numeric', 'min:0', 'max:100'], 'loss_relief_irt' => ['required', 'string', 'max:24'],
            'other_addbacks_irt' => ['required', 'string', 'max:24'], 'vat_carry_irt' => ['required', 'string', 'max:24'],
            'vat_output_irt' => ['nullable', 'string', 'max:24'],
            'legal_basis' => ['required', 'string', 'min:10', 'max:255'], 'reviewed' => ['nullable', 'boolean']]);
        $d['income_rate_bp'] = AccountingMoney::minor((string) $d['income_rate'], 'USD', 'income_rate');
        unset($d['income_rate']);
        $d['vat_output_irt'] = isset($d['vat_output_irt']) && $d['vat_output_irt'] !== '' ? AccountingMoney::minor($d['vat_output_irt'], 'IRT', 'vat_output_irt') : null;
        foreach (['loss_relief_irt', 'other_addbacks_irt', 'vat_carry_irt'] as $f) {
            $d[$f] = AccountingMoney::minor($d[$f], 'IRT', $f);
        }
        DB::transaction(function () use ($d, $request) {
            $p = FinanceTaxProfile::create($d + ['created_by' => $request->user()->id]);
            $this->journal->audit('tax_profile_created', $request->user()->id, $p->id, ['from' => $d['from_date'], 'to' => $d['to_date']]);
        });

        return back()->with('ok', __('finance.saved'));
    }

    public function reviewTax(Request $request, FinanceVoucher $voucher)
    {
        $d = $request->validate(['status' => ['required', 'in:pending,eligible,rejected'], 'reference' => ['required', 'string', 'min:10', 'max:255']]);
        $this->journal->reviewTax($voucher, $d['status'], $d['reference'], $request->user()->id);

        return back()->with('ok', __('finance.saved'));
    }

    public function closePeriod(Request $request)
    {
        FinanceDates::normalize($request, ['through']);
        abort_unless($this->journal->ready(), 409);
        $d = $request->validate(['through' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:5', 'max:255']]);
        $this->journal->closeThrough($d['through'], $d['reason'], $request->user()->id);

        return back()->with('ok', __('finance.saved'));
    }

    public function investment(Request $request)
    {
        $d = $request->validate(['discount_rate' => ['required', 'numeric', 'gt:-100', 'max:10000'],
            'cash_flows' => ['required', 'string', 'max:30000'], 'currency' => ['required', Rule::in(array_keys(AccountingMoney::EXPONENTS))]]);
        $flows = [];
        foreach (preg_split('/\R/u', trim($d['cash_flows'])) as $line) {
            $parts = explode(',', trim($line));
            if (count($parts) !== 2) {
                throw ValidationException::withMessages(['cash_flows' => __('finance.invalid_flows')]);
            }
            $raw = trim($parts[1]);
            $sign = str_starts_with($raw, '-') ? -1 : 1;
            $flows[] = ['date' => FinanceDates::iso(trim($parts[0]), 'cash_flows'), 'amount' => $sign * AccountingMoney::minor(ltrim($raw, '-+'), $d['currency'], 'cash_flows')];
        }

        return back()->withInput()->with('investment', app(InvestmentAnalysis::class)->calculate($flows, (float) $d['discount_rate'] / 100) + ['currency' => $d['currency']]);
    }

    public function bundle(Request $request)
    {
        abort_unless($this->journal->ready(), 409);
        $scope = FinanceFilter::fromRequest($request);
        $path = app(TaxEvidenceBundle::class)->build($scope);
        $this->journal->audit('evidence_exported', $request->user()->id, null, ['from' => $scope->from->toDateString(), 'to' => $scope->to->toDateString()]);

        return response()->download($path, 'ServerNet-Financial-Evidence-'.now('Asia/Tehran')->format('Ymd-His').'.zip',
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
    }

    public function export(Request $request)
    {
        abort_unless($this->journal->ready(), 409);
        $filter = FinanceFilter::fromRequest($request);

        return response()->streamDownload(function () use ($filter) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Voucher', 'Date Tehran', 'Type', 'Service', 'Vendor', 'Invoice', 'Bank reference', 'Original currency', 'Original minor', 'Settlement currency', 'Settlement minor', 'Gross IRT', 'Input VAT IRT', 'Tax review', 'Reversal of', 'Business entry', 'FX basis', 'Note'], escape: '');
            $q = FinanceVoucher::query();
            if ($filter->from) {
                $q->whereDate('occurred_at', '>=', $filter->from->toDateString());
            }
            if ($filter->to) {
                $q->whereDate('occurred_at', '<=', $filter->to->toDateString());
            }
            foreach ($q->orderBy('id')->lazyById(500) as $v) {
                $cells = [$v->id, $v->occurred_at->toDateString(), $v->type, $v->service_line, $v->vendor, $v->invoice_number, $v->bank_reference,
                    $v->original_currency, $v->original_minor, $v->settlement_currency, $v->settlement_minor, $v->amount_irt, $v->input_vat_irt,
                    $v->tax_status, $v->reversal_of, $v->business_entry_id, $v->fx_basis, $v->note];
                fputcsv($out, array_map(fn ($x) => is_string($x) && preg_match('/^\s*[=+@\-]/u', $x) ? "'".$x : $x, $cells), escape: '');
            }
            fclose($out);
        }, 'servernet-accounting-'.now('Asia/Tehran')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
