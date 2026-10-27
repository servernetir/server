<?php

namespace App\Services\Cloud;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Billing\OrderPaymentWindow;
use App\Services\Finance\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Unpaid GPU intent. Capacity, approved price and wallet are rechecked before allocation. */
class GpuCapacityOrders
{
    // services.provision_status is VARCHAR(16), also on existing installations.
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

    /** Presentation only: retain the provider's real state and all allocation/billing gates. */
    public static function displayState(Service $service): string
    {
        $state = (string) data_get($service->provision_meta, 'gpu_capacity_order.state', 'checking_capacity');
        if (in_array($state, ['waiting_capacity', 'available'], true)) {
            try {
                $requested = Carbon::parse(data_get($service->provision_meta, 'gpu_capacity_order.requested_at') ?? $service->created_at ?? now());
            } catch (\Throwable) {
                $requested = $service->created_at ?? now();
            }
            if ($state === 'available' || now()->lt($requested->copy()->addHour())) {
                return 'awaiting_allocation';
            }
        }
        return $state;
    }

    /** The original request timestamp is immutable for funding-window purposes. */
    public static function requestDeadline(Service $service): ?Carbon
    {
        if (! self::managed($service) || $service->billing_mode !== 'hourly') {
            return null;
        }
        $createdAt = $service->created_at;
        $requested = data_get($service->provision_meta, 'gpu_capacity_order.requested_at');
        $startedAt = null;
        // Carbon accepts relative phrases; stored request dates must be absolute.
        if (is_string($requested) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/D', $requested)) {
            try {
                $parsed = Carbon::parse($requested);
                $errors = Carbon::getLastErrors();
                if (! is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                    $startedAt = $parsed;
                }
            } catch (\Throwable) {
                // A malformed legacy date falls back to actual row creation.
            }
        }
        $startedAt ??= $createdAt?->copy();
        if ($startedAt === null) {
            return null;
        }
        if ($createdAt && $startedAt->gt($createdAt)) {
            $startedAt = $createdAt->copy();
        }
        return OrderPaymentWindow::deadline($startedAt);
    }

    /** Read-only presentation data, using the same eligibility and usable-credit gates. */
    public static function fundingWindow(Service $service): ?array
    {
        if (! $service->customer_id || $service->provision_status !== self::WAIT
            || ! self::isUnallocatedHourlyIntent($service) || ! ($deadline = self::requestDeadline($service))) {
            return null;
        }
        $minimum = app(HourlyStartCredit::class)->minimum($service->customer_id, (int) $service->hourly_rate_irt, $service->id);
        return ['deadline' => $deadline, 'hours' => OrderPaymentWindow::hours(), 'required_credit' => $minimum,
            'funded' => app(Wallet::class)->availableOf($service->customer_id) >= $minimum];
    }

    /** Never closes a paid, allocated, invoice-backed, or staff-created service. */
    private static function isUnallocatedHourlyIntent(Service $service): bool
    {
        return self::unpaid($service) && $service->billing_mode === 'hourly'
            && $service->cloudPlan?->provider === 'salad'
            && $service->status === 'awaiting_provision' && $service->created_by === null
            && $service->activated_at === null && (int) $service->hourly_rate_irt > 0
            && in_array($service->provision_status, [self::WAIT, 'pending', 'running'], true)
            && ! data_get($service->provision_meta, 'gpu_capacity_order.allocation_started_at')
            && ! $service->cloudInstance()->exists() && ! $service->invoices()->exists()
            && ! CreditEntry::where('customer_id', $service->customer_id)->where('source_type', Service::class)
                ->where('source_id', $service->id)->where('reason', 'cloud_hourly')->where('amount', '<', 0)->exists();
    }

    public function expireUnfunded(Service $service): bool
    {
        if (! self::managed($service) || $service->billing_mode !== 'hourly') {
            return false;
        }
        $expired = DB::transaction(function () use ($service): bool {
            Customer::whereKey($service->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->customer_id !== (int) $service->customer_id) {
                return false;
            }
            return $this->expireUnfundedLocked($locked);
        }, 3);
        $service->refresh();
        return $expired;
    }

    /** Caller holds the customer lock followed by the fresh service lock. */
    private function expireUnfundedLocked(Service $service): bool
    {
        if (! self::isUnallocatedHourlyIntent($service) || ! ($deadline = self::requestDeadline($service))
            || now()->lt($deadline)) {
            return false;
        }
        $minimum = $this->credit->minimum($service->customer_id, (int) $service->hourly_rate_irt, $service->id);
        if ($this->wallet->availableOf($service->customer_id) >= $minimum) {
            return false;
        }
        $this->state($service, 'expired', ['closed_reason' => 'payment_expired', 'charge_pending' => false,
            'stop_pending' => false, 'expired_at' => now()->toIso8601String(), 'expires_at' => $deadline->toIso8601String()]);
        $service->update(['status' => 'cancelled', 'cancelled_at' => now(), 'provision_status' => 'none']);
        ActivityLog::forService($service, 'gpu_request_expired', __('ui.act_gpu_request_expired'), 'system');
        return true;
    }

