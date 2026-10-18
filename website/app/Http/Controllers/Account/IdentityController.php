<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IdentityApplication;
use App\Models\IdentityRequest;
use App\Models\IdentityVerification;
use App\Services\Finance\Wallet;
use App\Services\IdentityApi\Catalog;
use App\Services\IdentityApi\Controls;
use App\Services\IdentityApi\Engine;
use App\Services\IdentityApi\IdentityError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IdentityController extends Controller
{
    public function index(Request $request, Controls $controls, Catalog $catalog, Wallet $wallet)
    {
        $c = Auth::guard('customer')->user();
        $ready = $controls->ready();
        $query = $ready ? IdentityRequest::where('customer_id', $c->id) : null;
        foreach (['service', 'financial_status', 'processing_status'] as $filter) {
            if ($query && is_string($request->query($filter)) && strlen($request->query($filter)) <= 100) {
                $query->where($filter, $request->query($filter));
            }
        }
        $selected = null;
        $checkout = null;
        if ($request->query('service')) {
            try {
                $selected = $catalog->find((string) $request->query('service'));
                $checkout = $catalog->checkoutQuote($selected['slug'], $c);
            } catch (IdentityError) {
            }
        }

        return view('account.identity', AccountController::shell('identity') + [
            'ready' => $ready, 'services' => $catalog->publicList(), 'selected' => $selected, 'checkout' => $checkout,
            'applications' => $ready ? IdentityApplication::where('customer_id', $c->id)->get() : collect(),
            'history' => $query ? $query->orderByDesc('id')->paginate(20) : null,
            'balance' => $wallet->balanceOf($c->id), 'reserved' => $wallet->reservedOf($c->id),
            'eligible' => IdentityVerification::where('customer_id', $c->id)->where('status', 'verified')->exists(),
            'keys' => $ready ? $c->apiTokens()->usable()->whereNotNull('identity_application_id')->get() : collect(),
        ]);
    }

    public function activate(Request $request, Controls $controls)
    {
        if (! $controls->ready()) {
            throw new IdentityError('service_unavailable', 503);
        }
        $c = Auth::guard('customer')->user();
        if (! IdentityVerification::where('customer_id', $c->id)->where('status', 'verified')->exists()) {
            throw new IdentityError('product_not_active', 403);
        }
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'daily_cap_irt' => ['required', 'integer', 'min:1', 'max:9000000000000'], 'accept' => ['accepted']]);
        DB::transaction(function () use ($c, $d) {
            Customer::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if (IdentityApplication::where('customer_id', $c->id)->count() >= 10) {
                throw new IdentityError('quota_exceeded', 429);
            }
            IdentityApplication::create(['customer_id' => $c->id, 'name' => $d['name'], 'daily_cap_irt' => $d['daily_cap_irt'],
                'terms_version' => config('identity_api.terms_version'), 'accepted_at' => now()]);
        });

        return back()->with('ok', __('identity.saved'));
    }

    public function update(Request $request, IdentityApplication $application)
    {
        abort_unless($application->customer_id === Auth::guard('customer')->id(), 404);
        $d = $request->validate(['status' => ['required', 'in:active,disabled'], 'daily_cap_irt' => ['required', 'integer', 'min:1', 'max:9000000000000']]);
        $application->update($d);

        return back()->with('ok', __('identity.saved'));
    }

    public function inquire(Request $request, Engine $engine, Catalog $catalog)
    {
        $d = $request->validate(['application_id' => ['required', 'integer'], 'service' => ['required', 'string', 'max:100'], 'input' => ['required', 'array'],
            'idempotency_key' => ['required', 'string', 'max:128'], 'max_charge_irt' => ['required', 'integer', 'min:0'], 'price_version' => ['nullable', 'integer', 'min:1'], 'real_charge' => ['accepted']]);
        $a = IdentityApplication::where('customer_id', Auth::guard('customer')->id())->findOrFail($d['application_id']);
        $s = $catalog->find($d['service']);
        // HTML controls submit strings; public API retains strict native JSON types.
        foreach ($s['operations'][0]['input']['properties'] ?? [] as $k => $spec) {
            if (isset($d['input'][$k]) && ($spec['type'] ?? null) === 'integer' && preg_match('/^-?[0-9]+$/D', $d['input'][$k])) {
                $d['input'][$k] = (int) $d['input'][$k];
            }
            if (isset($d['input'][$k]) && ($spec['type'] ?? null) === 'boolean' && in_array($d['input'][$k], ['true', 'false'], true)) {
                $d['input'][$k] = $d['input'][$k] === 'true';
            }
        }
        $r = $engine->accept($a, null, $d['service'], $d['input'], $d['idempotency_key'], (int) $d['max_charge_irt'], isset($d['price_version']) ? (int) $d['price_version'] : null);
        $r = $engine->dispatch($r);

        return response()->json($r->receipt(), $r->financial_status === 'captured' ? 200 : 202);
    }

    public function result(string $id)
    {
        $r = IdentityRequest::where('customer_id', Auth::guard('customer')->id())->where('public_id', $id)->firstOrFail();

        return response()->json($r->receipt());
    }

    public function sandbox()
    {
        return response()->json(['simulated' => true, 'charged_irt' => 0, 'message' => __('identity.sandbox_help'),
            'processing_status' => 'completed', 'inquiry_outcome' => 'unmatched', 'financial_status' => 'not_applicable', 'result' => ['matched' => false]]);
    }
}
