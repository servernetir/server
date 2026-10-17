<?php

namespace App\Services\Domain;

use App\Models\Currency;
use App\Models\Setting;
use App\Services\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Complete registrar catalogue. Public readers never contact the registrar. */
class TldPriceBook
{
    public const SNAPSHOT_PATH = 'pricing/openprovider-tld-catalog-v1.json';
    private const MAX_AGE_HOURS = 24;
    private const PAGE_SIZE = 1000;
    private const MAX_ROWS = 10000;

    public function __construct(private OpenProviderClient $op) {}

    /** Compatibility reader; refreshing is exclusively an explicit background job. */
    public function forTlds(array $tlds): array
    {
        return $this->cachedForTlds($tlds);
    }

    public function cachedForTlds(array $tlds): array
    {
        return array_map(fn ($row) => $row['register'], $this->cachedFullForTlds($tlds));
    }

    public function fullForTlds(array $tlds): array
    {
        return $this->cachedFullForTlds($tlds);
    }

    /** Amounts are for the documented minimum term, not an invented annual rate. */
    public function cachedFullForTlds(array $tlds): array
    {
        try {
            $snapshot = $this->snapshot();
            return $snapshot === [] ? [] : $this->convert($snapshot, $this->normalise($tlds));
        } catch (\Throwable) {
            return []; // Missing/corrupt data or invalid pricing settings never become a public 500 or a guessed price.
        }
    }

    public function catalogTlds(): array
    {
        try {
            $snapshot = $this->snapshot();
            return $snapshot === [] ? [] : array_keys($this->convert($snapshot));
        } catch (\Throwable) {
            return [];
        }
    }

    public function metadata(): array
    {
        $snapshot = $this->snapshot();
        if ($snapshot === []) {
            return [];
        }
        return array_intersect_key($snapshot, array_flip(['fetched_at', 'total', 'excluded', 'supplier_vat_pct']))
            + ['count' => count($snapshot['rows'])];
    }

