<?php

namespace App\Services\Domain;

use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\Invoice;
use App\Models\DomainSupplierAccount;
use App\Models\Setting;
use App\Services\Domain\Pricing\DomainMoney;
use App\Services\Domain\Pricing\DomainProcurementException;
use App\Services\Domain\Providers\DomainProviderFactory;
use App\Services\Domain\Providers\DomainProviderException;
use App\Services\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes are operation/term/account specific. Only create compares accounts;
 * existing assets never change supplier as a side effect of a cheaper price.
 */
class DomainProcurement
{
    public function __construct(private DomainProviderFactory $providers, private ExchangeRate $fx) {}

    public function enabled(): bool
    {
        return (bool) config('domain_suppliers.enabled', false)
            && Schema::hasTable('domain_supplier_accounts')
            && Schema::hasColumn('domain_quotes', 'supplier_account_id');
    }

    public function quote(string $fqdn, string $operation, int $months, array $options = [], ?Domain $bound = null, ?int $customerId = null): DomainQuote
    {
        if (! $this->enabled()) {
            throw new DomainProcurementException('supplier_feature_disabled');
        }
        [$fqdn, $tld] = $this->request($fqdn, $operation, $months);
        $options = $this->options($options);
        if ($operation === 'transfer') {
            throw new DomainProcurementException('explicit_transfer_destination_required');
        }
        if ($operation !== 'create' && $bound === null) {
            throw new DomainProcurementException('supplier_binding_required');
        }
        if ($bound !== null && strtolower((string) idn_to_ascii($bound->domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)) !== $fqdn) {
            throw new DomainProcurementException('supplier_binding_mismatch');
        }
        $accounts = DomainSupplierAccount::query()->where('active', true)->where('environment', 'production');
        if ($bound !== null) {
            if (! $bound->supplier_account_id) {
                throw new DomainProcurementException('supplier_binding_required');
            }
            $accounts->whereKey($bound->supplier_account_id);
        }
        $best = null;
        $failures = [];
        $unavailable = 0;
        foreach ($accounts->orderBy('id')->get() as $account) {
            try {
                $candidate = $this->candidate($account, $fqdn, $tld, $operation, $months, $options, $bound);
                if ($candidate['available'] === false && $operation === 'create') {
                    $unavailable++;
                    continue;
                }
                // Compare landed costs in the same currency (IRT), with the
                // same current rate snapshot. Deterministic account-id ties.
                if ($best === null || $this->cheaper($candidate, $best)) {
                    $best = $candidate;
                }
            } catch (\Throwable $e) {
                $reason = $e instanceof DomainProcurementException || $e instanceof DomainProviderException
                    ? $e->reason : 'supplier_quote_failed';
                $failures[] = ['account_id' => $account->id, 'reason' => $reason];
                Log::notice('Domain supplier quote excluded', ['account_id' => $account->id, 'reason' => $reason]);
            }
        }
        if ($best === null) {
            // An authentication/price error must never become a taken result.
            throw new DomainProcurementException($unavailable > 0 && $failures === []
                ? 'domain_unavailable' : 'no_eligible_supplier');
        }
        return $this->persist($fqdn, $tld, $operation, $months, $best, $options, $failures,
            $bound === null ? 'lowest_eligible_landed_cost' : 'bound_supplier_account',
            $bound ? (int) $bound->customer_id : $customerId);
    }

    /** A transfer has an explicitly chosen destination; it is never a renewal or an automatic switch. */
    public function quoteTransfer(string $fqdn, int $months, DomainSupplierAccount $destination,
        array $options = [], ?int $customerId = null, ?Domain $pendingTransfer = null): DomainQuote
    {
        if (! $this->enabled()) {
            throw new DomainProcurementException('supplier_feature_disabled');
        }
        [$fqdn, $tld] = $this->request($fqdn, 'transfer', $months);
        $options = $this->options($options);
        // A locally managed asset has a separate transfer authorization flow.
        // This incoming-transfer quote cannot silently move it to a cheaper account.
        if ($pendingTransfer && (! $pendingTransfer->isTransfer()
            || $pendingTransfer->status !== Domain::STATUS_TRANSFERRING
            || strtolower((string) idn_to_ascii($pendingTransfer->domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)) !== $fqdn
            || (int) $pendingTransfer->supplier_account_id !== (int) $destination->id
            || filled($pendingTransfer->supplier_object_id))) {
            throw new DomainProcurementException('existing_domain_transfer_requires_review');
        }
        if (Domain::whereIn('domain', array_unique([$fqdn, (string) idn_to_utf8($fqdn, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)]))->get()->contains(fn ($domain) => ! $domain->isDead()
            && (! $pendingTransfer || (int) $domain->id !== (int) $pendingTransfer->id))) {
            throw new DomainProcurementException('existing_domain_transfer_requires_review');
        }
        $destination = $destination->fresh() ?? throw new DomainProcurementException('supplier_binding_required');
        $candidate = $this->candidate($destination, $fqdn, $tld, 'transfer', $months, $options, null, true);
        return $this->persist($fqdn, $tld, 'transfer', $months, $candidate, $options, [],
            'explicit_transfer_destination', $customerId);
    }

