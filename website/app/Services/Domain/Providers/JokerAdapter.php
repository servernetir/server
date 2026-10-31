<?php

namespace App\Services\Domain\Providers;

use App\Models\DomainSupplierAccount;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** DMAPI, reviewed against Joker's official documentation on 2026-10-31. */
class JokerAdapter implements DomainProvider
{
    private string $base;

    public function __construct(private DomainSupplierAccount $account)
    {
        $this->base = match ($account->environment) {
            'production' => 'https://dmapi.joker.com/request/',
            'ote' => 'https://dmapi.ote.joker.com/request/',
            default => throw new DomainProviderException('invalid_environment'),
        };
    }

    public function probe(): array
    {
        $res = $this->request('query-account-balance');
        $columns = array_map('trim', explode(',', (string) ($res['fields']['columns'] ?? '')));
        if ($columns !== ['available', 'pending', 'total', 'currency']) { throw new DomainProviderException('invalid_balance_response'); }
        $lines = $this->lines($res['body']);
        $row = explode("\t", $lines[0] ?? '');
        if (count($row) !== 4 || count($lines) !== 1) { throw new DomainProviderException('invalid_balance_response'); }
        $currency = strtoupper(trim($row[3]));
        return ['healthy' => true, 'balance_minor' => ProviderMoney::minor(trim($row[0]), $currency), 'currency' => $currency];
    }

    public function quote(string $fqdn, string $operation, int $months, array $options = []): array
    {
        $fqdn = ProviderMoney::fqdn($fqdn);
        ProviderMoney::months($months);
        if (! in_array($operation, ['create', 'renew', 'transfer', 'restore'], true)) { throw new DomainProviderException('unsupported_operation'); }
        if (($options['privacy'] ?? 'none') !== 'none') {
            // domain-check documents no privacy-price input. Never sell an
            // unpriced add-on or assume the base domain price includes it.
            throw new DomainProviderException('privacy_price_unavailable');
        }
        $params = ['domain' => $fqdn, 'check-price' => $operation, 'period' => $months];
        if (! empty($options['language'])) { $params['language'] = $options['language']; }
        $res = $this->request('domain-check', $params);
        $fields = $res['fields'];
        $status = strtolower((string) ($fields['domain-status'] ?? ''));
        $available = match ($status) { 'available', 'premium' => true, 'unavailable' => false, default => null };
        if ($available === null) { throw new DomainProviderException('availability_unknown'); }
        $price = (string) ($fields['domain-price-'.$operation] ?? '');
        if (! preg_match('/^(\d+(?:\.\d+)?)\s+([A-Z]{3})\s+(\d+)y$/D', $price, $m)) {
            throw new DomainProviderException('price_unavailable');
        }
        if ((int) $m[3] * 12 !== $months) { throw new DomainProviderException('supplier_period_mismatch'); }
        $minor = ProviderMoney::minor($m[1], $m[2]);
        if ($minor <= 0) { throw new DomainProviderException('price_unavailable'); }
        $class = strtolower(trim((string) ($fields['domain-class'] ?? '')));
        // The API says every non-standard price class is premium. Missing
        // class is unknown, not a reason to invent a standard-domain price.
        if ($class === '') { throw new DomainProviderException('price_class_unknown'); }
        $premium = $status === 'premium' || $class !== 'standard';
        $validUntil = now()->addMinutes(5);
        $promo = (string) ($fields['domain-price-promo'] ?? '');
        if ($promo !== '') {
            $window = preg_split('/\s+/', trim($promo));
            if (count($window) === 2 && strtotime($window[1]) > time() && strtotime($window[1]) < $validUntil->getTimestamp()) {
                $validUntil->setTimestamp(strtotime($window[1]));
            }
        }
        // The DMAPI price is the account's final debit, including its VAT and
        // discounts. External funding/procurement charges are a separate cost;
        // they must affect selection without increasing the registrar ceiling.
        [$feeBps, $mandatory] = $this->externalFees();
        $fee = ProviderMoney::surcharge($minor, $feeBps);
        $landed = ProviderMoney::add($minor, $fee, $mandatory);
        $providerTerms = hash('sha256', json_encode([$fqdn, $operation, $months, $premium, $class, 'none', $promo, $options['language'] ?? null]));
        return [
            'available' => $available, 'amount_minor' => $landed, 'provider_debit_minor' => $minor, 'currency' => $m[2],
            'period_months' => $months, 'premium' => $premium, 'tax_included' => true,
            // Applying supplier VAT again would duplicate it. The additional
            // fee below is external to the account debit, not a second VAT.
            'components' => ['final_account_domain_minor' => $minor, 'privacy_minor' => 0,
                'supplier_fee_minor' => $fee, 'mandatory_fee_minor' => $mandatory,
                'supplier_tax_included' => true, 'account_discounts_included' => true],
            'provider_terms_hash' => $providerTerms,
            'terms_hash' => $feeBps === 0 && $mandatory === 0 ? $providerTerms
                : hash('sha256', json_encode([$providerTerms, $feeBps, $mandatory])),
            'valid_until' => $validUntil->toIso8601String(),
        ];
    }

