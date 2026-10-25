<?php

namespace App\Services\Marketing;

use App\Models\Customer;
use App\Models\GiftCoupon;
use App\Models\Invoice;
use App\Models\MarketingCampaign;
use App\Models\MarketingGrant;
use App\Models\MarketingRedemption;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DiscountEngine
{
    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['code' => __('marketing.'.$key)]);
    }

    /** Client supplies only code and invoice ID. All eligibility/price data comes from the DB. */
    public function reserve(Invoice $invoice, Customer $customer, string $code): MarketingRedemption
    {
        return DB::transaction(function () use ($invoice, $customer, $code) {
            // Consistent lock order: invoice -> campaign -> grant -> redemption.
            $i = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ((int) $i->customer_id !== (int) $customer->id || ! $customer->isActive()) {
                $this->fail('unknown');
            }
            $code = GiftCoupon::normalize($code);
            $g = MarketingGrant::where('code', $code)
                ->where(fn ($q) => $q->where('customer_id', $customer->id)->orWhereNull('customer_id'))->first();
            if (! $g) {
                $this->fail('unknown');
            }
            $c = MarketingCampaign::whereKey($g->campaign_id)->lockForUpdate()->firstOrFail();
            $g = MarketingGrant::whereKey($g->id)->lockForUpdate()->firstOrFail();
            $existing = MarketingRedemption::where('invoice_id', $i->id)->lockForUpdate()->first();
            if ($existing && $existing->grant_id === $g->id && $existing->status === 'reserved' && $existing->expires_at->isFuture()) {
                return $existing;
            }
            if ($existing && $existing->status !== 'released') {
                $this->fail('reserved');
            }
            if ($i->kind === 'topup' || $i->status !== 'unpaid' || $i->paid > 0 ||
                $this->unresolvedPayments($i)->exists()) {
                $this->fail('state');
            }
            if ($g->revoked_at || $g->starts_at->isFuture() || ! $g->expires_at->isFuture()) {
                $this->fail('expired');
            }
            if (! app(CampaignAudience::class)->matches($customer, $g->snapshot['audience'])) {
                $this->fail('scope');
            }
            $scope = MarketingRedemption::where('campaign_id', $c->id)
                ->whereIn('status', ['reserved', 'committed', 'reconciliation', 'refunded']);
            $customerScope = (clone $scope)->where('customer_id', $customer->id);
            $customerPromises = MarketingGrant::where('campaign_id', $c->id)->where('customer_id', $customer->id)
                ->where('budget_held', true)->where('id', '!=', $g->id);
            if ($c->trigger === 'birthday') {
                $since = $g->starts_at->copy()->subDays(330);
                $customerScope->where('created_at', '>', $since);
                $customerPromises->where('starts_at', '>', $since);
            }
            $customerPromises->whereNotIn('id', (clone $customerScope)->select('grant_id'));
            $personalOutstanding = MarketingGrant::where('campaign_id', $c->id)->where('budget_held', true)
                ->whereNotIn('id', (clone $scope)->select('grant_id'))->count();
            // Outstanding guaranteed grants occupy public campaign capacity as well.
            if ((clone $scope)->count() + $personalOutstanding - ($g->budget_held ? 1 : 0) >= $c->max_uses ||
                (clone $scope)->where('grant_id', $g->id)->count() >= $g->snapshot['max_uses'] ||
                (clone $customerScope)->count() + (clone $customerPromises)->count() >= $g->snapshot['max_per_customer']) {
                $this->fail('capacity');
            }
            $lines = $i->items()->orderBy('id')->get()->map(fn ($item) => [
                'id' => $item->id, 'line_total' => $item->line_total, 'discount' => $item->discount,
                'tax_rate_bp' => $item->tax_rate_bp, 'tax_amount' => $item->tax_amount,
                'context' => $item->marketing_context ?: [],
            ])->all();
            if ((int) $i->discount !== array_sum(array_column($lines, 'discount'))) {
                $this->fail('stacking');
            }
            $operations = array_values(array_unique(array_filter(array_map(fn ($l) => $l['context']['operation'] ?? null, $lines))));
            if (count($operations) !== 1) {
                $this->fail('scope');
            }
            $quote = app(DiscountCalculator::class)->calculate($g->snapshot['rule'], $lines,
                $i->currency_code, $customer->locale ?: 'fa', $operations[0]);
            $customerSpent = (int) (clone $customerScope)->sum('amount') + (clone $customerPromises)->sum('liability');
            if ($customerSpent + $quote['amount'] > $g->snapshot['rule']['per_customer_cap']) {
                $this->fail('capacity');
            }
            if (! $g->budget_held && $c->held + $c->spent + $quote['amount'] > $c->budget) {
                $this->fail('budget');
            }
            $original = ['subtotal' => $i->subtotal, 'discount' => $i->discount, 'tax' => $i->tax, 'total' => $i->total,
                'discount_note' => $i->discount_note, 'lines' => $lines];
            foreach ($quote['allocations'] as $allocation) {
                $i->items()->whereKey($allocation['id'])->update([
                    'discount' => $allocation['discount'], 'tax_amount' => $allocation['tax_amount']]);
            }
            $i->unsetRelation('items');
            $i->recalculateTotals();
            $i->discount_note = 'Campaign #'.$c->id.' / v'.$g->snapshot['version'];
            $i->save();
            $expires = now()->addMinutes(config('marketing.reservation_minutes', 30));
            if ($g->expires_at->lessThan($expires)) {
                $expires = $g->expires_at->copy();
            }
            if (! $g->budget_held) {
                $c->increment('held', $quote['amount']);
            }
            $data = ['campaign_id' => $c->id, 'grant_id' => $g->id, 'customer_id' => $customer->id,
                'invoice_id' => $i->id, 'status' => 'reserved', 'amount' => $quote['amount'],
                'tax_reduction' => $original['tax'] - $i->tax, 'expires_at' => $expires,
                'snapshot' => ['grant' => $g->snapshot, 'original' => $original, 'quote' => $quote,
                    'discounted' => $this->fingerprint($i)]];
            $redemption = $existing ? tap($existing)->update($data) : MarketingRedemption::create($data);
            DB::table('marketing_events')->insertOrIgnore(['campaign_id' => $c->id, 'grant_id' => $g->id,
                'invoice_id' => $i->id, 'event_key' => hash('sha256', 'checkout:'.$i->id),
                'kind' => 'checkout', 'created_at' => now()]);

            return $redemption;
        }, 5);
    }

    private function fingerprint(Invoice $invoice): string
    {
        return hash('sha256', json_encode([$invoice->subtotal, $invoice->discount, $invoice->tax, $invoice->total,
            $invoice->items()->orderBy('id')->get(['id', 'line_total', 'discount', 'tax_rate_bp', 'tax_amount'])->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    private function unresolvedPayments(Invoice $invoice)
    {
        return $invoice->payments()->whereIn('status', ['pending', 'redirected', 'paid'])
            ->whereNotIn('id', DB::table('marketing_payment_reconciliations')->select('payment_id'));
    }

    /** Manual financial review never credits money or invents a gateway outcome. */
    public function releaseAfterReview(MarketingRedemption $redemption): void
    {
        DB::transaction(function () use ($redemption) {
            $i = Invoice::whereKey($redemption->invoice_id)->lockForUpdate()->firstOrFail();
            $campaign = MarketingCampaign::whereKey($redemption->campaign_id)->lockForUpdate()->firstOrFail();
            $grant = MarketingGrant::whereKey($redemption->grant_id)->lockForUpdate()->firstOrFail();
            $r = MarketingRedemption::whereKey($redemption->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'reconciliation' || $i->paid > 0 || $this->unresolvedPayments($i)->exists() ||
                $this->fingerprint($i) !== $r->snapshot['discounted']) {
                $this->fail('reconciliation');
            }
            $r->update(['status' => 'reserved', 'expires_at' => now()]);
            $this->sync($i);
            if ($grant->budget_held && ! $grant->expires_at->isFuture()) {
                $campaign->decrement('held', $grant->liability);
                $grant->update(['budget_held' => false]);
            }
        }, 5);
    }

    /** Called inside existing PaymentService transaction, after verified real money has been recorded. */
    public function lateSettlement(Invoice $invoice): bool
    {
        $r = MarketingRedemption::where('invoice_id', $invoice->id)->lockForUpdate()->first();
        if (! $r || in_array($r->status, ['released', 'committed', 'refunded'], true)) {
            return false;
        }
        if ($r->status === 'reconciliation' || ! $r->expires_at->isFuture() ||
            $r->snapshot['discounted'] !== $this->fingerprint($invoice)) {
            $r->update(['status' => 'reconciliation']);

            return true;
        }

        return false;
    }

    /** Reserved/reconciliation is still a budget commitment; a worker crash cannot release it. */
    public function sync(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $i = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $ref = MarketingRedemption::where('invoice_id', $i->id)->first();
            if (! $ref) {
                return;
            }
            $c = MarketingCampaign::whereKey($ref->campaign_id)->lockForUpdate()->firstOrFail();
            $g = MarketingGrant::whereKey($ref->grant_id)->lockForUpdate()->firstOrFail();
            $r = MarketingRedemption::whereKey($ref->id)->lockForUpdate()->firstOrFail();
            if ($i->status === 'refunded' && $r->status === 'committed') {
                // Consumed offers stay consumed. Refund is limited to Invoice.paid by existing billing.
                $r->update(['status' => 'refunded']);

                return;
            }
            if ($r->status !== 'reserved') {
                return;
            }
            if ($i->status === 'paid') {
                if ($this->fingerprint($i) !== $r->snapshot['discounted']) {
                    $r->update(['status' => 'reconciliation']);

                    return;
                }
                $held = $g->budget_held ? $g->liability : $r->amount;
                $c->forceFill(['held' => $c->held - $held, 'spent' => $c->spent + $r->amount])->save();
                $g->update(['budget_held' => false]);
                $r->update(['status' => 'committed', 'committed_at' => now()]);
                DB::table('marketing_deliveries')->where('grant_id', $g->id)->where('kind', 'reminder')
                    ->where('status', 'scheduled')->update(['status' => 'canceled', 'reason' => 'redeemed', 'updated_at' => now()]);

                return;
            }
            if ($r->expires_at->isFuture() && ! in_array($i->status, ['canceled', 'void'], true)) {
                return;
            }
            // Never guess the result of an in-flight payment or reverse a partial payment.
            if ($i->paid > 0 || $this->unresolvedPayments($i)->exists() ||
                $this->fingerprint($i) !== $r->snapshot['discounted']) {
                $r->update(['status' => 'reconciliation']);

                return;
            }
            foreach ($r->snapshot['original']['lines'] as $line) {
                $i->items()->whereKey($line['id'])->update(['discount' => $line['discount'], 'tax_amount' => $line['tax_amount']]);
            }
            $i->forceFill(collect($r->snapshot['original'])->only(['subtotal', 'discount', 'tax', 'total', 'discount_note'])->all())->saveQuietly();
            if (! $g->budget_held) {
                $c->decrement('held', $r->amount);
            }
            $r->update(['status' => 'released']);
        }, 5);
    }

    /** Protect wallet/bank/gateway starts as well as the coupon form. */
    public function assertPayable(Invoice $invoice): void
    {
        if (! Schema::hasTable('marketing_redemptions')) {
            return;
        }
        $this->sync($invoice);
        $r = MarketingRedemption::where('invoice_id', $invoice->id)->first();
        if ($r && in_array($r->status, ['reserved', 'reconciliation'], true) &&
            ($r->status === 'reconciliation' || ! $r->expires_at->isFuture())) {
            $this->fail('reconciliation');
        }
        $invoice->refresh();
    }

    public function expireGrants(): void
    {
        MarketingGrant::where('budget_held', true)->where(fn ($q) => $q->where('expires_at', '<=', now())->orWhereNotNull('revoked_at'))
            ->orderBy('id')->chunkById(100, function ($grants) {
                foreach ($grants as $grant) {
                    DB::transaction(function () use ($grant) {
                        $c = MarketingCampaign::whereKey($grant->campaign_id)->lockForUpdate()->firstOrFail();
                        $g = MarketingGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
                        if (! $g->budget_held || MarketingRedemption::where('grant_id', $g->id)
                            ->whereIn('status', ['reserved', 'reconciliation'])->exists()) {
                            return;
                        }
                        $c->decrement('held', $g->liability);
                        $g->update(['budget_held' => false]);
                    }, 5);
                }
            });
    }
}
