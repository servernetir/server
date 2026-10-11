<?php

namespace App\Services\Cloud;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Read-only public website calculator; never uses account/OpenAPI credentials. */
class LightNodePublicPricing
{
    private bool $catalogSnapshot = false;
    private const ORIGIN = 'https://www.lightnode.com';
    public const SERIES = ['s2', 'g2', 's3', 'g2a'];
    // A rolling customer month can span two supplier calendar billing months;
    // its total is not guaranteed to receive the supplier's 672-hour cap.
    public const CALENDAR_MONTH_RESERVE_HOURS = 31 * 24;

    private function http()
    {
        return Http::acceptJson()->asJson()->connectTimeout(10)->timeout(30)
            ->withOptions(['allow_redirects' => false])
            ->retry(3, 500, fn (\Throwable $e) => $this->transientRead($e), throw: false);
    }

    private function transientRead(\Throwable $e): bool
    {
        return $e instanceof \Illuminate\Http\Client\ConnectionException
            || ($e instanceof \Illuminate\Http\Client\RequestException
                && ($e->response->serverError() || $e->response->status() === 429));
    }

    private function certificate(): array
    {
        $recipe = Cache::remember('lightnode-public-certificate-recipe', 3600, function (): array {
            $html = $this->http()->get(self::ORIGIN.'/product');
            if (! $html->successful() || strlen($html->body()) > 3000000
                || ! preg_match('~src="(/_next/static/chunks/pages/_app-[a-f0-9]+\.js)"~', $html->body(), $asset)) {
                throw new \RuntimeException('Public calculator bootstrap changed');
            }
            $script = $this->http()->get(self::ORIGIN.$asset[1]);
            $info = $this->http()->get(self::ORIGIN.'/__internal/site-info');
            // Parse the published anonymous certificate recipe, without executing
            // vendor code or copying a browser/account credential. Changed code
            // stops quotes rather than falling back to a saved rate or cookie.
            if (! $script->successful() || strlen($script->body()) > 3000000
                || ! $info->successful() || $info->json('id') !== 34
                || ! preg_match('~function r\(\)\{let e=parseInt\(new Date\(\)\.getTime\(\),10\),t=function\(e,t\)\{.{0,600}?\}\(12,32\);return.{0,300}?\}\}~s', $script->body(), $function)) {
                throw new \RuntimeException('Public calculator certificate unavailable');
            }
            preg_match_all('~"(?:\\\\.|[^"\\\\])*"~', $function[0], $strings);
            $values = array_map(fn ($s) => json_decode($s, true, 512, JSON_THROW_ON_ERROR), $strings[0]);
            if (count($values) !== 6 || strlen($values[0]) !== 62 || ! ctype_alnum($values[0])
                || count(array_unique(str_split($values[0]))) !== 62 || $values[1] !== '' || $values[2] !== ''
                || $values[3] !== 'Basic ' || $values[4] !== ':'
                || ! preg_match('/^[A-Za-z0-9:_-]{1,40}$/', $values[5])) {
                throw new \RuntimeException('Public calculator certificate schema changed');
            }
            return ['alphabet' => substr($values[0], 0, 32), 'suffix' => $values[5], 'domain_id' => $info->json('id')];
        });
        $nonce = '';
        for ($i = 0; $i < 12; $i++) { $nonce .= $recipe['alphabet'][random_int(0, 31)]; }
        return ['header' => 'Basic '.base64_encode((string) round(microtime(true) * 1000).':'.$nonce.$recipe['suffix']),
            'domain_id' => $recipe['domain_id']];
    }

    private function read(string $path, array $input = [], bool $post = false): array
    {
        $certificate = $this->certificate();
        $input += ['domainId' => $certificate['domain_id']];
        $http = $this->http()->withHeaders(['Authorization' => $certificate['header']])
            ->retry(3, 500, function (\Throwable $e, $pending): bool {
                if (! $this->transientRead($e)) { return false; }
                // Only anonymous configuration/quote reads use this transport.
                // A retry receives a fresh timestamp/nonce, never a saved cookie.
                $pending->withHeaders(['Authorization' => $this->certificate()['header']]);
                return true;
            }, throw: false);
        $response = $post ? $http->post(self::ORIGIN.$path, $input + ['timestamp' => (int) round(microtime(true) * 1000)])
            : $http->get(self::ORIGIN.$path, $input);
        if (! $response->successful() || $response->json('success') !== true
            || ! is_array($response->json('data')) || ! array_is_list($response->json('data'))) {
            throw new \RuntimeException('Public calculator response rejected');
        }
        return $response->json('data');
    }

    private function child(array $nodes, string $field, mixed $value): array
    {
        $matches = array_values(array_filter($nodes, fn ($n) => is_array($n)
            && ($n['itemName'] ?? '') === $field && ($n['value'] ?? null) === $value));
        if (count($matches) !== 1) { throw new \RuntimeException('Public calculator configuration mismatch'); }
        return $matches[0];
    }

