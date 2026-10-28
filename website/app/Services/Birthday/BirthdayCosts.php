<?php

namespace App\Services\Birthday;

use App\Models\BirthdayProductCost;
use App\Models\CloudPlan;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\ExchangeRate;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Schema;

class BirthdayCosts
{
    /** Every result is a landed supplier cost for this exact line/cycle, never a reverse markup guess. */
    public function forLine(Invoice $invoice, InvoiceItem $item): ?array
    {
        if ($item->quantity < 1 || $item->quantity > 1000) {
            return null;
        }
        if ($invoice->domain_id) {
            $domain = app(\App\Services\Domain\LiveDomainPricing::class)->birthdayCost($invoice, $item);
            if ($domain !== null) {
                return $domain;
            }
        }
        $service = $invoice->service_id ? Service::find($invoice->service_id) : null;
        if (! $service || (int) $service->customer_id !== (int) $invoice->customer_id || $service->billing_mode === 'hourly') {
            return null;
        }
        $ctx = $item->marketing_context ?: [];
        if (isset($ctx['billing_cycle']) && $ctx['billing_cycle'] !== $service->cycle) {
            return null;
        }
        if ($service->cloud_plan_id && isset($ctx['cloud_plan_id'])
            && (int) $ctx['cloud_plan_id'] !== (int) $service->cloud_plan_id) {
            return null;
        }
        $component = $ctx['birthday_component'] ?? 'service';
        $primary = ($ctx['eligible'] ?? false) && in_array($ctx['operation'] ?? null, ['purchase', 'renewal'], true);
        if (! $primary && $component !== 'setup') {
            return null;
        }
        if ($service->cloud_plan_id && $primary) {
            $plan = CloudPlan::find($service->cloud_plan_id);
            $months = Service::monthsIn((string) $service->cycle);
            if (! $plan || $months < 1 || $months > 36 || $item->quantity !== 1
                || array_filter((array) $service->cloud_addons)
                || ! app(CloudPricingSnapshot::class)->current((string) $plan->provider)
                || (int) $plan->cost_eur_cents < 1) {
                return null;
            }
            $amount = $this->convert((int) $plan->cost_eur_cents * $months, 'EUR', $invoice->currency_code);
            return $amount ? ['amount' => $amount, 'currency' => $invoice->currency_code, 'source' => 'cloud_plan_landed_cost',
                'cloud_plan_id' => $plan->id, 'cycle' => $service->cycle, 'as_of' => now()->toIso8601String()] : null;
        }
        $id = (int) ($ctx['product_id'] ?? $ctx['birthday_product_id'] ?? 0);
        $product = $id > 0 ? Product::find($id) : null;
        if (! $product || $product->plan !== $service->plan
            || ! in_array($component, ['service', 'setup'], true) || ! Schema::hasTable('birthday_product_costs')) {
            return null;
        }
        return $this->forProduct($product, (string) $service->cycle, $service->server_id,
            $invoice->currency_code, $component, $item->quantity);
    }

    public function forProduct(Product $product, string $cycle, ?int $serverId, string $currency, string $component = 'service', int $quantity = 1): ?array
    {
        if (! Schema::hasTable('birthday_product_costs') || $quantity < 1 || $quantity > 1000) return null;
        $cost = BirthdayProductCost::where('product_id', $product->id)->where('cycle', $cycle)
            ->where('component', $component)->where('server_id', $serverId)->where('valid_until', '>', now())
            ->where('verified_at', '<=', now())->first();
        if (! $cost || $cost->amount < 1 || blank($cost->reference)) return null;
        $amount = $this->convert((int) $cost->amount * $quantity, $cost->currency_code, $currency);
        return $amount ? ['amount' => $amount, 'currency' => $currency, 'source' => 'verified_product_cost',
            'cost_id' => $cost->id, 'product_id' => $product->id, 'cycle' => $cycle, 'component' => $component,
            'native_amount' => (int) $cost->amount * $quantity, 'native_currency' => $cost->currency_code,
            'reference' => $cost->reference, 'verified_by' => $cost->verified_by,
            'valid_until' => $cost->valid_until->toIso8601String(), 'quoted_at' => now()->toIso8601String(),
            'as_of' => $cost->verified_at->toIso8601String()] : null;
    }

    /** IRT is whole toman; USD/EUR/TRY are cents. Convert once, upwards, using valid stored FX. */
    public function convert(int $amount, string $from, string $to): ?int
    {
        $scales = ['IRT' => 1, 'USD' => 100, 'EUR' => 100, 'TRY' => 100];
        if ($amount < 1 || $amount > 1000000000000 || ! isset($scales[$from], $scales[$to])) {
            return null;
        }
        if ($from === $to) {
            return $amount;
        }
        $source = $from === 'IRT' ? 1 : (app(ExchangeRate::class)->current($from)['rate_toman'] ?? null);
        $target = $to === 'IRT' ? 1 : (app(ExchangeRate::class)->current($to)['rate_toman'] ?? null);
        if (! is_numeric($source) || ! is_numeric($target) || $source < 1 || $target < 1
            || $source > 5000000 || $target > 5000000) {
            return null;
        }
        $value = BigInteger::of($amount)->multipliedBy((int) $source)->multipliedBy($scales[$to])
            ->dividedBy(BigInteger::of($scales[$from])->multipliedBy((int) $target), RoundingMode::Up)->toInt();

        return $value > 0 && $value <= 1000000000000 ? $value : null;
    }
}
