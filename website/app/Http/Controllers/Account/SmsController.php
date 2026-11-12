<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\SmsSubscription;
use App\Services\SmsResale\Controls;
use App\Services\SmsResale\Orders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SmsController extends Controller
{
    public function index(Controls $controls)
    {
        $subscription = $controls->ready() ? SmsSubscription::with('service')->where('customer_id', Auth::guard('customer')->id())->first() : null;
        return response()->view('account.sms', AccountController::shell('sms') + [
            'plans' => $controls->publicPlans(), 'subscription' => $subscription, 'panelUrl' => $controls->panelUrl(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function order(Request $request, Controls $controls, Orders $orders)
    {
        abort_unless($controls->ready(), 503);
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(config('sms_resale.plans')))],
            'national_code' => ['required', 'regex:/\A[0-9]{10}\z/D'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'], 'consent' => ['accepted']]);
        $customer = Auth::guard('customer')->user();
        $phone = preg_replace('/\D/', '', (string) $customer->phone);
        if (str_starts_with($phone, '98')) { $phone = '0'.substr($phone, 2); }
        abort_unless(preg_match('/\A09[0-9]{9}\z/D', $phone) && $customer->phone_verified_at, 422, __('sms.verify_phone'));
        $s = $orders->place($customer, $data['plan'], ['national_code' => $data['national_code'],
            'birth_date' => $data['birth_date'], 'mobile_number' => $phone]);
        return redirect($s->pending_invoice_id ? lroute('account.invoice', $s->pending_invoice_id) : lroute('account.sms'));
    }

    public function upgrade(Request $request, Controls $controls, Orders $orders)
    {
        abort_unless($controls->ready(), 503);
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(config('sms_resale.plans')))]]);
        $s = SmsSubscription::where('customer_id', Auth::guard('customer')->id())->firstOrFail();
        $s = $orders->upgrade($s, $data['plan']);
        return redirect(lroute('account.invoice', $s->pending_invoice_id));
    }
}