    private function configuration(string $path, array $input): array
    {
        if (! $this->catalogSnapshot) { return $this->read($path, $input); }
        // Catalog variants share read-only region/spec trees for up to one
        // minute. Amounts are never cached; purchase quotes bypass this cache.
        return Cache::remember('lightnode-public-config:'.hash('sha256', $path.json_encode($input)), 60,
            fn () => $this->read($path, $input));
    }

    private function within(array $nodes, int $size): bool
    {
        foreach ($nodes as $node) {
            if (($node['itemName'] ?? '') !== 'diskSize') { continue; }
            if (is_int($node['value'] ?? null) && $node['value'] === $size) { return true; }
            $range = $node['value'] ?? [];
            if (is_array($range) && isset($range['min'], $range['max'])
                && $size >= $range['min'] && $size <= $range['max']
                && ($size - $range['min']) % max(1, (int) ($range['step'] ?? 1)) === 0) { return true; }
        }
        return false;
    }

    /** Callers supply receipt-verified or explicitly owner-accepted CPU metadata. */
    public function quote(array $package, string $series, string $os = 'linux', bool $fresh = true): array
    {
        $this->catalogSnapshot = ! $fresh;
        try {
            if (! in_array($series, self::SERIES, true) || ! in_array($os, ['linux', 'windows'], true)
                || ($os === 'windows' && (($package['cpu'] ?? 0) < 2 || ($package['memory'] ?? 0) < 4))) {
                throw new \RuntimeException('Unsupported calculator series or OS');
            }
            $region = $package['regionCode']; $zone = $package['zoneCode'];
            $query = ['region' => $region, 'zone' => $zone, 'functionName' => 'NORMAL'];
            $regions = $this->configuration('/api/site/sku/getRegionAndZoneDetailInfo.do', ['resourceType' => 'LIGHTNODE_ECS', 'functionName' => 'NORMAL']);
            $location = array_values(array_filter($regions, fn ($r) => ($r['regionCode'] ?? '') === $region));
            if (count($location) !== 1 || ($location[0]['saleStatus'] ?? '') !== 'IN_SELL'
                || count(array_filter($location[0]['zones'] ?? [], fn ($z) => ($z['zoneCode'] ?? '') === $zone
                    && ($z['saleStatus'] ?? '') === 'IN_SELL')) !== 1) {
                throw new \RuntimeException('Calculator region unavailable');
            }
            $compute = $this->configuration('/api/site/sku/getBuyConfigInfo.do', $query + ['resourceType' => 'LIGHTNODE_ECS']);
            $net = $this->child($compute, 'netType', 'privateNet');
            $type = $this->child($net['children'] ?? [], 'instanceType', $series);
            $sizes = array_values(array_filter($type['children'] ?? [], fn ($s) => ($s['itemName'] ?? '') === 'instanceSpec'
                && ($s['prop']['cpu'] ?? null) === (int) $package['cpu'] && ($s['prop']['memory'] ?? null) === (int) $package['memory']));
            if (count($sizes) !== 1 || empty($sizes[0]['value'])) { throw new \RuntimeException('Calculator CPU/RAM mismatch'); }
            $boot = $this->child($compute, 'diskType', 'System');
            $boot = $this->child($boot['children'] ?? [], 'diskCategory', 'local_ssd');
            $boot = $this->child($boot['children'] ?? [], 'image', $os);
            if (! $this->within($boot['children'] ?? [], (int) $package['systemDiskSize'])) { throw new \RuntimeException('Calculator boot disk mismatch'); }
            $network = $this->configuration('/api/site/sku/getBuyConfigInfo.do', $query + ['resourceType' => 'OWN_EIP']);
            $mode = $package['publicIpChargeMode'];
            $network = $this->child($network, 'chargeMode', $mode);
            $network = $this->child($network['children'] ?? [], 'bandwidthType', 'BGP');
            $defaults = array_values(array_filter($network['children'] ?? [], fn ($n) => ($n['itemName'] ?? '') === 'defaultBandwidth'));
            if (count($defaults) !== 1) { throw new \RuntimeException('Calculator network mismatch'); }
            $options = $this->child($defaults[0]['children'] ?? [], 'cpu', (int) $package['cpu']);
            $networkField = $mode === 'PayByTraffic' ? 'actualFlow' : 'bandwidth';
            $networkValue = (int) ($package[$mode === 'PayByTraffic' ? 'freeFlow' : 'bandwidth'] ?? 0);
            $this->child($options['children'] ?? [], $networkField, $networkValue);
            $resources = [['identifier' => 'LIGHTNODE_ECS', 'resourceType' => 'LIGHTNODE_ECS', 'zone' => $zone,
                'data' => ['cpu' => (int) $package['cpu'], 'memory' => (int) $package['memory'], 'instanceSpec' => $sizes[0]['value'],
                    'instanceType' => $series, 'netType' => 'privateNet', 'image' => $os, 'imageId' => '',
                    'autoRenew' => 'None', 'instanceChargeType' => 'PostPaid', 'region' => $region, 'zone' => $zone,
                    'subnetId' => '', 'vpcId' => '', 'multiItems' => [['diskCategory' => 'local_ssd',
                        'diskSize' => (int) $package['systemDiskSize'], 'diskType' => 'System', 'multiItemId' => 999]]]],
                ['identifier' => 'OWN_EIP', 'resourceType' => 'OWN_EIP', 'data' => ['bandwidthType' => 'BGP',
                    'chargeMode' => $mode, 'instanceChargeType' => 'PostPaid', 'region' => $region,
                    'bandwidth' => $mode === 'PayByTraffic' ? $defaults[0]['value'] : $networkValue] + ($mode === 'PayByTraffic' ? ['actualFlow' => $networkValue] : [])]];
            if ((int) ($package['dataDiskSize'] ?? 0) > 0) {
                $data = $this->configuration('/api/site/sku/getBuyConfigInfo.do', $query + ['resourceType' => 'OWN_DISK']);
                $data = $this->child($data, 'diskType', 'Data');
                $data = $this->child($data['children'] ?? [], 'diskCategory', 'local_ssd');
                if (! $this->within($data['children'] ?? [], (int) $package['dataDiskSize'])) { throw new \RuntimeException('Calculator data disk mismatch'); }
                $resources[] = ['identifier' => 'OWN_DISK', 'resourceType' => 'OWN_DISK', 'zone' => $zone,
                    'data' => ['diskCategory' => 'local_ssd', 'diskType' => 'Data', 'diskSize' => (int) $package['dataDiskSize'], 'region' => $region, 'zone' => $zone]];
            }
            $rows = $this->read('/api/site/sku/getBatchPrice.do', $query + ['resources' => $resources, 'billingCycle' => 'HOUR',
                'orderTypeEnum' => 'BUY_PRODUCT', 'time' => 1], true);
            return $this->normalize($rows, $mode, count($resources) === 3) + ['series' => $series, 'verified_at' => time()];
        } catch (\Throwable) {
            // No vendor body, source code, certificate or exception text in logs.
            return ['ok' => false, 'message' => 'قیمت و موجودی معتبر از محاسبه‌گر عمومی دریافت نشد.'];
        }
    }

