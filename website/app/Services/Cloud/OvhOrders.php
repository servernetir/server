<?php

namespace App\Services\Cloud;

use App\Models\{ActivityLog, CloudImage, CloudPlan, CloudSshKey, Invoice, OvhOrder, Service, Setting};
use App\Services\Notify\AdminNotifier;
use App\Support\ErrorTracker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Durable state machine behind OvhClient. No HTTP retries of a monetary POST. */
class OvhOrders
{
    public static function enabled(): bool
    {
        return config('ovh.purchase_enabled') === true
            && Setting::get('ovh_purchase_enabled') === '1'
            && Setting::get('ovh_previous_order_checked') === '1';
    }

    public static function cataloguePricingHash(): string
    {
        $pricing = app(CloudPricing::class);
        return hash('sha256', json_encode([$pricing->marginPct('ovh'), $pricing->fxFeePctFor('ovh')]));
    }

    public function __construct(private OvhOrderFinance $finance) {}

    public function provision(OvhClient $client, array $input, bool $dryRun = false, bool $readOnly = false): array
    {
        $service = Service::find((int) ($input['service_id'] ?? 0));
        if (! $service || ! Schema::hasTable('ovh_orders')) {
            return $this->unavailable('journal_missing', 'شناسهٔ سرویس معتبر یا جدول ثبت سفارش آماده نیست.');
        }
        $order = OvhOrder::forService($service->id);
        try {
            if ($order) {
                $spec = (array) $order->spec;
                if ((int) ($spec['customer_id'] ?? 0) !== (int) $service->customer_id
                    || (int) ($spec['plan_id'] ?? 0) !== (int) $service->cloud_plan_id
                    || ($spec['image_key'] ?? null) !== $service->cloud_image_key
                    || (int) ($spec['ssh_key_id'] ?? 0) !== (int) $service->cloud_ssh_key_id) {
                    throw new OvhOrderHold('identity_changed', 'مالک یا مشخصات سفارش پرداخت‌شده تغییر کرده است.');
                }
            } else { $spec = $this->spec($service); }
            if (! $order) {
                try {
                    $order = OvhOrder::create([
                        'service_id' => $service->id, 'idempotency_key' => (string) Str::uuid(),
                        'account_hash' => $client->accountFingerprint(), 'spec_hash' => hash('sha256', json_encode($spec)),
                        'spec' => $spec, 'phase' => 'new',
                    ]);
                } catch (UniqueConstraintViolationException) { $order = OvhOrder::forService($service->id); }
            }
        } catch (OvhOrderHold $e) {
            return $this->unavailable($e->reason, $e->getMessage());
        }
        $token = (string) Str::uuid();
        $claimed = $order->claimLease($token);
        if (! $claimed) { return $this->result($order->fresh(), 'busy'); }
        $order->refresh();
        try {
            if ($order->account_hash !== $client->accountFingerprint()
                || $order->spec_hash !== hash('sha256', json_encode($spec))) {
                throw new OvhOrderHold('identity_changed', 'حساب زیرساخت یا مشخصات/مالک سفارش نسبت به ثبت اولیه تغییر کرده است.');
            }
            if (in_array($order->phase, ['ready', 'rejected', 'refunded'], true)) { return $this->result($order); }
            if ($service->isDead() && ! $readOnly) { throw new OvhOrderHold('service_closed', 'سرویس لغو شده؛ فقط بررسی خواندنی منبع خارجی مجاز است.'); }
            if (! $readOnly && $order->next_attempt_at?->isFuture()) { return $this->result($order); }
            if (! $readOnly && ! $dryRun && ! self::enabled() && ! $order->hasExposure()) {
                throw new OvhOrderHold('purchase_disabled', 'خرید خودکار به تأیید انتشار، بررسی سفارش قبلی و فعال‌سازی مدیر نیاز دارد.');
            }
            $canWrite = ! $readOnly && ! $dryRun && self::enabled();
            if (! $readOnly && $order->phase === 'review') { return $this->result($order); }
            if (! $readOnly && ($order->attempts >= (int) config('ovh.max_attempts', 12)
                || ($order->retry_started_at ?? $order->created_at)->lt(now()->subHours((int) config('ovh.max_age_hours', 24))))) {
                throw new OvhOrderHold('retry_exhausted', 'تعداد یا مدت پیگیری خودکار به سقف رسیده است؛ تطبیق خواندنی همچنان در دسترس است.');
            }
            if (! $readOnly) { $this->save($order, ['attempts' => (int) $order->attempts + 1]); }
            if ($order->checkout_sent_at && ! $order->order_id) {
                $this->recoverCheckout($client, $order);
            }
            if ($order->order_id) {
                return $this->follow($client, $order, $canWrite);
            }
            if ($readOnly) { return $this->result($order); }
            if (! $canWrite && ! $dryRun) { return $this->result($order); }
            $this->finance->paidInvoice($service);
            $plan = $client->purchasePlan($order->spec);
            if (! $plan['ok']) { throw new OvhOrderHold($plan['code'], $plan['message'], $plan['transient'] ?? false); }
            $cart = $this->prepareCart($client, $order, $plan);
            $this->quote($client, $order, $plan);
            if ($dryRun) {
                $this->change($order, 'dry_run', ['error_code' => null, 'error_message' => null]);
                return $this->result($order);
            }
            $this->prepareAccessKey($order);
            // Claim the exact invoice and persist checkout intent before the monetary boundary.
            DB::transaction(function () use ($order): void {
                \App\Models\Customer::whereKey($order->spec['customer_id'])->lockForUpdate()->firstOrFail();
                $service = Service::whereKey($order->service_id)->lockForUpdate()->firstOrFail();
                Invoice::whereKey($order->invoice_id)->lockForUpdate()->firstOrFail();
                $this->finance->paidInvoice($service);
                $this->assertLease($order);
                if (! self::enabled()) { throw new OvhOrderHold('purchase_disabled', 'دروازهٔ خرید خاموش شد.'); }
                $this->change($order, 'checkout_submitting', ['checkout_sent_at' => now(), 'financial_state' => 'purchase_committed']);
            });
            $r = $client->orderApi('POST', '/order/cart/'.rawurlencode($order->cart_id).'/checkout', [
                'autoPayWithPreferredPaymentMethod' => false, 'waiveRetractationPeriod' => false,
            ]);
            $id = data_get($r, 'body.orderId');
            if (! $r['ok'] || ! $this->positiveId($id)) {
                // Even a 4xx is kept for review: we do not infer absence of an external transaction.
                throw new OvhOrderHold('checkout_unknown', 'نتیجهٔ ثبت سفارش قطعی نیست؛ خرید دوباره ممنوع و تطبیق همان سبد لازم است.');
            }
            $this->change($order, 'ordered', ['order_id' => (int) $id, 'error_code' => null, 'error_message' => null]);
            return $this->follow($client, $order, true);
        } catch (OvhOrderHold $e) {
            if ($e->transient && $order->attempts < (int) config('ovh.max_attempts', 12)) {
                $this->save($order, ['next_attempt_at' => now()->addSeconds($this->backoff($order)), 'error_code' => $e->reason, 'error_message' => $e->getMessage()]);
                $this->event($order, 'retry_scheduled', $e->reason);
                return $this->result($order);
            }
            return $this->hold($order, $e->reason, $e->getMessage());
        } catch (\Throwable $e) {
            // Preserve intent on process/DB/response failures; never turn an unknown purchase into a refund.
            ErrorTracker::note('ovh-order', 'خطای داخلی در پردازش سفارش OVH.', ['service' => $service->id, 'order_journal' => $order->id, 'exception' => get_class($e), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
            return $this->hold($order, 'internal_error', 'خطای داخلی؛ دفتر سفارش برای تطبیق حفظ شد.');
        } finally {
            OvhOrder::whereKey($order->id)->where('lease_token', $token)->update(['lease_token' => null, 'locked_until' => null]);
        }
    }

    /** Admin actions are state transitions; none performs a monetary API call. */
    public function adminAction(OvhClient $client, OvhOrder $order, string $action, string $reason, int $actor, ?int $externalId = null, bool $verifiedAbsent = false): void
    {
        $token = (string) Str::uuid();
        $ok = $order->claimLease($token);
        if (! $ok) { throw new OvhOrderHold('busy', 'پردازش دیگری مالک قفل است.'); }
        $order->refresh();
        try {
            if ($order->account_hash !== $client->accountFingerprint()) { throw new OvhOrderHold('identity_changed', 'حساب API تغییر کرده است.'); }
            $service = $order->service()->firstOrFail();
            if ($action === 'retry') {
                // canRetry checks the pre-acquisition lease, so inspect exposure/terminal state here.
                if ($service->isDead() || $order->hasExposure() || in_array($order->phase, ['ready', 'refunded', 'rejected'], true)) {
                    throw new OvhOrderHold('unsafe_retry', 'این سفارش فقط قابل پیگیری است؛ تکرار خرید مجاز نیست.');
                }
                $this->change($order, $order->resume_phase ?: 'new', ['attempts' => 0, 'retry_epoch' => $order->retry_epoch + 1, 'retry_started_at' => now(),
                    'next_attempt_at' => null, 'error_code' => null, 'error_message' => null]);
                $service->forceFill(['provision_status' => 'pending', 'provision_error' => null])->save();
            } elseif ($action === 'resume') {
                if ($service->isDead() || ! $order->order_id || $order->phase === 'ready'
                    || ! in_array($order->provider_status, ['checking', 'delivering', 'delivered', 'notPaid'], true)
                    || ($order->provider_status === 'notPaid' && $order->payment_sent_at)
                    || ($order->install_sent_at && ! $order->install_task_id)) {
                    throw new OvhOrderHold('unsafe_resume', 'وضعیت سفارش/پرداخت/نصب هنوز برای ادامه روشن نیست؛ ابتدا تطبیق خواندنی انجام شود.');
                }
                $this->change($order, $order->install_sent_at ? 'installing' : 'ordered', ['attempts' => 0,
                    'retry_epoch' => $order->retry_epoch + 1, 'retry_started_at' => now(), 'next_attempt_at' => null, 'error_code' => null, 'error_message' => null]);
                $service->forceFill(['provision_status' => 'pending', 'provision_error' => null])->save();
            } elseif ($action === 'pause') {
                $this->hold($order, 'admin_pause', 'پیگیری توسط مدیر متوقف شد.');
                $service->forceFill(['provision_status' => 'manual'])->save();
            } elseif ($action === 'reject_refund') {
                if ($order->phase === 'refunded') { return; }
                if (! $verifiedAbsent || $order->order_id || $order->payment_sent_at || $order->server_ref
                    || $order->install_sent_at || $order->phase === 'ready') {
                    throw new OvhOrderHold('unsafe_refund', 'نبود سفارش، پرداخت و منبع خارجی باید مستقلاً تأیید شود؛ سفارش دارای شناسه/پرداخت از این مسیر برگشت نمی‌خورد.');
                }
                DB::transaction(function () use ($order): void {
                    \App\Models\Customer::whereKey($order->spec['customer_id'])->lockForUpdate()->firstOrFail();
                    $service = Service::whereKey($order->service_id)->lockForUpdate()->firstOrFail();
                    Invoice::where('service_id', $service->id)->orderBy('id')->lockForUpdate()->get();
                    $this->change($order, 'rejected', ['financial_state' => 'refund_in_progress', 'private_key' => null]);
                    $service->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'provision_status' => 'failed',
                        'provision_error' => 'عدم ایجاد منبع خارجی توسط مدیر تأیید و سفارش لغو شد.'])->save();
                    app(\App\Services\Billing\UndeliveredRefund::class)->maybeRefund($service, 'staff');
                    $this->change($order, 'refunded', ['financial_state' => 'customer_refunded',
                        'next_attempt_at' => null, 'error_code' => 'cancelled_after_audit', 'error_message' => 'سفارش پس از بررسی مستقل لغو و وجه از مسیر مالی موجود برگشت داده شد.']);
                });
            } elseif ($action === 'link') {
                if (! $order->checkout_sent_at || $order->order_id || ! $externalId || $client->accountRegion() === 'us') {
                    throw new OvhOrderHold('unsafe_link', 'اتصال دستی فقط برای Checkout نامشخص EU/CA با مبلغ و پیکربندی قابل تطبیق است.');
                }
                $this->matchingDetails($client, $order, $externalId);
                $order->order_id = $externalId;
                $this->verifyOrderPrice($client, $order);
                $this->change($order, 'review', ['order_id' => $externalId, 'error_code' => 'linked_review',
                    'error_message' => 'مدیر ارتباط با سبد را تأیید کرد؛ ابتدا وضعیت خوانده شود.']);
            } else { throw new OvhOrderHold('invalid_action', 'عملیات معتبر نیست.'); }
            $this->event($order, 'admin_'.$action, $reason, $actor);
        } finally {
            OvhOrder::whereKey($order->id)->where('lease_token', $token)->update(['lease_token' => null, 'locked_until' => null]);
        }
    }

