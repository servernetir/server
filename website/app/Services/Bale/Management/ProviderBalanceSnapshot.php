<?php

namespace App\Services\Bale\Management;

use App\Services\Cloud\CloudManager;
use App\Services\Cloud\OvhClient;
use App\Services\Cloud\VpsTcApi;
use App\Services\Cloud\VpsTcMoneyContract;
use App\Services\Domain\OpenProviderClient;
use Illuminate\Support\Facades\Cache;

/** Manager-only account observations. This never buys, pays, renews or changes a service. */
class ProviderBalanceSnapshot
{
    public const FRESH_SECONDS = 21600;
    private const RETAIN_SECONDS = 604800;

    public function __construct(private OpenProviderClient $openprovider, private VpsTcApi $vpstc, private OvhClient $ovh) {}

    /**
     * Every provider remains visible, including those whose connected API has no
     * reviewed account-balance adapter. Unknown is never converted to zero.
     *
     * @return array<string,array{provider:string,label:string,status:string,value:?string,currency:?string,observed_at:?string,checked_at:string,reason:?string,cached:bool,age_seconds:?int,stale:bool,value_kind:?string}>
     */
    public function snapshot(bool $refresh = false): array
    {
        $rows = [];
        foreach (['openprovider' => 'Openprovider'] + CloudManager::REAL_NAMES as $provider => $label) {
            $rows[$provider] = match ($provider) {
                'openprovider', 'vpstc', 'ovh' => $this->readSupported($provider, $label, $refresh),
                'proxmox' => $this->unknown($provider, $label, 'not_applicable'),
                default => $this->unknown($provider, $label, 'balance_api_not_integrated'),
            };
        }

        return $rows;
    }

    /**
     * Display-only projection for previews and detail buttons. No provider query,
     * refresh lock or observation-cache write. Existing settings read memoization
     * belongs to the clients; expired observations are never presented as current.
     */
    public function cachedSnapshot(): array
    {
        $rows = [];
        foreach (['openprovider' => 'Openprovider'] + CloudManager::REAL_NAMES as $provider => $label) {
            if (! in_array($provider, ['openprovider', 'vpstc', 'ovh'], true)) {
                $rows[$provider] = $this->unknown($provider, $label,
                    $provider === 'proxmox' ? 'not_applicable' : 'balance_api_not_integrated');
                continue;
            }
            try {
                [$configured, $identity] = $this->accountContext($provider);
                if (! $configured) {
                    $rows[$provider] = $this->unknown($provider, $label, 'not_configured');
                    continue;
                }
                $cached = Cache::get($this->cacheKey($provider, $identity));
                $rows[$provider] = $this->validCached($cached, $provider)
                    ? $this->decorate($cached, true)
                    : $this->unknown($provider, $label, is_array($cached) ? 'observation_expired' : 'no_observation');
            } catch (\Throwable) {
                $rows[$provider] = $this->unknown($provider, $label, 'balance_unavailable');
            }
        }

        return $rows;
    }

    /** Only sanitized, dated projections are cached; raw responses and credentials never leave adapters. */
    private function readSupported(string $provider, string $label, bool $refresh): array
    {
        try {
            [$configured, $identity] = $this->accountContext($provider);
            if (! $configured) { return $this->unknown($provider, $label, 'not_configured'); }
            $key = $this->cacheKey($provider, $identity);
            $cached = Cache::get($key);
            if (! $refresh && $this->validCached($cached, $provider)) {
                return $this->decorate($cached, true);
            }
            $lock = Cache::lock($key.':refresh', 180);
            if (! $lock->get()) { return $this->unknown($provider, $label, 'refresh_in_progress'); }
            try {
                // Another worker may have refreshed while this worker obtained its lock.
                $cached = Cache::get($key);
                if (! $refresh && $this->validCached($cached, $provider)) {
                    return $this->decorate($cached, true);
                }
                $row = match ($provider) {
                    'openprovider' => $this->openprovider($label),
                    'ovh' => $this->ovh($label),
                    default => $this->vpstc($label),
                };
                Cache::put($key, $row, now()->addSeconds(self::RETAIN_SECONDS));

                return $this->decorate($row, false);
            } finally { $lock->release(); }
        } catch (\Throwable) {
            // Exception text can contain a signed URL or credentials. Never publish it.
            return $this->unknown($provider, $label, 'balance_unavailable');
        }
    }

    private function openprovider(string $label): array
    {
        $balance = $this->openprovider->managerBalance();
        if (($balance['known'] ?? false) !== true) {
            return $this->unknown('openprovider', $label, 'balance_unavailable');
        }

        return $this->known('openprovider', $label, $balance['amount'] ?? null,
            $balance['currency'] ?? null, 'reported_account_balance');
    }

