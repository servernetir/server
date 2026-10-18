<?php

namespace App\Services\IdentityApi;

use App\Models\CustomerApiToken;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class Controls
{
    private ?bool $schemaReady = null;

    public function ready(): bool
    {
        return $this->schemaReady ??= Schema::hasTable('identity_requests') && Schema::hasTable('identity_controls')
            && Schema::hasTable('identity_prices') && Schema::hasTable('identity_applications')
            && Schema::hasColumn('customer_api_tokens', 'identity_application_id');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->ready()) {
            $value = DB::table('identity_controls')->where('key', $key)->value('value');
            if ($value !== null) {
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return config('identity_api.'.$key, $default);
    }

    public function put(string $key, mixed $value, int $actor): void
    {
        DB::transaction(function () use ($key, $value, $actor) {
            DB::table('identity_controls')->updateOrInsert(['key' => $key], ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_by' => $actor, 'updated_at' => now(), 'created_at' => now()]);
            $this->audit($actor, 'control', ['key' => $key, 'value' => $value]);
        });
    }

    public function audit(?int $actor, string $action, array $detail): void
    {
        DB::table('identity_audits')->insert(['actor_id' => $actor, 'action' => $action, 'detail' => json_encode($detail, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function dispatchEnabled(): bool
    {
        return $this->ready() && $this->runtimeReady() && $this->get('enabled', false) === true && filled($this->get('resale_evidence'))
            && $this->get('egress_verified', false) === true;
    }

    public function fundingReady(): bool
    {
        if ($this->ownerManagedFunding()) {
            return (int) $this->get('provider_daily_cap_irt', 0) > 0;
        }
        if ($this->get('funding_mode', 'verified_balance_v1') !== 'verified_balance_v1') {
            return false;
        }
        $at = $this->get('upstream_balance_verified_at');
        try {
            $date = $at ? Carbon::parse($at) : null;

            return $date && ! $date->isFuture()
                && $date->gte(now()->subMinutes((int) $this->get('balance_max_age_minutes', 60)))
                && (int) $this->get('provider_daily_cap_irt', 0) > 0
                && (int) $this->get('upstream_balance_irt', 0) > (int) $this->get('internal_kyc_reserve_irt', 0);
        } catch (\Throwable) {
            return false;
        }
    }

    /** An explicit owner policy, never a fabricated balance observation. */
    public function ownerManagedFunding(): bool
    {
        return $this->get('funding_mode', 'verified_balance_v1') === 'owner_managed_v1';
    }

    /** Guard partial deployments or stale web bytecode before any paid request. */
    public function runtimeReady(): bool
    {
        $parameters = array_map(fn ($p) => $p->getName(), (new \ReflectionMethod(Wallet::class, 'debit'))->getParameters());
        $legacy = new CustomerApiToken(['abilities' => ['*'], 'identity_application_id' => 1]);

        return in_array('excludingIdentityRequestId', $parameters, true)
            && defined(Engine::class.'::USAGE_POLICY') && Engine::USAGE_POLICY === 'available_wallet_v1'
            && defined(Engine::class.'::FUNDING_POLICY') && Engine::FUNDING_POLICY === 'owner_managed_v1'
            && method_exists(BusinessLedger::class, 'recordIdentitySale')
            && defined(CustomerApiToken::class.'::IDENTITY_ABILITIES')
            && ! $legacy->can('identity:execute');
    }
}
