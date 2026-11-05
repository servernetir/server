<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Nonfinancial pending-traffic reserves. Never reads or locks the global meter record. */
final class ArvanTrafficExposure
{
    public const PREFIX = 'arvan_traffic_exposure_v1:';
    private const LIMIT = 1_000_000_000;

    private function check(bool $ok): void
    {
        if (!$ok) { throw new RuntimeException('Pending traffic exposure requires reconciliation.'); }
    }

    private function state(int $customer, bool $current): array
    {
        $empty = ['version' => 1, 'customer_id' => $customer, 'currency' => 'IRT', 'components' => []];
        if (!Schema::hasTable('settings')) { return $empty; }
        if ($current && DB::transactionLevel() === 0) { throw new \LogicException('Current exposure reads require a customer transaction.'); }
        $query = DB::table('settings')->where('key', self::PREFIX.$customer);
        if ($current) { $query->lockForUpdate(); }
        $length = DB::getDriverName() === 'sqlite' ? 'LENGTH(CAST(value AS BLOB))' : 'OCTET_LENGTH(value)';
        $row = $query->selectRaw('CASE WHEN '.$length.' <= 60000 THEN value ELSE NULL END AS value')->first();
        if ($row === null) { return $empty; }
        $this->check(is_string($row->value));
        $state = json_decode(Crypt::decryptString($row->value), true, 32, JSON_THROW_ON_ERROR);
        $this->check(is_array($state) && ($state['version'] ?? null) === 1
            && ($state['customer_id'] ?? null) === $customer && ($state['currency'] ?? null) === 'IRT'
            && is_array($state['components'] ?? null) && count($state['components']) <= 200);
        foreach ($state['components'] as $key => $part) {
            $this->check(is_string($key) && strlen($key) <= 180 && is_array($part)
                && is_int($part['amount_irt'] ?? null) && $part['amount_irt'] > 0 && $part['amount_irt'] <= self::LIMIT
                && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($part['base_sha256'] ?? '')) === 1
                && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($part['source_hash'] ?? '')) === 1
                && is_array($part['next'] ?? null) && ($part['next']['customer_id'] ?? null) === $customer
                && is_int($part['next']['service_id'] ?? null) && $part['next']['service_id'] > 0);
            foreach (['cost_irr', 'sell_irt', 'tax_irt'] as $field) {
                $this->check(is_int($part['next'][$field] ?? null) && $part['next'][$field] >= 0);
            }
        }
        $this->check(array_sum(array_column($state['components'], 'amount_irt')) <= self::LIMIT);
        return $state;
    }

    public function reservedOf(int $customer, bool $currentRead = false): int
    {
        return array_sum(array_column($this->state($customer, $currentRead)['components'], 'amount_irt'));
    }

    private function save(int $customer, array $state): void
    {
        $this->check(DB::transactionLevel() > 0);
        $cipher = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
        $this->check(strlen($cipher) <= 60000);
        DB::table('settings')->upsert([['key' => self::PREFIX.$customer, 'value' => $cipher,
            'created_at' => now(), 'updated_at' => now()]], ['key'], ['value', 'updated_at']);
    }

    public static function base(array $line): string
    {
        return hash('sha256', json_encode($line['old'], JSON_THROW_ON_ERROR));
    }

    /** Caller holds all affected customer/service/instance locks after current attribution revalidation. */
    public function observeLocked(array $lines, string $sourceHash): void
    {
        $this->check(DB::transactionLevel() > 0 && preg_match('/\A[a-f0-9]{64}\z/D', $sourceHash) === 1);
        foreach (collect($lines)->groupBy('next.customer_id')->sortKeys() as $customer => $group) {
            $customer = (int) $customer;
            $state = $this->state($customer, true);
            foreach ($group as $line) {
                $next = $line['next']; $key = $line['key']; $amount = $line['subtotal_irt'] + $line['tax_irt'];
                $this->check(is_int($amount) && $amount > 0 && $amount <= self::LIMIT);
                $old = $state['components'][$key] ?? null;
                if ($old !== null) {
                    $this->check($old['base_sha256'] === self::base($line)
                        && $old['next']['service_id'] === $next['service_id']
                        && $old['next']['resource'] === $next['resource']
                        && $old['next']['from'] === $next['from']
                        && $next['cost_irr'] >= $old['next']['cost_irr']
                        && $next['sell_irt'] >= $old['next']['sell_irt']
                        && $next['tax_irt'] >= $old['next']['tax_irt']
                        && $amount >= $old['amount_irt']);
                }
                $state['components'][$key] = ['base_sha256' => self::base($line), 'source_hash' => $sourceHash,
                    'amount_irt' => $amount, 'next' => $next, 'observed_at' => now()->toIso8601String()];
            }
            $this->check(array_sum(array_column($state['components'], 'amount_irt')) <= self::LIMIT);
            $this->save($customer, $state);
        }
    }

    /** Clear only components paid by this transaction; missing/held/excluded components never expire. */
    public function settleLocked(int $customer, array $lines): void
    {
        $this->check(DB::transactionLevel() > 0);
        $state = $this->state($customer, true);
        foreach ($lines as $line) {
            $part = $state['components'][$line['key']] ?? null;
            $this->check(is_array($part) && $part['base_sha256'] === self::base($line)
                && $part['next'] === $line['next']
                && $part['amount_irt'] === $line['subtotal_irt'] + $line['tax_irt']);
            unset($state['components'][$line['key']]);
        }
        $this->save($customer, $state);
    }
}
