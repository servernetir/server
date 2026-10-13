<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\CloudPlan;
use App\Models\Service;
use App\Services\Cloud\GpuCapacityOrders;
use App\Services\Cloud\SaladClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GpuCapacityController extends Controller
{
    public function check(Request $request, SaladClient $provider): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'string', 'max:96']]);
        $plan = CloudPlan::offers(SaladClient::LOCATION, true)->get($data['plan']);
        abort_unless($plan && $plan->provider === 'salad', 404);
        $capacity = $provider->capacityFor($plan);
        return response()->json(['ok' => $capacity['ok'], 'available' => $capacity['available'],
            'checked_at' => $capacity['checked_at'], 'label' => __($capacity['ok']
                ? ($capacity['available'] ? 'ui.gpu_capacity_available' : 'ui.gpu_capacity_waiting') : 'ui.gpu_capacity_unknown')])
            ->header('Cache-Control', 'no-store');
    }

    public function confirmPrice(Request $request, Service $service, GpuCapacityOrders $orders): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $service->customer_id === (int) Auth::guard('customer')->id(), 404);
        $data = $request->validate(['quoted_price_irt' => ['required', 'integer', 'min:1']]);
        $orders->confirmPrice($service, (int) $data['quoted_price_irt']);
        return redirect(lroute('account.cloud.show', $service))->with('ok', __('ui.gpu_price_confirmed'));
    }
}
