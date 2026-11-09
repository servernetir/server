<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A registrar login is an account, not just a provider name. */
class DomainSupplierAccount extends Model
{
    protected $fillable = [
        'provider', 'environment', 'label', 'credentials', 'settings', 'legacy_config',
        'active', 'sales_enabled', 'contract_verified', 'identity_verified', 'pricing_verified',
        'connection_status', 'balance_minor', 'balance_currency', 'synced_at',
        'capabilities', 'health_checked_at',
    ];

    protected $hidden = ['credentials', 'settings'];

    /**
     * The owner's one-click profile for ordinary suffixes. It claims only what
     * both adapters implement for every suffix (create/renew, the three
     * management actions, no privacy add-on). Transfer is deliberately absent:
     * its resulting term differs by registry and must be stated per suffix.
     */
    public const STANDARD_PROFILE = [
        'operations' => ['create', 'renew'],
        'period_months' => [12, 24, 36, 48, 60, 72, 84, 96, 108, 120],
        'privacy' => ['none'],
        'management' => ['nameservers', 'lock', 'contacts'],
        'contact_creation' => 'standard',
        'idn' => false,
        'premium' => false,
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', 'settings' => 'array', 'capabilities' => 'array',
            'legacy_config' => 'boolean', 'active' => 'boolean', 'sales_enabled' => 'boolean',
            'contract_verified' => 'boolean', 'identity_verified' => 'boolean',
            'pricing_verified' => 'boolean', 'balance_minor' => 'integer',
            'synced_at' => 'datetime', 'health_checked_at' => 'datetime',
        ];
    }

    /** Readiness is evidence; it never accepts a contract or funds an account. */
    public function readinessIssues(bool $ignoreGlobalFlag = false): array
    {
        $issues = [];
        if (! $ignoreGlobalFlag && ! config('domain_suppliers.enabled', false)) {
            $issues[] = 'feature_disabled';
        }
        if (! $this->active) $issues[] = 'inactive';
        if ($this->environment !== 'production') $issues[] = 'test_environment';
        if (! $this->contract_verified) $issues[] = 'contract_unverified';
        if (! $this->identity_verified) $issues[] = 'identity_unverified';
        if (! $this->pricing_verified) $issues[] = 'pricing_unverified';
        if ($this->connection_status !== 'healthy' || ! $this->health_checked_at
            || $this->health_checked_at->isFuture()
            || $this->health_checked_at->lt(now()->subSeconds((int) config('domain_suppliers.health_max_age_seconds', 300)))) {
            $issues[] = 'connection_unverified';
        }
        if ($this->balance_minor === null || $this->balance_minor <= 0
            || ! preg_match('/^[A-Z]{3}$/D', (string) $this->balance_currency)) {
            $issues[] = 'balance_unknown';
        }
        return $issues;
    }

    /**
     * The single reader of per-suffix evidence. An explicit `tlds` entry wins
     * outright (no merge: a narrower owner statement must never be widened by
     * the default); otherwise `default` applies unless the suffix is listed in
     * `exclude`. Nothing here makes a suffix sellable on its own — the account
     * must still price it in its own catalogue or live quote.
     */
    public function tldCapabilities(string $tld): array
    {
        $caps = (array) $this->capabilities;
        $tld = strtolower(trim($tld, " \t."));
        if (is_array($caps['tlds'][$tld] ?? null)) return $caps['tlds'][$tld];
        if (! is_array($caps['default'] ?? null) || in_array($tld, (array) ($caps['exclude'] ?? []), true)) return [];
        return $caps['default'];
    }

    public function usesStandardProfile(): bool
    {
        // Key order is not evidence; a JSON column may hand the object back reordered.
        $default = ((array) $this->capabilities)['default'] ?? null;
        if (! is_array($default)) return false;
        $standard = self::STANDARD_PROFILE;
        ksort($default); ksort($standard);
        return $default === $standard;
    }

    public function saleEligible(): bool
    {
        return $this->sales_enabled && $this->readinessIssues() === [];
    }

    public function scopeEligibleForSales(Builder $query): Builder
    {
        if (! config('domain_suppliers.enabled', false)) return $query->whereRaw('1 = 0');
        return $query->where('active', true)->where('sales_enabled', true)
            ->where('environment', 'production')->where('contract_verified', true)
            ->where('identity_verified', true)->where('pricing_verified', true)
            ->where('connection_status', 'healthy')->where('balance_minor', '>', 0)
            ->whereNotNull('balance_currency')->where('health_checked_at', '<=', now())
            ->where('health_checked_at', '>=', now()->subSeconds((int) config('domain_suppliers.health_max_age_seconds', 300)));
    }
}
