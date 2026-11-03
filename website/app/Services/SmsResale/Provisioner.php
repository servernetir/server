<?php

namespace App\Services\SmsResale;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\SmsSubscription;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Provisioner
{
    public function __construct(private Controls $controls, private IppanelClient $client) {}

    public static function handles(Service $service): bool
    {
        return str_starts_with((string) $service->plan, 'sms:');
    }

    public function provision(Service $service): bool
    {
        if (! $this->controls->runtime() || ! self::handles($service)) { return false; }
        $lock = Cache::lock('sms-resale:service:'.$service->id, 180);
        if (! $lock->get()) { return false; }
        try {
            $service->refresh();
            $s = SmsSubscription::where('service_id', $service->id)->first();
            if (! $s || $service->status === 'suspended' || $service->isDead() || (int) $s->customer_id !== (int) $service->customer_id) { return false; }
            $invoice = $s->pending_invoice_id ? Invoice::find($s->pending_invoice_id) : null;
            if ($s->pending_invoice_id && (! $invoice || $invoice->status !== 'paid'
                || (int) $invoice->customer_id !== (int) $s->customer_id || $invoice->paid < $invoice->total)) {
                $s->update(['checked_at' => now()]);
                if ($s->pending_plan && $invoice && in_array($invoice->status, ['cancelled', 'expired'], true)) {
                    $s->update(['pending_plan' => null, 'pending_quote' => null, 'pending_invoice_id' => null]);
                }
                return false;
            }
            if (! in_array($service->status, ['active', 'awaiting_provision', 'suspended'], true)) { return false; }
            $quote = $s->pending_plan ? $s->pending_quote : $s->quote;
            $plan = $quote['plan'] ?? [];
            if ((int) ($quote['parent_id'] ?? 0) !== (int) $this->controls->get('parent_id')
                || (int) ($plan['acl_id'] ?? 0) <= 0) { throw new ProviderError('binding_mismatch', true); }
            if ($s->plan === 'start' && ! $s->pending_plan && $service->next_due_at?->isPast()) {
                $service->update(['next_due_at' => now()->addYear()]);
            }
            $until = $service->next_due_at?->format('Y-m-d');
            if (! $until || ! $service->next_due_at->isFuture()) { $s->update(['checked_at' => now()]); return false; }
            $marker = 'servernet:sms:'.$s->operation_id;

            if (! $s->upstream_user_id) {
                $rows = $this->client->users($s->username);
                if (! $s->attempted_at && $rows !== []) { throw new ProviderError('binding_mismatch', true); }
                if (! $s->attempted_at) {
                    // Persist intent before the external write; a crash must never trigger another create.
                    $s->update(['attempted_at' => now(), 'state' => 'reconciling', 'error_code' => null]);
                    try {
                        $this->client->create(['user_name' => $s->username, 'password' => $s->initial_password,
                            'national_code' => $s->identity['national_code'], 'mobile_number' => $s->identity['mobile_number'],
                            'birth_date' => $s->identity['birth_date'], 'acl_id' => $plan['acl_id'],
                            'description' => $marker, 'expire_time' => $until]);
                    } catch (ProviderError $e) {
                        if ($e->definite) { throw $e; }
                        // Read/reconcile if the create response was lost.
                    }
                    $rows = $this->client->users($s->username);
                }
                if (count($rows) !== 1) { throw new ProviderError('unknown'); }
                $this->assertBinding($s, $rows[0], $marker);
                $s->update(['upstream_user_id' => (int) $rows[0]['user_id']]);
            }
            $user = $this->client->show($s->upstream_user_id);
            $this->assertBinding($s, $user, $marker);
            if ((int) ($user['acl_role_id'] ?? 0) !== (int) $plan['acl_id'] || ($user['expire_time'] ?? null) !== $until) {
                // Absolute ACL/expiry assignment; the next attempt reads first after any uncertainty.
                $this->client->update($s->upstream_user_id, ['acl_id' => (int) $plan['acl_id'], 'expire_time' => $until]);
                $user = $this->client->show($s->upstream_user_id);
                $this->assertBinding($s, $user, $marker);
                if ((int) ($user['acl_role_id'] ?? 0) !== (int) $plan['acl_id'] || ($user['expire_time'] ?? null) !== $until) {
                    throw new ProviderError('unknown');
                }
            }
            $active = ($user['status'] ?? null) === 'active' && ($user['document_block'] ?? null) === 'no'
                && ($user['send_block'] ?? null) === 'no';
            // Display-only snapshot, never used to settle a financial operation.
            $credit = data_get($user, 'credit.credit');
            $credit = is_numeric($credit) && BigDecimal::of((string) $credit)->isGreaterThanOrEqualTo(0)
                ? (string) BigDecimal::of((string) $credit)->toScale(6, RoundingMode::Down) : null;
            DB::transaction(function () use ($s, $service, $quote, $plan, $until, $credit, $active) {
                $fresh = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
                if ($fresh->isDead()) { return; }
                $s->update(['plan' => $plan['key'], 'quote' => $quote, 'pending_plan' => null, 'pending_quote' => null,
                    'pending_invoice_id' => null, 'state' => $active ? 'active' : 'awaiting_review',
                    'error_code' => null, 'confirmed_until' => $until, 'credit_irr' => $credit, 'checked_at' => now()]);
                $fresh->update(['status' => $active ? 'active' : 'awaiting_provision', 'provision_status' => $active ? 'done' : 'pending',
                    'provisioned_at' => $active ? ($fresh->provisioned_at ?? now()) : $fresh->provisioned_at,
                    'activated_at' => $fresh->activated_at ?? now(), 'provision_error' => null,
                    'price' => $plan['renew_irt'], 'cycle' => $plan['renew_irt'] === 0 ? 'once' : 'yearly',
                    'plan' => 'sms:'.$plan['key']]);
            });
            if ($service->fresh()->isDead()) {
                $this->client->update($s->upstream_user_id, ['status' => 'inactive']);
                $stopped = $this->client->show($s->upstream_user_id);
                $this->assertBinding($s, $stopped, $marker);
                if (($stopped['status'] ?? null) !== 'inactive') { throw new ProviderError('unknown'); }
                $s->update(['state' => 'suspended', 'checked_at' => now()]);
                return false;
            }
            return $active;
        } catch (ProviderError $e) {
            if (isset($s) && $s) {
                $s->update(['state' => $e->definite ? 'needs_attention' : 'reconciling', 'error_code' => $e->reason, 'checked_at' => now()]);
            }
            return false;
        } catch (\Throwable) {
            if (isset($s) && $s) { $s->update(['state' => 'reconciling', 'error_code' => 'internal_error', 'checked_at' => now()]); }
            return false;
        } finally { $lock->release(); }
    }

