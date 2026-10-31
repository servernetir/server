<?php

namespace App\Services\Domain;

use App\Models\DomainSupplierAccount;
use App\Services\Domain\Providers\DomainProviderFactory;
use Illuminate\Support\Facades\Cache;

/** Read-only authentication/balance snapshot. No capabilities or sales are inferred. */
class DomainSupplierProbe
{
    public function __construct(private DomainProviderFactory $factory) {}

    public function check(DomainSupplierAccount $account): bool
    {
        $lock = Cache::lock('domain-supplier-probe:'.$account->id, 120);
        if (! $lock->get()) return false;
        try {
            try {
                $probe = $this->factory->forAccount($account)->probe();
                $healthy = ($probe['healthy'] ?? false) === true;
                $balance = $probe['balance_minor'] ?? null;
                $currency = strtoupper((string) ($probe['currency'] ?? ''));
                $values = [
                    'connection_status' => $healthy ? 'healthy' : 'failed',
                    'balance_minor' => is_int($balance) && $balance >= 0 ? $balance : null,
                    'balance_currency' => preg_match('/^[A-Z]{3}$/D', $currency) ? $currency : null,
                    'health_checked_at' => now(), 'synced_at' => now(),
                ];
                if (! $healthy) $values['sales_enabled'] = false;
                $account->forceFill($values)->save();
                return $healthy;
            } catch (\Throwable) {
                $account->forceFill(['connection_status' => 'failed', 'health_checked_at' => now(),
                    'balance_minor' => null, 'balance_currency' => null, 'sales_enabled' => false])->save();
                return false;
            }
        } finally {
            $lock->release();
        }
    }
}
