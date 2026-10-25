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
            if (Carbon::parse($cycle['expired_at'])->gte(now()->subDays(7))) {
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
            if ($before !== $after || count($rows) !== $total) {
                throw new RuntimeException('Supplier bill changed during pagination; retry later');
            }
            $reports[] = ['cycle' => $after, 'rows' => $rows, 'total_items' => $total];
        }

        return ['observed_at' => now()->toIso8601String(), 'current_cycle' => $current['id'], 'reports' => $reports];
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
                    if (! is_string($id) || $id === '' || isset($seenItems[$id])) {
                        throw new RuntimeException('Duplicate or missing supplier component');
                    }
                    $seenItems[$id] = true;
                    $p = self::integer($item['price']);
                    $d = self::integer($item['discount']);
                    $g = self::integer($item['usedGift']);
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
