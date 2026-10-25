<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\Finance\FinanceFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TransactionController extends Controller
{
    private function paymentQuery(Request $request, FinanceFilter $f)
    {
        $request->validate(['status' => ['nullable', 'in:all,paid,pending,redirected,failed,canceled,expired'],
            'gateway' => ['nullable', 'string', 'max:40', 'regex:/^[a-zA-Z0-9_\-]+$/D']]);
        $q = Payment::query()->with('customer');
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('gateway') && $request->input('gateway') !== 'all') {
            $q->where('gateway', $request->input('gateway'));
        }
        if ($f->from) {
            $q->whereRaw('COALESCE(paid_at, created_at) >= ?', [$f->from->copy()->startOfDay()->utc()]);
        }
        if ($f->to) {
            $q->whereRaw('COALESCE(paid_at, created_at) <= ?', [$f->to->copy()->endOfDay()->utc()]);
        }
        if (! empty($f->values['currency'])) {
            $q->where('currency_code', $f->values['currency']);
        }
        $term = trim($f->values['q'] ?? '');
        if ($term !== '') {
            $q->where(function ($w) use ($term) {
                $w->where('external_ref', 'like', '%'.$term.'%')->orWhere('ref_id', 'like', '%'.$term.'%')
                    ->orWhereHas('invoice', fn ($i) => $i->where('number', 'like', '%'.$term.'%'))
                    ->orWhereHas('customer', fn ($c) => $this->customerSearch($c, $term));
                if (ctype_digit($term)) {
                    $w->orWhere('id', (int) $term)->orWhere('invoice_id', (int) $term);
                }
            });
        }

        return $q;
    }

    private function customerSearch($c, string $term): void
    {
        $c->where(function ($w) use ($term) {
            $w->where('code', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')->orWhere('phone', 'like', '%'.$term.'%')
                ->orWhereHas('profiles', function ($p) use ($term) {
                    foreach (array_slice(preg_split('/\s+/u', $term), 0, 6) as $part) {
                        $p->where(fn ($n) => $n->where('first_name', 'like', '%'.$part.'%')
                            ->orWhere('last_name', 'like', '%'.$part.'%')->orWhere('company_name', 'like', '%'.$part.'%'));
                    }
                });
        });
    }

    public function index(Request $request)
    {
        $f = FinanceFilter::fromRequest($request);
        $ready = Schema::hasTable('payments') && Schema::hasTable('credit_ledger');
        $defaults = ['ready' => $ready, 'kpis' => ['credit' => 0, 'topups' => 0, 'paidSum' => 0, 'creditCustomers' => 0],
            'creditByCurrency' => collect(), 'revenueByCurrency' => collect(), 'topCredit' => collect(), 'credit' => collect(), 'payments' => collect(),
            'status' => $request->input('status', 'all'), 'gateway' => $request->input('gateway', 'all'), 'q' => $f->values['q'] ?? '',
            'filters' => $f->values, 'gateways' => collect()];
        if (! $ready) {
            return view('admin.transactions', $defaults);
        }
        $payQ = $this->paymentQuery($request, $f);
        $payments = (clone $payQ)->orderByDesc('id')->paginate(50, ['*'], 'page')->withQueryString();
        $paid = (clone $payQ)->where('status', 'paid')->whereNotIn('gateway', ['credit', 'gift']);
        $revenueByCurrency = (clone $paid)->selectRaw('currency_code, SUM(amount) as total, COUNT(*) as cnt')->groupBy('currency_code')->orderBy('currency_code')->get();
        // Balances are as-of the end date, not net movements within the period.
        $balanceQ = CreditEntry::query();
        if ($f->to) {
            $balanceQ->where('created_at', '<=', $f->to->copy()->endOfDay()->utc());
        }
        $creditByCurrency = (clone $balanceQ)->selectRaw('currency_code, SUM(amount) as bal')->groupBy('currency_code')->pluck('bal', 'currency_code');
        $positive = (clone $balanceQ)->selectRaw('customer_id, currency_code, SUM(amount) as bal')->groupBy('customer_id', 'currency_code')->havingRaw('SUM(amount) > 0');
        $count = DB::query()->fromSub((clone $positive)->toBase(), 'positive_wallets')->distinct()->count('customer_id');
        $topCredit = (clone $positive)->orderByRaw("(currency_code = 'IRT') desc")->orderByDesc('bal')->limit(50)->get();
        $custMap = Customer::whereIn('id', $topCredit->pluck('customer_id')->unique()->all())->get()->keyBy('id');
        foreach ($topCredit as $row) {
            $row->setRelation('customer', $custMap->get($row->customer_id));
        }
        $creditQ = CreditEntry::with('customer');
        if ($f->from) {
            $creditQ->where('created_at', '>=', $f->from->copy()->startOfDay()->utc());
        }
        if ($f->to) {
            $creditQ->where('created_at', '<=', $f->to->copy()->endOfDay()->utc());
        }
        if (! empty($f->values['currency'])) {
            $creditQ->where('currency_code', $f->values['currency']);
        }
        if (! empty($f->values['q'])) {
            $term = $f->values['q'];
            $creditQ->where(fn ($w) => $w->where('note', 'like', '%'.$term.'%')->orWhere('reason', 'like', '%'.$term.'%')
                ->orWhereHas('customer', fn ($c) => $this->customerSearch($c, $term)));
        }
        $kpis = ['credit' => (int) ($creditByCurrency['IRT'] ?? 0),
            'topups' => (int) (clone $paid)->where('currency_code', 'IRT')->whereHas('invoice', fn ($i) => $i->where('kind', 'topup'))->sum('amount'),
            'paidSum' => (int) (clone $paid)->where('currency_code', 'IRT')->sum('amount'), 'creditCustomers' => $count];

        return view('admin.transactions', array_replace($defaults, compact('kpis', 'creditByCurrency', 'revenueByCurrency', 'topCredit', 'payments') + [
            'credit' => $creditQ->orderByDesc('id')->paginate(50, ['*'], 'credit_page')->withQueryString(),
            'gateways' => Payment::distinct()->orderBy('gateway')->pluck('gateway')]));
    }

    public function export(Request $request)
    {
        $f = FinanceFilter::fromRequest($request);
        $q = $this->paymentQuery($request, $f);

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Payment ID', 'Invoice ID', 'Date UTC', 'Gateway', 'Status', 'Currency', 'Amount minor', 'Customer code', 'Reference'], escape: '');
            foreach ($q->orderBy('id')->lazyById(500) as $p) {
                $cells = [$p->id, $p->invoice_id, ($p->paid_at ?? $p->created_at)->toIso8601String(), $p->gateway, $p->status,
                    $p->currency_code, $p->amount, $p->customer?->code, $p->ref_id ?: $p->external_ref];
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^\s*[=+@\-]/u', $v) ? "'".$v : $v, $cells), escape: '');
            }
            fclose($out);
        }, 'servernet-payments-'.now('Asia/Tehran')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
