<?php

namespace App\Services\Domain\Providers;

use App\Models\Domain;
use App\Models\DomainSupplierAccount;
use App\Models\Setting;
use App\Services\Domain\OpenProviderClient;

class OpenProviderAdapter implements DomainProvider
{
    public function __construct(private DomainSupplierAccount $account, private OpenProviderClient $client) {}

    public function probe(): array
    {
        $balance = $this->client->balance();
        if (! ($balance['known'] ?? false)) { throw new DomainProviderException('balance_unavailable'); }
        $currency = strtoupper((string) $balance['currency']);
        return ['healthy' => true, 'balance_minor' => ProviderMoney::minor($balance['amount'], $currency), 'currency' => $currency];
    }

    public function quote(string $fqdn, string $operation, int $months, array $options = []): array
    {
        $fqdn = ProviderMoney::fqdn($fqdn);
        ProviderMoney::months($months);
        if (! in_array($operation, ['create', 'renew', 'transfer', 'restore'], true)
            || (in_array($operation, ['transfer', 'restore'], true) && $months !== 12)) {
            throw new DomainProviderException('unsupported_operation');
        }
        if (($options['privacy'] ?? 'none') !== 'none') { throw new DomainProviderException('privacy_price_unavailable'); }
        [$sld, $tld] = Domain::splitFqdn($fqdn);
        $available = null;
        $premium = false;
        if (in_array($operation, ['create', 'transfer'], true)) {
            $check = $this->client->check([['name' => $sld, 'extension' => $tld]]);
            $this->assertOk($check);
            $row = (array) ($check['results'][0] ?? []);
            $status = strtolower((string) ($row['status'] ?? ''));
            $available = match ($status) {
                'free', 'available', 'premium' => true,
                'active', 'registered', 'unavailable', 'taken', 'inuse' => false,
                default => null,
            };
            if ($available === null) { throw new DomainProviderException('availability_unknown'); }
            $premium = (bool) ($row['is_premium'] ?? false) || $status === 'premium';
        }
        // Openprovider's period applies only to create. Renew price is one
        // year; unlike a create promotion it can be extended by whole years.
        $res = $this->client->domainPrice($sld, $tld, $operation, $operation === 'create' ? intdiv($months, 12) : 1);
        $this->assertOk($res);
        $price = data_get($res, 'data.price.reseller', []);
        $currency = strtoupper((string) ($price['currency'] ?? ''));
        $base = ProviderMoney::minor($price['price'] ?? '', $currency);
        if ($operation === 'renew') { $base *= intdiv($months, 12); }
        $premium = $premium || (bool) data_get($res, 'data.is_premium', false);
        if ($base <= 0) { throw new DomainProviderException('price_unavailable'); }

        $settings = (array) $this->account->settings;
        // API reseller prices exclude VAT; do not let a UI flag suppress it.
        $account = $this->client->resellerInfo();
        $this->assertOk($account);
        $vat = ProviderMoney::basisPoints(data_get($account, 'data.vatperc', ''));
        $reserve = $settings['supplier_vat_pct'] ?? $settings['vat_pct'] ?? Setting::get('domain_supplier_vat_pct') ?? config('domain_pricing.supplier_vat_pct');
        if ($reserve !== null) { $vat = max($vat, ProviderMoney::basisPoints($reserve)); }
        $feeBps = ProviderMoney::basisPoints($settings['supplier_fee_pct'] ?? $settings['fee_pct'] ?? Setting::get('domain_supplier_fee_pct') ?? config('domain_pricing.supplier_fee_pct', 0));
        $tax = ProviderMoney::surcharge($base, $vat);
        $fee = ProviderMoney::surcharge($base + $tax, $feeBps);
        $mandatory = (int) ($settings['mandatory_fee_minor'] ?? 0);
        if ($mandatory < 0) { throw new DomainProviderException('invalid_supplier_amount'); }
        $total = $base + $tax + $fee + $mandatory;
        $validUntil = now()->addMinutes(5);
        $promotion = (array) data_get($res, 'data.promotion_data', []);
        $promotionEnd = isset($promotion['end_date']) && is_numeric($promotion['end_date']) ? (int) $promotion['end_date'] : null;
        if ($promotionEnd && $promotionEnd > time() && $promotionEnd < $validUntil->getTimestamp()) { $validUntil->setTimestamp($promotionEnd); }
        return [
            'available' => $available, 'amount_minor' => $total, 'currency' => $currency,
            'period_months' => $months, 'premium' => $premium, 'tax_included' => true,
            'components' => ['discounted_domain_minor' => $base, 'supplier_tax_minor' => $tax,
                'supplier_fee_minor' => $fee, 'mandatory_fee_minor' => $mandatory, 'privacy_minor' => 0],
            'terms_hash' => hash('sha256', json_encode([$fqdn, $operation, $months, $premium, 'none', $vat, $feeBps, $mandatory,
                (bool) data_get($res, 'data.is_promotion', false), $promotion])),
            'valid_until' => $validUntil->toIso8601String(),
        ];
    }

