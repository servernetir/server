<?php

namespace App\Services\Cloud;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudInstance;
use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Finance\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Unpaid GPU intent. Capacity, approved price and wallet are rechecked before allocation. */
class GpuCapacityOrders
{
    /*
     * `services.provision_status` is VARCHAR(16) on MariaDB.  The old value
     * `gpu_capacity_wait` is 17 bytes and therefore every capacity miss failed
     * before the waiting intent could be persisted.  Keep this provider-only
     * state inside the shared column's published contract instead of widening
     * a hot, cross-provider column for one workflow.
     */
    public const WAIT = 'gpu_wait';

    public function __construct(private SaladClient $provider, private Wallet $wallet, private HourlyStartCredit $credit) {}

    public static function managed(Service $service): bool
    {
        return data_get($service->provision_meta, 'gpu_capacity_order.version') === 1;
    }

    public static function unpaid(Service $service): bool
    {
        return self::managed($service) && data_get($service->provision_meta, 'gpu_capacity_order.charge_pending') === true;
    }

    public function replay(int $customerId, string $key): ?Service
    {
        return Service::where('customer_id', $customerId)
            ->where('provision_meta->gpu_capacity_order->request_key', $key)->first();
    }

    public function register(Customer $customer, CloudPlan $plan, array $data, string $label, string $description, array $capacity): Service
    {
        return DB::transaction(function () use ($customer, $plan, $data, $label, $description, $capacity) {
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $key = (string) ($data['gpu_request_key'] ?? '');
            if ($key !== '' && ($replay = $this->replay($customer->id, $key))) { return $replay; }
            // A repeated submit while waiting must not create a second intent.
            $existing = $key === '' ? Service::where('customer_id', $customer->id)->where('cloud_plan_id', $plan->id)
                ->where('cloud_image_key', $data['image'])->where('provision_status', self::WAIT)
                ->where('billing_mode', ($data['billing_mode'] ?? 'cycle') === 'hourly' ? 'hourly' : 'cycle')
                ->where('cycle', ($data['billing_mode'] ?? 'cycle') === 'hourly' ? 'monthly' : $data['cycle'])
                ->where('provision_meta->gpu_capacity_order->label', $label)
                ->whereNotIn('status', Service::DEAD_STATUSES)->first() : null;
            if ($existing) {
                return $existing;
            }
            $cycle = ($data['billing_mode'] ?? 'cycle') === 'hourly' ? 'monthly' : $data['cycle'];
            $hourly = ($data['billing_mode'] ?? 'cycle') === 'hourly';
            return Service::create([
                'customer_id' => $customer->id,
                'name' => mb_substr(__($hourly ? 'ui.svc_name_vps_hourly' : 'ui.svc_name_vps', ['label' => $label]), 0, 150),
                'description' => $description, 'currency_code' => 'IRT',
                'price' => CloudStoreController::priceForCycle($plan, $cycle),
                'tax_percent' => $hourly ? 0 : CloudStoreController::taxPercent(), 'cycle' => $cycle,
                'billing_mode' => $hourly ? 'hourly' : 'cycle',
                'hourly_rate_irt' => $hourly ? $plan->hourlyIrt() : null,
                'hourly_rate_eur' => $hourly ? $plan->hourlyEurCents() : null,
                'on_credit_out' => $data['on_credit_out'] ?? 'suspend',
                'status' => 'awaiting_provision', 'provision_status' => self::WAIT,
                'cloud_plan_id' => $plan->id, 'cloud_image_key' => $data['image'], 'cloud_addons' => [],
                'plan' => $plan->public_name,
                'provision_meta' => ['gpu_capacity_order' => [
                    'version' => 1, 'request_key' => $key, 'label' => $label, 'requested_at' => now()->toIso8601String(),
                    'state' => $capacity['ok'] ? ($capacity['available'] ? 'available' : 'waiting_capacity') : 'checking_capacity',
                    'checked_at' => $capacity['checked_at'], 'charge_pending' => $hourly,
                ]],
            ]);
        }, 3);
    }