    /** Recheck after provider calls: a late callback must not revive a closed intent. */
    private function waitForAllocation(Service $service, string $state, array $extra = []): void
    {
        DB::transaction(function () use ($service, $state, $extra) {
            Customer::whereKey($service->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->customer_id !== (int) $service->customer_id
                || ! self::isUnallocatedHourlyIntent($locked) || $this->expireUnfundedLocked($locked)) {
                return;
            }
            $this->state($locked, $state, $extra);
            $locked->update(['status' => 'awaiting_provision', 'provision_status' => self::WAIT]);
        }, 3);
        $service->refresh();
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
        if ($this->expireUnfunded($service)) {
            return 'expired';
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
            if ((int) $locked->customer_id !== (int) $service->customer_id || $locked->isDead() || $locked->provision_status !== self::WAIT) {
                return 'skip';
            }
            if ($this->expireUnfundedLocked($locked)) {
                return 'expired';
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
        $service->refresh();
        if ($this->expireUnfunded($service)
            || data_get($service->provision_meta, 'gpu_capacity_order.closed_reason') === 'payment_expired') {
            throw ValidationException::withMessages(['price' => __('ui.gpu_request_expired_p')]);
        }
        abort_if($service->isDead(), 409);
        $rate = $this->currentRate($service);
        if ($rate === null || $rate !== $quoted || $quoted <= 0) {
            throw ValidationException::withMessages(['price' => __('ui.cvb_gpu_price_changed')]);
        }
        // Return first so expiry commits before validation is reported to the customer.
        $confirmed = DB::transaction(function () use ($service, $rate): bool {
            Customer::whereKey($service->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->customer_id === (int) $service->customer_id
                && self::managed($locked) && ! $locked->isDead() && $locked->provision_status === self::WAIT, 409);
            if ($this->expireUnfundedLocked($locked)) {
                return false;
            }
            if ($locked->billing_mode === 'hourly') {
                $locked->hourly_rate_irt = $rate;
                $locked->hourly_rate_eur = $locked->cloudPlan->hourlyEurCents();
            } else {
                $locked->price = $rate;
            }
            $locked->save();
            $this->state($locked, 'waiting_capacity', ['new_price_irt' => null]);
            return true;
        }, 3);
        if (! $confirmed) {
            throw ValidationException::withMessages(['price' => __('ui.gpu_request_expired_p')]);
        }
    }

    /** A final capacity check in the provisioner's claimed path, before create. */
    public function allowAllocation(Service $service, CloudPlan $plan): bool
    {
        $service->refresh()->load('cloudInstance');
        if ($service->isDead() || $this->expireUnfunded($service)) {
            return false;
        }
        if (! self::unpaid($service) || filled($service->cloudInstance?->provider_ref)) {
            return true;
        }
        if (CloudPlan::providerIsDisabled('salad') || SaladClient::salesPaused((string) $service->cloud_image_key)
            || ! $plan->is_active || ! $plan->in_stock || $plan->admin_disabled
            || CloudImage::compatibleRefFor($plan, (string) $service->cloud_image_key) === null) {
            $this->waitForAllocation($service, 'unavailable');
            return false;
        }
        $capacity = $this->provider->capacityFor($plan, true);
        $rate = $this->currentRate($service);
        if (! $capacity['ok'] || ! $capacity['available'] || $rate === null || $rate > $service->hourly_rate_irt) {
            $this->waitForAllocation($service, $rate !== null && $rate > $service->hourly_rate_irt ? 'price_changed'
                : ($capacity['ok'] ? 'waiting_capacity' : 'checking_capacity'), ['new_price_irt' => $rate]);
            return false;
        }
        return DB::transaction(function () use ($service): bool {
            Customer::whereKey($service->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->customer_id !== (int) $service->customer_id || $locked->isDead()
                || $this->expireUnfundedLocked($locked)) {
                return false;
            }
            $minimum = $this->credit->minimum($locked->customer_id, (int) $locked->hourly_rate_irt, $locked->id);
            if ($this->wallet->availableOf($locked->customer_id) < $minimum) {
                $this->state($locked, 'insufficient_credit', ['minimum_credit_irt' => $minimum]);
                $locked->update(['status' => 'awaiting_provision', 'provision_status' => self::WAIT]);
                return false;
            }
            // The expiry checker sees this marker under the same lock before create starts.
            $this->state($locked, 'allocating', ['allocation_started_at' =>
                data_get($locked->provision_meta, 'gpu_capacity_order.allocation_started_at') ?: now()->toIso8601String()]);
            return true;
        }, 3);
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
        $query = Service::whereKey($service->id);
        if (! in_array($state, ['closed', 'expired'], true)) {
            $query->whereNotIn('status', Service::DEAD_STATUSES);
        }
        $query->update($changes);
        $service->refresh();
    }
}