    private function vpstc(string $label): array
    {
        $balance = $this->vpstc->balance();
        $details = $balance['data']['details'] ?? null;
        if (($balance['ok'] ?? false) !== true || ! is_array($details)) {
            return $this->unknown('vpstc', $label, 'balance_unavailable');
        }
        // acc_credit is spendable account credit. acc_balance is not a substitute.
        // A blank currency needs the client's account-bound financial evidence;
        // never infer TRY/USD from a saved label or today's exchange rate.
        $currency = (new VpsTcMoneyContract($this->vpstc))->resolveCurrency($balance);

        return $this->known('vpstc', $label, $details['acc_credit'] ?? null,
            $currency, 'available_account_credit');
    }

    private function ovh(string $label): array
    {
        $balance = $this->ovh->accountBalance();
        if (($balance['known'] ?? false) !== true) {
            $reason = in_array($balance['reason'] ?? null,
                ['no_prepaid_account', 'ambiguous_accounts', 'prepaid_account_inactive'], true)
                ? $balance['reason'] : 'balance_unavailable';

            return $this->unknown('ovh', $label, $reason);
        }

        return $this->known('ovh', $label, $balance['amount'] ?? null,
            $balance['currency'] ?? null, 'reported_prepaid_account_balance');
    }

    private function known(string $provider, string $label, mixed $amount, mixed $currency, string $kind): array
    {
        $value = $this->decimal($amount);
        $currency = is_string($currency) ? strtoupper(trim($currency)) : '';
        if ($value === null) { return $this->unknown($provider, $label, 'amount_unverified'); }
        if (! in_array($currency, ['USD', 'EUR', 'USDT', 'TRY', 'IRR', 'IRT', 'GBP', 'CHF', 'CAD', 'AUD', 'RUB'], true)) {
            return $this->unknown($provider, $label, 'currency_unverified');
        }
        $at = now()->toIso8601String();

        return ['provider' => $provider, 'label' => $label, 'status' => 'known',
            'value' => $value, 'currency' => $currency, 'observed_at' => $at,
            'checked_at' => $at, 'reason' => null, 'value_kind' => $kind];
    }

    private function unknown(string $provider, string $label, string $reason): array
    {
        return ['provider' => $provider, 'label' => $label, 'status' => 'unknown',
            'value' => null, 'currency' => null, 'observed_at' => null,
            'checked_at' => now()->toIso8601String(), 'reason' => $reason,
            'cached' => false, 'age_seconds' => null, 'stale' => false, 'value_kind' => null];
    }

    private function decimal(mixed $amount): ?string
    {
        if (is_float($amount)) {
            if (! is_finite($amount) || abs($amount) > 999999999999999) { return null; }
            // Legacy Openprovider::balance returns float. This is a dated display
            // projection only; it is never used for a ledger or a purchase decision.
            $amount = number_format($amount, 8, '.', '');
        }
        if (! is_int($amount) && ! is_string($amount)) { return null; }
        $amount = trim((string) $amount);
        if (! preg_match('/\A-?(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,8})?\z/D', $amount)) { return null; }
        if (str_contains($amount, '.')) { $amount = rtrim(rtrim($amount, '0'), '.'); }

        return in_array($amount, ['-0', ''], true) ? '0' : $amount;
    }

    private function validCached(mixed $row, string $provider): bool
    {
        if (! is_array($row) || ($row['provider'] ?? null) !== $provider
            || ! in_array($row['status'] ?? null, ['known', 'unknown'], true)
            || ! is_string($row['checked_at'] ?? null)) { return false; }
        try { $age = now()->timestamp - \Illuminate\Support\Carbon::parse($row['checked_at'])->timestamp; }
        catch (\Throwable) { return false; }

        return $age >= 0 && $age < self::FRESH_SECONDS;
    }

    private function decorate(array $row, bool $cached): array
    {
        $age = max(0, now()->timestamp - \Illuminate\Support\Carbon::parse($row['checked_at'])->timestamp);

        return array_replace($row, ['cached' => $cached, 'age_seconds' => $age,
            'stale' => $age >= self::FRESH_SECONDS]);
    }

    private function cacheKey(string $provider, string $identity): string
    {
        // v2 drops observations made before the Openprovider read token was
        // bound to its account; their provenance cannot be repaired in place.
        return 'bale:manager:provider-balance:v2:'.$provider.':'.$identity;
    }

    /** @return array{bool,string} */
    private function accountContext(string $provider): array
    {
        return match ($provider) {
            'openprovider' => [$this->openprovider->enabled(), $this->openprovider->accountFingerprint()],
            'ovh' => [$this->ovh->isConfigured(), $this->ovh->accountFingerprint()],
            default => [$this->vpstc->isConfigured(), $this->vpstc->accountFingerprint()],
        };
    }
}
