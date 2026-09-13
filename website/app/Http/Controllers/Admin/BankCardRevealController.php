<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Explicit, audited, non-cacheable reveal of an encrypted PAN. */
class BankCardRevealController extends Controller
{
    public function __invoke(Request $request, Customer $customer, BankAccount $bankAccount): Response
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($bankAccount->customer_id === $customer->id, 404);

        $pan = preg_replace('/\D/', '', (string) $bankAccount->card_number_enc) ?? '';
        abort_unless(preg_match('/^\d{16}$/', $pan) === 1, 404, 'شمارهٔ کامل برای این حساب نگهداری نشده است.');

        ActivityLog::record(
            $customer->id,
            'bank_card_revealed',
            'مدیر شمارهٔ کامل کارت حساب بانکی #'.$bankAccount->id.' را مشاهده کرد',
            $request,
            'staff',
        );

        return response()->view('admin.bank-card-reveal', [
            'customer' => $customer,
            'bankAccount' => $bankAccount,
            'pan' => $pan,
        ])->withHeaders([
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