    private function persist(string $fqdn, string $tld, string $operation, int $months, array $best,
        array $options, array $failures, string $reason, ?int $customerId): DomainQuote
    {
        $expiry = now()->addSeconds(max(1, (int) config('domain_suppliers.quote_ttl_seconds', 900)));
        if (isset($best['valid_until'])) {
            $expiry = $expiry->min(Carbon::parse($best['valid_until']));
        }
        if (! $expiry->isFuture()) {
            throw new DomainProcurementException('supplier_quote_expired');
        }
        return DomainQuote::create([
            'domain' => $fqdn, 'tld' => $tld, 'registrar' => $best['provider'],
            'supplier_account_id' => $best['account_id'], 'operation' => $operation,
            'period_months' => $months, 'purchase_minor' => $best['amount_minor'], 'currency' => $best['currency'],
            'cost_amount' => $best['amount_minor'], 'cost_currency' => $best['currency'],
            'is_premium' => $best['premium'], 'sell_toman' => $best['sell_toman'], 'renew_toman' => null,
            'honour_until' => $expiry, 'fx_snapshot' => $best['fx_snapshot'],
            'cost_components' => $best['components'],
            'selection_reason' => $reason, 'customer_id' => $customerId && $customerId > 0 ? $customerId : null,
            // The private immutable snapshot includes exclusions, never raw API/PII.
            'raw' => ['supplier_quote' => $best, 'options' => $options, 'excluded' => $failures],
        ]);
    }

    public function quoteForDomain(Domain $domain, string $operation, int $months): DomainQuote
    {
        $options = (array) data_get($domain->supplier_quote, 'options', []);
        // Never turn off previously selected privacy merely because an old
        // imported domain has no normalized quote snapshot yet.
        if ($domain->whois_privacy && (! isset($options['privacy']) || $options['privacy'] === 'none')) {
            throw new DomainProcurementException('privacy_selection_required');
        }
        return $this->quote($domain->domain, $operation, $months, $options, $domain);
    }

    /** Public invoice shape plus a private supplier snapshot for persistence. */
    public function snapshot(DomainQuote $quote): array
    {
        $source = (array) data_get($quote->raw, 'supplier_quote', []);
        if ($source === [] || ! $quote->supplier_account_id) {
            throw new DomainProcurementException('supplier_quote_missing');
        }
        return $source + [
            'supplier_provider' => $quote->registrar,
            'supplier_account_id' => (int) $quote->supplier_account_id, 'quote_id' => (int) $quote->id,
            'operation' => $quote->operation, 'period_months' => (int) $quote->period_months,
            'years' => intdiv((int) $quote->period_months, 12), 'cost_amount' => (int) $quote->purchase_minor,
            'net_amount' => null, 'quoted_at' => $quote->created_at->toIso8601String(),
            'honour_until' => $quote->honour_until->toIso8601String(),
            'options' => (array) data_get($quote->raw, 'options', []),
        ];
    }

    /** No account switch, changed term, or price increase is accepted here. */
    public function revalidate(DomainQuote $quote): DomainQuote
    {
        $this->validatedSnapshot($quote);
        return $quote;
    }

