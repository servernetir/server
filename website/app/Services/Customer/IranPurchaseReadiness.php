<?php

namespace App\Services\Customer;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Validation\ValidationException;

/** New Iranian delivery only; confirmed funds and delivered-service renewals stay intact. */
class IranPurchaseReadiness
{
    public function assertInvoice(Invoice $invoice): void
    {
        if ($invoice->status === 'paid' || $invoice->kind !== 'service' || ! $invoice->service_id) {
            return;
        }
        $service = Service::find($invoice->service_id);
        if ($service && ! $this->hasExistingDelivery($service) && $this->targetsIran($service)
            && IranSalesGate::blocksIranFor(Customer::find($invoice->customer_id))) {
            throw ValidationException::withMessages(['iran' => IranSalesGate::message()]);
        }
    }

    /** A placeholder instance and activated_at set by first payment are not delivery. */
    public function hasExistingDelivery(Service $service): bool
    {
        if ($service->provision_status === 'done' || $service->provisioned_at !== null
            || CloudInstance::where('service_id', $service->id)->whereNotNull('provider_ref')
                ->where('provider_ref', '!=', '')->where('status', '!=', 'deleted')->exists()) return true;

        // Pre-provisioning/imported hosting can lack the later delivery columns.
        // A settled earlier service invoice plus real credentials and an active
        // lifecycle is evidence of ownership; first payment awaiting delivery is not.
        return $this->isLegacyDeliveredHosting($service);
    }

    public function isLegacyDeliveredHosting(Service $service, ?int $exceptInvoiceId = null): bool
    {
        return $service->server_id !== null && in_array($service->status, ['active', 'suspended'], true)
            && in_array($service->provision_status, [null, '', 'none'], true)
            && $service->activated_at !== null && filled($service->username)
            && Invoice::where('service_id', $service->id)->where('kind', 'service')->where('status', 'paid')
                ->when($exceptInvoiceId, fn ($query) => $query->where('id', '!=', $exceptInvoiceId))->exists();
    }

    public function targetsIran(Service $service, ?CloudPlan $selectedPlan = null): bool
    {
        $known = false;
        $country = $service->server()->value('country');
        if (filled($country)) {
            $known = true;
            if (IranSalesGate::isIranTarget($country)) return true;
        }
        $plan = $selectedPlan ?? ($service->cloud_plan_id ? CloudPlan::find($service->cloud_plan_id) : null);
        $codes = array_filter([$plan?->location_code, $service->provision_meta['location'] ?? null], 'is_string');
        foreach ($codes as $code) {
            if ($code === '') continue;
            $known = true;
            if (IranSalesGate::isIranTarget($code)
                || IranSalesGate::isIranTarget(CloudLocation::where('code', $code)->value('country'))) return true;
        }
        if ($known || blank($service->plan)) return false;

        // Older manual orders may only retain the product plan. Use a unique
        // match and explicit geographic metadata; never guess from names or language.
        $products = Product::where('plan', $service->plan)->limit(2)->get();
        if ($products->count() !== 1) return false;
        $product = $products->first();

        $declared = collect((array) $product->locations)->filter(fn ($target) => filled($target));

        return IranSalesGate::isIranTarget($product->server?->country)
            || ($declared->isNotEmpty() && $declared->every(fn ($target) => IranSalesGate::isIranTarget($target)));
    }

    /** Rechecked on every attempt, before a new provider mutation; never undoes payment. */
    public function holdDeliveryIfBlocked(Service $service, ?CloudPlan $selectedPlan = null): bool
    {
        if ($this->hasExistingDelivery($service) || ! $this->targetsIran($service, $selectedPlan)
            || ! IranSalesGate::blocksIranFor($service->customer()->first())) return false;

        $service->forceFill(['status' => 'awaiting_provision', 'provision_status' => 'manual',
            'provision_error' => IranSalesGate::message()])->save();

        return true;
    }
}
