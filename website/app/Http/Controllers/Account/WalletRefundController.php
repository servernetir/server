<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\FinanceDocument;
use App\Models\WalletRefundRequest;
use App\Services\Finance\AccountingMoney;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletRefunds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

final class WalletRefundController extends Controller
{
    public function index(WalletRefunds $refunds, Wallet $wallet)
    {
        $c = Auth::guard('customer')->user();
        abort_unless($refunds->visible($c->id), 404);

        return view('account.wallet-refunds', AccountController::shell('wallet-refunds') + [
            'enabled' => $refunds->enabled($c->id), 'available' => $wallet->availableOf($c->id, 'IRT'),
            'balance' => $wallet->balanceOf($c->id, 'IRT'),
            'banks' => $c->bankAccounts()->where('status', 'verified')->where('name_matched', true)->whereNotNull('iban')->get(),
            'refunds' => WalletRefundRequest::where('customer_id', $c->id)->latest('id')->paginate(20),
            'pending' => WalletRefundRequest::where('active_customer_id', $c->id)->first(),
        ]);
    }

    public function store(Request $request, WalletRefunds $refunds)
    {
        $c = Auth::guard('customer')->user();
        abort_unless($refunds->enabled($c->id), 403);
        $d = $request->validate(['idempotency_key' => ['required', 'uuid'], 'amount_irt' => ['required', 'string', 'max:24'],
            'bank_account_id' => ['required', 'integer'], 'note' => ['required', 'string', 'min:5', 'max:2000'],
            'acknowledge' => ['required', 'accepted']]);
        $refunds->request($c, $d['idempotency_key'], AccountingMoney::minor($d['amount_irt'], 'IRT', 'amount_irt'),
            (int) $d['bank_account_id'], $d['note']);

        return redirect()->to(lroute('account.wallet-refunds'))->with('ok', __('refund.request_saved'));
    }

    public function cancel(Request $request, WalletRefundRequest $refund, WalletRefunds $refunds)
    {
        $c = Auth::guard('customer')->user();
        abort_unless((int) $refund->customer_id === $c->id, 404);
        $refunds->close($refund, 'cancelled', __('refund.customer_cancelled'), $c);

        return back()->with('ok', __('refund.cancelled_saved'));
    }

    public function receipt(WalletRefundRequest $refund)
    {
        abort_unless((int) $refund->customer_id === Auth::guard('customer')->id() && $refund->status === 'paid', 404);
        $doc = FinanceDocument::whereKey($refund->document_id)->where('voucher_id', $refund->voucher_id)->firstOrFail();
        $disk = Storage::disk('local');
        abort_unless($disk->exists($doc->path), 404);
        abort_unless(hash_equals($doc->sha256, hash_file('sha256', $disk->path($doc->path))), 409);

        return $disk->download($doc->path, $doc->name,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