    private function spec(Service $s): array
    {
        $p = CloudPlan::find($s->cloud_plan_id);
        if (! $p || $p->provider !== 'ovh' || ! $s->customer || $s->isHourly() || $s->cycle !== 'monthly') {
            throw new OvhOrderHold('unsupported_order', 'این مسیر فقط VPS ماهانهٔ همین مشتری روی زیرساخت چهارم را پشتیبانی می‌کند.');
        }
        $image = CloudImage::where('provider', 'ovh')->where('key', $s->cloud_image_key)->where('is_active', true)->first();
        $users = ['ubuntu' => 'ubuntu', 'debian' => 'debian', 'alma' => 'almalinux', 'rocky' => 'rocky'];
        if (! $image || $image->kind !== 'os' || ! isset($users[$image->family]) || ! app(CloudAddons::class)->isEmpty(app(CloudAddons::class)->sanitize($s->cloud_addons))) {
            throw new OvhOrderHold('unsupported_configuration', 'فقط Linux استانداردِ پشتیبانی‌شده بدون افزونه قابل خرید خودکار است.');
        }
        return ['service_id' => (int) $s->id, 'customer_id' => (int) $s->customer_id, 'plan_id' => (int) $p->id,
            'plan_ref' => (string) $p->provider_ref, 'location_ref' => (string) ($p->provider_location ?: $p->location_code),
            'image_ref' => (string) $image->provider_ref, 'image_key' => (string) $s->cloud_image_key,
            'vcpu' => (int) $p->vcpu, 'ram_mb' => (int) $p->ram_mb, 'disk_gb' => (int) $p->disk_gb,
            'ssh_key_id' => (int) $s->cloud_ssh_key_id, 'username' => $users[$image->family]];
    }