    private function externalFees(): array
    {
        $settings = (array) $this->account->settings;
        $percentage = $settings['supplier_fee_pct'] ?? $settings['fee_pct']
            ?? Setting::get('domain_supplier_fee_pct') ?? config('domain_pricing.supplier_fee_pct', 0);
        return [ProviderMoney::basisPoints($percentage), ProviderMoney::integerMinor($settings['mandatory_fee_minor'] ?? 0)];
    }

    public function find(string $fqdn): array
    {
        $fqdn = ProviderMoney::fqdn($fqdn);
        // A public query-whois/RDAP reply proves nothing about our account.
        // Only this authenticated account portfolio list establishes access.
        $res = $this->request('query-domain-list', ['pattern' => $fqdn, 'showstatus' => 1, 'showgrants' => 1]);
        foreach ($this->lines($res['body']) as $line) {
            $row = preg_split('/\s+/', trim($line));
            if (strtolower((string) ($row[0] ?? '')) !== $fqdn) { continue; }
            if (empty($row[1]) || strtotime($row[1]) === false) { throw new DomainProviderException('invalid_domain_object'); }
            return ['found' => true, 'object_id' => $fqdn, 'expires_at' => $row[1],
                'data' => ['status' => $row[2] ?? null, 'grants' => $row[3] ?? null]];
        }
        return ['found' => false, 'object_id' => null];
    }

