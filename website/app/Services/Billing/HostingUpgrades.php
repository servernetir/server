<?php

namespace App\Services\Billing;

use App\Models\HostingUpgrade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** A separate invoice purpose: paying an upgrade must NEVER renew the service. */
class HostingUpgrades
{
    public const KIND = 'hosting_upgrade';

    public function ready(): bool
    {
        return Schema::hasTable('hosting_upgrades');
    }

    public function eligible(Service $s): bool
    {
        return $this->ready() && $s->status === 'active' && $s->provision_status === 'done'
            && ! $s->is_reseller && ! $s->isCloud() && ! $s->isHourly()
            && ! $s->hasUnverifiedBillingTerms() && ! $s->isInternalInfrastructure()
            && in_array($s->server?->type, ['whm', 'plesk'], true) && $s->server->status === 'active'
            && filled($s->username) && filled($s->domain) && filled($s->plan)
            && ! in_array(strtolower((string) $s->username), ['root', 'admin', strtolower((string) $s->server->username)], true)
            && $s->activated_at !== null && RenewalTerms::serviceDeadline($s)?->isFuture()
            && Service::monthsIn((string) $s->cycle) > 0 && $s->currency_code === 'IRT' && $s->price > 0;
    }

    public function currentProduct(Service $s): ?Product
    {
        $rows = Product::where('plan', $s->plan)->get()->filter(fn ($p) => ! $p->isReseller() && in_array($p->category, ['shared', 'plesk'], true)
            && $p->hostingServerType() === $s->server?->type);

        return $rows->count() === 1 ? $rows->first() : null;
    }

    public function options(Service $s): array
    {
        if (! $this->eligible($s) || ! ($current = $this->currentProduct($s))) {
            return [];
        }

        return Product::where('group', $current->group)->where('is_active', true)
            ->where('sort', '>', $current->sort)->orderBy('sort')->get()
            ->filter(fn ($p) => $this->compatible($s, $current, $p)
                && $p->priceForCycle($s->cycle, $s->server->country) > $s->price)->values()->all();
    }

    private function compatible(Service $s, Product $current, Product $target): bool
    {
        $locations = array_map('strtoupper', (array) $target->locations);

        return $target->is_active && ! $target->isReseller() && $target->group === $current->group
            && $target->category === $current->category && $target->sort > $current->sort
            && filled($target->plan) && $target->plan !== $s->plan && $target->currency_code === $s->currency_code
            && $target->hostingServerType() === $s->server->type
            && (! $target->server_id || (int) $target->server_id === (int) $s->server_id)
            && ($locations === [] || in_array(strtoupper((string) $s->server->country), $locations, true));
    }

    public function fingerprint(Service $s): array
    {
        return ['customer_id' => (int) $s->customer_id, 'server_id' => (int) $s->server_id,
            'username' => $s->username, 'domain' => $s->domain, 'plan' => $s->plan, 'price' => (int) $s->price,
            'currency' => $s->currency_code, 'tax_percent' => (int) $s->tax_percent, 'cycle' => $s->cycle,
            'due' => $s->next_due_at?->toDateString(), 'activated_at' => $s->activated_at?->toIso8601String(),
            'plesk' => data_get($s->provision_meta, 'plesk')];
    }