    private function prepareCart(OvhClient $c, OvhOrder $o, array $plan): array
    {
        if (! $o->cart_id) {
            if ($o->phase === 'cart_submitting') {
                $carts = $this->get($c, $o, '/order/cart', ['description' => 'snet-'.$o->idempotency_key]);
                if (count($carts) !== 1 || ! is_string($carts[0])) { throw new OvhOrderHold('cart_unknown', 'سبد پیشین با شناسهٔ دقیق پیدا نشد؛ سبد دیگری ساخته نشد.'); }
                $this->save($o, ['cart_id' => $carts[0]]);
            } else {
                $this->change($o, 'cart_submitting');
                $r = $this->request($c, $o, 'POST', '/order/cart', ['ovhSubsidiary' => $plan['subsidiary'], 'description' => 'snet-'.$o->idempotency_key]);
                if (! is_string($r['cartId'] ?? null) || $r['cartId'] === '') { throw new OvhOrderHold('cart_unknown', 'شناسه معتبر سبد دریافت نشد.'); }
                $this->save($o, ['cart_id' => $r['cartId']]);
            }
        }
        $base = '/order/cart/'.rawurlencode($o->cart_id);
        $cart = $this->get($c, $o, $base);
        if (($cart['description'] ?? null) !== 'snet-'.$o->idempotency_key || ! empty($cart['readOnly'])) {
            throw new OvhOrderHold('cart_mismatch', 'سبد متعلق به این عملیات یا قابل ویرایش نیست.');
        }
        if (! $o->assigned_at) {
            $this->request($c, $o, 'POST', $base.'/assign');
            $this->save($o, ['assigned_at' => now()]);
        }
        // Recover an item POST that succeeded before its response was persisted.
        $cart = $this->get($c, $o, $base);
        $items = (array) ($cart['items'] ?? []);
        if (count($items) > 1) { throw new OvhOrderHold('cart_items_mismatch', 'سبد دقیقاً یک آیتم ندارد؛ Checkout ممنوع است.'); }
        if (! $o->item_id && $items !== []) {
            $item = $this->get($c, $o, $base.'/item/'.(int) $items[0]);
            if (($item['planCode'] ?? null) !== $o->spec['plan_ref'] || (int) ($item['quantity'] ?? 0) !== 1
                || ($item['duration'] ?? null) !== 'P1M') { throw new OvhOrderHold('item_mismatch', 'آیتم بازیابی‌شده با سفارش مطابقت ندارد.'); }
            $this->save($o, ['item_id' => (int) $items[0]]);
        }
        if (! $o->item_id) {
            if ($o->phase === 'item_submitting') { throw new OvhOrderHold('item_unknown', 'نتیجهٔ افزودن آیتم قبلی نامشخص است؛ آیتم دیگری افزوده نشد.'); }
            $products = $this->get($c, $o, $base.'/vps');
            $product = collect($products)->firstWhere('planCode', $o->spec['plan_ref']);
            $price = collect($product['prices'] ?? [])->first(fn ($p) => ($p['duration'] ?? '') === 'P1M'
                && is_string($p['pricingMode'] ?? null) && (int) ($p['commitment'] ?? 0) === 0);
            if (! $product || ! $price || (($product['orderable'] ?? true) === false)) { throw new OvhOrderHold('not_orderable', 'پلن با دورهٔ ماهانهٔ بدون تعهد در سبد قابل سفارش نیست.'); }
            $this->change($o, 'item_submitting');
            $item = $this->request($c, $o, 'POST', $base.'/vps', ['duration' => 'P1M', 'planCode' => $o->spec['plan_ref'], 'pricingMode' => $price['pricingMode'], 'quantity' => 1]);
            if (! $this->positiveId($item['itemId'] ?? null)) { throw new OvhOrderHold('item_unknown', 'شناسه آیتم تأیید نشد.'); }
            $this->save($o, ['item_id' => (int) $item['itemId']]);
        }
        $itemBase = $base.'/item/'.$o->item_id;
        $required = $this->get($c, $o, $itemBase.'/requiredConfiguration');
        $wanted = $plan['configuration'];
        foreach ($required as $r) {
            if (! empty($r['required']) && ! array_key_exists($r['label'] ?? '', $wanted)) {
                throw new OvhOrderHold('unsupported_required_configuration', 'پیکربندی اجباری ناشناخته است؛ مقدار حدسی ارسال نشد.');
            }
        }
        $ids = $this->get($c, $o, $itemBase.'/configuration');
        $existing = [];
        foreach ($ids as $id) {
            $row = $this->get($c, $o, $itemBase.'/configuration/'.(int) $id);
            $label = $row['label'] ?? '';
            if (! isset($wanted[$label]) || ($row['value'] ?? null) !== $wanted[$label] || isset($existing[$label])) {
                throw new OvhOrderHold('configuration_mismatch', 'پیکربندی موجود سبد متفاوت یا تکراری است.');
            }
            $existing[$label] = true;
        }
        foreach ($wanted as $label => $value) {
            if (! isset($existing[$label])) { $this->request($c, $o, 'POST', $itemBase.'/configuration', compact('label', 'value')); }
        }
        // Any option accidentally present would add a second item and blocks purchase.
        $cart = $this->get($c, $o, $base);
        if (array_map('intval', (array) ($cart['items'] ?? [])) !== [(int) $o->item_id]) {
            throw new OvhOrderHold('cart_items_mismatch', 'محتوای نهایی سبد با سفارش تک‌سرور مطابقت ندارد.');
        }
        // Read configuration back before the financial boundary, including recovered POSTs.
        $confirmed = [];
        foreach ($this->get($c, $o, $itemBase.'/configuration') as $id) {
            $row = $this->get($c, $o, $itemBase.'/configuration/'.(int) $id);
            $label = $row['label'] ?? '';
            if (! isset($wanted[$label]) || ($row['value'] ?? null) !== $wanted[$label] || isset($confirmed[$label])) {
                throw new OvhOrderHold('configuration_mismatch', 'پیکربندی نهایی سبد یکسان یا یکتا نیست.');
            }
            $confirmed[$label] = true;
        }
        if (count($confirmed) !== count($wanted)) { throw new OvhOrderHold('configuration_incomplete', 'ثبت نهایی پیکربندی تأیید نشد.'); }
        $this->change($o, 'prepared', ['next_attempt_at' => null]);
        return $cart;
    }

