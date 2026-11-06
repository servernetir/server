<?php

namespace App\Services\Birthday;

use App\Models\BirthdayRedemption;
use App\Models\BirthdayReward;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BirthdayInvoices
{
    public function redemption(Invoice $invoice): ?BirthdayRedemption
    {
        return Schema::hasTable('birthday_redemptions')
            ? BirthdayRedemption::where('invoice_id', $invoice->id)->first() : null;
    }

    public function hasDiscount(Invoice $invoice): bool
    {
        $r = $this->redemption($invoice);
        return $r && in_array($r->status, ['applied', 'locked', 'committed', 'reconciliation'], true);
    }

    public function fingerprint(Invoice $invoice): string
    {
        return hash('sha256', json_encode([(int) $invoice->id, $invoice->currency_code, (int) $invoice->customer_id,
            $invoice->subtotal, $invoice->discount, $invoice->tax, $invoice->total,
            $invoice->items()->orderBy('id')->get(['id', 'line_total', 'discount', 'tax_rate_bp', 'tax_amount'])->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    public function token(Invoice $invoice): string
    {
        return hash_hmac('sha256', $this->fingerprint($invoice), (string) config('app.key'));
    }

    public function assertPresented(Invoice $invoice, string $token): void
    {
        if ($this->redemption($invoice) && ($token === '' || ! hash_equals($this->token($invoice), $token))) {
            throw ValidationException::withMessages(['birthday' => __('birthday.price_changed')]);
        }
    }

    private function inFlight(Invoice $invoice): bool
    {
        return app(\App\Services\Domain\LiveDomainPricing::class)->hasPaymentInFlight($invoice);
    }

    /** Expiration never reverses a payment or changes an amount already accepted by a gateway. */
    public function synchronize(Invoice $invoice): void
    {
        if (! Schema::hasTable('birthday_redemptions')) {
            return;
        }
        DB::transaction(function () use ($invoice) {
            $i = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $r = BirthdayRedemption::where('invoice_id', $i->id)->lockForUpdate()->first();
            if (! $r || in_array($r->status, ['released', 'committed'], true)) {
                return;
            }
            if ($this->fingerprint($i) !== $r->snapshot['discounted']) {
                $r->update(['status' => 'reconciliation']);
                return;
            }
            if ($i->status === 'paid') {
                $r->update(['status' => 'committed', 'committed_at' => now()]);
                return;
            }
            $reward = BirthdayReward::find($r->birthday_reward_id);
            $expired = ! $reward || $reward->revoked_at || ! $reward->expires_at->isFuture()
                || ! app(BirthdayProgram::class)->enabled();
            if (! $expired && ! in_array($i->status, ['canceled', 'void'], true)) {
                return;
            }
            if ($i->paid > 0 || $this->inFlight($i)) {
                $r->update(['status' => 'locked']);
                return;
            }
            foreach ($r->snapshot['original']['lines'] as $line) {
                $i->items()->whereKey($line['id'])->update(['discount' => $line['discount'], 'tax_amount' => $line['tax_amount']]);
            }
            $i->forceFill(collect($r->snapshot['original'])->only(['subtotal', 'discount', 'tax', 'total', 'discount_note'])->all())->saveQuietly();
            $r->update(['status' => 'released']);
        }, 5);
        $invoice->refresh();
    }

    private function costsMatch(Invoice $invoice, BirthdayRedemption $redemption): bool
    {
        foreach ($redemption->snapshot['costs'] as $id => $oldCost) {
            $line = $invoice->items()->find($id);
            if (! $line) {
                return false;
            }
            try {
                $cost = app(BirthdayCosts::class)->forLine($invoice, $line);
            } catch (ValidationException) {
                return false;
            }
            if (! $cost || $cost['currency'] !== $invoice->currency_code || $cost['amount'] !== $line->netTotal()) {
                return false;
            }
        }
        return true;
    }

    private function restoreForReview(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $i = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $r = BirthdayRedemption::where('invoice_id', $i->id)->lockForUpdate()->firstOrFail();
            if ($i->paid > 0 || $this->inFlight($i) || $this->fingerprint($i) !== $r->snapshot['discounted']) {
                throw ValidationException::withMessages(['birthday' => __('birthday.review_required')]);
            }
            foreach ($r->snapshot['original']['lines'] as $line) {
                $i->items()->whereKey($line['id'])->update(['discount' => $line['discount'], 'tax_amount' => $line['tax_amount']]);
            }
            $i->forceFill(collect($r->snapshot['original'])->only(['subtotal', 'discount', 'tax', 'total', 'discount_note'])->all())->saveQuietly();
            $r->update(['status' => 'released']);
        }, 5);
        $invoice->refresh();
    }

    /** Review can present a new quote; a POST can never silently increase the accepted amount. */
    public function prepare(Invoice $invoice, bool $review = false): void
    {
        if (! Schema::hasTable('birthday_redemptions')) {
            return;
        }
        $before = $this->fingerprint($invoice);
        $this->synchronize($invoice);
        if (! $review && $before !== $this->fingerprint($invoice)) {
            throw ValidationException::withMessages(['birthday' => __('birthday.price_changed')]);
        }
        if ($existing = $this->redemption($invoice)) {
            if ($existing->status === 'committed' || $invoice->status === 'paid') {
                return;
            }
            if ($existing->status === 'locked' && ! $existing->expires_at->isFuture()) {
                throw ValidationException::withMessages(['birthday' => __('birthday.review_required')]);
            }
            if ($existing->status !== 'released') {
                $matches = $this->fingerprint($invoice) === $existing->snapshot['discounted'];
                if (! $matches || $this->inFlight($invoice) && $existing->status === 'reconciliation') {
                    throw ValidationException::withMessages(['birthday' => __('birthday.review_required')]);
                }
                if ($existing->status === 'reconciliation' || ! $this->costsMatch($invoice, $existing)) {
                    if (! $review) {
                        $existing->update(['status' => 'reconciliation']);
                        throw ValidationException::withMessages(['birthday' => __('birthday.price_changed')]);
                    }
                    $this->restoreForReview($invoice);
                    $existing = $this->redemption($invoice);
                } else {
                    return;
                }
            }
        }
        $program = app(BirthdayProgram::class);
        if (! $program->enabled()) {
            return;
        }
        $customer = Customer::find($invoice->customer_id);
        $reward = $customer ? $program->current($customer) : null;
        if (! $reward || ! $invoice->issued_at || $invoice->issued_at->lt($reward->starts_at)) {
            return;
        }
        DB::transaction(function () use ($invoice, $reward, $customer, $existing) {
            $i = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $benefit = BirthdayReward::whereKey($reward->id)->lockForUpdate()->firstOrFail();
            if ($benefit->revoked_at || ! $benefit->expires_at->isFuture() || ! $customer->fresh()->isActive()
                || ! in_array($i->kind, ['service', 'domain'], true) || ! in_array($i->status, ['unpaid', 'draft'], true)
                || $i->paid > 0 || $i->discount > 0 || $this->inFlight($i)
                || (Schema::hasTable('marketing_redemptions') && DB::table('marketing_redemptions')->where('invoice_id', $i->id)
                    ->where('status', '!=', 'released')->exists())) {
                return;
            }
            $current = BirthdayRedemption::where('invoice_id', $i->id)->lockForUpdate()->first();
            if ($current && $current->status !== 'released') {
                return;
            }
            $items = $i->items()->orderBy('id')->get();
            if ($items->isEmpty() || $items->sum('line_total') !== $i->subtotal || $items->sum('tax_amount') !== $i->tax
                || $items->sum('discount') !== 0) {
                return;
            }
            $original = ['subtotal' => $i->subtotal, 'discount' => $i->discount, 'tax' => $i->tax,
                'total' => $i->total, 'discount_note' => $i->discount_note,
                'lines' => $items->map(fn ($line) => $line->only(['id', 'discount', 'tax_amount']))->all()];
            $costs = []; $allocations = []; $amount = 0;
            foreach ($items as $item) {
                if ($item->line_total < 1 || ($item->tax_rate_bp === 0 && $item->tax_amount > 0)) {
                    continue;
                }
                $expectedTax = intdiv($item->line_total * $item->tax_rate_bp + 5000, 10000);
                if ($expectedTax !== $item->tax_amount) {
                    continue;
                }
                $cost = app(BirthdayCosts::class)->forLine($i, $item);
                if (! $cost || $cost['amount'] > $item->line_total || $cost['currency'] !== $i->currency_code) {
                    continue; // No fabricated wholesale price for a setup, add-on or unknown product.
                }
                $quote = app(BirthdayCalculator::class)->line($item->line_total, $cost['amount'], $item->tax_rate_bp);
                if ($quote['discount'] < 1) {
                    continue;
                }
                $amount += $quote['discount'];
                $allocations[] = ['id' => $item->id, 'discount' => $quote['discount'], 'tax_amount' => $quote['tax']];
                $costs[(string) $item->id] = $cost;
            }
            if ($amount < 1) {
                return;
            }
            foreach ($allocations as $row) {
                $i->items()->whereKey($row['id'])->update(['discount' => $row['discount'], 'tax_amount' => $row['tax_amount']]);
            }
            $i->unsetRelation('items');
            $i->recalculateTotals();
            $i->discount_note = __('birthday.invoice_note');
            $i->saveQuietly();
            $data = ['birthday_reward_id' => $benefit->id, 'customer_id' => $customer->id,
                'invoice_id' => $i->id, 'currency_code' => $i->currency_code, 'status' => 'applied',
                'amount' => $amount, 'expires_at' => $benefit->expires_at,
                'snapshot' => ['policy' => 'landed_cost_v1', 'original' => $original, 'costs' => $costs,
                    'discounted' => $this->fingerprint($i)]];
            $current ? $current->update($data) : BirthdayRedemption::create($data);
        }, 5);
        $invoice->refresh();
    }

    /** Existing registrar anti-loss guards only waive markup for this recorded, exact domain line. */
    public function domainFloor(Invoice $invoice, Domain $domain, InvoiceItem $item, int $wholesale): ?int
    {
        $r = $this->redemption($invoice);
        if (! $r || (int) $r->customer_id !== (int) $domain->customer_id
            || ! in_array($r->status, ['applied', 'locked', 'committed'], true)
            || $this->fingerprint($invoice) !== $r->snapshot['discounted']
            || (int) data_get($r->snapshot, 'costs.'.$item->id.'.domain_id') !== (int) $domain->id
            || (int) data_get($r->snapshot, 'costs.'.$item->id.'.amount') < $wholesale
            || $item->netTotal() < $wholesale) {
            return null;
        }
        if ($invoice->status !== 'paid' && ! $r->expires_at->isFuture() && ! $this->inFlight($invoice)) {
            return null;
        }

        return $wholesale;
    }
}