    public function find(string $fqdn): array
    {
        [$sld, $tld] = Domain::splitFqdn(ProviderMoney::fqdn($fqdn));
        $result = $this->client->findDomain($sld, $tld);
        $this->assertOk($result);
        if (! ($result['found'] ?? false)) { return ['found' => false, 'object_id' => null]; }
        $data = (array) ($result['data'] ?? []);
        if (empty($data['id'])) { throw new DomainProviderException('invalid_domain_object'); }
        return ['found' => true, 'object_id' => (string) $data['id'],
            'expires_at' => $data['expiration_date'] ?? $data['renewal_date'] ?? null,
            'data' => $data];
    }

    public function submit(string $operation, string $fqdn, int $months, array $context): array
    {
        LegacyAccountIdentity::assertWritable($this->account);
        ProviderMoney::months($months);
        [$sld, $tld] = Domain::splitFqdn(ProviderMoney::fqdn($fqdn));
        $years = intdiv($months, 12);
        $handle = (string) ($context['contact_handles']['owner'] ?? '');
        $id = (string) ($context['object_id'] ?? '');
        if (in_array($operation, ['create', 'transfer'], true) && $handle === '') { throw new DomainProviderException('contact_handle_required'); }
        if (in_array($operation, ['renew', 'restore'], true) && (! ctype_digit($id) || (int) $id < 1)) { throw new DomainProviderException('domain_object_required'); }
        if ($operation === 'renew') {
            $existing = $this->find($fqdn);
            $prior = $context['prior_expires_at'] ?? null;
            $target = $context['target_expires_at'] ?? null;
            if (! $existing['found'] || $existing['object_id'] !== $id || ! $prior || ! $existing['expires_at']
                || strtotime($prior) !== strtotime($existing['expires_at'])
                || ($target && strtotime($existing['expires_at']) >= strtotime($target))) {
                throw new DomainProviderException('renewal_expiry_changed');
            }
        }
        $quote = $this->quote($fqdn, $operation, $months, ['privacy' => $context['privacy'] ?? 'none']);
        if (! is_int($context['max_price_minor'] ?? null) || $context['max_price_minor'] <= 0
            || $quote['amount_minor'] > $context['max_price_minor'] || $quote['currency'] !== ($context['currency'] ?? null)
            || ($operation === 'create' && $quote['available'] !== true)) {
            throw new DomainProviderException('supplier_price_changed');
        }
        $acceptedPremiumFee = ($quote['premium'] && in_array($operation, ['create', 'transfer'], true))
            ? ProviderMoney::decimal($quote['components']['discounted_domain_minor'], $quote['currency']) : null;
        $res = match ($operation) {
            'create' => $this->client->registerDomain($sld, $tld, $handle, (array) ($context['name_servers'] ?? []), $years, false, (array) ($context['additional_data'] ?? []), $acceptedPremiumFee),
            'renew' => $this->client->renewDomain((int) $id, $years),
            'restore' => $this->client->restoreDomain((int) $id),
            'transfer' => $this->client->transferDomain($sld, $tld, $handle, (string) ($context['auth_code'] ?? ''), (array) ($context['name_servers'] ?? []), $years, $acceptedPremiumFee),
            default => throw new DomainProviderException('unsupported_operation'),
        };
        if (! ($res['ok'] ?? false)) {
            return ['status' => (int) ($res['code'] ?? -1) < 0 ? 'unknown' : 'failed', 'error_code' => 'provider_'.(int) ($res['code'] ?? -1)];
        }
        return ['status' => 'queued', 'object_id' => isset($res['id']) ? (string) $res['id'] : ($id ?: null)];
    }

