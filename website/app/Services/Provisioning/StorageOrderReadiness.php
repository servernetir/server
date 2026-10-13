<?php

namespace App\Services\Provisioning;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Validation\ValidationException;

/** Guard starting a first payment; never rejects a confirmed payment settlement. */
class StorageOrderReadiness
{
    public function assertInvoice(Invoice $invoice): void
    {
        if ($invoice->status === 'paid' || ! $invoice->service_id || $invoice->kind !== 'service') {
            return;
        }
        $service = Service::find($invoice->service_id);
        if (! $service || (! str_starts_with((string) $service->plan, 'sn_storage_box_')
            && ! str_starts_with((string) $service->plan, 'sn_object_'))) {
            return;
        }
        // Renewal of a delivered service keeps its existing billing/lifecycle.
        if ($service->provision_status === 'done' || $service->provisioned_at !== null
            || $service->activated_at !== null) {
            return;
        }
        $products = Product::query()->with('server')->where('plan', $service->plan)
            ->where('server_id', $service->server_id)->limit(2)->get();
        $product = $products->count() === 1 ? $products->first() : null;
        if (! $product || ! $product->isStorageCatalogProduct() || ! $product->is_active
            || $product->storageConfigurationError() !== null || $product->availableCountries() === []
            || ! in_array($service->cycle, $product->orderCycles(), true)
            || $service->currency_code !== 'IRT' || $invoice->currency_code !== 'IRT'
            || (int) $service->customer_id !== (int) $invoice->customer_id) {
            throw ValidationException::withMessages(['storage' => 'تحویل این سفارش فضای ذخیره‌سازی فعلاً آماده نیست؛ پرداخت آغاز نشد.']);
        }

        if (str_starts_with((string) $service->plan, 'sn_storage_box_')) {
            $sold = $service->provision_meta['storage_box_offer'] ?? null;
            $current = app(HetznerStorageCosts::class)->metadataForPlan((string) $service->plan);
            $matches = is_array($sold) && is_array($current);
            foreach (['type', 'location', 'size', 'server_id', 'currency'] as $field) {
                if (! isset($sold[$field], $current[$field]) || (string) $sold[$field] !== (string) $current[$field]) {
                    $matches = false;
                }
            }
            foreach (['country', 'subaccounts_limit', 'snapshot_limit', 'automatic_snapshot_limit'] as $field) {
                if (array_key_exists($field, (array) $sold) && ($sold[$field] ?? null) !== ($current[$field] ?? null)) {
                    $matches = false;
                }
            }
            foreach (['net_monthly_cents', 'net_setup_cents'] as $field) {
                if (! isset($sold[$field], $current[$field]) || (int) $current[$field] > (int) $sold[$field]) {
                    $matches = false;
                }
            }
            if (! $matches) {
                throw ValidationException::withMessages(['storage' => 'مشخصات یا بهای عرضه با سفارش ثبت‌شده مطابقت ندارد؛ پرداخت آغاز نشد.']);
            }
        }

        $quote = $product->storageQuote();
        if (! $quote || ($quote['irt'] ?? 0) <= 0
            || (int) $service->price < (int) $quote['irt']
            || (int) $invoice->subtotal < (int) $quote['irt'] + (int) ($quote['setup_irt'] ?? 0)) {
            throw ValidationException::withMessages(['storage' => 'تعرفه این سفارش تغییر کرده است؛ پیش از پرداخت، پیش‌فاکتور تازه دریافت کنید.']);
        }
    }
}