    public function normalize(array $rows, string $mode, bool $dataDisk): array
    {
        if (! in_array($mode, ['PayByTraffic', 'PayByBandwidth'], true)) {
            throw new \RuntimeException('Unknown calculator billing mode');
        }
        $expected = ['LIGHTNODE_ECS' => ['instanceSpec', 'diskSize'], 'OWN_EIP' => [$mode === 'PayByTraffic' ? 'actualFlow' : 'bandwidth']];
        if ($dataDisk) { $expected['OWN_DISK'] = ['diskSize']; }
        $hour = 0.0; $month = 0.0; $seen = [];
        foreach ($rows as $row) {
            $id = $row['identifier'] ?? '';
            if (! isset($expected[$id]) || isset($seen[$id])) { throw new \RuntimeException('Unexpected calculator component'); }
            $seen[$id] = true; $itemNames = []; $sum = 0.0;
            foreach ($row['itemPrices'] ?? [] as $item) {
                $amount = $item['originPrice'] ?? null;
                if (! is_numeric($amount) || ! is_finite((float) $amount) || $amount <= 0
                    || ($item['discountPrice'] ?? null) != $amount || ($item['settlementType'] ?? '') !== 'Hour'
                    || ($item['unitName'] ?? '') !== ($id === 'OWN_EIP' && $mode === 'PayByTraffic' ? 'GB' : 'Hour')) {
                    throw new \RuntimeException('Invalid or promotional calculator amount');
                }
                $itemNames[] = $item['itemName'] ?? ''; $sum += (float) $amount;
            }
            sort($itemNames); $names = $expected[$id]; sort($names);
            if ($itemNames !== $names || abs($sum - (float) ($row['sumOriginPrice'] ?? -1)) > 0.000000001
                || ($row['sumDiscountPrice'] ?? null) != ($row['sumOriginPrice'] ?? null)) {
                throw new \RuntimeException('Incomplete calculator amount');
            }
            // The official calculator and billing guide prorate traffic packages
            // over 672 hours. Compute, storage and bandwidth quotes are hourly.
            $traffic = $id === 'OWN_EIP' && $mode === 'PayByTraffic';
            $hour += $traffic ? $sum / 672 : $sum;
            $month += $traffic ? $sum : $sum * 672;
        }
        if (count($seen) !== count($expected) || $hour <= 0 || ! is_finite($hour) || ! is_finite($month)) {
            throw new \RuntimeException('Missing calculator amount');
        }
        $calculatorMonth = $month;
        if ($mode === 'PayByBandwidth') {
            $month = $hour * self::CALENDAR_MONTH_RESERVE_HOURS;
        }
        return ['ok' => true, 'source' => 'public_calculator', 'currency' => 'USD',
            'hour_usd' => number_format($hour, 12, '.', ''), 'month_usd' => number_format($month, 12, '.', ''),
            'calculator_month_usd' => number_format($calculatorMonth, 12, '.', '')];
    }
}