    /** Publish only after every catalogue page, tax rate and conversion validates. */
    public function refreshAll(): array
    {
        $lock = Cache::lock('tld.catalog.refresh', 900);
        if (! $lock->get()) {
            throw new RuntimeException('A domain catalogue refresh is already running.');
        }
        try {
            $started = now()->toIso8601String();
            $total = null;
            $seen = [];
            $rows = [];
            $excluded = [];
            $offset = 0;
            do {
                $page = $this->op->listTlds(self::PAGE_SIZE, $offset, true);
                if (! ($page['ok'] ?? false) || ! is_array($page['results'] ?? null)) {
                    throw new RuntimeException('Openprovider catalogue page failed (code '.(int) ($page['code'] ?? -1).').');
                }
                $pageTotal = $page['total'] ?? null;
                if (! is_int($pageTotal) || $pageTotal < 1 || $pageTotal > self::MAX_ROWS
                    || ($total !== null && $pageTotal !== $total)) {
                    throw new RuntimeException('Openprovider catalogue total changed or is invalid.');
                }
                $total = $pageTotal;
                $count = count($page['results']);
                if ($count === 0 || $count > self::PAGE_SIZE || $offset + $count > $total) {
                    throw new RuntimeException('Openprovider catalogue pagination is incomplete.');
                }
                foreach ($page['results'] as $source) {
                    if (! is_array($source)) {
                        throw new RuntimeException('Invalid Openprovider catalogue row.');
                    }
                    $name = strtolower(trim((string) ($source['name'] ?? ''), " \t."));
                    if ($name === '' || strlen($name) > 253 || preg_match('/[\s\/\\\\]/u', $name) || isset($seen[$name])) {
                        throw new RuntimeException('Openprovider catalogue has an invalid or repeated extension.');
                    }
                    $seen[$name] = true;
                    if (($source['status'] ?? null) !== 'ACT') {
                        $excluded[$name] = 'inactive';
                        continue;
                    }
                    if (! DomainSearch::sells($name)) {
                        $excluded[$name] = 'not_sold_here';
                        continue;
                    }
                    $rows[$name] = $this->normaliseRow($source);
                }
                $offset += $count;
            } while ($offset < $total);
            if (count($seen) !== $total || $rows === []) {
                throw new RuntimeException('Openprovider catalogue coverage is incomplete.');
            }
            $account = $this->op->resellerInfo();
            $vat = data_get($account, 'data.vatperc');
            if (! ($account['ok'] ?? false) || ! is_numeric($vat) || ! is_finite((float) $vat) || $vat < 0 || $vat > 100) {
                throw new RuntimeException('Openprovider supplier VAT could not be verified.');
            }
            ksort($rows);
            $snapshot = ['version' => 1, 'fetched_at' => $started, 'total' => $total,
                'supplier_vat_pct' => (float) $vat, 'excluded' => $excluded, 'rows' => $rows];
            if (count($this->convert($snapshot)) !== count($rows)) {
                throw new RuntimeException('Some active Openprovider extensions have no valid current price.');
            }
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $path = Storage::disk('local')->path(self::SNAPSHOT_PATH);
            File::ensureDirectoryExists(dirname($path), 0700, true);
            // Same-directory atomic replacement; failed/incomplete fetches never replace the last good catalogue.
            $this->publish($path, $json);
            return ['total' => $total, 'count' => count($rows), 'excluded' => count($excluded),
                'fetched_at' => $started];
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                report($e); // Expiry still releases the lock; do not misreport a completed publication.
            }
        }
    }

    private function publish(string $path, string $json): void
    {
        $temp = tempnam(dirname($path), '.op-tlds-');
        if ($temp === false) {
            throw new RuntimeException('Could not prepare domain catalogue publication.');
        }
        try {
            if (! chmod($temp, 0600) || file_put_contents($temp, $json, LOCK_EX) !== strlen($json)
                || hash_file('sha256', $temp) !== hash('sha256', $json)) {
                throw new RuntimeException('Could not write a complete domain catalogue.');
            }
            if (! rename($temp, $path)) {
                throw new RuntimeException('Could not publish the domain catalogue.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function snapshot(): array
    {
        try {
            $raw = Storage::disk('local')->get(self::SNAPSHOT_PATH);
            $snapshot = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($snapshot) || ($snapshot['version'] ?? null) !== 1
                || ! is_array($snapshot['rows'] ?? null) || ! is_numeric($snapshot['supplier_vat_pct'] ?? null)) {
                return [];
            }
            if (! is_int($snapshot['total'] ?? null) || $snapshot['total'] < 1 || $snapshot['total'] > self::MAX_ROWS
                || ! is_array($snapshot['excluded'] ?? null)
                || count($snapshot['rows']) + count($snapshot['excluded']) !== $snapshot['total']
                || array_intersect_key($snapshot['rows'], $snapshot['excluded']) !== []) {
                return [];
            }
            $timestamp = $snapshot['fetched_at'] ?? null;
            if (! is_string($timestamp) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $timestamp)) {
                return [];
            }
            $at = Carbon::createFromFormat('!Y-m-d\TH:i:sP', $timestamp);
            if ($at->format('Y-m-d\TH:i:sP') !== $timestamp) {
                return [];
            }
            if ($at->isBefore(now()->subHours(self::MAX_AGE_HOURS)) || $at->isAfter(now()->addMinutes(5))) {
                return [];
            }
            foreach ($snapshot['rows'] as $tld => $row) {
                if (! is_string($tld) || ! $this->validStoredRow($row)) {
                    return [];
                }
            }
            return $snapshot;
        } catch (\Throwable) {
            return [];
        }
    }

    private function validStoredRow(mixed $row): bool
    {
        if (! is_array($row) || ! is_int($row['min_years'] ?? null) || ! is_int($row['max_years'] ?? null)
            || $row['min_years'] < 1 || $row['max_years'] < $row['min_years'] || $row['max_years'] > 10) {
            return false;
        }
        foreach (['register', 'renew', 'transfer'] as $operation) {
            if (! array_key_exists($operation, $row)) {
                return false;
            }
            if ($row[$operation] === null && $operation !== 'register') {
                continue;
            }
            if (! $this->validStoredAmount($row[$operation]) || ($operation === 'register' && $row[$operation]['amount'] <= 0)) {
                return false;
            }
        }
        $promotion = $row['promotion'] ?? null;
        return $promotion === null || (is_array($promotion)
            && is_int($promotion['start'] ?? null) && is_int($promotion['end'] ?? null)
            && $promotion['start'] < $promotion['end'] && $this->validStoredAmount($promotion['regular'] ?? null)
            && $promotion['regular']['amount'] > 0);
    }

    private function validStoredAmount(mixed $amount): bool
    {
        return is_array($amount) && is_numeric($amount['amount'] ?? null)
            && is_finite((float) $amount['amount']) && $amount['amount'] >= 0
            && in_array($amount['currency'] ?? null, ['EUR', 'USD', 'USDT'], true);
    }

    private function normalise(array $tlds): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($tld) => strtolower(trim((string) $tld, " \t.")), $tlds
        ), fn ($tld) => $tld !== '' && DomainSearch::sells($tld))));
    }

    private function normaliseRow(array $row): array
    {
        $min = $row['min_period'] ?? null;
        $max = $row['max_period'] ?? null;
        if (! is_int($min) || ! is_int($max) || $min < 1 || $max < $min || $max > 10) {
            throw new RuntimeException('Invalid Openprovider registration period: '.$row['name']);
        }
        $prices = $row['prices'] ?? [];
        $create = $prices['reseller_price'] ?? $prices['create_price'] ?? null;
        $register = $this->amount(data_get($create, 'reseller'));
        if ($register === null || $register['amount'] <= 0) {
            throw new RuntimeException('Missing Openprovider registration price: '.$row['name']);
        }
        $promotion = data_get($create, 'promotion_details');
        if (is_array($promotion) && $promotion !== []) {
            $start = $this->promotionTime($promotion['start_date'] ?? null);
            $end = $this->promotionTime($promotion['end_date'] ?? null);
            $regular = $this->amount(data_get($promotion, 'non_promo_price.reseller'));
            if ($start === null || $end === null || $end <= $start || $regular === null || $regular['amount'] <= 0) {
                throw new RuntimeException('Invalid Openprovider promotion period: '.$row['name']);
            }
            $promotion = ['start' => $start, 'end' => $end, 'regular' => $regular];
        } else {
            $promotion = null;
        }
        return ['min_years' => $min, 'max_years' => $max, 'register' => $register,
            'renew' => ($row['renew_available'] ?? false) === true ? $this->amount(data_get($prices, 'renew_price.reseller')) : null,
            'transfer' => ($row['transfer_available'] ?? false) === true ? $this->amount(data_get($prices, 'transfer_price.reseller')) : null,
            'promotion' => $promotion];
    }

    private function amount(mixed $value): ?array
    {
        if (! is_array($value) || ! is_numeric($value['price'] ?? null)
            || ! is_finite((float) $value['price']) || $value['price'] < 0
            || ! in_array(strtoupper((string) ($value['currency'] ?? '')), ['USD', 'EUR', 'USDT'], true)) {
            return null;
        }
        return ['amount' => (float) $value['price'], 'currency' => strtoupper($value['currency'])];
    }

    private function promotionTime(mixed $value): ?int
    {
        try {
            if (is_numeric($value)) {
                $seconds = (int) $value;
                return $seconds >= 1000000000 && $seconds < 10000000000 ? $seconds : null;
            }
            return is_string($value) && $value !== '' ? Carbon::parse($value, 'UTC')->timestamp : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function convert(array $snapshot, ?array $tlds = null): array
    {
        $rows = $tlds === null ? $snapshot['rows'] : array_intersect_key($snapshot['rows'], array_flip($tlds));
        if ($rows === []) {
            return [];
        }
        $rates = [];
        $fx = app(ExchangeRate::class);
        foreach (['USD', 'EUR', 'USDT'] as $currency) {
            $rates[$currency] = (int) ($fx->current($currency)['rate_toman'] ?? 0);
        }
        $step = max(1, (int) (Currency::find('IRT')?->rounding_step ?: 1000));
        $pricing = app(LiveDomainPricing::class)->withSupplierVat((float) $snapshot['supplier_vat_pct']);
        $out = [];
        foreach ($rows as $tld => $row) {
            if (! DomainSearch::sells($tld)) {
                continue;
            }
            $promotion = $row['promotion'] ?? null;
            if (is_array($promotion) && (now()->timestamp < $promotion['start'] || now()->timestamp >= $promotion['end'])) {
                $row['register'] = $promotion['regular'];
            }
            $converted = ['min_years' => $row['min_years'], 'max_years' => $row['max_years']];
            foreach (['register', 'renew', 'transfer'] as $operation) {
                $amount = $row[$operation] ?? null;
                $converted[$operation] = $amount === null || $rates[$amount['currency']] <= 0 ? null : ($amount['amount'] == 0 ? 0 :
                    $pricing->fromAmount($amount['amount'], $amount['currency'], $tld,
                        $rates[$amount['currency']], $step)['sell_toman']);
            }
            if ($converted['register'] > 0) {
                $out[$tld] = $converted;
            }
        }
        return $out;
    }
}