    public function submit(string $operation, string $fqdn, int $months, array $context): array
    {
        $fqdn = ProviderMoney::fqdn($fqdn);
        ProviderMoney::months($months);
        if (! in_array($operation, ['create', 'renew', 'transfer'], true)) { throw new DomainProviderException('unsupported_operation'); }
        $price = $context['max_price_minor'] ?? null;
        $currency = (string) ($context['currency'] ?? '');
        if (! is_int($price) || $price <= 0) { throw new DomainProviderException('supplier_price_ceiling_required'); }
        [$feeBps, $mandatory] = $this->externalFees();
        $providerCeiling = $context['provider_price_ceiling_minor'] ?? null;
        // Old no-fee requests used one amount for both concepts. Never make
        // that inference after external costs have been configured.
        if ($providerCeiling === null && $feeBps === 0 && $mandatory === 0) { $providerCeiling = $price; }
        if (! is_int($providerCeiling) || $providerCeiling <= 0 || $providerCeiling > $price) {
            throw new DomainProviderException('provider_price_ceiling_required');
        }
        $quoted = $this->quote($fqdn, $operation, $months, array_filter([
            'privacy' => $context['privacy'] ?? 'none', 'language' => $context['language'] ?? null,
        ], fn ($value) => $value !== null));
        if ($quoted['provider_debit_minor'] > $providerCeiling || $quoted['amount_minor'] > $price
            || $quoted['currency'] !== $currency
            || ($operation === 'create' && $quoted['available'] !== true)
            || ($operation === 'transfer' && $quoted['available'] !== false)
            || (isset($context['quoted_premium']) && $context['quoted_premium'] !== $quoted['premium'])
            || (isset($context['quoted_provider_terms_hash']) && $context['quoted_provider_terms_hash'] !== $quoted['provider_terms_hash'])) {
            throw new DomainProviderException('supplier_price_changed');
        }
        $params = ['domain' => $fqdn, 'max-price' => ProviderMoney::decimal($providerCeiling, $currency),
            'privacy' => (string) ($context['privacy'] ?? 'none')];
        if ($params['privacy'] !== 'none') { throw new DomainProviderException('privacy_price_unavailable'); }
        if (! empty($context['client_transaction_id'] ?? $context['cltrid'] ?? null)) { $params['cltrid'] = $context['client_transaction_id'] ?? $context['cltrid']; }
        if (! empty($context['language'])) { $params['language'] = $context['language']; }
        if ($operation === 'renew') {
            $owned = $this->find($fqdn);
            if (! $owned['found'] || ($context['object_id'] ?? '') !== $owned['object_id']) { throw new DomainProviderException('domain_not_in_account'); }
            // expyear and period are mutually exclusive. It bounds the
            // wanted expiration year; validate it against the quoted term.
            $targetYear = (int) ($context['target_expiration_year'] ?? 0);
            $expiration = strtotime((string) $owned['expires_at']);
            if ($expiration === false || $targetYear !== (int) gmdate('Y', $expiration) + intdiv($months, 12)) {
                throw new DomainProviderException('renewal_target_required');
            }
            $params['expyear'] = $targetYear;
        } else {
            $handles = (array) ($context['contact_handles'] ?? []);
            foreach (['owner', 'billing', 'admin', 'tech'] as $role) {
                if (empty($handles[$role]) || ! is_string($handles[$role])) { throw new DomainProviderException('contact_handle_required'); }
                $params[$role.'-c'] = $handles[$role];
            }
            $params['autorenew'] = 0;
            if ($operation === 'create') {
                $params['period'] = $months;
                $params['status'] = 'production';
                $nameServers = array_values((array) ($context['name_servers'] ?? []));
                if ($nameServers === []) { throw new DomainProviderException('nameservers_required'); }
                $params['ns-list'] = implode(':', $nameServers);
            } else {
                // Joker transfer's period parameter is explicitly not in use.
                if ($months !== 12 || empty($context['auth_code'])) { throw new DomainProviderException('transfer_terms_required'); }
                $params['transfer-auth-id'] = $context['auth_code'];
                // Preserve existing NS unless the owner explicitly selected new NS.
                if (! empty($context['name_servers'])) { $params['ns-list'] = implode(':', (array) $context['name_servers']); }
            }
        }
        $command = match ($operation) { 'create' => 'domain-register', 'renew' => 'domain-renew', 'transfer' => 'domain-transfer-in-reseller' };
        try { $result = $this->request($command, $params, true); }
        catch (DomainProviderException $e) { return ['status' => $e->uncertain ? 'unknown' : 'failed', 'error_code' => $e->reason]; }
        return $this->accepted($result, $fqdn);
    }

