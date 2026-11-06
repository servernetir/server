<?php

namespace App\Services\Hermes;

use App\Models\ActivityLog;
use App\Models\Service;
use App\Services\Finance\Wallet;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/** Account/lifecycle observations only. This class never invokes a billing or power action. */
final class HermesServiceInvestigation
{
    public function read(Service $service): array
    {
        $base = ['service_id' => (int) $service->id, 'customer_id' => (int) $service->customer_id,
            'source' => 'servernet_database_and_wallet', 'checked_at' => time(), 'result' => 'unavailable',
            'scope' => 'read_only', 'changes_performed' => false];
        try {
            return DB::transaction(function () use ($service, $base): array {
            $current = Service::query()->whereKey($service->id)->where('customer_id', $service->customer_id)->first();
            if (! $current) { return $base; }
            $wallet = null;
            if ($current->billing_mode === 'hourly') {
                $reader = app(Wallet::class);
                $balance = $reader->balanceOf((int) $current->customer_id, 'IRT');
                $reserved = $reader->reservedOf((int) $current->customer_id, 'IRT');
                $wallet = ['unit' => 'IRT', 'balance' => $balance, 'reserved' => $reserved, 'available' => $balance - $reserved];
            }
            $events = Schema::hasTable('activity_logs') ? ActivityLog::query()->where('service_id', $current->id)
                ->where('customer_id', $current->customer_id)->where('action', 'suspend')->latest('id')->limit(3)
                ->get(['actor', 'description', 'created_at'])->map(fn ($e) => ['actor' => $e->actor,
                    'description' => $e->description, 'at' => $e->created_at?->timestamp])->all() : [];
            $result = array_replace($base, self::project([
                'status' => $current->status, 'billing_mode' => $current->billing_mode,
                'hourly_rate_irt' => $current->hourly_rate_irt, 'hold_reserve_irt' => $current->hold_reserve_irt,
                'provision_meta' => $current->provision_meta ?? [],
            ], $wallet, $events));
            $result['ledger_history'] = ['available' => false, 'unit' => 'IRT'];
            if ($current->billing_mode === 'hourly' && Schema::hasTable('credit_ledger')) {
                $query = DB::table('credit_ledger')->where('customer_id', $current->customer_id)->where('currency_code', 'IRT');
                $credits = (clone $query)->where('reason', 'topup')->latest('id')->limit(3)->get(['id', 'amount', 'created_at']);
                $debits = (clone $query)->where('source_type', $current->getMorphClass())->where('source_id', $current->id)
                    ->whereIn('reason', ['cloud_hourly', 'cloud_hourly_hold'])->latest('id')->limit(4)->get(['id', 'amount', 'reason', 'created_at']);
                $result['ledger_history'] = ['available' => true, 'unit' => 'IRT',
                    'recent_account_topups' => $credits->map(fn ($r) => ['entry_id' => (int) $r->id, 'amount_irt' => (int) $r->amount, 'at' => $r->created_at])->all(),
                    'recent_service_debits' => $debits->map(fn ($r) => ['entry_id' => (int) $r->id, 'amount_irt' => (int) $r->amount, 'reason' => $r->reason, 'at' => $r->created_at])->all(),
                    'scope' => 'account_topups_and_exact_service_meter_debits', 'proves_service_activation' => false];
            }
            return $result;
            });
        } catch (\Throwable) { return $base + ['reason' => 'account_evidence_unavailable']; }
    }

    public static function project(array $service, ?array $wallet, array $events): array
    {
        $meta = $service['provision_meta'] ?? [];
        $held = ($meta['hourly_billing_hold']['active'] ?? false) === true;
        $manual = ! empty($meta['staff_suspend']);
        $cause = 'not_established'; $causeAt = null;
        if (($service['status'] ?? '') === 'suspended') {
            if ($held) { $cause = 'operator_billing_hold_recorded'; }
            elseif ($manual) { $cause = 'staff_suspension_recorded'; }
            else {
                foreach ($events as $event) {
                    if (($event['actor'] ?? '') === 'system' && is_int($event['at'] ?? null)
                        && preg_match('/اتمام اعتبار ساعتی/u', (string) ($event['description'] ?? ''))) {
                        $cause = 'hourly_credit_out_recorded'; $causeAt = $event['at']; break;
                    }
                }
            }
        }
        $result = ['result' => 'verified', 'database_status' => in_array($service['status'] ?? '',
            ['active', 'suspended', 'pending', 'cancelled'], true) ? $service['status'] : 'unknown',
            'cause_code' => $cause, 'cause_recorded_at' => $causeAt, 'operator_billing_hold' => $held,
            'staff_suspension' => $manual, 'wallet' => null,
            'current_wallet_explains_credit_gate' => false, 'power_action_authorized' => false,
            'financial_action_authorized' => false];
        if ($held) { $result['supplier_report_status'] = 'not_checked'; }
        elseif ($cause === 'hourly_credit_out_recorded') { $result['supplier_report_status'] = 'not_part_of_recorded_credit_gate'; }
        $result['hold_reason_category'] = $held && str_contains((string) ($meta['hourly_billing_hold']['reason'] ?? ''), 'provider VM unreachable')
            ? 'recorded_provider_incident_pending_verified_recovery' : ($held ? 'recorded_hold_reason_not_projected' : 'no_operator_hold_recorded');
        $result['hold_reason_is_historical'] = true;
        if (($service['billing_mode'] ?? '') !== 'hourly' || ($wallet['unit'] ?? '') !== 'IRT'
            || ! is_int($wallet['balance'] ?? null) || ! is_int($wallet['reserved'] ?? null)
            || ! is_int($wallet['available'] ?? null) || $wallet['reserved'] < 0) { return $result; }
        $rate = $service['hourly_rate_irt'] ?? null; $reserve = $service['hold_reserve_irt'] ?? null;
        $result['wallet'] = ['unit' => 'IRT', 'ledger_balance_irt' => $wallet['balance'],
            'reserved_total_irt' => $wallet['reserved'], 'available_irt' => $wallet['available'],
            'service_retention_reserve_irt' => is_int($reserve) && $reserve >= 0 ? $reserve : null,
            'hourly_rate_irt' => is_int($rate) && $rate > 0 ? $rate : null];
        $result['current_wallet_explains_credit_gate'] = is_int($rate) && $rate > 0 && $wallet['available'] < $rate;
        // A positive total is never described as zero just because reservations reduce spendable credit.
        $result['total_wallet_is_zero'] = $wallet['balance'] === 0;
        $result['retention_reserve_limits_available_credit'] = $wallet['balance'] > 0 && is_int($reserve)
            && $reserve > 0 && $result['current_wallet_explains_credit_gate'];
        return $result;
    }
}
