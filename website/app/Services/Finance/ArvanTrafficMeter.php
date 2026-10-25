<?php

namespace App\Services\Finance;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\BusinessEntry;
use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudPricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Encrypted durable checkpoint, invoices and wallet changes commit in ONE database transaction. */
final class ArvanTrafficMeter
{
    public const KEY = 'arvan_traffic_meter_v1';

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    public function state(): ?array
    {
        $row = Setting::query()->find(self::KEY);

        return $row ? json_decode(Crypt::decryptString($row->value), true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private function save(Setting $row, array $state): void
    {
        $cipher = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
        $this->check(strlen($cipher) < 60_000, 'Traffic checkpoint capacity reached; reconciliation required');
        $row->forceFill(['value' => $cipher])->save();
    }

    private function rates(array $state): void
    {
        $this->check((int) round(app(CloudPricing::class)->marginPct('arvan') * 100) === $state['margin_bp']
            && CloudStoreController::taxPercent() * 100 === $state['tax_bp'], 'Traffic pricing changed; review required');
    }

    /** Initialize ONLY from the already-paid reviewed plan; never assume that historic consumption was unpaid. */
    public function initialize(array $plan, array $snapshot, array $excluded, string $authorization): array
    {
        app(ArvanTrafficSource::class)->validate($snapshot);
        $this->check(mb_strlen(trim($authorization)) >= 10, 'Owner authorization required');
        $this->rates($plan);
        $this->check($plan['cycle'] === $snapshot['current_cycle'], 'Baseline is not the current supplier cycle');
        $unsigned = $plan;
        unset($unsigned['hash']);
        $this->check(hash_equals(hash('sha256', json_encode($unsigned, JSON_THROW_ON_ERROR)), $plan['hash']), 'Baseline plan hash changed');

        return DB::transaction(function () use ($plan, $snapshot, $excluded, $authorization) {
            $this->check(! Setting::query()->whereKey(self::KEY)->exists(), 'Traffic meter already initialized');
            $groups = collect($plan['lines'])->groupBy('customer_id');
            $payments = Payment::where('external_ref', 'like', 'traffic-owner:'.$plan['cycle'].':%')->get();
            $this->check($payments->count() === $groups->count()
                && $payments->every(fn ($p) => $p->ref_id === $plan['hash'] && $groups->has($p->customer_id)), 'Paid baseline proof missing');
            // Existing payments were proved present first; legacy verifier must replay, never create a charge.
            $proof = app(ArvanTrafficSettlement::class)->apply($plan, $plan['hash'], $authorization);
            $this->check(collect($proof)->every(fn ($r) => $r['replayed']), 'Baseline must already be paid');
            $state = ['version' => 1, 'enabled' => true, 'authorization' => $authorization,
                'authorized_at' => now()->toIso8601String(), 'margin_bp' => $plan['margin_bp'], 'tax_bp' => $plan['tax_bp'],
                'excluded_customers' => array_values(array_unique(array_map('intval', $excluded))),
                'baseline_hash' => $plan['hash'], 'baseline_paid_at' => $payments->max('paid_at')->toIso8601String(), 'sequence' => 0, 'cycles' => [], 'checkpoints' => [], 'last_success' => null];
            foreach ($snapshot['reports'] as $report) {
                $cycle = $report['cycle'];
                $state['cycles'][$cycle['id']] = ['expired_at' => $cycle['expiredAt'], 'started_at' => $cycle['startedAt'],
                    'account_hash' => hash('sha256', $cycle['accountId'])];
            }
            foreach ($plan['lines'] as $line) {
                $state['checkpoints'][$plan['cycle'].'|'.$line['resource']] = $line;
            }
            $row = new Setting(['key' => self::KEY]);
            $this->save($row, $state);
            app(DocumentedAccounting::class)->audit('traffic_meter_initialized', 0, null,
                ['baseline_hash' => $plan['hash'], 'authorization' => $authorization, 'checkpoint_count' => count($plan['lines']),
                    'excluded_customer_count' => count($excluded)]);

            return $state;
        }, 3);
    }

    private function hasUnreviewedCharge(int $cid): bool
    {
        $known = Payment::where('customer_id', $cid)->where('status', 'paid')->where('gateway', 'credit')
            ->where(fn ($q) => $q->where('external_ref', 'like', 'traffic-owner:%')->orWhere('external_ref', 'like', 'traffic-meter:%'))
            ->pluck('invoice_id');
        $invoice = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.customer_id', $cid)->whereNotIn('invoices.id', $known)
            ->where(fn ($q) => $q->where('title', 'like', '%traffic%')->orWhere('title', 'like', '%ترافیک%'))->exists();
        $debits = CreditEntry::where('customer_id', $cid)->where('amount', '<', 0)
            ->where(fn ($q) => $q->where('reason', 'like', '%traffic%')->orWhere('note', 'like', '%traffic%')->orWhere('note', 'like', '%ترافیک%'))->get();

        return $invoice || $debits->contains(fn ($entry) => $entry->source_type !== Invoice::class || ! $known->contains($entry->source_id));
    }

    public function run(array $snapshot, bool $apply = false): array
    {
        app(ArvanTrafficSource::class)->validate($snapshot);
        $sourceHash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($snapshot, $apply, $sourceHash) {
            $row = Setting::query()->whereKey(self::KEY)->lockForUpdate()->firstOrFail();
            $state = json_decode(Crypt::decryptString($row->value), true, 512, JSON_THROW_ON_ERROR);
            $this->check($state['version'] === 1 && $state['enabled'] === true, 'Traffic meter disabled');
            $this->rates($state);
            $this->check(app(BusinessLedger::class)->ready() && app(DocumentedAccounting::class)->ready(), 'Accounting unavailable');
            $lines = [];
            $held = [];
            $unallocated = 0;
            $seen = [];
            $unreviewed = [];
            foreach ($snapshot['reports'] as $report) {
                $cycle = $report['cycle'];
                $cycleId = $cycle['id'];
                $account = hash('sha256', $cycle['accountId']);
                $this->check(collect($state['cycles'])->every(fn ($c) => hash_equals($c['account_hash'], $account)), 'Supplier account changed');
                $state['cycles'][$cycleId] = ['expired_at' => $cycle['expiredAt'], 'started_at' => $cycle['startedAt'], 'account_hash' => $account];
                foreach ($report['rows'] as $parent) {
                    foreach ($parent['items'] as $item) {
                        if ($item['metric'] !== 'cc_internet') {
                            continue;
                        }
                        if (($parent['productName'] ?? '') !== 'iaas' || ($parent['scope'] ?? '') !== 'instance') {
                            $unallocated++;

                            continue;
                        }
                        $resource = ($parent['region'] ?? '').':'.($parent['instanceId'] ?? '');
                        $key = $cycleId.'|'.$resource;
                        if (isset($seen[$key])) {
                            throw new RuntimeException('Multiple internet components for one resource require reconciliation');
                        }
                        $seen[$key] = true;
                        $instances = CloudInstance::where('provider', 'arvan')->where('provider_ref', $resource)->get();
                        if ($instances->count() !== 1 || ! $instances[0]->service) {
                            $held[] = ['reason' => 'unknown_or_ambiguous_owner'];

                            continue;
                        }
                        $service = $instances[0]->service;
                        $cid = (int) $service->customer_id;
                        $sid = (int) $service->id;
                        if (in_array($cid, $state['excluded_customers'], true)) {
                            $held[] = ['service_id' => $sid, 'reason' => 'owner_exclusion'];

                            continue;
                        }
                        $unreviewed[$cid] ??= $this->hasUnreviewedCharge($cid);
                        if ($unreviewed[$cid]) {
                            $held[] = ['service_id' => $sid, 'reason' => 'manual_traffic_charge_requires_reconciliation'];

                            continue;
                        }
                        $old = $state['checkpoints'][$key] ?? null;
                        $cost = ArvanTrafficSource::integer($item['price']) - ArvanTrafficSource::integer($item['discount']);
                        $from = Carbon::parse($item['usageFrom']);
                        $to = Carbon::parse($item['usageTo']);
                        $qty = $item['amount'];
                        $quantityValid = is_numeric($qty) && is_finite((float) $qty) && (float) $qty >= 0 && (float) $qty <= 1_000_000_000;
                        $invalid = $service->currency_code !== 'IRT' || ! $quantityValid
                            || ArvanTrafficSource::integer($item['discount']) !== 0 || ArvanTrafficSource::integer($item['usedGift']) !== 0
                            || ! $from->gte(Carbon::parse($cycle['startedAt'])) || ! $to->gt($from)
                            || ! $to->lte(Carbon::parse($cycle['expiredAt'])) || ! $to->lte(Carbon::parse($snapshot['observed_at']));
                        if ($invalid || ($old && ($old['customer_id'] !== $cid || $old['service_id'] !== $sid
                            || ! $from->eq(Carbon::parse($old['from'])) || $cost < $old['cost_irr']
                            || $to->lt(Carbon::parse($old['to'])) || (float) $qty + 0.000001 < (float) $old['gb_display']))) {
                            $held[] = ['service_id' => $sid, 'reason' => 'changed_owner_window_counter_discount_or_gift'];

                            continue;
                        }
                        // New machines created after the proven initial settlement could not be in that earlier bill.
                        $newAfterBaseline = $service->created_at && $service->created_at->gte(Carbon::parse($state['baseline_paid_at']));
                        if (! $old && ((! $newAfterBaseline && $from->lt(Carbon::parse($state['authorized_at'])))
                            || ! $service->created_at || $from->lt($service->created_at))) {
                            $held[] = ['service_id' => $sid, 'reason' => 'historic_resource_requires_baseline'];

                            continue;
                        }
                        if ($cost === ($old['cost_irr'] ?? 0)) {
                            continue;
                        }
                        if ($old && (! $to->gt(Carbon::parse($old['to'])) || (float) $qty <= (float) $old['gb_display'])) {
                            $held[] = ['service_id' => $sid, 'reason' => 'late_repricing_requires_review'];

                            continue;
                        }
                        $sell = intdiv($cost * (10_000 + $state['margin_bp']) + 99_999, 100_000);
                        $tax = intdiv($sell * $state['tax_bp'] + 5_000, 10_000);
                        $deltaSell = $sell - ($old['sell_irt'] ?? 0);
                        $deltaTax = $tax - ($old['tax_irt'] ?? 0);
                        $this->check($deltaSell >= 0 && $deltaTax >= 0, 'Negative traffic posting requires reconciliation');
                        $next = ['service_id' => $sid, 'customer_id' => $cid, 'resource' => $resource, 'item_id' => $item['itemId'],
                            'from' => $item['usageFrom'], 'to' => $item['usageTo'], 'gb_display' => number_format((float) $qty, 6, '.', ''),
                            'cost_irr' => $cost, 'sell_irt' => $sell, 'tax_irt' => $tax];
                        if ($deltaSell + $deltaTax === 0) {
                            continue; // Preserve rounding carry until a whole-toman posting is due.
                        }
                        $lines[] = ['key' => $key, 'cycle' => $cycleId, 'old' => $old, 'next' => $next,
                            'subtotal_irt' => $deltaSell, 'tax_irt' => $deltaTax,
                            'from' => $old['to'] ?? $item['usageFrom'], 'to' => $item['usageTo'],
                            'gb' => number_format(max(0, (float) $qty - (float) ($old['gb_display'] ?? 0)), 6, '.', '')];
                    }
                }
            }
            $total = array_sum(array_column($lines, 'subtotal_irt')) + array_sum(array_column($lines, 'tax_irt'));
            $this->check($total <= 1_000_000_000, 'Traffic batch exceeds settlement limit');
            $summary = ['mode' => $apply ? 'apply' : 'preview', 'source_hash' => $sourceHash, 'line_count' => count($lines),
                'total_irt' => $total, 'held' => $held, 'unallocated_count' => $unallocated, 'invoices' => []];
            if (! $apply) {
                return $summary;
            }
            // Global private state lock serializes meters; customer locks keep normal Wallet/hourly writes compatible.
            foreach (collect($lines)->pluck('next.customer_id')->unique()->sort() as $cid) {
                Customer::whereKey($cid)->lockForUpdate()->firstOrFail();
            }
            foreach (collect($lines)->sortBy('next.service_id') as $line) {
                $next = $line['next'];
                $service = Service::whereKey($next['service_id'])->lockForUpdate()->firstOrFail();
                $instances = CloudInstance::where('service_id', $service->id)->lockForUpdate()->get();
                $this->check((int) $service->customer_id === $next['customer_id'] && $service->currency_code === 'IRT'
                    && $instances->count() === 1 && $instances[0]->provider === 'arvan'
                    && $instances[0]->provider_ref === $next['resource'], 'Live traffic ownership changed');
            }
            $sequence = $state['sequence'] + 1;
            foreach (collect($lines)->groupBy(fn ($l) => $l['cycle'].'|'.$l['next']['customer_id'])->sortKeys() as $group) {
                $cid = $group->first()['next']['customer_id'];
                $subtotal = $group->sum('subtotal_irt');
                $tax = $group->sum('tax_irt');
                $invoice = Invoice::create(['customer_id' => $cid, 'kind' => 'service', 'currency_code' => 'IRT',
                    'status' => 'draft', 'issued_at' => now(), 'note' => 'تسویه تفاضلی مصرف ثبت‌شده ترافیک؛ کسری موجودی به بدهی حساب تبدیل می‌شود.']);
                foreach ($group as $line) {
                    $invoice->items()->create(['title' => 'ترافیک اینترنت سرور #'.$line['next']['service_id'],
                        'description' => $line['gb'].' GB | '.$line['from'].' — '.$line['to'].' (UTC)',
                        'quantity' => 1, 'unit_price' => $line['subtotal_irt'], 'line_total' => $line['subtotal_irt'], 'discount' => 0,
                        'tax_rate_bp' => $state['tax_bp'], 'tax_amount' => $line['tax_irt']]);
                }
                $invoice->recalculateTotals();
                $this->check($invoice->total === $subtotal + $tax, 'Traffic invoice math mismatch');
                $invoice->forceFill(['status' => 'paid', 'paid' => $invoice->total, 'paid_at' => now()])->save();
                $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $cid, 'gateway' => 'credit',
                    'currency_code' => 'IRT', 'amount' => $invoice->total, 'status' => 'paid', 'fee' => 0, 'paid_at' => now(),
                    'external_ref' => 'traffic-meter:'.$group->first()['cycle'].':'.$sequence.':'.$cid, 'ref_id' => $sourceHash]);
                app(Wallet::class)->debit($cid, 'IRT', $invoice->total, 'invoice_payment', $invoice,
                    'تسویه ترافیک — فاکتور '.$invoice->number, requireAvailable: false);
                app(BusinessLedger::class)->recordPayment($payment);
                $this->check(CreditEntry::where('source_type', Invoice::class)->where('source_id', $invoice->id)->count() === 1
                    && (int) CreditEntry::where('source_type', Invoice::class)->where('source_id', $invoice->id)->sum('amount') === -$invoice->total,
                    'Traffic wallet posting mismatch');
                $entries = BusinessEntry::where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->get();
                $this->check($entries->where('kind', 'revenue')->sum('amount') === $subtotal
                    && $entries->where('kind', 'tax_collected')->sum('amount') === $tax, 'Traffic accounting posting mismatch');
                app(DocumentedAccounting::class)->audit('traffic_meter_settlement', 0, $invoice->id,
                    ['source_hash' => $sourceHash, 'baseline_hash' => $state['baseline_hash'], 'sequence' => $sequence,
                        'authorization' => $state['authorization'], 'margin_bp' => $state['margin_bp'], 'tax_bp' => $state['tax_bp'], 'lines' => $group->values()->all()]);
                $summary['invoices'][] = ['invoice_id' => $invoice->id, 'total_irt' => $invoice->total];
                foreach ($group as $line) {
                    $state['checkpoints'][$line['key']] = $line['next'];
                }
            }
            $state['sequence'] = $sequence;
            $state['last_success'] = now()->toIso8601String();
            $state['last_result'] = $summary;
            $this->save($row, $state);

            return $summary;
        }, 3);
    }
}
