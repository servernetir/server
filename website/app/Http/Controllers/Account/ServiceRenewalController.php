<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Billing\ServiceRenewals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ServiceRenewalController extends Controller
{
    private function owned(Service $service): void
    {
        abort_unless(Auth::guard('customer')->id() && (int) $service->customer_id === (int) Auth::guard('customer')->id(), 404);
    }

    public function show(Request $request, Service $service, ServiceRenewals $renewals)
    {
        $this->owned($service);
        $options = $renewals->options($service);
        $quotes = [];
        $blocked = null;
        foreach ($options as $cycle => $option) {
            try {
                $quotes[$cycle] = $renewals->quote($service, $cycle);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $blocked = collect($e->errors())->flatten()->first();
                break;
            }
        }
        $request->session()->put('service_renewal.'.$service->id, ['at' => now()->timestamp, 'quotes' => $quotes]);

        return response()->view('account.service-renewal', AccountController::shell('hosting')
            + compact('service', 'options', 'quotes', 'blocked', 'renewals'))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Service $service, ServiceRenewals $renewals)
    {
        $this->owned($service);
        $data = $request->validate(['cycle' => 'required|string|max:30', 'quote_hash' => 'required|string|size:64']);
        $saved = (array) $request->session()->get('service_renewal.'.$service->id, []);
        if (($saved['at'] ?? 0) < now()->subMinutes(15)->timestamp) {
            throw \Illuminate\Validation\ValidationException::withMessages(['renewal' => __('ui.sr_changed')]);
        }
        $invoice = $renewals->issue($service, $data['cycle'], $saved['quotes'][$data['cycle']] ?? [], $data['quote_hash']);

        return redirect()->to(lroute('account.invoice', $invoice));
    }
}