    public function revalidateDomain(Domain $domain, string $operation, int $months, ?Invoice $invoice = null): array
    {
        $stored = (array) ($invoice ? $invoice->supplier_quote : $domain->supplier_quote);
        if ($invoice && ((int) $invoice->domain_id !== (int) $domain->id
            || (int) $invoice->customer_id !== (int) $domain->customer_id
            || (int) $invoice->supplier_account_id !== (int) $domain->supplier_account_id)) {
            throw new DomainProcurementException('supplier_invoice_mismatch');
        }
        $id = (int) ($stored['quote_id'] ?? 0);
        $quote = $id ? DomainQuote::find($id) : null;
        if (! $quote || (int) $quote->supplier_account_id !== (int) $domain->supplier_account_id
            || strtolower($quote->domain) !== strtolower((string) idn_to_ascii($domain->domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46))
            || $quote->operation !== $operation || (int) $quote->period_months !== $months
            || ($quote->customer_id !== null && (int) $quote->customer_id !== (int) $domain->customer_id)) {
            throw new DomainProcurementException('supplier_quote_mismatch');
        }
        $fresh = $this->validatedSnapshot($quote, $domain);
        // Keep the agreed cap and original quote identity; latest evidence is
        // attached separately and must never raise an existing paid invoice.
        return $this->snapshot($quote) + ['revalidated' => $fresh, 'revalidated_at' => now()->toIso8601String()];
    }

    private function validatedSnapshot(DomainQuote $quote, ?Domain $bound = null): array
    {
        if (! $this->enabled() || ! $quote->isHonourable()) {
            throw new DomainProcurementException('supplier_quote_expired');
        }
        $old = $this->snapshot($quote);
        $account = DomainSupplierAccount::find($quote->supplier_account_id);
        if (! $account || $account->provider !== $quote->registrar) {
            throw new DomainProcurementException('supplier_binding_mismatch');
        }
        if ($quote->operation === 'renew') {
            $prior = $old['prior_expires_at'] ?? null;
            if (! $bound || ! $bound->expires_at || ! is_string($prior)
                || ! Carbon::parse($prior)->equalTo($bound->expires_at)) {
                throw new DomainProcurementException('renewal_expiry_changed');
            }
        }
        $fresh = $this->candidate($account, $quote->domain, $quote->tld, $quote->operation,
            (int) $quote->period_months, (array) $old['options'], $bound,
            $quote->operation === 'transfer' && ($old['incoming_transfer'] ?? false) === true);
        if ($fresh['available'] === false && $quote->operation === 'create') {
            throw new DomainProcurementException('domain_unavailable');
        }
        if ($fresh['currency'] !== $old['currency'] || $fresh['amount_minor'] > $old['amount_minor']
            || $fresh['premium'] !== $old['premium'] || $fresh['terms_hash'] !== $old['terms_hash']
            || $fresh['floor_toman'] > (int) $quote->sell_toman) {
            throw new DomainProcurementException('supplier_quote_changed');
        }
        return $fresh;
    }