    public function reconcile(array $operation): array
    {
        $found = $this->find((string) ($operation['fqdn'] ?? $operation['domain'] ?? ''));
        if (! $found['found']) { return ['status' => 'unknown', 'error_code' => 'domain_not_in_account']; }
        $expected = (string) ($operation['object_id'] ?? '');
        if ($expected !== '' && $expected !== $found['object_id']) { return ['status' => 'unknown', 'error_code' => 'domain_object_mismatch']; }
        if (str_starts_with((string) ($operation['operation'] ?? ''), 'manage_')) {
            return $this->reconcileManagement($operation, $found);
        }
        $state = strtolower((string) data_get($found, 'data.status', ''));
        if (in_array($state, ['fai', 'failed', 'del', 'deleted', 'rej', 'rejected'], true)) { return ['status' => 'failed', 'error_code' => 'remote_operation_failed']; }
        if (! in_array($state, ['act', 'active', 'ok'], true)) { return ['status' => 'queued', 'object_id' => $found['object_id']]; }
        $kind = $operation['operation'] ?? '';
        if ($kind === 'renew') {
            $target = $operation['target_expires_at'] ?? data_get($operation, 'request_context.target_expires_at');
            if (! $target && data_get($operation, 'request_context.prior_expires_at')) {
                try {
                    $target = \Illuminate\Support\Carbon::parse(data_get($operation, 'request_context.prior_expires_at'))
                        ->addMonthsNoOverflow((int) ($operation['period_months'] ?? 0))->toIso8601String();
                } catch (\Throwable) { $target = null; }
            }
            if (! $target || ! $found['expires_at'] || strtotime($found['expires_at']) < strtotime($target)) {
                return ['status' => 'unknown', 'object_id' => $found['object_id'], 'error_code' => 'renewal_not_confirmed'];
            }
        }
        return ['status' => 'succeeded', 'object_id' => $found['object_id'], 'expires_at' => $found['expires_at'], 'cost_status' => 'unreconciled'];
    }

    public function manage(string $fqdn, string $objectId, string $action, array $payload = []): array
    {
        if ($action !== 'get') { LegacyAccountIdentity::assertWritable($this->account); }
        $found = $this->find($fqdn);
        if (! $found['found'] || $found['object_id'] !== $objectId || ! ctype_digit($objectId)) { throw new DomainProviderException('domain_not_in_account'); }
        $res = match ($action) {
            'get' => $this->client->getDomain((int) $objectId),
            'nameservers' => $this->client->setNameServers((int) $objectId, (array) ($payload['nameservers'] ?? [])),
            'lock' => $this->client->setLock((int) $objectId, (bool) ($payload['locked'] ?? true)),
            'auth_code' => $this->client->authCode((int) $objectId),
            'contacts' => $this->client->updateDomain((int) $objectId, array_filter([
                'admin_handle' => $payload['handles']['admin'] ?? null,
                'tech_handle' => $payload['handles']['tech'] ?? null,
                'billing_handle' => $payload['handles']['billing'] ?? null,
            ], fn ($value) => is_string($value) && $value !== '')),
            'dnssec' => $this->client->updateDomain((int) $objectId, ['dnssec_keys' => $this->dnssecKeys((array) ($payload['keys'] ?? []))]),
            default => throw new DomainProviderException('unsupported_operation'),
        };
        $this->assertOk($res);
        if (in_array($action, ['get', 'auth_code'], true)) {
            return ['ok' => true, 'data' => $action === 'auth_code' ? ['auth_code' => $res['auth_code'] ?? null] : (array) ($res['data'] ?? [])];
        }
        $verified = $this->reconcileManagement(['operation' => 'manage_'.$action, 'request_context' => ['payload' => $payload]], $found);
        return ($verified['status'] ?? '') === 'succeeded' ? ['ok' => true, 'status' => 'succeeded', 'data' => []]
            : ['ok' => false, 'status' => 'queued', 'object_id' => $objectId, 'error_code' => 'management_pending', 'data' => []];
    }

