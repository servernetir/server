<?php

namespace App\Services\IdentityApi;

use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\IdentityApplication;
use App\Models\IdentityRequest;
use App\Models\IdentityVerification;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\Wallet;
use App\Support\ErrorTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Engine
{
    public const USAGE_POLICY = 'available_wallet_v1';

    public function __construct(private Controls $controls, private Catalog $catalog, private Contract $contract, private Pricing $pricing, private Wallet $wallet) {}

    public function authorize(IdentityApplication $a, ?CustomerApiToken $token = null, ?string $category = null): void
    {
        if (! $this->controls->ready()) {
            throw new IdentityError('service_unavailable', 503);
        }
        $c = Customer::find($a->customer_id);
        if (! $c || ! $c->isActive() || $a->status !== 'active' || $a->terms_version !== config('identity_api.terms_version')
            || ! IdentityVerification::where('customer_id', $a->customer_id)->where('status', 'verified')->exists()) {
            throw new IdentityError('product_not_active', 403);
        }
        if ($token && ($token->unusableReason() || $token->customer_id != $a->customer_id || $token->identity_application_id != $a->id
            || ! $token->can('identity:execute') || $category && ! $token->can('identity:'.$category))) {
            throw new IdentityError('insufficient_scope', 403);
        }
    }

    public function accept(IdentityApplication $a, ?CustomerApiToken $token, string $slug, array $payload, string $key, int $maxCharge, ?int $version = null): IdentityRequest
    {
        $service = $this->catalog->find($slug);
        $this->authorize($a, $token, $service['category']);
        if (! preg_match('/^[A-Za-z0-9_.:-]{16,128}$/D', $key)) {
            throw new IdentityError('idempotency_required', 422);
        }
        $payload = $this->contract->input($service, $payload);
        $fingerprint = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) config('app.key'));
        $keyHash = hash('sha256', $key);

        return DB::transaction(function () use ($a, $token, $service, $slug, $payload, $keyHash, $fingerprint, $maxCharge, $version) {
            Customer::whereKey($a->customer_id)->lockForUpdate()->firstOrFail();
            $freshApp = IdentityApplication::whereKey($a->id)->lockForUpdate()->firstOrFail();
            $freshToken = $token ? CustomerApiToken::whereKey($token->id)->lockForUpdate()->firstOrFail() : null;
            $this->authorize($freshApp, $freshToken, $service['category']);
            $existing = IdentityRequest::where('application_id', $a->id)->where('service', $slug)->where('operation', 'execute')->where('key_hash', $keyHash)->first();
            if ($existing) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new IdentityError('idempotency_conflict');
                }

                return $existing;
            }
            if (! $this->controls->dispatchEnabled() || $this->controls->get('service:'.$slug, false) !== true) {
                throw new IdentityError('service_unavailable', 503);
            }
            if (! app(Provider::class)->configured()) {
                throw new IdentityError('provider_unavailable', 503);
            }
            if (Cache::has('identity:circuit:'.$slug)) {
                throw new IdentityError('provider_unavailable', 503);
            }
            $price = $this->catalog->price($slug);
            if (! $price) {
                throw new IdentityError('price_unavailable', 503);
            }
            $quote = $this->pricing->quote($price, Customer::findOrFail($a->customer_id));
            if ($maxCharge < $quote['total_irt'] || $version !== null && $version !== $price->id) {
                throw new IdentityError('quote_changed');
            }
            if ($this->wallet->availableOf($a->customer_id) < $quote['total_irt']) {
                throw new IdentityError('insufficient_funds', 402);
            }
            $recent = IdentityRequest::where('customer_id', $a->customer_id)->where('created_at', '>=', now()->subMinute());
            if ((clone $recent)->count() >= 60 || $freshToken && (clone $recent)->where('token_id', $freshToken->id)->count() >= 20) {
                throw new IdentityError('quota_exceeded', 429);
            }
            $exposure = fn ($q) => (int) $q->where(function ($q) {
                $q->holding()->orWhere('financial_status', 'captured');
            })->sum('hold_irt');
            // Identity inquiries spend available wallet funds. Historical customer,
            // application and token daily budgets no longer restrict this product.
            if (IdentityRequest::where('application_id', $a->id)->holding()->count() >= $freshApp->concurrency) {
                throw new IdentityError('concurrency_limit', 429);
            }
            // Global provider funding/concurrency serialized across all tenants. No network under this lock.
            DB::table('identity_controls')->where('key', 'provider_mutex')->lockForUpdate()->firstOrFail();
            if (IdentityRequest::holding()->count() >= (int) $this->controls->get('provider_concurrency', 4)) {
                throw new IdentityError('concurrency_limit', 429);
            }
            $providerToday = $exposure(IdentityRequest::where('created_at', '>=', now()->startOfDay()));
            if ($providerToday + $quote['total_irt'] > (int) $this->controls->get('provider_daily_cap_irt', 0)) {
                throw new IdentityError('provider_unavailable', 503);
            }
            if (! $this->controls->fundingReady()) {
                throw new IdentityError('provider_unavailable', 503);
            }
            $balanceAt = Carbon::parse($this->controls->get('upstream_balance_verified_at'));
            $used = (int) IdentityRequest::where(function ($q) use ($balanceAt) {
                // A new snapshot must not discard old requests still awaiting dispatch
                // or settlement. Include charges racing the start of the balance read.
                $q->holding()->orWhere('dispatch_at', '>=', $balanceAt)
                    ->orWhere(function ($q) {
                        $q->whereNotNull('dispatch_at')->whereNull('settled_at');
                    })
                    ->orWhere(function ($q) use ($balanceAt) {
                        $q->whereNotNull('dispatch_at')->where('settled_at', '>=', $balanceAt);
                    });
            })->sum('hold_irt');
            if ($used + $quote['cost_irt'] + (int) $this->controls->get('internal_kyc_reserve_irt', 0) > (int) $this->controls->get('upstream_balance_irt', 0)) {
                throw new IdentityError('provider_unavailable', 503);
            }

            return IdentityRequest::create(['public_id' => (string) Str::uuid(), 'customer_id' => $a->customer_id, 'application_id' => $a->id, 'token_id' => $token?->id,
                'service' => $slug, 'operation' => 'execute', 'key_hash' => $keyHash, 'fingerprint' => $fingerprint, 'payload' => $payload, 'quote' => $quote,
                'hold_irt' => $quote['total_irt'], 'decide_by' => now()->addMinutes((int) config('identity_api.decision_minutes', 15)),
                'retain_until' => now()->addHours((int) config('identity_api.result_retention_hours', 24))]);
        }, 3);
    }

    /** Claim once. An expired/released request can never re-enter dispatch, even after a top-up. */
    public function dispatch(IdentityRequest $request): IdentityRequest
    {
        $claimed = DB::transaction(function () use ($request) {
            Customer::whereKey($request->customer_id)->lockForUpdate()->firstOrFail();
            $r = IdentityRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->processing_status !== 'accepted' || $r->financial_status !== 'reserved' || ! $r->decide_by->isFuture()) {
                return false;
            }
            $a = IdentityApplication::findOrFail($r->application_id);
            $token = $r->token_id ? CustomerApiToken::find($r->token_id) : null;
            if ($r->token_id && ! $token) {
                return false;
            }
            try {
                $this->authorize($a, $token, $this->catalog->find($r->service)['category']);
            } catch (IdentityError) {
                return false;
            }
            if (! $this->controls->dispatchEnabled() || $this->controls->get('service:'.$r->service, false) !== true || ! app(Provider::class)->configured()) {
                return false;
            }
            $r->forceFill(['processing_status' => 'processing', 'dispatch_at' => now()])->save();

            return true;
        }, 3);
        if (! $claimed) {
            return $request->fresh();
        }
        $r = $request->fresh();
        $started = hrtime(true);
        try {
            $response = app(Provider::class)->send($this->catalog->find($r->service), $r->payload);
        } catch (\Throwable) {
            $response = ['http' => 0, 'json' => []];
        }
        // Save evidence BEFORE money. Failure here leaves a processing request; never resend it.
        $result = $this->contract->response($this->catalog->find($r->service), $response['json'], $response['http']);
        try {
            $metric = 'identity:errors:'.$r->service;
            if ($result) {
                Cache::forget($metric);
            } else {
                Cache::add($metric, 0, now()->addMinutes(5));
                if (Cache::increment($metric) >= 3) {
                    Cache::put('identity:circuit:'.$r->service, true, now()->addMinutes(5));
                    ErrorTracker::noteOnce('identity_api', 'Inquiry service paused after repeated uncertain responses', 300, ['service' => $r->service]);
                }
            }
            Cache::put('identity:last_response:'.$r->service, ['http' => $response['http'], 'at' => now()->toIso8601String()], now()->addDay());
        } catch (\Throwable) { /* Evidence persistence and financial resolution have priority. */
        }
        $latency = (int) min(4294967295, intdiv(hrtime(true) - $started, 1000000));
        DB::transaction(function () use ($r, $result, $response, $latency) {
            Customer::whereKey($r->customer_id)->lockForUpdate()->firstOrFail();
            $row = IdentityRequest::whereKey($r->id)->lockForUpdate()->firstOrFail();
            $open = in_array($row->financial_status, ['reserved', 'pending_reconciliation'], true);
            $row->forceFill(['processing_status' => $result ? 'completed' : 'unknown', 'inquiry_outcome' => $result['outcome'] ?? null,
                'result' => $result['data'] ?? null, 'provider_evidence' => $response, 'payload' => null,
                'latency_ms' => $latency, 'upstream_http' => $response['http'],
                'discrepancy' => $row->discrepancy || ! $open,
                'financial_status' => $open ? 'pending_reconciliation' : $row->financial_status,
                'error_code' => $open ? ($result ? null : 'pending_reconciliation') : $row->error_code])->save();
        }, 3);
        if ($result) {
            try {
                $this->capture($r);
            } catch (\Throwable) {
                ErrorTracker::noteOnce('identity_api', 'Inquiry settlement pending; evidence retained for reconciliation', 300, ['request_id' => $r->public_id]);
            }
        }

        return $r->fresh();
    }

    public function capture(IdentityRequest $request): void
    {
        DB::transaction(function () use ($request) {
            Customer::whereKey($request->customer_id)->lockForUpdate()->firstOrFail();
            $r = IdentityRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->financial_status === 'captured') {
                return;
            }
            if ($r->financial_status !== 'pending_reconciliation' || ! $r->decide_by->isFuture()) {
                return;
            }
            $ev = $r->provider_evidence;
            if (! $ev || ! $this->contract->response($this->catalog->find($r->service), $ev['json'], $ev['http'])) {
                return;
            }
            $entry = $r->hold_irt > 0 ? $this->wallet->debit($r->customer_id, 'IRT', $r->hold_irt, 'identity_inquiry', $r,
                'Identity API '.$r->public_id, excludingIdentityRequestId: $r->id) : null;
            app(BusinessLedger::class)->recordIdentitySale($r);
            $r->forceFill(['financial_status' => 'captured', 'charged_irt' => $r->hold_irt, 'credit_entry_id' => $entry?->id, 'settled_at' => now()])->save();
        }, 3);
    }

    public function release(IdentityRequest $request, string $reason = 'deadline_without_evidence'): void
    {
        DB::transaction(function () use ($request, $reason) {
            Customer::whereKey($request->customer_id)->lockForUpdate()->firstOrFail();
            $r = IdentityRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! in_array($r->financial_status, ['reserved', 'pending_reconciliation'], true)) {
                return;
            }
            $r->forceFill(['financial_status' => 'released', 'processing_status' => $r->dispatch_at ? 'unknown' : 'failed',
                'payload' => null, 'discrepancy' => $r->dispatch_at !== null, 'error_code' => $reason, 'settled_at' => now()])->save();
        }, 3);
    }

    public function refund(IdentityRequest $request, int $actor, string $evidence): void
    {
        DB::transaction(function () use ($request, $actor, $evidence) {
            Customer::whereKey($request->customer_id)->lockForUpdate()->firstOrFail();
            $r = IdentityRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->financial_status !== 'captured') {
                return;
            }
            $entry = $r->charged_irt > 0 ? $this->wallet->credit($r->customer_id,'IRT',$r->charged_irt,'identity_refund',$r,'Identity correction '.$r->public_id) : null;
            app(BusinessLedger::class)->recordIdentityRefund($r);
            $r->forceFill(['financial_status' => 'refunded', 'refund_entry_id' => $entry?->id, 'charged_irt' => 0])->save();
            $this->controls->audit($actor,'refund',['request_id' => $r->public_id, 'evidence' => $evidence]);
        }, 3);
    }
}