    private function quote(OvhClient $c, OvhOrder $o, array $plan): void
    {
        $quote = $this->get($c, $o, '/order/cart/'.rawurlencode($o->cart_id).'/checkout');
        if (! empty($quote['orderId'])) { throw new OvhOrderHold('unexpected_existing_order', 'سبد از قبل سفارش دارد؛ خرید جدید متوقف شد.'); }
        $values = $this->finance->validate($o, $quote, $plan['renewal'], $plan['currency']);
        $max = (int) Setting::get('ovh_max_order_minor', '0');
        if ($max <= 0 || $values['cost_minor'] > $max) { throw new OvhOrderHold('budget_limit', 'مبلغ نهایی از سقف مجاز هر سفارش بیشتر است یا سقف تنظیم نشده است.'); }
        $this->save($o, $values);
        $methods = $this->get($c, $o, '/me/payment/method', ['default' => 'true']);
        if (count($methods) !== 1 || ! $this->positiveId($methods[0])) { throw new OvhOrderHold('payment_method_missing', 'روش پرداخت پیش‌فرض یکتا در حساب زیرساخت وجود ندارد؛ موجودی قابل خرج فرض نشد.'); }
        $method = $this->get($c, $o, '/me/payment/method/'.(int) $methods[0]);
        if (isset($method['status']) && ! in_array(strtoupper((string) $method['status']), ['VALID', 'VALIDATED', 'ACTIVE'], true)) {
            throw new OvhOrderHold('payment_method_invalid', 'روش پرداخت پیش‌فرض معتبر نیست.');
        }
        $this->save($o, ['payment_method_id' => (int) $methods[0]]);
        $this->event($o, 'quote_verified', 'Invoice, tax, FX, renewal and margin verified; payment capacity not guaranteed by API.');
    }