    private function reconcileManagement(array $operation, array $found): array
    {
        $res = $this->client->getDomain((int) $found['object_id']);
        $this->assertOk($res);
        $data = (array) ($res['data'] ?? []);
        $action = substr((string) $operation['operation'], strlen('manage_'));
        $payload = (array) data_get($operation, 'request_context.payload', []);
        $known = false; $match = false;
        if ($action === 'nameservers' && isset($data['name_servers']) && is_array($data['name_servers'])) {
            $actual = array_map(fn ($row) => strtolower(rtrim(is_array($row) ? (string) ($row['name'] ?? '') : (string) $row, '.')), $data['name_servers']);
            $expected = array_map(fn ($ns) => strtolower(rtrim($ns, '.')), (array) ($payload['nameservers'] ?? []));
            sort($actual); sort($expected);
            $known = true; $match = $actual === $expected;
        } elseif ($action === 'lock' && isset($data['is_locked']) && is_bool($data['is_locked'])) {
            $known = true; $match = $data['is_locked'] === (bool) ($payload['locked'] ?? true);
        } elseif ($action === 'contacts' && ! empty($payload['handles'])) {
            $known = true; $match = true;
            foreach ((array) $payload['handles'] as $role => $handle) {
                if (! array_key_exists($role.'_handle', $data)) { $known = false; }
                if (($data[$role.'_handle'] ?? null) !== $handle) { $match = false; }
            }
        } elseif ($action === 'dnssec' && isset($data['dnssec_keys']) && is_array($data['dnssec_keys'])) {
            $normalize = function (array $rows) {
                $rows = array_map(fn ($row) => json_encode(['alg' => (int) ($row['alg'] ?? -1), 'flags' => (int) ($row['flags'] ?? -1),
                    'protocol' => (int) ($row['protocol'] ?? -1), 'pub_key' => $row['pub_key'] ?? null]), $rows);
                sort($rows); return $rows;
            };
            $known = true; $match = $normalize($data['dnssec_keys']) === $normalize($this->dnssecKeys((array) ($payload['keys'] ?? [])));
        }
        if ($known && $match) { return ['status' => 'succeeded', 'object_id' => $found['object_id']]; }
        $drifted = $known && ($operation['status'] ?? '') === 'succeeded';
        return ['status' => $drifted ? 'failed' : 'unknown', 'error_code' => $drifted ? 'management_state_drifted' : 'management_state_unconfirmed'];
    }

    private function dnssecKeys(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (($key['record_type'] ?? 'DS') !== 'DNSKEY' || ! isset($key['algorithm'], $key['flags'], $key['protocol'], $key['public_key'])) {
                throw new DomainProviderException('unsupported_dnssec_format');
            }
            $result[] = ['alg' => $key['algorithm'], 'flags' => $key['flags'], 'protocol' => $key['protocol'], 'pub_key' => $key['public_key']];
        }
        return $result;
    }

    private function assertOk(array $result): void
    {
        if (! ($result['ok'] ?? false)) { throw new DomainProviderException('provider_'.(int) ($result['code'] ?? -1), (int) ($result['code'] ?? -1) < 0); }
    }
}