    public function candidates(int $limit = 20): \Illuminate\Support\Collection
    {
        return Service::where('provision_meta->gpu_capacity_order->version', 1)
            ->where(fn ($q) => $q->where('provision_status', '!=', 'running')->orWhereNull('provision_status')
                ->orWhere('updated_at', '<=', now()->subMinutes(15)))
            ->where(function ($q) {
                $q->where('provision_status', self::WAIT)
                    ->orWhere('provision_meta->gpu_capacity_order->charge_pending', true)
                    ->orWhere('provision_meta->gpu_capacity_order->stop_pending', true);
            })->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
    }

    public function advance(Service $service): string
    {
        $service->refresh()->load(['cloudPlan', 'cloudInstance']);
        if (! self::managed($service) || $service->cloudPlan?->provider !== 'salad') {
            return 'skip';
        }
        if ($service->isDead()) {
            return data_get($service->provision_meta, 'gpu_capacity_order.stop_pending')
                ? $this->retryStop($service) : 'skip';
        }
        if ($service->provision_status !== self::WAIT) {
            return $this->settleDelivery($service);
        }
        if (CloudPlan::providerIsDisabled('salad') || SaladClient::salesPaused((string) $service->cloud_image_key) || ! $service->cloudPlan->is_active
            || ! $service->cloudPlan->in_stock || $service->cloudPlan->admin_disabled
            || CloudImage::compatibleRefFor($service->cloudPlan, (string) $service->cloud_image_key) === null) {
            $this->state($service, 'unavailable');
            return 'waiting';
        }
        $capacity = $this->provider->capacityFor($service->cloudPlan);
        if (! $capacity['ok'] || ! $capacity['available']) {
            $this->state($service, $capacity['ok'] ? 'waiting_capacity' : 'checking_capacity');
            return 'waiting';
        }
        $rate = $this->currentRate($service);
        if ($rate === null) {
            $this->state($service, 'checking_price');
            return 'waiting';
        }
        $approved = $service->billing_mode === 'hourly' ? (int) $service->hourly_rate_irt : (int) $service->price;
        if ($rate > $approved) {
            $this->state($service, 'price_changed', ['new_price_irt' => $rate]);
            return 'waiting';
        }
        return DB::transaction(function () use ($service) {
            Customer::whereKey($service->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if ($locked->isDead() || $locked->provision_status !== self::WAIT) {
                return 'skip';
            }
            if ($locked->billing_mode === 'hourly') {
                $minimum = $this->credit->minimum($locked->customer_id, (int) $locked->hourly_rate_irt, $locked->id);
                if ($this->wallet->availableOf($locked->customer_id) < $minimum) {
                    $this->state($locked, 'insufficient_credit', ['minimum_credit_irt' => $minimum]);
                    return 'waiting';
                }
                $this->state($locked, 'allocating');
                $locked->update(['provision_status' => 'pending']);
                return 'queued';
            }
            // Monthly orders still require their ordinary invoice payment before provisioning.
            $this->state($locked, 'awaiting_payment');
            $locked->update(['status' => 'pending', 'provision_status' => 'none']);
            app(CloudStoreController::class)->issueOrderInvoice($locked, $locked->cloudPlan,
                (string) data_get($locked->provision_meta, 'gpu_capacity_order.label'));
            return 'invoice';
        }, 3);
    }

    public function currentRate(Service $service): ?int
    {
        $plan = $service->cloudPlan;
        $quote = $plan ? $this->provider->purchaseQuote((string) $plan->provider_ref) : ['ok' => false];
        if (! ($quote['ok'] ?? false)) {
            return null;
        }
        if ($service->billing_mode === 'hourly') {
            return max($plan->hourlyIrt(), (int) $quote['minimum_hourly_irt']);
        }
        $price = CloudStoreController::priceForCycle($plan, $service->cycle);
        return max($price, (int) ceil($quote['minimum_hourly_irt'] * max(1, Service::monthsIn($service->cycle)) * SaladClient::HOURS_PER_MONTH));
    }

    public function confirmPrice(Service $service, int $quoted): void
    {
        $rate = $this->currentRate($service);
        if ($rate === null || $rate !== $quoted || $quoted <= 0) {
            throw ValidationException::withMessages(['price' => __('ui.cvb_gpu_price_changed')]);
        }
        DB::transaction(function () use ($service, $rate) {
            Customer::whereKey($service->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::managed($locked) && ! $locked->isDead() && $locked->provision_status === self::WAIT, 409);
            if ($locked->billing_mode === 'hourly') {
                $locked->hourly_rate_irt = $rate;
                $locked->hourly_rate_eur = $locked->cloudPlan->hourlyEurCents();
            } else {
                $locked->price = $rate;
            }
            $locked->save();
            $this->state($locked, 'waiting_capacity', ['new_price_irt' => null]);
        }, 3);
    }

    /** A final capacity check in the provisioner's claimed path, before create. */
    public function allowAllocation(Service $service, CloudPlan $plan): bool
    {
        if (! self::unpaid($service) || filled($service->cloudInstance?->provider_ref)) {
            return true;
        }
        if (CloudPlan::providerIsDisabled('salad') || SaladClient::salesPaused((string) $service->cloud_image_key)
            || ! $plan->is_active || ! $plan->in_stock || $plan->admin_disabled
            || CloudImage::compatibleRefFor($plan, (string) $service->cloud_image_key) === null) {
            $this->state($service, 'unavailable');
            $service->update(['status' => 'awaiting_provision', 'provision_status' => self::WAIT]);
            return false;
        }
        $capacity = $this->provider->capacityFor($plan, true);
        $rate = $this->currentRate($service);
        if (! $capacity['ok'] || ! $capacity['available'] || $rate === null || $rate > $service->hourly_rate_irt) {
            $this->state($service, $rate !== null && $rate > $service->hourly_rate_irt ? 'price_changed'
                : ($capacity['ok'] ? 'waiting_capacity' : 'checking_capacity'), ['new_price_irt' => $rate]);
            $service->update(['status' => 'awaiting_provision', 'provision_status' => self::WAIT]);
            return false;
        }
        $allowed = DB::transaction(function () use ($service): bool {
            Customer::whereKey($service->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if ($locked->isDead()) { return false; }
            $minimum = $this->credit->minimum($locked->customer_id, (int) $locked->hourly_rate_irt, $locked->id);
            if ($this->wallet->availableOf($locked->customer_id) < $minimum) {
                $this->state($locked, 'insufficient_credit', ['minimum_credit_irt' => $minimum]);
                $locked->update(['status' => 'awaiting_provision', 'provision_status' => self::WAIT]);
                return false;
            }
            return true;
        }, 3);
        if (! $allowed) { return false; }
        $this->state($service, 'allocating', ['allocation_started_at' =>
            data_get($service->provision_meta, 'gpu_capacity_order.allocation_started_at') ?: now()->toIso8601String()]);
        return true;
    }

    private function settleDelivery(Service $service): string
    {
        if (! self::unpaid($service)) {
            return 'skip';
        }
        $instance = $service->cloudInstance;
        $since = data_get($service->provision_meta, 'gpu_capacity_order.allocation_started_at');
        $expired = $since && Carbon::parse($since)->lte(now()->subMinutes(120));
        if (! $instance) {
            if ($expired) {
                return $this->close($service, 'timeout') ? $this->retryStop($service->fresh()) : 'skip';
            }
            $this->state($service, (string) data_get($service->provision_meta, 'gpu_capacity_order.state', 'allocating'));
            return 'waiting';
        }
        $live = $this->provider->gpuDeliveryState((string) ($instance->provider_ref ?: 'sn-svc-'.$service->id));
        if (($live['ok'] ?? false) && ($live['ready'] ?? false)) {
            $accessComplete = $service->provision_status === 'done' && filled($instance->provider_ref)
                && ($instance->image_key !== 'gpu-jupyter' || $instance->hasPassword());
            if (! $accessComplete) {
                if ($expired) {
                    return $this->close($service, 'access') ? $this->retryStop($service->fresh()) : 'skip';
                }
                if ($service->provision_status === 'failed') {
                    // Retry through the normal provisioner, adopting the verified group rather than creating another.
                    if (blank($instance->provider_ref)) { $instance->update(['provider_ref' => 'sn-svc-'.$service->id]); }
                    $service->update(['status' => 'awaiting_provision', 'provision_status' => 'pending']);
                }
                return 'waiting';
            }
            $result = DB::transaction(function () use ($service, $instance, $live) {
                Customer::whereKey($service->customer_id)->lockForUpdate()->firstOrFail();
                $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
                if ($locked->isDead() || ! self::unpaid($locked)) {
                    return 'skip';
                }
                if ($this->wallet->availableOf($locked->customer_id) < $locked->hourly_rate_irt) {
                    $this->close($locked, 'credit');
                    return 'closed';
                }
                $paid = CreditEntry::where('customer_id', $locked->customer_id)->where('source_type', Service::class)
                    ->where('source_id', $locked->id)->where('reason', 'cloud_hourly')->where('amount', '<', 0)->exists();
                if (! $paid) {
                    $this->wallet->debit($locked->customer_id, 'IRT', (int) $locked->hourly_rate_irt,
                        'cloud_hourly', $locked, 'ساعت اول GPU پس از تأیید آماده‌بودن برنامه');
                }
                $this->state($locked, 'ready', ['charge_pending' => false, 'charged_at' => now()->toIso8601String()]);
                $locked->update(['last_metered_at' => now(), 'activated_at' => now()]);
                $instance->setRelation('service', $locked);
                $instance->update(['status' => 'running', 'hostname' => $live['hostname'], 'synced_at' => now()]);
                return 'ready';
            }, 3);
            return $result === 'closed' ? $this->retryStop($service->fresh()) : $result;
        }
        $failed = ($live['ok'] ?? false) && (($live['state'] ?? '') === 'failed'
            || (($live['exists'] ?? true) === false && $service->provision_status === 'failed'));
        if ($failed || $expired) {
            return $this->close($service, $failed ? 'failed' : 'timeout')
                ? $this->retryStop($service->fresh()) : 'skip';
        }
        $this->state($service, 'allocating');
        return 'waiting';
    }

    private function close(Service $service, string $reason): bool
    {
        return DB::transaction(function () use ($service, $reason): bool {
            Customer::whereKey($service->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if ($locked->isDead() || ! self::unpaid($locked)) { return false; }
            $this->state($locked, 'closed', ['closed_reason' => $reason, 'charge_pending' => false, 'stop_pending' => true]);
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now(), 'provision_status' => 'none', 'last_metered_at' => now()]);
            return true;
        }, 3);
    }

    private function retryStop(Service $service): string
    {
        $ref = (string) ($service->cloudInstance?->provider_ref ?: 'sn-svc-'.$service->id);
        $live = $this->provider->gpuDeliveryState($ref);
        if (! ($live['ok'] ?? false) || ! ($live['stopped'] ?? false)) {
            $this->provider->power($ref, 'off');
            $live = $this->provider->gpuDeliveryState($ref);
        }
        $confirmed = ($live['ok'] ?? false) && ($live['stopped'] ?? false);
        $this->state($service, 'closed', ['stop_pending' => ! $confirmed]);
        if ($confirmed) {
            $service->cloudInstance?->update(['status' => 'off', 'synced_at' => now()]);
        }
        return $confirmed ? 'stopped' : 'stop_pending';
    }

    private function state(Service $service, string $state, array $extra = []): void
    {
        // Update only our JSON subtree; concurrent readiness/recovery metadata is retained.
        $changes = ['provision_meta->gpu_capacity_order->state' => $state,
            'provision_meta->gpu_capacity_order->checked_at' => now()->toIso8601String()];
        foreach ($extra as $key => $value) {
            $changes['provision_meta->gpu_capacity_order->'.$key] = $value;
        }
        Service::whereKey($service->id)->update($changes);
        $service->refresh();
    }
}