    private function recoverCheckout(OvhClient $c, OvhOrder $o): void
    {
        if (! $o->cart_id) { throw new OvhOrderHold('checkout_unknown', 'شناسه سبد برای تطبیق موجود نیست.'); }
        $r = $this->get($c, $o, '/order/cart/'.rawurlencode($o->cart_id).'/checkout');
        $id = $r['orderId'] ?? null;
        if (! $this->positiveId($id)) {
            throw new OvhOrderHold('checkout_unknown', 'API شناسهٔ قابل اثبات سفارش این سبد را برنگرداند؛ بررسی دستی لازم است و Checkout تکرار نشد.');
        }
        $this->matchingDetails($c, $o, (int) $id);
        $this->change($o, 'ordered', ['order_id' => (int) $id, 'error_code' => null, 'error_message' => null]);
        $this->event($o, 'checkout_reconciled', 'Order recovered from the exact persisted cart.');
    }

    private function follow(OvhClient $c, OvhOrder $o, bool $canWrite): array
    {
        $status = $this->get($c, $o, '/me/order/'.$o->order_id.'/status');
        if (! is_string($status)) { throw new OvhOrderHold('unknown_status', 'وضعیت قابل تفسیر سفارش دریافت نشد.'); }
        $this->save($o, ['provider_status' => $status]);
        if (in_array($status, ['cancelled', 'refused'], true)) {
            // Supplier cancellation alone does not prove that a charge was reversed.
            throw new OvhOrderHold('supplier_cancelled', 'سفارش زیرساخت لغو/رد شده؛ تطبیق پرداخت و برگشت وجه برای مدیر لازم است.');
        }
        if ($status === 'notPaid') {
            if ($o->payment_sent_at) { throw new OvhOrderHold('payment_unknown', 'درخواست پرداخت قبلی ثبت شده ولی تسویه تأیید نیست؛ پرداخت دیگری ارسال نشد.'); }
            if (! $canWrite) { return $this->wait($o); }
            $this->matchingDetails($c, $o, (int) $o->order_id);
            $this->verifyOrderPrice($c, $o);
            $this->intent($o, 'payment_submitting', 'payment_sent_at');
            $r = $c->orderApi('POST', '/me/order/'.$o->order_id.'/pay', ['paymentMethod' => ['id' => (int) $o->payment_method_id]]);
            if (! $r['ok']) { throw new OvhOrderHold('payment_unknown', 'پرداخت سفارش تأیید نشد؛ وضعیت همان سفارش باید تطبیق داده شود.'); }
            $this->change($o, 'ordered', ['error_code' => null, 'error_message' => null]);
            return $this->wait($o);
        }
        if (! in_array($status, ['checking', 'delivering', 'delivered'], true)) {
            throw new OvhOrderHold('supplier_review', 'وضعیت سفارش به بررسی حساب/مدارک یا اقدام مدیر نیاز دارد.');
        }
        $this->change($o, $o->install_sent_at ? 'installing' : 'ordered', ['financial_state' => 'supplier_processing', 'error_code' => null, 'error_message' => null]);
        if ($status !== 'delivered') { return $this->wait($o); }
        if (! $o->server_ref) {
            $ref = $this->matchingDetails($c, $o, (int) $o->order_id);
            if (! $ref) { return $this->wait($o); }
            if (\App\Models\CloudInstance::where('provider', 'ovh')->where('provider_ref', $ref)->where('service_id', '!=', $o->service_id)->exists()) {
                throw new OvhOrderHold('server_already_assigned', 'منبع خارجی به سرویس دیگری تخصیص دارد.');
            }
            $this->save($o, ['server_ref' => $ref]);
        }
        return $this->install($c, $o, $canWrite);
    }