    public function quote(Service $s, Product $target): array
    {
        $current = $this->currentProduct($s);
        if (! $this->eligible($s) || ! $current || ! $this->compatible($s, $current, $target)) {
            $this->invalid();
        }
        // Existing renewal/debt must be resolved first; it can otherwise change the paid period.
        if ($s->invoices()->outstanding()->exists()) {
            $this->invalid();
        }
        $dueDate = $s->next_due_at->copy()->startOfDay();
        $end = RenewalTerms::serviceDeadline($s);
        // Replay the same no-overflow calendar steps used by renewal. Subtracting
        // one month from Nov 30 cannot recover an Oct 31 first-cycle anchor.
        $start = $s->activated_at->copy();
        $cursor = $start->copy()->startOfDay();
        $matched = false;
        for ($step = 0; $step < 1200; $step++) {
            $next = $cursor->copy()->addMonthsNoOverflow(Service::monthsIn($s->cycle));
            if ($next->eq($dueDate)) {
                $matched = true;
                break;
            }
            if ($next->gt($dueDate)) {
                break;
            }
            $cursor = $next;
            $start = Carbon::parse($next->toDateString(), RenewalTerms::timezone())->endOfDay()->utc();
        }
        if (! $matched) {
            $this->invalid();
        }
        $at = now();
        if ($start->gt($at) || $end->lte($at)) {
            $this->invalid();
        }
        $seconds = $end->timestamp - $start->timestamp;
        $remaining = $end->timestamp - $at->timestamp;
        $newPrice = $target->priceForCycle($s->cycle, $s->server->country);
        if ($newPrice <= $s->price) {
            $this->invalid();
        }
        // Round each displayed component in stored currency units so the breakdown adds up.
        $prorate = fn (int $price) => BigInteger::of($price)->multipliedBy($remaining)
            ->dividedBy($seconds, RoundingMode::HalfUp)->toInt();
        $credit = $prorate((int) $s->price);
        $debit = $prorate($newPrice);
        $subtotal = $debit - $credit;
        if ($subtotal <= 0 || $s->tax_percent < 0 || $s->tax_percent > 100) {
            $this->invalid();
        }
        $tax = BigInteger::of($subtotal)->multipliedBy((int) $s->tax_percent)
            ->dividedBy(100, RoundingMode::HalfUp)->toInt();

        return ['version' => 1, 'from' => $this->fingerprint($s), 'product_id' => $target->id,
            'plan' => $target->plan, 'name' => $target->name, 'price' => $newPrice,
            'quoted_at' => $at->toIso8601String(), 'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(), 'seconds' => $seconds, 'remaining' => $remaining,
            'credit' => $credit, 'debit' => $debit, 'subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax];
    }

    public function open(Service $s): ?HostingUpgrade
    {
        return $this->ready() ? HostingUpgrade::where('open_service_id', $s->id)->with('invoice')->first() : null;
    }

    public function issue(Service $s, Product $target, array $preview, string $quoteHash): HostingUpgrade
    {
        return Cache::lock('hosting-upgrade:'.$s->id, 120)->block(3, function () use ($s, $target, $preview, $quoteHash) {
            return DB::transaction(function () use ($s, $target, $preview, $quoteHash) {
                $s = Service::whereKey($s->id)->lockForUpdate()->firstOrFail();
                if ($open = $this->open($s)) {
                    return $open;
                }
                $fresh = $this->quote($s, $target->fresh());
                if (! $preview || ! hash_equals($this->hash($preview), $quoteHash)
                    || Carbon::parse($preview['quoted_at'])->lt(now()->subMinutes(15))
                    || $preview['from'] !== $fresh['from'] || $preview['product_id'] !== $fresh['product_id']
                    || $preview['price'] !== $fresh['price'] || $preview['plan'] !== $fresh['plan']) {
                    $this->invalid('hu_changed');
                }
                $q = $preview;
                $invoice = Invoice::create(['customer_id' => $s->customer_id,
                    // Deliberately not a renewal invoice: lifecycle and applyPaid cannot extend/suspend this service.
                    'service_id' => null, 'kind' => self::KIND, 'currency_code' => $s->currency_code,
                    'subtotal' => $q['subtotal'], 'tax' => $q['tax'], 'total' => $q['total'], 'paid' => 0,
                    'status' => 'unpaid', 'issued_at' => now(),
                    'due_at' => now()->addHours(24)->min(RenewalTerms::serviceDeadline($s)),
                    'note' => __('ui.hu_invoice', ['from' => $s->name, 'to' => $target->name])]);
                InvoiceItem::create(['invoice_id' => $invoice->id, 'title' => mb_substr($invoice->note, 0, 190),
                    'description' => $s->domain.' · '.$q['quoted_at'].' → '.$q['period_end'],
                    'quantity' => 1, 'unit_price' => $q['subtotal'], 'line_total' => $q['subtotal'],
                    'tax_rate_bp' => $s->tax_percent * 100, 'tax_amount' => $q['tax']]);

                return HostingUpgrade::create(['service_id' => $s->id, 'invoice_id' => $invoice->id,
                    'open_service_id' => $s->id, 'quote' => $q, 'status' => 'awaiting_payment']);
            });
        });
    }

    public function hash(array $q): string
    {
        return hash_hmac('sha256', json_encode($q), (string) config('app.key'));
    }

    private function invalid(string $key = 'hu_unavailable'): never
    {
        throw ValidationException::withMessages(['upgrade' => __('ui.'.$key)]);
    }
}
