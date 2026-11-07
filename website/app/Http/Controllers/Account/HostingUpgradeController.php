<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\HostingUpgrade;
use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\HostingUpgrades;
use App\Services\Provisioning\HostingPlanChanger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class HostingUpgradeController extends Controller
{
    private function owned(Service $service): void
    {
        abort_unless(Auth::guard('customer')->id() && (int) $service->customer_id === (int) Auth::guard('customer')->id(), 404);
    }

    public function show(Request $request, Service $service, HostingUpgrades $upgrades)
    {
        $this->owned($service);
        abort_unless($upgrades->ready(), 404);
        $options = $upgrades->options($service);
        $open = $upgrades->open($service);
        $last = HostingUpgrade::where('service_id', $service->id)->latest('id')->first();
        $quote = null;
        if (! $open && $request->filled('product_id')) {
            $target = collect($options)->firstWhere('id', (int) $request->query('product_id'));
            abort_unless($target, 404);
            $quote = $upgrades->quote($service, $target);
            $request->session()->put('hosting_upgrade.'.$service->id, $quote);
        }

        return response()->view('account.hosting-upgrade', AccountController::shell('hosting') + compact('service', 'options', 'open', 'last', 'quote', 'upgrades'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Service $service, HostingUpgrades $upgrades, HostingPlanChanger $changer)
    {
        $this->owned($service);
        abort_unless($upgrades->ready(), 404);
        $data = $request->validate(['product_id' => 'required|integer', 'quote_hash' => 'required|string|size:64']);
        $target = Product::findOrFail($data['product_id']);
        $preview = (array) $request->session()->get('hosting_upgrade.'.$service->id, []);
        if (! $upgrades->open($service)) {
            $upgrades->quote($service, $target); // Validate scope before calling any panel.
            if ($changer->inspect($service, $target->plan)['state'] !== 'source') {
                throw ValidationException::withMessages(['upgrade' => __('ui.hu_unavailable')]);
            }
        }
        $upgrade = $upgrades->issue($service, $target, $preview, $data['quote_hash']);

        return redirect()->to(lroute('account.invoice', $upgrade->invoice_id));
    }
}