    /** Order detail + extension is the official EU/CA mapping; US operations differ. */
    private function matchingDetails(OvhClient $c, OvhOrder $o, int $id): ?string
    {
        if ($c->accountRegion() === 'us') {
            $details = $this->get($c, $o, '/me/order/'.$id.'/details');
            $names = [];
            foreach (array_slice($details, 0, 40) as $detailId) {
                $base = '/me/order/'.$id.'/details/'.(int) $detailId.'/operations';
                foreach (array_slice($this->get($c, $o, $base), 0, 40) as $operationId) {
                    $operation = $this->get($c, $o, $base.'/'.(int) $operationId);
                    $name = data_get($operation, 'resource.name');
                    if (is_string($name) && $name !== '') { $names[$name] = true; }
                }
            }
            if (count($names) > 1) { throw new OvhOrderHold('order_details_mismatch', 'چند منبع در سفارش US دیده شد؛ تخصیص خودکار متوقف شد.'); }
            return $names === [] ? null : (string) array_key_first($names);
        }
        $ids = $this->get($c, $o, '/me/order/'.$id.'/details');
        $matches = [];
        foreach (array_slice($ids, 0, 40) as $detailId) {
            $base = '/me/order/'.$id.'/details/'.(int) $detailId;
            $ext = $this->get($c, $o, $base.'/extension');
            if (data_get($ext, 'order.plan.code') !== $o->spec['plan_ref']) { continue; }
            $configs = collect(data_get($ext, 'order.configurations', []))->pluck('value', 'label')->all();
            if (($configs['vps_datacenter'] ?? null) !== $o->spec['location_ref'] || ($configs['vps_os'] ?? null) !== $o->spec['image_ref']) {
                throw new OvhOrderHold('order_configuration_mismatch', 'مشخصات سفارش خارجی با سرویس یکسان نیست.');
            }
            $detail = $this->get($c, $o, $base);
            if ((int) ($detail['quantity'] ?? 0) !== 1) { throw new OvhOrderHold('order_quantity_mismatch', 'تعداد سفارش خارجی دقیقاً یک نیست.'); }
            $matches[] = trim((string) ($detail['domain'] ?? ''));
        }
        if (count($matches) !== 1) { throw new OvhOrderHold('order_details_mismatch', 'ردیف یکتای منطبق با پلن، دیتاسنتر و سیستم‌عامل پیدا نشد.'); }
        return $matches[0] !== '' ? $matches[0] : null;
    }

    private function prepareAccessKey(OvhOrder $o): void
    {
        if ($o->public_key) { return; }
        if ($o->spec['ssh_key_id']) {
            $key = CloudSshKey::whereKey($o->spec['ssh_key_id'])->where('customer_id', $o->spec['customer_id'])->first();
            $checked = $key ? CloudSshKey::inspect($key->public_key) : ['ok' => false];
            if (! $checked['ok'] || ! in_array($checked['type'] ?? '', ['ssh-rsa', 'ssh-ed25519'], true)) { throw new OvhOrderHold('ssh_key_invalid', 'کلید SSH متعلق به مشتری و معتبر نیست.'); }
            $this->save($o, ['public_key' => $checked['normalized']]);
            return;
        }
        // Per-order PKCS#8 key, encrypted by Laravel. Only its public half goes to OVH.
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (! $key || ! openssl_pkey_export($key, $private)) { throw new OvhOrderHold('key_generation_failed', 'ساخت امن کلید دسترسی موفق نشد.'); }
        $details = openssl_pkey_get_details($key);
        $pack = static fn (string $s): string => pack('N', strlen($s)).$s;
        $mpint = static fn (string $s): string => (ord($s[0]) & 128) ? chr(0).$s : $s;
        $public = 'ssh-rsa '.base64_encode($pack('ssh-rsa').$pack($mpint($details['rsa']['e'])).$pack($mpint($details['rsa']['n'])));
        $this->save($o, ['public_key' => $public, 'private_key' => $private]);
    }

