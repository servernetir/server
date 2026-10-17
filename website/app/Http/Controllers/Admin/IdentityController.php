<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IdentityPrice;
use App\Models\IdentityRequest;
use App\Services\IdentityApi\Catalog;
use App\Services\IdentityApi\Controls;
use App\Services\IdentityApi\Engine;
use App\Services\IdentityApi\IdentityError;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IdentityController extends Controller
{
    public function index(Controls $controls, Catalog $catalog)
    {
        return view('admin.identity', ['ready' => $controls->ready(), 'runtimeReady' => $controls->runtimeReady(), 'controls' => $controls, 'catalog' => $catalog->all(),
            'prices' => $controls->ready() ? IdentityPrice::orderByDesc('id')->limit(100)->get() : collect(),
            'history' => $controls->ready() ? IdentityRequest::orderByDesc('id')->paginate(40) : null,
            'heartbeat' => Cache::get('identity:reconcile:heartbeat')]);
    }

    public function control(Request $request, Controls $controls, Catalog $catalog)
    {
        $d = $request->validate(['key' => ['required', 'string', 'max:100'], 'value' => ['nullable', 'string', 'max:200']]);
        $key = $d['key'];
        $value = $d['value'] ?? '';
        if (in_array($key, ['enabled', 'egress_verified', 'notifications_enabled'], true) || str_starts_with($key, 'service:')) {
            if (str_starts_with($key, 'service:')) {
                $catalog->find(substr($key, 8));
            }
            if (! in_array($value, ['true', 'false'], true)) {
                throw new IdentityError('invalid_input', 422);
            }
            $value = $value === 'true';
        } elseif (in_array($key, ['markup_bps', 'provider_concurrency', 'provider_daily_cap_irt', 'upstream_balance_irt', 'internal_kyc_reserve_irt'], true) || preg_match('/^customer_cap:[0-9]+$/D', $key)) {
            if (! ctype_digit($value) || strlen($value) > 12 || $key === 'markup_bps' && (int) $value > 10000) {
                throw new IdentityError('invalid_input', 422);
            }
            $value = (int) $value;
        } elseif ($key === 'upstream_balance_verified_at') {
            $date = Carbon::parse($value);
            if ($date->isFuture()) {
                throw new IdentityError('invalid_input', 422);
            }
            $value = $date->toIso8601String();
        } elseif ($key !== 'resale_evidence') {
            throw new IdentityError('invalid_input', 422);
        }
        $controls->put($key, $value, Auth::id());

        return back()->with('ok', __('identity.saved'));
    }

    public function price(Request $request, Controls $controls, Catalog $catalog)
    {
        $d = $request->validate(['service' => ['required', 'string', 'max:100'], 'source_amount' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'source_currency' => ['required', 'in:IRR,IRT'], 'purchase_tax_amount' => ['required', 'integer', 'min:0', 'lte:source_amount'],
            'purchase_tax_included' => ['accepted'], 'markup_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'confirmed_zero_cost' => ['nullable', 'boolean'], 'daily_quota' => ['required', 'integer', 'min:1', 'max:100000'],
            'billing_policy' => ['required', 'in:valid_data_v1'], 'evidence' => ['required', 'string', 'max:200'],
            'access_evidence' => ['required', 'string', 'max:200'], 'effective_at' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after:effective_at']]);
        $s = $catalog->find($d['service']);
        if ($s['contract_blocker']) {
            throw new IdentityError('service_unavailable', 503);
        }
        $d['purchase_tax_included'] = true;
        $d['markup_bps'] ??= (int) $controls->get('markup_bps', 500);
        $d['created_by'] = Auth::id();
        DB::transaction(function () use ($d, $controls) {
            $p = IdentityPrice::create($d);
            $controls->audit(Auth::id(), 'price_version', ['id' => $p->id, 'service' => $p->service]);
        });

        return back()->with('ok', __('identity.saved'));
    }

    public function customerCap(Request $request, Controls $controls)
    {
        $d = $request->validate(['customer_id' => ['required', 'integer', 'min:1'], 'cap_irt' => ['required', 'integer', 'min:0', 'max:9000000000000']]);
        Customer::findOrFail($d['customer_id']);
        $controls->put('customer_cap:'.$d['customer_id'], (int) $d['cap_irt'], Auth::id());

        return back()->with('ok', __('identity.saved'));
    }

    public function refund(Request $request, string $id, Engine $engine)
    {
        $d = $request->validate(['evidence' => ['required', 'string', 'max:200']]);
        $engine->refund(IdentityRequest::where('public_id', $id)->firstOrFail(), Auth::id(), $d['evidence']);

        return back()->with('ok', __('identity.saved'));
    }

    public function export(Request $request)
    {
        return response()->streamDownload(function () use ($request) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['request_id', 'customer_id', 'token_id', 'service', 'date_utc', 'processing', 'outcome', 'finance', 'sell_irt', 'tax_irt', 'cost_irt', 'charged_irt', 'discrepancy']);
            $q = IdentityRequest::orderBy('id');
            foreach (['customer_id', 'token_id', 'service', 'financial_status'] as $k) {
                if ($request->filled($k)) {
                    $q->where($k, $request->query($k));
                }
            }
            if ($request->filled('from')) {
                $q->whereDate('created_at', '>=', $request->query('from'));
            }
            if ($request->filled('to')) {
                $q->whereDate('created_at', '<=', $request->query('to'));
            }
            foreach ($q->cursor() as $r) {
                fputcsv($f, [$r->public_id, $r->customer_id, $r->token_id, $r->service, $r->created_at, $r->processing_status, $r->inquiry_outcome, $r->financial_status, $r->quote['sell_irt'], $r->quote['tax_irt'], $r->quote['cost_irt'], $r->charged_irt, (int) $r->discrepancy]);
            }
            fclose($f);
        }, 'identity-usage.csv', ['Content-Type' => 'text/csv']);
    }
}
