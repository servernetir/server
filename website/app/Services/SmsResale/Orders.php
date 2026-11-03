<?php

namespace App\Services\SmsResale;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\SmsSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Orders
{
    public function __construct(private Controls $controls) {}

    public function place(Customer $customer, string $planKey, array $identity): SmsSubscription
    {
        if (! $this->controls->sellable($planKey)) { throw ValidationException::withMessages(['plan' => __('sms.unavailable')]); }
        $plan = $this->controls->plan($planKey);
        return DB::transaction(function () use ($customer, $plan, $identity) {
            // Customer row exists even for the first order: serializes concurrent creates.
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $existing = SmsSubscription::where('customer_id', $customer->id)->first();
            if ($existing) { return $existing; }
            $free = $plan['first_irt'] === 0;
            $service = Service::create(['customer_id' => $customer->id, 'name' => 'ServerNet SMS — '.$plan['key'],
                'currency_code' => 'IRT', 'price' => $plan['renew_irt'], 'tax_percent' => (int) $this->controls->get('tax_percent', '0'),
                'cycle' => $free ? 'once' : 'yearly', 'plan' => 'sms:'.$plan['key'],
                'status' => $free ? 'awaiting_provision' : 'pending', 'provision_status' => $free ? 'pending' : null,
                'next_due_at' => $free ? now()->addYear() : null, 'provision_meta' => ['sms_resale' => 1]]);
            $operation = (string) Str::uuid();
            $subscription = SmsSubscription::create(['customer_id' => $customer->id, 'service_id' => $service->id,
                'operation_id' => $operation, 'plan' => $plan['key'], 'quote' => $this->quote($plan), 'username' => 'sn-sms-'.$service->id,
                'identity' => $identity, 'initial_password' => 'Sn!'.Str::random(28).'9a', 'consented_at' => now(),
                'state' => $free ? 'pending' : 'unpaid']);
            if (! $free) {
                $invoice = $this->invoice($customer->id, $service->id, $plan['first_irt'], $service->tax_percent, __('sms.license').' — '.$plan['key']);
                $subscription->update(['pending_invoice_id' => $invoice->id]);
            }
            return $subscription;
        });
    }

    public function upgrade(SmsSubscription $subscription, string $planKey): SmsSubscription
    {
        if (! $this->controls->sellable($planKey)) { throw ValidationException::withMessages(['plan' => __('sms.unavailable')]); }
        return DB::transaction(function () use ($subscription, $planKey) {
            $s = SmsSubscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $service = $s->service()->lockForUpdate()->firstOrFail();
            if ($s->pending_invoice_id || $s->state !== 'active' || $service->status !== 'active'
                || ! $service->next_due_at?->isFuture()) {
                throw ValidationException::withMessages(['plan' => __('sms.pending')]);
            }
            $old = $this->controls->plan($s->plan);
            $new = $this->controls->plan($planKey);
            if ($new['first_irt'] <= $old['first_irt']) { throw ValidationException::withMessages(['plan' => __('sms.upgrade_only')]); }
            $end = $service->next_due_at;
            $start = $end->copy()->subYear();
            $remaining = max(0, $end->getTimestamp() - now()->getTimestamp());
            $period = max(1, $end->getTimestamp() - $start->getTimestamp());
            $amount = intdiv(($new['first_irt'] - $old['first_irt']) * $remaining + $period - 1, $period);
            // No service_id: generic payment must not extend the annual period for an upgrade.
            $invoice = $this->invoice($s->customer_id, null, $amount, $service->tax_percent, __('sms.upgrade').' — '.$planKey);
            $s->update(['pending_plan' => $planKey, 'pending_quote' => $this->quote($new), 'pending_invoice_id' => $invoice->id]);
            return $s->refresh();
        });
    }

    private function quote(array $plan): array
    {
        return ['plan' => $plan, 'parent_id' => (int) $this->controls->get('parent_id'),
            'cost_irr' => (int) $this->controls->get('cost_irr'), 'verified_at' => $this->controls->get('verified_at')];
    }

    private function invoice(int $customer, ?int $service, int $amount, int $taxPercent, string $title): Invoice
    {
        $tax = intdiv($amount * $taxPercent + 50, 100);
        $invoice = Invoice::create(['customer_id' => $customer, 'service_id' => $service, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => $amount, 'tax' => $tax, 'total' => $amount + $tax,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(), 'due_at' => now()->addDay(),
            'note' => $title.' — '.__('sms.credit_separate')]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'title' => $title, 'quantity' => 1,
            'unit_price' => $amount, 'line_total' => $amount, 'tax_rate_bp' => $taxPercent * 100, 'tax_amount' => $tax]);
        return $invoice;
    }
}
