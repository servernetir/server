<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\CloudPlan;
use App\Models\Service;
use App\Services\Cloud\GpuCapacityOrders;
use App\Services\Cloud\GpuCapacityAlternatives;
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
            'count' => $capacity['ok'] ? $capacity['count'] : null,
            'checked_at' => $capacity['checked_at'], 'label' => __($capacity['ok']
                ? ($capacity['available'] ? 'ui.gpu_capacity_available' : 'ui.gpu_capacity_waiting') : 'ui.gpu_capacity_unknown')])
            ->header('Cache-Control', 'no-store');
    }

    private function owned(Service $service): void
    {
        abort_unless((int) $service->customer_id === (int) Auth::guard('customer')->id(), 404);
    }

    public function alternatives(Service $service, GpuCapacityAlternatives $alternatives): \Illuminate\View\View
    {
        $this->owned($service);
        abort_unless(GpuCapacityOrders::replaceable($service), 409);
        $cards = $alternatives->candidates($service)->map(fn (CloudPlan $p) => [
            'id' => $p->id, 'slug' => $p->slug, 'gpu' => $p->gpu_model,
            'cpu' => $p->vcpu, 'ram' => $p->ramLabel(), 'disk' => $p->diskLabel(), 'rate' => $p->hourlyIrt(),
        ]);
        return view('account.gpu-alternatives', AccountController::shell('servers') + compact('service', 'cards'));
    }

    public function previewAlternative(Service $service, CloudPlan $plan, GpuCapacityAlternatives $alternatives): \Illuminate\View\View
    {
        $this->owned($service);
        $preview = $alternatives->preview($service, $plan->id);
        return view('account.gpu-alternative-confirm', AccountController::shell('servers') + compact('service') + $preview);
    }

    public function replaceAlternative(Request $request, Service $service, GpuCapacityAlternatives $alternatives): \Illuminate\Http\RedirectResponse
    {
        $this->owned($service);
        $data = $request->validate(['plan_id' => ['required', 'integer', 'min:1'],
            'quoted_rate_irt' => ['required', 'integer', 'min:1'], 'confirm' => ['required', 'accepted']]);
        $replacement = $alternatives->replace($service, (int) $data['plan_id'], (int) $data['quoted_rate_irt']);
        return redirect(lroute('account.cloud.show', $replacement))->with('ok', __('gpu-alternatives.replaced'));
    }

    public function confirmPrice(Request $request, Service $service, GpuCapacityOrders $orders): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $service->customer_id === (int) Auth::guard('customer')->id(), 404);
        $data = $request->validate(['quoted_price_irt' => ['required', 'integer', 'min:1']]);
        $orders->confirmPrice($service, (int) $data['quoted_price_irt']);
        return redirect(lroute('account.cloud.show', $service))->with('ok', __('ui.gpu_price_confirmed'));
    }
}