    private function candidate(DomainSupplierAccount $account, string $fqdn, string $tld, string $operation,
        int $months, array $options, ?Domain $bound, bool $incomingTransfer = false): array
    {
        $this->eligible($account, $tld, $operation, $months, $options, $fqdn, $bound);
        $provider = $this->providers->forAccount($account);
        $transferResult = null;
        if ($incomingTransfer) {
            $found = $provider->find($fqdn);
            if (($found['found'] ?? null) !== false) {
                throw new DomainProcurementException('transfer_destination_portfolio_unverified');
            }
            $transferResult = $account->capabilities['tlds'][$tld]['transfer_result_months'] ?? null;
            if (! is_int($transferResult) || $transferResult < 0 || $transferResult > 120) {
                throw new DomainProcurementException('transfer_result_period_unverified');
            }
        }
        if ($bound !== null && $operation !== 'create' && ! $incomingTransfer) {
            if (! $bound->supplier_object_id) {
                throw new DomainProcurementException('supplier_object_missing');
            }
            $found = $provider->find($fqdn);
            if (($found['found'] ?? null) !== true
                || (string) ($found['object_id'] ?? '') !== (string) $bound->supplier_object_id) {
                throw new DomainProcurementException('supplier_ownership_unverified');
            }
            if ($operation === 'renew') {
                $actual = isset($found['expires_at']) ? Carbon::parse($found['expires_at']) : null;
                if (! $actual || ! $bound->expires_at || ! $actual->equalTo($bound->expires_at)) {
                    throw new DomainProcurementException('renewal_expiry_changed');
                }
            }
        }
        $q = $provider->quote($fqdn, $operation, $months, $options);
        if ($operation === 'create' && ($q['available'] ?? null) === false) {
            return ['available' => false];
        }
        if ($incomingTransfer && ($q['available'] ?? null) !== false) {
            throw new DomainProcurementException('transfer_source_not_registered');
        }
        if (($operation === 'create' && ($q['available'] ?? null) !== true)
            || ! is_int($q['amount_minor'] ?? null) || $q['amount_minor'] < 1
            || (int) ($q['period_months'] ?? 0) !== $months || ($q['tax_included'] ?? false) !== true
            || ! is_bool($q['premium'] ?? null) || ! is_array($q['components'] ?? null)
            || ! preg_match('/\A[A-Z]{3}\z/D', (string) ($q['currency'] ?? ''))) {
            throw new DomainProcurementException('supplier_quote_invalid');
        }
        if ($q['premium'] && ($account->capabilities['tlds'][$tld]['premium'] ?? false) !== true) {
            throw new DomainProcurementException('premium_not_supported');
        }
        if (isset($options['premium']) && $options['premium'] !== $q['premium']) {
            throw new DomainProcurementException('supplier_quote_changed');
        }
        if (isset($q['valid_until']) && ! Carbon::parse($q['valid_until'])->isFuture()) {
            throw new DomainProcurementException('supplier_quote_expired');
        }
        $fx = $this->rate($q['currency']);
        $balance = $provider->probe();
        if (($balance['healthy'] ?? false) !== true || ! is_int($balance['balance_minor'] ?? null)
            || ($balance['currency'] ?? null) !== $q['currency'] || $balance['balance_minor'] < $q['amount_minor']) {
            throw new DomainProcurementException('supplier_balance_insufficient');
        }
        $numerator = DomainMoney::multiply($q['amount_minor'], $fx['numerator']);
        $wholesale = DomainMoney::ceilRatio($numerator, $fx['denominator']);
        $margin = $this->margin($tld);
        $floorMargin = DomainMoney::percentUnits(config('domain_reseller.min_margin_pct', 0));
        $step = max(1, (int) (Currency::find('IRT')?->rounding_step ?: 1000));
        $floor = DomainMoney::roundUp(DomainMoney::addPercent($wholesale, $floorMargin), $step);
        $sell = DomainMoney::roundUp(DomainMoney::addPercent($wholesale, max($margin, $floorMargin)), $step);
        $terms = ['domain' => $fqdn, 'operation' => $operation, 'period_months' => $months,
            'premium' => $q['premium'], 'options' => $options, 'incoming_transfer' => $incomingTransfer,
            'resulting_period_months' => $transferResult,
            'prior_expires_at' => $operation === 'renew' ? $bound?->expires_at?->toIso8601String() : null,
            'target_expires_at' => $operation === 'renew' ? $bound?->expires_at?->copy()->addMonthsNoOverflow($months)->toIso8601String() : null,
            'supplier_terms_hash' => $q['terms_hash'] ?? null];
        return array_merge($q, ['provider' => $account->provider, 'account_id' => (int) $account->id,
            'operation' => $operation, 'period_months' => $months, 'options' => $options,
            'incoming_transfer' => $incomingTransfer, 'resulting_period_months' => $transferResult,
            'prior_expires_at' => $operation === 'renew' ? $bound?->expires_at?->toIso8601String() : null,
            'target_expires_at' => $operation === 'renew' ? $bound?->expires_at?->copy()->addMonthsNoOverflow($months)->toIso8601String() : null,
            'fx_snapshot' => $fx, 'comparison_numerator' => $numerator, 'comparison_denominator' => $fx['denominator'],
            'wholesale_toman' => $wholesale, 'floor_toman' => $floor, 'sell_toman' => $sell,
            'margin_units' => $margin, 'terms_hash' => hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR))]);
    }

    private function eligible(DomainSupplierAccount $account, string $tld, string $operation, int $months,
        array $options, string $fqdn, ?Domain $bound): void
    {
        if (! $account->active || $account->environment !== 'production' || ! $account->contract_verified
            || ! $account->identity_verified || ! $account->pricing_verified
            || (($bound === null || in_array($operation, ['create', 'transfer'], true)) && ! $account->sales_enabled)
            || $account->connection_status !== 'healthy' || ! $account->health_checked_at
            || $account->health_checked_at->isFuture()
            || $account->health_checked_at->lt(now()->subSeconds((int) config('domain_suppliers.health_max_age_seconds', 300)))) {
            throw new DomainProcurementException('supplier_account_ineligible');
        }
        $caps = (array) ($account->capabilities['tlds'][$tld] ?? []);
        if (! in_array($operation, (array) ($caps['operations'] ?? []), true)
            || ! in_array($months, (array) ($caps['period_months'] ?? []), true)
            || ! in_array($options['privacy'], (array) ($caps['privacy'] ?? []), true)) {
            throw new DomainProcurementException('supplier_capability_missing');
        }
        foreach (['nameservers', 'lock', 'contacts'] as $action) {
            if (! in_array($action, (array) ($caps['management'] ?? []), true)) {
                throw new DomainProcurementException('supplier_management_missing');
            }
        }
        if ((preg_match('/[^\x00-\x7F]/', $fqdn) || str_contains($fqdn, 'xn--')) && ($caps['idn'] ?? false) !== true) {
            throw new DomainProcurementException('idn_not_supported');
        }
    }

    private function rate(string $currency): array
    {
        $money = Currency::find($currency);
        if (! $money || ! $money->is_active || $money->exponent < 0 || $money->exponent > 6) {
            throw new DomainProcurementException('currency_not_configured');
        }
        $scale = 10 ** (int) $money->exponent;
        if (in_array($currency, ['IRT', 'IRR'], true)) {
            return ['currency' => $currency, 'numerator' => 1, 'denominator' => $currency === 'IRR' ? $scale * 10 : $scale,
                'rate_toman' => $currency === 'IRT' ? 1 : '0.1', 'at' => now()->toIso8601String(), 'source' => 'currency_unit'];
        }
        $row = $this->fx->current($currency);
        if (! $row || ! is_int($row['rate_toman'] ?? null) || $row['rate_toman'] < 1) {
            throw new DomainProcurementException('fx_unavailable');
        }
        $oldest = now()->subSeconds((int) config('domain_suppliers.fx_max_age_seconds', 172800));
        foreach (['at', 'source_at'] as $field) {
            $time = $row[$field] ?? null;
            if (! is_string($time) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T/', $time)) {
                throw new DomainProcurementException('fx_unavailable');
            }
            $at = Carbon::parse($time);
            if ($at->lt($oldest) || $at->gt(now()->addMinutes(5))) {
                throw new DomainProcurementException('fx_expired');
            }
        }
        return $row + ['currency' => $currency, 'numerator' => $row['rate_toman'], 'denominator' => $scale];
    }

    private function margin(string $tld): int
    {
        $value = Setting::get('domain_margin_pct');
        $cfg = (array) config('services.openprovider.margin', []);
        return DomainMoney::percentUnits($value !== null && $value !== '' ? $value : ($cfg[$tld] ?? $cfg['default'] ?? 0));
    }

    private function options(array $options): array
    {
        $privacy = (string) ($options['privacy'] ?? 'none');
        if (! in_array($privacy, ['none', 'basic', 'pro', 'keep'], true)) {
            throw new DomainProcurementException('invalid_privacy');
        }
        $out = ['privacy' => $privacy];
        if (array_key_exists('language', $options)) {
            $language = strtolower((string) $options['language']);
            if (! preg_match('/\A[a-z]{3}\z/D', $language)) {
                throw new DomainProcurementException('invalid_idn_language');
            }
            $out['language'] = $language;
        }
        if (array_key_exists('premium', $options)) {
            if (! is_bool($options['premium'])) {
                throw new DomainProcurementException('invalid_premium');
            }
            $out['premium'] = $options['premium'];
        }
        return $out;
    }

    private function request(string $fqdn, string $operation, int $months): array
    {
        $fqdn = trim($fqdn, '. ');
        $ascii = idn_to_ascii($fqdn, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || strlen($ascii) > 253
            || ! preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/iD', $ascii)) {
            throw new DomainProcurementException('invalid_domain_request');
        }
        $fqdn = strtolower($ascii);
        [$label, $tld] = Domain::splitFqdn($fqdn);
        if ($label === '' || $tld === '' || ! DomainSearch::sells($tld)
            || ! in_array($operation, ['create', 'renew', 'transfer', 'restore'], true)
            || $months < 12 || $months > 120 || $months % 12 !== 0) {
            throw new DomainProcurementException('invalid_domain_request');
        }
        return [$fqdn, $tld];
    }

    private function cheaper(array $candidate, array $best): bool
    {
        $leftWhole = intdiv($candidate['comparison_numerator'], $candidate['comparison_denominator']);
        $rightWhole = intdiv($best['comparison_numerator'], $best['comparison_denominator']);
        if ($leftWhole !== $rightWhole) {
            return $leftWhole < $rightWhole;
        }
        return DomainMoney::multiply($candidate['comparison_numerator'] % $candidate['comparison_denominator'], $best['comparison_denominator'])
            < DomainMoney::multiply($best['comparison_numerator'] % $best['comparison_denominator'], $candidate['comparison_denominator']);
    }
}
