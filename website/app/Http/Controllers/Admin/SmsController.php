<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SmsSubscription;
use App\Services\SmsResale\Controls;
use App\Services\SmsResale\IppanelClient;
use App\Services\SmsResale\ProviderError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SmsController extends Controller
{
    public function index(Controls $controls)
    {
        return response()->view('admin.sms', ['controls' => $controls, 'plans' => $controls->publicPlans(),
            'subscriptions' => $controls->ready() ? SmsSubscription::orderByDesc('id')->paginate(30) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request)
    {
        $secret = $request->input('api_key');
        $request->replace($request->except('api_key')); // Never flash an API key after validation errors.
        \Illuminate\Support\Facades\Validator::make(['api_key' => $secret], ['api_key' => ['nullable', 'string', 'min:20', 'max:512']])->validate();
        $rules = ['api_key' => ['nullable', 'string', 'min:20', 'max:512'],
            'parent_id' => ['required', 'integer', 'min:1'], 'cost_irr' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'tax_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'commercial_evidence' => ['nullable', 'string', 'max:300'],
            'native_billing_evidence' => ['nullable', 'string', 'max:300'],
            'automation_evidence' => ['nullable', 'string', 'max:300'],
            'verified_at' => ['required', 'date', 'before_or_equal:now'],
            'valid_until' => ['required', 'date', 'after:verified_at'],
            'automation_enabled' => ['nullable', 'boolean'], 'sales_enabled' => ['nullable', 'boolean']];
        foreach (array_keys(config('sms_resale.plans')) as $key) {
            $rules[$key.'_acl'] = ['required', 'integer', 'min:1'];
            $rules[$key.'_tariff_verified'] = ['nullable', 'boolean'];
        }
        $d = $request->validate($rules);
        $d['api_key'] = $secret;
        DB::transaction(function () use ($d, $request) {
            foreach ($d as $key => $value) {
                if ($key !== 'api_key') { Setting::put('sms_resale_'.$key, (string) $value); }
            }
            foreach (['automation_enabled', 'sales_enabled', 'start_tariff_verified', 'growth_tariff_verified', 'pro_tariff_verified'] as $key) {
                Setting::put('sms_resale_'.$key, $request->boolean($key) ? '1' : '0');
            }
            if (filled($d['api_key'] ?? null)) { Setting::putSecret('sms_resale_api_key', $d['api_key']); }
        });
        return back()->with('ok', __('sms.saved'));
    }

    public function preflight(IppanelClient $client)
    {
        try {
            $packages = $client->packages();
            $credit = $client->credit();
            // A read-only check is not permission to enable sales or a receipt for a real transaction.
            Setting::put('sms_resale_preflight_at', now()->toIso8601String());
            return back()->with('ok', __('sms.preflight_ok', ['count' => count($packages)]));
        } catch (ProviderError) { return back()->with('err', __('sms.connection_failed')); }
    }
}