    public function changeAccess(Service $service, bool $enable): \App\Services\Provisioning\ProvisionResult
    {
        $result = \App\Services\Provisioning\ProvisionResult::class;
        if (! $this->controls->runtime()) { return $result::fail(__('sms.connection_failed')); }
        $lock = Cache::lock('sms-resale:service:'.$service->id, 180);
        if (! $lock->get()) { return $result::fail(__('sms.pending')); }
        try {
            $s = SmsSubscription::where('service_id', $service->id)->where('customer_id', $service->customer_id)->firstOrFail();
            if (! $s->upstream_user_id) {
                return ! $enable && ! $s->attempted_at ? $result::success(null, null, null) : $result::fail(__('sms.pending'));
            }
            $user = $this->client->show($s->upstream_user_id);
            $this->assertBinding($s, $user, 'servernet:sms:'.$s->operation_id);
            if ($enable && ($service->fresh()->isDead() || ! $service->next_due_at?->isFuture()
                || ($user['document_block'] ?? null) !== 'no' || ($user['send_block'] ?? null) !== 'no')) {
                return $result::fail(__('sms.pending'));
            }
            $wanted = $enable ? 'active' : 'inactive';
            $assignment = ['status' => $wanted];
            if ($enable) { $assignment['expire_time'] = $service->next_due_at->format('Y-m-d'); }
            $this->client->update($s->upstream_user_id, $assignment);
            $verified = $this->client->show($s->upstream_user_id);
            $this->assertBinding($s, $verified, 'servernet:sms:'.$s->operation_id);
            if (($verified['status'] ?? null) !== $wanted || ($enable && ($verified['expire_time'] ?? null) !== $service->next_due_at->format('Y-m-d'))) { throw new ProviderError('unknown'); }
            $s->update(['state' => $enable ? 'active' : 'suspended', 'checked_at' => now(), 'error_code' => null]);
            if (! $service->fresh()->isDead()) { $service->update(['status' => $enable ? 'active' : 'suspended']); }
            // Closing access retains the upstream account, credit and message history.
            return $result::success(null, null, null);
        } catch (\Throwable) { return $result::fail(__('sms.pending')); }
        finally { $lock->release(); }
    }

    private function assertBinding(SmsSubscription $s, array $user, string $marker): void
    {
        $parent = $user['parent_id'] ?? (is_array($user['parent'] ?? null) ? $user['parent']['user_id'] ?? 0 : $user['parent'] ?? 0);
        $phone = preg_replace('/\D/', '', (string) ($user['mobile'] ?? ''));
        $expectedPhone = preg_replace('/\D/', '', $s->identity['mobile_number']);
        if (($user['uname'] ?? null) !== $s->username || (int) ($user['user_id'] ?? 0) <= 0
            || (int) $parent !== (int) $s->quote['parent_id']
            || (string) ($user['national_id'] ?? '') !== $s->identity['national_code']
            || strlen($phone) < 10 || substr($phone, -10) !== substr($expectedPhone, -10)
            || ($user['description'] ?? $user['user_description'] ?? null) !== $marker
            || ($s->upstream_user_id && (int) $user['user_id'] !== $s->upstream_user_id)) {
            throw new ProviderError('binding_mismatch', true);
        }
    }
}