    public function reconcile(array $operation): array
    {
        $processId = trim((string) ($operation['process_id'] ?? ''));
        $trackingId = trim((string) ($operation['tracking_id'] ?? ''));
        if ($trackingId === '' && $processId === '') {
            // ClTrId is deliberately not used as a unique lookup key. A lost
            // identifier requires manual reconciliation of actual provider orders.
            return ['status' => 'unknown', 'error_code' => 'provider_reference_missing'];
        }
        foreach ([$trackingId, $processId] as $reference) {
            if ($reference !== '' && ! preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $reference)) { throw new DomainProviderException('invalid_provider_reference'); }
        }
        $list = $this->request('result-list', ($trackingId !== '' ? ['svtrid' => $trackingId] : ['procid' => $processId]) + ['showall' => 1]);
        $matches = [];
        foreach ($this->lines($list['body']) as $line) {
            $row = preg_split('/\s+/', trim($line));
            // The documented six columns are TimeStamp SvTrId Proc-ID
            // request-type status ClTrId. Some responses include object too.
            if (count($row) < 6 || ($trackingId !== '' ? ($row[1] ?? '') !== $trackingId : ($row[2] ?? '') !== $processId)) { continue; }
            if ($processId !== '' && ($row[2] ?? '') !== $processId) { continue; }
            $expectedType = match ($operation['operation'] ?? '') {
                'create' => 'domain-register', 'renew' => 'domain-renew', 'transfer' => 'domain-transfer-in-reseller',
                'manage_nameservers', 'manage_contacts', 'manage_dnssec' => 'domain-modify',
                'manage_lock' => (bool) data_get($operation, 'request_context.payload.locked', true) ? 'domain-lock' : 'domain-unlock',
                default => '',
            };
            if ($expectedType !== '' && ($row[3] ?? '') !== $expectedType) { throw new DomainProviderException('provider_result_mismatch'); }
            $matches[] = strtolower((string) $row[count($row) - 2]);
            $processId = $processId ?: (string) ($row[2] ?? '');
            $trackingId = $trackingId ?: (string) ($row[1] ?? '');
        }
        if (count($matches) !== 1) { return ['status' => 'unknown', 'error_code' => 'provider_result_not_unique']; }
        $res = $this->request('result-retrieve', $trackingId !== '' ? ['svtrid' => $trackingId] : ['proc-id' => $processId]);
        // ACK on result-retrieve can acknowledge the retrieval itself. The
        // exact original result-list record is the authoritative completion.
        $result = $matches[0];
        if ($result === 'nack') { return ['status' => 'failed', 'error_code' => 'remote_operation_failed']; }
        if (in_array($result, ['?', 'pending', 'queued'], true)) { return ['status' => 'queued', 'process_id' => $processId ?: null, 'tracking_id' => $trackingId ?: null]; }
        if ($result !== 'ack') { return ['status' => 'unknown', 'error_code' => 'provider_result_unknown']; }
        $fqdn = ProviderMoney::fqdn((string) ($operation['fqdn'] ?? $operation['domain'] ?? ''));
        $remote = $this->find($fqdn);
        if (! $remote['found']) { return ['status' => 'unknown', 'error_code' => 'domain_not_in_account']; }
        if (str_starts_with((string) ($operation['operation'] ?? ''), 'manage_')) {
            return $this->reconcileManagement($operation, $remote);
        }
        if (($operation['operation'] ?? '') === 'renew') {
            $target = (int) ($operation['target_expiration_year'] ?? data_get($operation, 'request_context.target_expiration_year', 0));
            $actualYear = (int) gmdate('Y', strtotime((string) $remote['expires_at']));
            if ($target <= 0 || $actualYear < $target) { return ['status' => 'unknown', 'error_code' => 'renewal_not_confirmed']; }
        }
        return ['status' => 'succeeded', 'object_id' => $remote['object_id'], 'expires_at' => $remote['expires_at'],
            'process_id' => $processId ?: null, 'tracking_id' => $trackingId ?: null]
            + $this->reportedDebit($processId, $fqdn, (string) ($operation['operation'] ?? ''));
    }

    /** Provider-reported actual debit is not a final invoice reconciliation. */
    private function reportedDebit(string $processId, string $fqdn, string $operation): array
    {
        $unmatched = ['cost_status' => 'unreconciled'];
        if ($processId === '' || ! in_array($operation, ['create', 'renew', 'transfer'], true)) { return $unmatched; }
        try {
            $reply = $this->request('query-order-list', ['proc-id' => $processId, 'fqdn' => $fqdn, 'type' => $operation,
                'status' => 'ok', 'separator' => 'tab']);
            $columns = array_map('trim', explode(',', $reply['fields']['columns'] ?? ''));
            $required = ['order_id', 'proc_id', 'created_at', 'status', 'type', 'amount_brutto', 'amount_netto', 'currency', 'identifier'];
            if ($columns !== $required) { return $unmatched; }
            $matches = [];
            foreach ($this->lines($reply['body']) as $line) {
                $values = explode("\t", $line);
                if (count($values) !== count($columns)) { continue; }
                $row = array_combine($columns, $values);
                if ($row['proc_id'] === $processId && strtolower($row['identifier']) === $fqdn && $row['type'] === $operation && $row['status'] === 'ok'
                    && preg_match('/^\d+(?:\.\d+)?$/D', $row['amount_brutto'])) { $matches[] = $row; }
            }
            if (count($matches) !== 1) { return $unmatched; }
            $row = $matches[0];
            $minor = ProviderMoney::minor($row['amount_brutto'], $row['currency']);
            if ($minor <= 0) { return $unmatched; }
            return ['purchase_minor' => $minor, 'currency' => $row['currency'], 'cost_status' => 'provider_reported', 'provider_order_id' => $row['order_id']];
        } catch (\Throwable) {
            // A billing-read outage must not repeat a successful domain order.
            return $unmatched;
        }
    }

    public function manage(string $fqdn, string $objectId, string $action, array $payload = []): array
    {
        $fqdn = ProviderMoney::fqdn($fqdn);
        $found = $this->find($fqdn);
        if (! $found['found'] || $found['object_id'] !== $objectId) { throw new DomainProviderException('domain_not_in_account'); }
        if ($action === 'get') { return ['ok' => true, 'data' => $found]; }
        $params = ['domain' => $fqdn];
        $command = match ($action) {
            'lock' => (bool) ($payload['locked'] ?? true) ? 'domain-lock' : 'domain-unlock',
            'nameservers', 'contacts', 'dnssec' => 'domain-modify',
            // get-auth-id generates a new secret asynchronously. Do not
            // repeatedly request it or persist it in a generic operation log.
            default => throw new DomainProviderException('unsupported_operation'),
        };
        if ($action === 'nameservers') {
            $ns = (array) ($payload['nameservers'] ?? []);
            if ($ns === []) { throw new DomainProviderException('nameservers_required'); }
            $params['ns-list'] = implode(':', $ns);
        }
        if ($action === 'contacts') {
            foreach (['admin', 'tech', 'billing'] as $role) {
                if (! empty($payload['handles'][$role])) { $params[$role.'-c'] = $payload['handles'][$role]; }
            }
            if (count($params) === 1) { throw new DomainProviderException('contact_handle_required'); }
        }
        if ($action === 'dnssec') {
            $keys = array_values((array) ($payload['keys'] ?? []));
            $params['dnssec'] = $keys === [] ? 0 : 1;
            if (count($keys) > 6) { throw new DomainProviderException('invalid_dnssec_keys'); }
            foreach ($keys as $i => $key) {
                $params['ds-'.($i + 1)] = $this->dnssecKey($fqdn, $key);
            }
        }
        $res = $this->request($command, $params, true);
        return $this->accepted($res, $fqdn) + ['ok' => false, 'data' => []];
    }

    private function reconcileManagement(array $operation, array $remote): array
    {
        $action = substr((string) $operation['operation'], strlen('manage_'));
        $payload = (array) data_get($operation, 'request_context.payload', []);
        $verified = false;
        $known = false;
        if ($action === 'lock') {
            $state = strtolower((string) data_get($remote, 'data.status', ''));
            $flags = preg_split('/[,\s]+/', $state);
            $known = $state !== '';
            $verified = $state !== '' && in_array('lock', $flags, true) === (bool) ($payload['locked'] ?? true);
        } elseif (in_array($action, ['nameservers', 'contacts', 'dnssec'], true)) {
            $details = $this->request('query-whois', ['domain' => $remote['object_id']]);
            $values = $details['values'];
            if ($action === 'nameservers') {
                $actual = [];
                foreach (['nserver', 'nameserver', 'name-server', 'ns-list'] as $key) {
                    foreach (($values[$key] ?? []) as $value) {
                        foreach (preg_split('/[:,\s]+/', $value) as $ns) {
                            if (str_contains($ns, '.') && ! filter_var($ns, FILTER_VALIDATE_IP)) { $actual[] = strtolower(rtrim($ns, '.')); }
                        }
                    }
                }
                $expected = array_map(fn ($ns) => strtolower(rtrim($ns, '.')), (array) ($payload['nameservers'] ?? []));
                $actual = array_values(array_unique($actual)); sort($actual); sort($expected);
                $known = $actual !== [];
                $verified = $actual !== [] && $actual === $expected;
            } elseif ($action === 'contacts') {
                $verified = ! empty($payload['handles']);
                $known = ! empty($payload['handles']);
                foreach ((array) ($payload['handles'] ?? []) as $role => $handle) {
                    if (! isset($details['fields'][$role.'-c'])) { $known = false; }
                    if (! in_array($role, ['admin', 'tech', 'billing'], true)
                        || ($details['fields'][$role.'-c'] ?? null) !== $handle) { $verified = false; }
                }
            } else {
                $actual = [];
                foreach ($values as $key => $rows) { if (preg_match('/^ds-\d+$/D', $key)) { $actual = array_merge($actual, $rows); } }
                $expected = array_map(fn ($key) => $this->dnssecKey($remote['object_id'], $key), (array) ($payload['keys'] ?? []));
                sort($actual); sort($expected);
                $known = $actual !== [] || ($details['fields']['dnssec'] ?? '') === '0';
                $verified = $actual === $expected && ($actual !== [] || ($details['fields']['dnssec'] ?? '') === '0');
            }
        }
        if ($verified) { return ['status' => 'succeeded', 'object_id' => $remote['object_id']]; }
        $drifted = $known && ($operation['status'] ?? '') === 'succeeded';
        return ['status' => $drifted ? 'failed' : 'unknown', 'error_code' => $drifted ? 'management_state_drifted' : 'management_state_unconfirmed'];
    }

    private function dnssecKey(string $fqdn, mixed $key): string
    {
        if (! is_array($key)) { throw new DomainProviderException('invalid_dnssec_keys'); }
        if (($key['record_type'] ?? 'DS') === 'DNSKEY') {
            if (! str_ends_with($fqdn, '.de') || ! isset($key['protocol'], $key['algorithm'], $key['flags'], $key['public_key'])) {
                throw new DomainProviderException('unsupported_dnssec_format');
            }
            return implode(':', [$key['protocol'], $key['algorithm'], $key['flags'], $key['public_key']]);
        }
        if (str_ends_with($fqdn, '.de') || ! isset($key['tag'], $key['algorithm'], $key['digest_type'], $key['digest'])) {
            throw new DomainProviderException('unsupported_dnssec_format');
        }
        return implode(':', [$key['tag'], $key['algorithm'], $key['digest_type'], strtoupper($key['digest'])]);
    }

    private function accepted(array $result, string $fqdn): array
    {
        $processId = $result['fields']['proc-id'] ?? null;
        $trackingId = $result['fields']['tracking-id'] ?? $result['fields']['svtrid'] ?? null;
        $unknown = (! $processId && ! $trackingId) || strtolower((string) ($result['fields']['result'] ?? '')) === 'nack';
        return ['status' => $unknown ? 'unknown' : 'queued', 'object_id' => $fqdn,
            'process_id' => $processId, 'tracking_id' => $trackingId,
            'error_code' => $unknown ? 'provider_acknowledgement_unknown' : null];
    }

    private function session(): string
    {
        $credentials = (array) $this->account->credentials;
        $apiKey = trim((string) ($credentials['api_key'] ?? ''));
        if ($apiKey === '') { throw new DomainProviderException('credentials_missing'); }
        $key = 'domain.joker.session:'.hash_hmac('sha256', $this->base."\0".$this->account->getKey()."\0".$apiKey, (string) config('app.key'));
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') { return $cached; }
        if (Cache::has($key.':auth-down')) { throw new DomainProviderException('authentication_unavailable'); }
        try { $result = $this->send('login', ['api-key' => $apiKey]); }
        catch (DomainProviderException $e) {
            Cache::put($key.':auth-down', true, now()->addMinutes(10));
            throw $e;
        }
        $sid = trim((string) ($result['fields']['auth-sid'] ?? ''));
        if ($sid === '') { throw new DomainProviderException('authentication_failed'); }
        Cache::put($key, $sid, now()->addMinutes(45));
        return $sid;
    }

    private function request(string $command, array $params = [], bool $mutation = false): array
    {
        // Authentication occurs before the mutation: login failures are safe
        // unsubmitted failures. Financial requests have no automatic retry.
        $params['auth-sid'] = $this->session();
        return $this->send($command, $params, $mutation);
    }

    private function send(string $command, array $params, bool $mutation = false): array
    {
        try {
            $response = Http::asForm()->accept('text/plain')->timeout(25)->withOptions(['allow_redirects' => false])
                ->post($this->base.$command, $params);
        } catch (\Throwable) {
            // No exception chains: transport messages may contain sessions,
            // private contacts or EPP codes in the original request.
            throw new DomainProviderException('transport_failure', $mutation);
        }
        try { $result = self::parse($response->body()); }
        catch (DomainProviderException) { throw new DomainProviderException('invalid_provider_response', $mutation); }
        $code = $result['protocol_fields']['status-code'] ?? null;
        $error = array_key_exists('error', $result['protocol_fields']);
        if (! $response->successful() || $code === null || ! preg_match('/^\d+$/D', $code)
            || ! ((int) $code === 0 || ((int) $code >= 1000 && (int) $code <= 1999)) || $error) {
            throw new DomainProviderException($error ? 'request_rejected' : 'provider_response_invalid', $mutation && ! $error);
        }
        return $result;
    }

    /** Parse protocol headers and data separately; never expose the raw text. */
    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $parts = explode("\n\n", trim($text), 2);
        $fields = [];
        $values = [];
        foreach (explode("\n", $parts[0]) as $line) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9 _-]*):[ \t]*(.*)$/D', $line, $m)) {
                $key = strtolower(str_replace([' ', '_'], '-', trim($m[1])));
                $values[$key][] = trim($m[2]);
                if (array_key_exists($key, $fields) && $fields[$key] !== trim($m[2])) { throw new DomainProviderException('ambiguous_provider_response'); }
                $fields[$key] = trim($m[2]);
            }
        }
        $dataFields = [];
        foreach (explode("\n", $parts[1] ?? '') as $line) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9 _-]*):[ \t]*(.*)$/D', $line, $m)) {
                $dataFields[strtolower(str_replace([' ', '_'], '-', trim($m[1])))] = trim($m[2]);
                $values[strtolower(str_replace([' ', '_'], '-', trim($m[1])))][] = trim($m[2]);
            }
        }
        return ['fields' => $fields + $dataFields, 'protocol_fields' => $fields,
            'data_fields' => $dataFields, 'values' => $values, 'body' => $parts[1] ?? ''];
    }

    private function lines(string $body): array
    {
        return array_values(array_filter(explode("\n", trim($body)), fn ($line) => trim($line) !== ''));
    }
}
