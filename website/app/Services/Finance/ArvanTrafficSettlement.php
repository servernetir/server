<?php

namespace App\Services\Finance;

use App\Models\BusinessEntry;
use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** One reviewed snapshot, once per provider cycle. Never scheduled; no provider/lifecycle calls. */
final class ArvanTrafficSettlement
{
    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new RuntimeException($message);
        }
    }

    private function money(mixed $value): int
    {
        $this->check(is_string($value) && preg_match('/^\d{1,10}$/D', $value) === 1, 'Invalid integer IRR amount');
        $amount = (int) $value;
        $this->check($amount <= 1_000_000_000, 'IRR amount exceeds settlement limit');

        return $amount;
    }

    /** Supplier IRR is the authority. Displayed GB and net_irt floats never enter money arithmetic. */
    public function plan(string $json, string $sourceHash, int $marginBp, int $taxBp): array
    {
        $this->check(hash_equals(hash('sha256', $json), $sourceHash), 'Snapshot hash mismatch');
        $this->check($marginBp >= 0 && $marginBp <= 100_000 && $taxBp >= 0 && $taxBp <= 10_000, 'Invalid markup or tax');
        $s = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $cycle = data_get($s, 'billing.cycle.data', []);
        $usage = data_get($s, 'billing.usage.data', []);
        $maps = $s['traffic_attribution'] ?? [];
        $this->check(($s['billing']['usage_status'] ?? 0) === 200 && ($s['billing']['cycle_status'] ?? 0) === 200, 'Incomplete billing response');
        $this->check(($cycle['currency'] ?? '') === 'irr' && preg_match('/^[a-f0-9-]{36}$/D', $cycle['id'] ?? '') === 1, 'Invalid billing cycle');
        $this->check((int) data_get($s, 'billing.usage.meta.pagination.totalPages') === 1
            && count($usage) === (int) data_get($s, 'billing.usage.meta.pagination.totalItems'), 'Incomplete usage pagination');
        $this->check(is_array($maps) && count($maps) > 0 && count($maps) <= 500, 'No reviewed attribution');
        $start = Carbon::parse($cycle['startedAt']);
        $end = Carbon::parse($cycle['expiredAt']);
        $seen = [];
        $lines = [];
        foreach ($maps as $a) {
            $sid = $a['service_id'] ?? null;
            $cid = $a['customer_id'] ?? null;
            $native = $a['provider_instance_id'] ?? '';
            $this->check(is_int($sid) && $sid > 0 && is_int($cid) && $cid > 0 && ($a['matched'] ?? false) === true, 'Invalid owner attribution');
            $this->check(is_string($native) && $native !== '' && ! isset($seen[$sid]) && ! in_array($native, $seen, true), 'Duplicate resource attribution');
            $seen[$sid] = $native;
            $rows = array_values(array_filter($usage, fn ($r) => ($r['instanceId'] ?? '') === $native && ($r['scope'] ?? '') === 'instance' && ($r['productName'] ?? '') === 'iaas'));
            $this->check(count($rows) === 1 && filled($rows[0]['region'] ?? null), 'Missing unique instance billing row');
            $items = array_values(array_filter($rows[0]['items'] ?? [], fn ($i) => ($i['metric'] ?? '') === 'cc_internet'));
            $this->check(count($items) === 1, 'Missing unique internet billing item');
            $i = $items[0];
            $cost = $this->money($i['price']) - $this->money($i['discount']);
            $this->check($cost > 0 && $this->money($i['usedGift'] ?? '0') === 0, 'Nonpositive cost or unreviewed gift allocation');
            $this->check($this->money($a['price_irr']) - $this->money($a['discount_irr']) === $cost
                && ($a['usage_from'] ?? null) === ($i['usageFrom'] ?? null) && ($a['usage_to'] ?? null) === ($i['usageTo'] ?? null), 'Reviewed attribution differs from source');
            $from = Carbon::parse($i['usageFrom']);
            $to = Carbon::parse($i['usageTo']);
            $this->check($from->gte($start) && $to->gt($from) && $to->lte($end) && $to->lte(Carbon::parse($s['observed_at'])), 'Invalid billing window');
            $this->check(is_numeric($i['amount']) && is_finite((float) $i['amount']) && $i['amount'] > 0, 'Invalid displayed traffic quantity');
            // IRR -> IRT and markup in ONE division, ceiling to whole toman per service.
            $sell = intdiv($cost * (10_000 + $marginBp) + 99_999, 100_000);
            $tax = intdiv($sell * $taxBp + 5_000, 10_000);
            $lines[] = ['service_id' => $sid, 'customer_id' => $cid, 'resource' => $rows[0]['region'].':'.$native,
                'item_id' => $i['itemId'], 'from' => $i['usageFrom'], 'to' => $i['usageTo'],
                'gb_display' => number_format((float) $i['amount'], 6, '.', ''),
                'cost_irr' => $cost, 'sell_irt' => $sell, 'tax_irt' => $tax];
        }
        usort($lines, fn ($a, $b) => [$a['customer_id'], $a['service_id']] <=> [$b['customer_id'], $b['service_id']]);
        $this->check(array_sum(array_column($lines, 'sell_irt')) + array_sum(array_column($lines, 'tax_irt')) <= 1_000_000_000, 'Batch exceeds settlement limit');
        $plan = ['version' => 1, 'cycle' => $cycle['id'], 'source_hash' => $sourceHash, 'margin_bp' => $marginBp, 'tax_bp' => $taxBp, 'lines' => $lines];
        $plan['hash'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));

        return $plan;
    }

    private function prefix(array $plan): string
    {
        return 'traffic-owner:'.$plan['cycle'].':';
    }

    /** Caller supplies the reviewed hash and explicit owner instruction, never a public HTTP route. */
    public function apply(array $plan, string $expectedHash, string $authorization): array
    {
        $unsigned = $plan;
        unset($unsigned['hash']);
        $this->check(hash_equals(hash('sha256', json_encode($unsigned, JSON_THROW_ON_ERROR)), $expectedHash)
            && hash_equals($plan['hash'], $expectedHash), 'Plan changed');
        $this->check(mb_strlen(trim($authorization)) >= 10, 'Owner authorization record required');
        $this->check(app(BusinessLedger::class)->ready() && app(DocumentedAccounting::class)->ready(), 'Accounting unavailable');
        $groups = collect($plan['lines'])->groupBy('customer_id')->sortKeys();
        $this->check($groups->isNotEmpty(), 'Empty settlement');

        return DB::transaction(function () use ($plan, $groups, $authorization) {
            // Same first lock as Wallet and hourly metering; deterministic ordering across a batch.
            foreach ($groups->keys() as $cid) {
                Customer::whereKey($cid)->lockForUpdate()->firstOrFail();
            }
            foreach (collect($plan['lines'])->sortBy('service_id') as $line) {
                $service = Service::whereKey($line['service_id'])->lockForUpdate()->firstOrFail();
                $instances = CloudInstance::where('service_id', $service->id)->lockForUpdate()->get();
                $this->check((int) $service->customer_id === $line['customer_id'] && $service->currency_code === 'IRT'
                    && $instances->count() === 1 && $instances[0]->provider === 'arvan'
                    && $instances[0]->provider_ref === $line['resource'], 'Live ownership/resource mismatch');
            }
            $existing = Payment::where('external_ref', 'like', $this->prefix($plan).'%')->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                $this->check($existing->count() === $groups->count(), 'Partial or different cycle settlement; manual reconciliation required');
                foreach ($existing as $p) {
                    $this->check($p->ref_id === $plan['hash'] && $groups->has($p->customer_id), 'Cycle already settled with another snapshot');
                }
            } else {
                // Legacy/manual traffic charges must be reconciled explicitly, never silently charged twice.
                $prior = CreditEntry::whereIn('customer_id', $groups->keys())->where('amount', '<', 0)->where(function ($q) {
                    $q->where('reason', 'like', '%traffic%')->orWhere('note', 'like', '%traffic%')->orWhere('note', 'like', '%ترافیک%');
                })->exists();
                $priorInvoice = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                    ->whereIn('invoices.customer_id', $groups->keys())->where(function ($q) {
                        $q->where('title', 'like', '%ترافیک%')->orWhere('title', 'like', '%traffic%');
                    })->exists();
                $this->check(! $prior && ! $priorInvoice, 'Prior traffic charge requires reconciliation');
            }
            $out = [];
            foreach ($groups as $cid => $lines) {
                $subtotal = $lines->sum('sell_irt');
                $tax = $lines->sum('tax_irt');
                $total = $subtotal + $tax;
                $payment = $existing->firstWhere('customer_id', $cid);
                $replayed = $payment !== null;
                if (! $payment) {
                    $invoice = Invoice::create(['customer_id' => $cid, 'kind' => 'service', 'currency_code' => 'IRT',
                        'status' => 'draft', 'issued_at' => now(), 'note' => 'هزینه مصرف ترافیک سرورها؛ تسویه با اعتبار حساب و ثبت بدهی در صورت کسری موجودی.']);
                    foreach ($lines as $l) {
                        $invoice->items()->create(['title' => 'ترافیک اینترنت سرور #'.$l['service_id'],
                            'description' => $l['gb_display'].' GB | '.$l['from'].' — '.$l['to'].' (UTC)',
                            'quantity' => 1, 'unit_price' => $l['sell_irt'], 'line_total' => $l['sell_irt'],
                            'discount' => 0, 'tax_rate_bp' => $plan['tax_bp'], 'tax_amount' => $l['tax_irt']]);
                    }
                    $invoice->recalculateTotals();
                    $this->check($invoice->total === $total, 'Invoice math mismatch');
                    $invoice->forceFill(['status' => 'paid', 'paid' => $total, 'paid_at' => now()])->save();
                    $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $cid,
                        'gateway' => 'credit', 'currency_code' => 'IRT', 'amount' => $total, 'status' => 'paid',
                        'external_ref' => $this->prefix($plan).$cid, 'ref_id' => $plan['hash'], 'fee' => 0, 'paid_at' => now()]);
                    // Explicit owner-authorized consumed-traffic debt. The normal purchase guard stays enabled.
                    app(Wallet::class)->debit($cid, 'IRT', $total, 'invoice_payment', $invoice,
                        'تسویه ترافیک — فاکتور '.$invoice->number, requireAvailable: false);
                    app(BusinessLedger::class)->recordPayment($payment);
                    app(DocumentedAccounting::class)->audit('owner_traffic_settlement', 0, $invoice->id,
                        ['plan_hash' => $plan['hash'], 'source_hash' => $plan['source_hash'], 'cycle' => $plan['cycle'],
                            'authorization' => $authorization, 'markup_bp' => $plan['margin_bp'], 'tax_bp' => $plan['tax_bp'], 'lines' => $lines->values()->all()]);
                }
                $invoice = $payment->invoice()->firstOrFail();
                $debits = CreditEntry::where('source_type', Invoice::class)->where('source_id', $invoice->id)->get();
                $entries = BusinessEntry::where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->get();
                $this->check($payment->status === 'paid' && $payment->gateway === 'credit' && $payment->amount === $total
                    && $payment->currency_code === 'IRT' && (int) $payment->customer_id === (int) $cid
                    && $payment->external_ref === $this->prefix($plan).$cid
                    && $invoice->status === 'paid' && $invoice->paid === $total && $invoice->total === $total
                    && $invoice->subtotal === $subtotal && $invoice->tax === $tax && $invoice->service_id === null
                    && (int) $invoice->customer_id === (int) $cid && $invoice->items()->count() === $lines->count(), 'Settlement records differ');
                $actualItems = $invoice->items()->orderBy('id')->get();
                foreach ($lines->values() as $n => $l) {
                    $item = $actualItems[$n];
                    $this->check($item->title === 'ترافیک اینترنت سرور #'.$l['service_id'] && $item->quantity === 1
                        && $item->unit_price === $l['sell_irt'] && $item->line_total === $l['sell_irt'] && $item->discount === 0
                        && $item->tax_amount === $l['tax_irt'] && $item->tax_rate_bp === $plan['tax_bp'], 'Settlement invoice item changed');
                }
                $audits = DB::table('finance_audit_events')->where('action', 'owner_traffic_settlement')->where('subject_id', $invoice->id)->get();
                $this->check($audits->count() === 1 && data_get(json_decode($audits[0]->details, true), 'plan_hash') === $plan['hash'], 'Settlement audit missing or changed');
                $this->check($debits->count() === 1 && $debits[0]->amount === -$total && $debits[0]->reason === 'invoice_payment'
                    && (int) $debits[0]->customer_id === (int) $cid && $debits[0]->currency_code === 'IRT', 'Settlement debit missing or changed');
                $this->check($entries->where('kind', 'revenue')->sum('amount') === $subtotal
                    && $entries->where('kind', 'tax_collected')->sum('amount') === $tax
                    && $entries->count() === ($tax > 0 ? 2 : 1), 'Accounting posting missing or changed');
                $out[] = ['customer_id' => (int) $cid, 'invoice_id' => $invoice->id, 'number' => $invoice->number,
                    'payment_id' => $payment->id, 'credit_entry_id' => $debits[0]->id, 'subtotal_irt' => $subtotal,
                    'tax_irt' => $tax, 'total_irt' => $total, 'balance_irt' => app(Wallet::class)->balanceOf($cid), 'replayed' => $replayed];
            }

            return $out;
        }, 3);
    }
}
