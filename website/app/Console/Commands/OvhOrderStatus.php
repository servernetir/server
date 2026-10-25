<?php

namespace App\Console\Commands;

use App\Models\{CloudInstance, CreditEntry, Invoice, OvhOrder, Service};
use App\Services\Cloud\{OvhClient, OvhOrders};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class OvhOrderStatus extends Command
{
    protected $signature = 'ovh:orders {service? : Local service ID} {--audit-existing : Read existing service/payment/refund evidence only} {--reconcile : Read OVH and update only the local journal} {--dry-run : Prepare a non-payable cart and quote; never checkout/pay}';
    protected $description = 'Read OVH order evidence, reconcile an existing order or prepare a non-payable quote. Never enables purchase.';

    public function handle(OvhClient $client, OvhOrders $orders): int
    {
        if ($this->option('dry-run') && $this->option('reconcile')) { $this->error('Select one operation.'); return self::INVALID; }
        $id = $this->argument('service');
        if ($this->option('audit-existing')) {
            $q = Service::whereHas('cloudPlan', fn ($q) => $q->where('provider', 'ovh'));
            if ($id !== null) { $q->whereKey((int) $id); }
            foreach ($q->orderBy('id')->limit(100)->get() as $s) {
                $invoices = Invoice::where('service_id', $s->id)->get(['id', 'currency_code', 'status', 'total', 'paid']);
                $instance = CloudInstance::where('service_id', $s->id)->first();
                $refunds = CreditEntry::where('source_type', Invoice::class)->whereIn('source_id', $invoices->pluck('id'))
                    ->where('amount', '>', 0)->whereIn('reason', ['refund', 'undelivered_refund'])->get(['id', 'currency_code', 'amount', 'reason', 'source_id']);
                $this->line(json_encode(['service_id' => $s->id, 'status' => $s->status, 'provision_status' => $s->provision_status,
                    'external_ref' => $instance?->provider_ref, 'invoices' => $invoices->toArray(), 'refunds' => $refunds->toArray()], JSON_UNESCAPED_UNICODE));
            }
            $this->warn('Local evidence only. No absence of a supplier order or actual refund is inferred.');
            return self::SUCCESS;
        }
        if (! Schema::hasTable('ovh_orders')) { $this->error('OVH journal migration is required.'); return self::FAILURE; }
        if ($this->option('dry-run') && $id === null) { $this->error('A service ID is required for dry-run.'); return self::INVALID; }
        if ($this->option('dry-run')) {
            $r = $orders->provision($client, ['service_id' => (int) $id], true);
            $this->line(json_encode(['journal_id' => $r['journal_id'] ?? null, 'code' => $r['code'] ?? null,
                'message' => $r['message'] ?? '', 'monetary_calls_sent' => false], JSON_UNESCAPED_UNICODE));
            return ($r['manual'] ?? false) ? self::FAILURE : self::SUCCESS;
        }
        $rows = OvhOrder::query()->when($id !== null, fn ($q) => $q->where('service_id', (int) $id))->orderBy('id')->limit(40)->get();
        foreach ($rows as $order) {
            if ($this->option('reconcile')) { $orders->provision($client, ['service_id' => $order->service_id], false, true); $order->refresh(); }
            $this->line(json_encode($order->only(['id', 'service_id', 'phase', 'order_id', 'provider_status', 'financial_state', 'attempts', 'next_attempt_at', 'error_code']), JSON_UNESCAPED_UNICODE));
        }
        return self::SUCCESS;
    }
}
