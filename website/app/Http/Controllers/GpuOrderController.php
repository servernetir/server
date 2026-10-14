<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudPlan;
use App\Services\Cloud\GpuCapacityOrders;
use App\Services\Cloud\SaladClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A short-lived, explicitly submitted GPU order survives login without repeating the configurator. */
class GpuOrderController extends \App\Http\Controllers\Account\AccountController
{
    private const SESSION = 'gpu_public_order';

    public function start(Request $request)
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:96'],
            'image' => ['required', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9-]*$/D'],
            'quotes' => ['required', 'array', 'max:200'],
            'quotes.*' => ['required', 'integer', 'min:1'],
        ]);
        $plan = $this->offer($data);
        $rate = (int) ($data['quotes'][$plan->slug] ?? 0);
        if ($rate !== $plan->hourlyIrt()) {
            throw ValidationException::withMessages(['plan' => __('ui.cvb_gpu_price_changed')]);
        }
        $previous = $request->session()->get(self::SESSION);
        if (is_array($previous) && ($previous['expires_at'] ?? 0) >= now()->timestamp
            && ($previous['customer_id'] ?? null) === Auth::guard('customer')->id()
            && data_get($previous, 'data.plan') === $plan->slug
            && data_get($previous, 'data.image') === $data['image']
            && data_get($previous, 'data.label') === $data['label']
            && data_get($previous, 'data.quoted_hourly_irt') === $rate) {
            return redirect(lroute('account.gpu.resume'));
        }
        $request->session()->put(self::SESSION, [
            'expires_at' => now()->addMinutes(30)->timestamp,
            'customer_id' => Auth::guard('customer')->id(),
            'data' => [
                'location' => SaladClient::LOCATION, 'plan' => $plan->slug,
                'image' => $data['image'], 'label' => $data['label'],
                'billing_mode' => 'hourly', 'cycle' => 'monthly', 'on_credit_out' => 'suspend',
                'quoted_hourly_irt' => $rate, 'gpu_request_key' => Str::random(40),
            ],
        ]);
        return redirect(lroute('account.gpu.resume'));
    }

    public function resume(Request $request)
    {
        $intent = $this->intent($request);
        if ($existing = app(GpuCapacityOrders::class)->replay((int) Auth::guard('customer')->id(), $intent['data']['gpu_request_key'])) {
            return redirect(lroute('account.cloud.show', $existing));
        }
        return response()->view('account.gpu-resume', $this->shell('servers') + ['intent' => $intent['data']])
            ->header('Cache-Control', 'private, no-store');
    }

    public function complete(Request $request, CloudStoreController $store)
    {
        $intent = $this->intent($request);
        abort_unless(hash_equals($intent['data']['gpu_request_key'], (string) $request->input('intent_key')), 419);
        $data = $intent['data'];
        if ($existing = app(GpuCapacityOrders::class)->replay((int) Auth::guard('customer')->id(), $data['gpu_request_key'])) {
            return redirect(lroute('account.cloud.show', $existing));
        }
        try {
            $plan = $this->offer($data);
            if ($plan->hourlyIrt() !== $data['quoted_hourly_irt']) {
                throw ValidationException::withMessages(['plan' => __('ui.cvb_gpu_price_changed')]);
            }
            // The existing checkout performs fresh capacity/price checks and creates only an unpaid intent.
            // Ignore any additional posted fields: the public form's approved session data is authoritative.
            $request->replace($data);
            $response = $store->order($request);
        } catch (ValidationException $e) {
            return redirect(lroute('gpu'))->withInput($data)->withErrors($e->errors());
        }
        if (app(GpuCapacityOrders::class)->replay((int) Auth::guard('customer')->id(), $data['gpu_request_key'])) {
            $request->session()->put(self::SESSION.'.customer_id', Auth::guard('customer')->id());
            return $response;
        }
        // Errors must return to editable choices, never an auto-submit loop.
        return redirect(lroute('gpu'))->withInput($data);
    }

    private function intent(Request $request): array
    {
        $intent = $request->session()->get(self::SESSION);
        if (! is_array($intent) || ($intent['expires_at'] ?? 0) < now()->timestamp
            || (isset($intent['customer_id']) && (int) $intent['customer_id'] !== (int) Auth::guard('customer')->id())) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                redirect(lroute('gpu'))->withErrors(['plan' => __('gpu.intent_expired')])
            );
        }
        return $intent;
    }

    private function offer(array $data): CloudPlan
    {
        $plan = CloudPlan::offers(SaladClient::LOCATION, true)->get($data['plan']);
        if (! $plan || $plan->provider !== 'salad' || blank($plan->gpu_model)) {
            throw ValidationException::withMessages(['plan' => __('ui.cvb_e_plan')]);
        }
        if (! in_array($data['image'], CloudStoreController::imageKeysFor($plan, 'app'), true)) {
            throw ValidationException::withMessages(['image' => __('ui.cvb_e_image')]);
        }
        return $plan;
    }
}