    private function install(OvhClient $c, OvhOrder $o, bool $canWrite): array
    {
        $base = '/vps/'.rawurlencode($o->server_ref);
        if (! $o->install_sent_at) {
            if (! $canWrite) { return $this->wait($o); }
            if (! $o->public_key) { throw new OvhOrderHold('key_missing', 'کلید دسترسی ثبت‌شدهٔ پیش از خرید وجود ندارد.'); }
            $currentImage = $this->get($c, $o, $base.'/images/current');
            $matches = [];
            if (($currentImage['name'] ?? null) === $o->spec['image_ref'] && is_string($currentImage['id'] ?? null) && $currentImage['id'] !== '') {
                $matches[] = $currentImage['id'];
            } else {
                $ids = $this->get($c, $o, $base.'/images/available');
                if (count($ids) > 40) { throw new OvhOrderHold('image_lookup_limit', 'فهرست ایمیج برای یک اجرای صف بیش از حد بزرگ است؛ انتخاب دقیق مدیر لازم است.'); }
                foreach ($ids as $id) {
                    $image = $this->get($c, $o, $base.'/images/available/'.rawurlencode((string) $id));
                    if (($image['name'] ?? null) === $o->spec['image_ref']) { $matches[] = (string) $id; }
                }
            }
            if (count($matches) !== 1) { throw new OvhOrderHold('install_image_mismatch', 'ایمیج نصب با نام دقیق سفارش یکتا نیست؛ نصب حدسی اجرا نشد.'); }
            $this->intent($o, 'install_submitting', 'install_sent_at');
            $r = $c->orderApi('POST', $base.'/rebuild', ['imageId' => $matches[0], 'publicSshKey' => $o->public_key, 'doNotSendPassword' => true]);
            if (! $r['ok'] || ! $this->positiveId(data_get($r, 'body.id'))) {
                throw new OvhOrderHold('install_unknown', 'نتیجهٔ نصب کلید قطعی نیست؛ نصب دوباره خودکار اجرا نخواهد شد.');
            }
            $this->change($o, 'installing', ['install_task_id' => (int) $r['body']['id']]);
            return $this->wait($o);
        }
        if (! $o->install_task_id) { throw new OvhOrderHold('install_unknown', 'شناسهٔ عملیات نصب در دسترس نیست؛ بررسی دستی لازم است.'); }
        $task = $this->get($c, $o, $base.'/tasks/'.$o->install_task_id);
        if (($task['state'] ?? '') !== 'done') {
            if (in_array($task['state'] ?? '', ['error', 'cancelled'], true)) { throw new OvhOrderHold('install_failed', 'نصب اولیهٔ کلید دسترسی خطا دارد؛ خرید تکرار نمی‌شود.'); }
            return $this->wait($o);
        }
        $current = $this->get($c, $o, $base.'/images/current');
        if (($current['name'] ?? null) !== $o->spec['image_ref']) { throw new OvhOrderHold('installed_image_mismatch', 'ایمیج نصب‌شده با انتخاب مشتری یکسان نیست.'); }
        $r = $c->serverStatus($o->server_ref);
        if (! $r['ok'] || $r['status'] !== 'running' || ! filter_var($r['ipv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $this->wait($o);
        }
        $this->change($o, 'ready', ['ipv4' => $r['ipv4'], 'ipv6' => $r['ipv6'], 'ready_at' => now(), 'financial_state' => 'supplier_delivered', 'next_attempt_at' => null, 'error_code' => null, 'error_message' => null]);
        return $this->result($o);
    }

    private function verifyOrderPrice(OvhClient $c, OvhOrder $o): void
    {
        $body = $this->get($c, $o, '/me/order/'.$o->order_id);
        $gross = $body['priceWithTax'] ?? null;
        $net = $body['priceWithoutTax'] ?? null;
        if (! is_array($gross) || ! is_array($net)) { throw new OvhOrderHold('order_price_missing', 'مبلغ نهایی سفارش پیش از پرداخت خوانده نشد.'); }
        $grossMinor = OvhOrderFinance::minor($gross['value'] ?? null);
        $netMinor = OvhOrderFinance::minor($net['value'] ?? null);
        if ($grossMinor !== (int) $o->cost_minor || $grossMinor < $netMinor) {
            throw new OvhOrderHold('order_price_changed', 'مبلغ سفارش با پیش‌فاکتور تأییدشده یکسان نیست؛ پرداخت انجام نشد.');
        }
        $this->finance->validate($o, ['prices' => ['withTax' => $gross, 'withoutTax' => $net,
            'tax' => ['value' => ($grossMinor - $netMinor) / 100, 'currencyCode' => $gross['currencyCode'] ?? '']]],
            $o->quote['renewal'], $o->currency);
        if (! $o->payment_method_id) { throw new OvhOrderHold('payment_method_missing', 'روش پرداخت برای این سفارش ثبت نشده است.'); }
    }

    private function intent(OvhOrder $o, string $phase, string $timestamp): void
    {
        DB::transaction(function () use ($o, $phase, $timestamp): void {
            \App\Models\Customer::whereKey($o->spec['customer_id'])->lockForUpdate()->firstOrFail();
            $service = Service::whereKey($o->service_id)->lockForUpdate()->firstOrFail();
            Invoice::whereKey($o->invoice_id)->lockForUpdate()->firstOrFail();
            $this->finance->paidInvoice($service);
            if (! self::enabled()) { throw new OvhOrderHold('purchase_disabled', 'دروازهٔ عملیات زیرساخت خاموش است.'); }
            $this->change($o, $phase, [$timestamp => now()]);
        });
    }

    private function request(OvhClient $c, OvhOrder $o, string $method, string $path, array $payload = []): mixed
    {
        $this->assertLease($o);
        if ($method !== 'GET' && $o->service()->first()?->isDead()) { throw new OvhOrderHold('service_closed', 'سرویس لغو شده؛ عملیات جدید متوقف شد.'); }
        $r = $c->orderApi($method, $path, $payload);
        if (! $r['ok']) { throw new OvhOrderHold('api_'.$r['status'], 'ارتباط/مجوز API در مرحلهٔ '.$o->phase.' تأیید نشد (HTTP '.(int) $r['status'].').', $r['status'] === 0 || $r['status'] === 429 || $r['status'] === 408 || $r['status'] >= 500); }
        return $r['body'];
    }
    private function get(OvhClient $c, OvhOrder $o, string $path, array $payload = []): mixed { return $this->request($c, $o, 'GET', $path, $payload); }
    private function positiveId(mixed $id): bool { return (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0; }
    private function assertLease(OvhOrder $o): void
    {
        if (! OvhOrder::whereKey($o->id)->where('lease_token', $o->lease_token)->where('locked_until', '>', now())->exists()) {
            throw new OvhOrderHold('lease_lost', 'قفل عملیات در اختیار این پردازش نیست.');
        }
    }
    private function save(OvhOrder $o, array $values): void
    {
        $o->forceFill($values);
        $dirty = $o->getDirty();
        if ($dirty === []) { $this->assertLease($o); return; }
        $dirty['updated_at'] = now();
        $ok = OvhOrder::whereKey($o->id)->where('lease_token', $o->lease_token)->where('locked_until', '>', now())->update($dirty);
        if (! $ok) { throw new OvhOrderHold('lease_lost', 'قفل عملیات از دست رفت؛ نوشتن انجام نشد.'); }
        $o->refresh();
    }
    private function change(OvhOrder $o, string $phase, array $values = []): void
    {
        $this->save($o, ['phase' => $phase] + $values); $this->event($o, 'phase_changed', $phase);
    }
    public function event(OvhOrder $o, string $event, string $message = '', ?int $actor = null): void
    {
        $o->events()->create(['event' => $event, 'phase' => $o->phase, 'actor_id' => $actor, 'message' => mb_substr($message, 0, 500), 'created_at' => now()]);
    }
    private function hold(OvhOrder $o, string $code, string $message): array
    {
        $phase = in_array($code, ['checkout_unknown', 'payment_unknown', 'install_unknown'], true) ? $code : 'review';
        if (! OvhOrder::whereKey($o->id)->where('lease_token', $o->lease_token)->where('locked_until', '>', now())->exists()) {
            return $this->unavailable('lease_lost', 'پردازش دیگری مالک قفل است؛ این پردازش متوقف شد.');
        }
        $this->save($o, ['resume_phase' => in_array($o->phase, ['review', 'checkout_unknown', 'payment_unknown', 'install_unknown'], true) ? $o->resume_phase : $o->phase, 'phase' => $phase, 'error_code' => $code, 'error_message' => mb_substr($message, 0, 500), 'next_attempt_at' => null]);
        $this->event($o, 'manual_review', $code);
        ErrorTracker::noteOnce('ovh-order', 'سفارش OVH نیازمند بررسی است: '.$code, 900, ['service' => $o->service_id, 'order_journal' => $o->id]);
        if (! $o->alerted_at || $o->alerted_at->lt(now()->subHour())) {
            $this->save($o, ['alerted_at' => now()]);
            try { app(AdminNotifier::class)->event('سفارش OVH نیازمند بررسی', ['سرویس' => $o->service_id, 'شماره سفارش' => $o->order_id, 'کد' => $code], url('/admin/cloud/ovh')); }
            catch (\Throwable) { /* ErrorTracker remains the durable alert. */ }
        }
        return $this->result($o);
    }
    private function backoff(OvhOrder $o): int { return min(900, 30 * (2 ** min(5, max(0, $o->attempts - 1)))); }
    private function wait(OvhOrder $o): array
    {
        $this->save($o, ['next_attempt_at' => now()->addSeconds($this->backoff($o))]); return $this->result($o);
    }
    private function unavailable(string $code, string $message): array { return ['ok' => false, 'manual' => true, 'code' => $code, 'message' => $message, 'ref' => null]; }
    private function result(OvhOrder $o, ?string $hint = null): array
    {
        $review = in_array($o->customerState(), ['review', 'failed'], true);
        return ['ok' => ! $review, 'pending' => $o->phase !== 'ready' && ! $review, 'manual' => $review,
            'message' => $o->error_message ?: '', 'code' => $hint ?: $o->error_code, 'journal_id' => $o->id,
            'ref' => $o->server_ref ?: ($o->order_id ? 'order:'.$o->order_id : null),
            'status' => $o->phase === 'ready' ? 'running' : 'building', 'username' => $o->spec['username'],
            'ipv4' => $o->ipv4, 'ipv6' => $o->ipv6, 'root_password' => null];
    }
}
