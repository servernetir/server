<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceDocument;
use App\Models\FinanceVoucher;
use App\Models\Payment;
use App\Models\WalletRefundRequest;
use App\Services\Finance\FinanceFilter;
use App\Services\Finance\WalletRefunds;
use App\Support\FinanceDates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

final class WalletRefundController extends Controller
{
    private function query(Request $request, WalletRefunds $refunds)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($refunds->ready(), 409);
        $request->validate(['status' => ['nullable', Rule::in(['all', 'requested', 'approved', 'paid', 'rejected', 'cancelled'])],
            'customer' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:150']]);
        $scope = FinanceFilter::fromRequest($request);
        $q = WalletRefundRequest::with('customer');
        if ($scope->from) {
            $q->where('created_at', '>=', $scope->from->copy()->startOfDay()->utc());
        }
        if ($scope->to) {
            $q->where('created_at', '<=', $scope->to->copy()->endOfDay()->utc());
        }
        if ($request->filled('customer')) {
            $q->where('customer_id', $request->integer('customer'));
        }
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $q->where(fn ($w) => $w->where('public_id', 'like', $term)->orWhere('bank_reference', 'like', $term)
                ->orWhereHas('customer', fn ($c) => $c->where('code', 'like', $term)));
        }

        return $q;
    }

    public function index(Request $request, WalletRefunds $refunds)
    {
        return view('admin.wallet-refunds', ['refunds' => $this->query($request, $refunds)->latest('id')->paginate(30)->withQueryString()]);
    }

    public function show(Request $request, WalletRefundRequest $refund)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $events = Schema::hasTable('wallet_refund_events')
            ? DB::table('wallet_refund_events')->where(fn ($q) => $q->where('request_id', $refund->id)
                ->orWhere(fn ($q) => $q->whereNull('request_id')->where('customer_id', $refund->customer_id)))->orderBy('id')->get()
            : collect();
        $paidEvent = $events->first(fn ($event) => $event->action === 'paid' && (int) $event->request_id === (int) $refund->id);

        return view('admin.wallet-refund', ['refund' => $refund->load('customer'), 'events' => $events, 'paidEvent' => $paidEvent,
            'receiptDocument' => $refund->document_id ? FinanceDocument::whereKey($refund->document_id)->where('voucher_id', $refund->voucher_id)->first() : null,
            'accounts' => FinanceAccount::whereIn('type', ['bank', 'partner'])->whereIn('currency_code', ['IRT', 'IRR'])
                ->orderBy('type')->orderBy('name')->orderBy('id')->get(),
            'payments' => Payment::where('customer_id', $refund->customer_id)->where('status', 'paid')
                ->where('currency_code', 'IRT')->whereNotIn('gateway', ['credit', 'gift'])->latest('id')->limit(100)->get()]
            + $this->fundingEvidence($refund, $paidEvent));
    }

    private function fundingEvidence(WalletRefundRequest $refund, ?object $paidEvent): array
    {
        $fundingSnapshot = WalletRefunds::fundingAccountSnapshot($paidEvent);
        $fundingAccountId = $fundingSnapshot['id'] ?? ($refund->voucher_id
            ? FinanceVoucher::whereKey($refund->voucher_id)->value('funding_account_id') : null);
        $fundingLegacyFallback = $refund->status === 'paid' && $fundingSnapshot === null;
        $fundingAccount = $fundingLegacyFallback && $fundingAccountId ? FinanceAccount::find($fundingAccountId) : null;

        return compact('fundingSnapshot', 'fundingAccountId', 'fundingAccount', 'fundingLegacyFallback');
    }

    public function permission(Request $request, Customer $customer, WalletRefunds $refunds)
    {
        $d = $request->validateWithBag('walletRefundPermission', ['enabled' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $refunds->permission($customer, (bool) $d['enabled'], $d['reason'], $request->user());

        return redirect('/admin/customers/'.$customer->id.'#finance')->with('ok', __('refund.permission_saved'));
    }

    public function approve(Request $request, WalletRefundRequest $refund, WalletRefunds $refunds)
    {
        $d = $request->validate(['note' => ['required', 'string', 'min:5', 'max:2000'],
            'source_payment_id' => ['nullable', 'integer'], 'source_note' => ['required', 'string', 'min:10', 'max:2000']]);
        $refunds->approve($refund, $request->user(), $d['note'], empty($d['source_payment_id']) ? null : (int) $d['source_payment_id'], $d['source_note']);

        return back()->with('ok', __('refund.approved_saved'));
    }

    public function reject(Request $request, WalletRefundRequest $refund, WalletRefunds $refunds)
    {
        $d = $request->validate(['note' => ['required', 'string', 'min:5', 'max:2000'], 'not_transferred' => ['required', 'accepted']]);
        $refunds->close($refund, 'rejected', $d['note'], $request->user());

        return back()->with('ok', __('refund.rejected_saved'));
    }

    public function paid(Request $request, WalletRefundRequest $refund, WalletRefunds $refunds)
    {
        FinanceDates::normalize($request, ['payout_date']);
        $d = $request->validate(['funding_account_id' => ['required', 'integer'],
            'payout_date' => ['required', 'date_format:Y-m-d'], 'bank_reference' => ['required', 'string', 'min:4', 'max:150'],
            'bank_transaction_id' => ['nullable', 'integer'], 'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'transferred' => ['required', 'accepted']]);
        $refunds->paid($refund, $request->user(), (int) $d['funding_account_id'], $d['payout_date'], $d['bank_reference'],
            $request->file('evidence'), empty($d['bank_transaction_id']) ? null : (int) $d['bank_transaction_id']);

        return back()->with('ok', __('refund.paid_saved'));
    }

    public function export(Request $request, WalletRefunds $refunds)
    {
        $q = $this->query($request, $refunds);

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Request', 'Customer', 'Status', 'Amount IRT', 'Requested UTC', 'Approved UTC', 'Paid date Tehran',
                'Bank reference', 'Finance voucher', 'Receipt', 'Source payment', 'Reviewer', 'Payout recorder',
                'Funding account ID', 'Funding account name', 'Funding account type', 'Funding account currency',
                'Funding account owner', 'Funding account legacy fallback'], escape: '');
            $hasEvents = Schema::hasTable('wallet_refund_events');
            foreach ($q->orderBy('id')->lazyById(200) as $r) {
                $paidEvent = $hasEvents && $r->status === 'paid' ? DB::table('wallet_refund_events')
                    ->where('request_id', $r->id)->where('action', 'paid')->orderBy('id')->first() : null;
                $funding = $this->fundingEvidence($r, $paidEvent);
                $identity = $funding['fundingSnapshot'] ?? $funding['fundingAccount']?->only(['id', 'name', 'type', 'currency_code', 'owner_name']);
                $cells = [$r->public_id, $r->customer->code, $r->status, $r->amount_irt, $r->created_at, $r->reviewed_at,
                    $r->payout_date?->toDateString(), $r->bank_reference, $r->voucher_id, $r->document_id, $r->source_payment_id, $r->reviewed_by, $r->paid_by,
                    $funding['fundingAccountId'], $identity['name'] ?? null, $identity['type'] ?? null,
                    $identity['currency_code'] ?? null, $identity['owner_name'] ?? null, (int) $funding['fundingLegacyFallback']];
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^\s*[=+@\-]/u', $v) ? "'".$v : $v, $cells), escape: '');
            }
            fclose($out);
        }, 'wallet-refunds.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
