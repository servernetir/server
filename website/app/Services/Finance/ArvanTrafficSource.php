<?php

namespace App\Services\Finance;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Read-only supplier finance API. Never infer billable money from network charts. */
final class ArvanTrafficSource
{
    private const BASE = 'https://napi.arvancloud.ir/resid/v1/';

    private function get(string $path, array $query = []): array
    {
        $token = Setting::getSecret('arvan_api_token');
        if (! $token) {
            throw new RuntimeException('Traffic finance credential unavailable');
        }
        $response = Http::withHeaders(['Authorization' => $token])->acceptJson()
            ->connectTimeout(10)->timeout(30)->get(self::BASE.$path, $query);
        if ($response->status() !== 200 || ! is_array($response->json('data'))) {
            throw new RuntimeException('Traffic finance API failed: HTTP '.$response->status());
        }

        return $response->json();
    }

    public function fetch(array $knownCycles = []): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->fetchStable($knownCycles);
            } catch (RuntimeException $e) {
                if ($attempt === 2 || ! in_array($e->getMessage(), [
                    'Supplier bill changed during pagination; retry later',
                    'Incomplete or changing traffic pagination',
                ], true)) {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('Stable supplier traffic snapshot unavailable');
    }

    private function fetchStable(array $knownCycles): array
    {
        $current = $this->get('current-billing-cycle')['data'];
        $ids = [$current['id']];
        foreach ($knownCycles as $id => $cycle) {
            if (($cycle['pending'] ?? false) === true || Carbon::parse($cycle['expired_at'])->gte(now()->subDays(7))) {
                $ids[] = $id;
            }
        }
        $reports = [];
        foreach (array_unique($ids) as $id) {
            if (! is_string($id) || ! preg_match('/^[a-f0-9-]{36}$/D', $id)) {
                throw new RuntimeException('Invalid supplier cycle identifier');
            }
            $path = 'billing-cycles/'.$id;
            $before = $this->get($path)['data'];
            $rows = [];
            $total = null;
            $pages = null;
            for ($page = 1; $page <= ($pages ?? 1); $page++) {
                $result = $this->get($path.'/usage-items', ['page' => $page, 'perPage' => 20]);
                $meta = data_get($result, 'meta.pagination', []);
                if (! is_int($meta['totalItems'] ?? null) || ! is_int($meta['totalPages'] ?? null)
                    || ($meta['current'] ?? null) !== $page || $meta['totalPages'] < 1 || $meta['totalPages'] > 100
                    || ($pages !== null && ($meta['totalPages'] !== $pages || $meta['totalItems'] !== $total))) {
                    throw new RuntimeException('Incomplete or changing traffic pagination');
                }
                $pages = $meta['totalPages'];
                $total = $meta['totalItems'];
                if (! array_is_list($result['data']) || count($result['data']) > 100) {
                    throw new RuntimeException('Invalid usage page');
                }
                array_push($rows, ...$result['data']);
            }
            $after = $this->get($path)['data'];
            if ($this->canonicalCycle($before) !== $this->canonicalCycle($after) || count($rows) !== $total) {
                throw new RuntimeException('Supplier bill changed during pagination; retry later');
            }
            $reports[] = ['cycle' => $after, 'rows' => $rows, 'total_items' => $total];
        }

        return ['observed_at' => now()->toIso8601String(), 'current_cycle' => $current['id'], 'reports' => $reports];
    }

    /** Product summaries are an unordered API list; changes to values still invalidate the read. */
    private function canonicalCycle(array $cycle): array
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            $list = array_is_list($value);
            foreach ($value as $key => $child) {
                $value[$key] = $canonical($child);
            }
            if (! $list) {
                ksort($value);
            }

            return $value;
        };
        $cycle = $canonical($cycle);
        if (isset($cycle['items']) && is_array($cycle['items']) && array_is_list($cycle['items'])) {
            usort($cycle['items'], fn ($a, $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        }

        return $cycle;
    }

    public static function integer(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^\d{1,13}$/D', $value) || (int) $value > 1_000_000_000_000) {
            throw new RuntimeException('Invalid integer IRR supplier amount');
        }

        return (int) $value;
    }

    /** Validate every parent and component, including non-traffic products, before charging any owner. */
    public function validate(array $snapshot): array
    {
        $seenCycles = [];
        foreach ($snapshot['reports'] as $report) {
            $cycle = $report['cycle'];
            if (($cycle['currency'] ?? null) !== 'irr' || isset($seenCycles[$cycle['id']])
                || count($report['rows']) !== $report['total_items']) {
                throw new RuntimeException('Invalid traffic bill currency, cycle or row count');
            }
            $seenCycles[$cycle['id']] = true;
            $prices = $discounts = $gifts = 0;
            $seenItems = [];
            foreach ($report['rows'] as $row) {
                $price = $discount = $gift = 0;
                foreach ($row['items'] as $item) {
                    $id = $item['itemId'] ?? null;
                    if (! is_string($id) || $id === '') {
                        throw new RuntimeException('Duplicate or missing supplier component');
                    }
                    $p = self::integer($item['price']);
                    $d = self::integer($item['discount']);
                    $g = self::integer($item['usedGift']);
                    // Supplier sometimes repeats the SAME zero-cost tenant outbound item in a zero-cost parent.
                    // Preserve the raw source and parent checks; this item is never a customer charge or volume.
                    $freeSend = ($item['metric'] ?? '') === 'cc_internet_send'
                        && ($row['scope'] ?? '') === 'tenant' && ($row['productName'] ?? '') === 'iaas'
                        && $p === 0 && $d === 0 && $g === 0
                        && self::integer($row['totalPrice']) === 0 && self::integer($row['totalDiscount']) === 0
                        && self::integer($row['totalUsedGift']) === 0 && self::integer($row['netPrice']) === 0;
                    // Exact repeated zero-cost CDN parents cannot change the IaaS bill.
                    // Paid duplicates, changed payloads, other products and IaaS internet remain rejected.
                    $freeCdn = ($row['productName'] ?? '') === 'cdn'
                        && str_starts_with((string) ($item['metric'] ?? ''), 'cdn_')
                        && $p === 0 && $d === 0 && $g === 0
                        && self::integer($row['totalPrice']) === 0 && self::integer($row['totalDiscount']) === 0
                        && self::integer($row['totalUsedGift']) === 0 && self::integer($row['netPrice']) === 0;
                    $repeatableZero = $freeSend || $freeCdn;
                    $fingerprint = hash('sha256', json_encode([
                        $row['scope'] ?? null, $row['productName'] ?? null, $row['region'] ?? null,
                        $row['instanceId'] ?? null, $this->canonicalCycle($item), $freeCdn ? $this->canonicalCycle($row) : null,
                    ], JSON_THROW_ON_ERROR));
                    if (isset($seenItems[$id]) && (! $repeatableZero || ! $seenItems[$id]['repeatable_zero']
                        || ! hash_equals($seenItems[$id]['fingerprint'], $fingerprint))) {
                        throw new RuntimeException('Duplicate or missing supplier component');
                    }
                    $seenItems[$id] = ['repeatable_zero' => $repeatableZero, 'fingerprint' => $fingerprint];
                    if ($d + $g > $p) {
                        throw new RuntimeException('Supplier discount exceeds price');
                    }
                    $price += $p;
                    $discount += $d;
                    $gift += $g;
                }
                if ($price !== self::integer($row['totalPrice']) || $discount !== self::integer($row['totalDiscount'])
                    || $gift !== self::integer($row['totalUsedGift']) || $price - $discount - $gift !== self::integer($row['netPrice'])) {
                    throw new RuntimeException('Supplier parent/component totals differ');
                }
                $prices += $price;
                $discounts += $discount;
                $gifts += $gift;
            }
            if ($prices !== self::integer($cycle['totalItemsPrice']) || $discounts !== self::integer($cycle['totalItemsDiscount'])
                || $gifts !== self::integer($cycle['totalUsedGift'])
                || $prices - $discounts - $gifts + self::integer($cycle['totalVatPrice']) !== self::integer($cycle['totalPrice'])) {
                throw new RuntimeException('Supplier cycle totals differ');
            }
        }
        if (! isset($seenCycles[$snapshot['current_cycle']])) {
            throw new RuntimeException('Current cycle missing');
        }

        return $snapshot;
    }
}
