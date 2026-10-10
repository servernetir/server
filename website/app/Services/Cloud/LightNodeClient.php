<?php

namespace App\Services\Cloud;

use App\Models\CloudLocation;
use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\CloudProviderOperation;
use App\Models\Setting;
use App\Services\ExchangeRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Official OpenAPI lifecycle; anonymous public calculator for supplier quotes. */
class LightNodeClient implements CloudProvider
{
    private const BASE = 'https://openapi.lightnode.com';

    public function slug(): string { return 'lightnode'; }

    public function isConfigured(): bool
    {
        return filled(Setting::getSecret('lightnode_api_token'));
    }

    private function request(string $method, string $path, array $data = []): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'کلید زیرساخت تنظیم نشده است.'];
        }
        try {
            // Do not retry mutations or follow redirects with a private token.
            $http = Http::withHeaders(['x-open-token' => Setting::getSecret('lightnode_api_token')])
                ->acceptJson()->asJson()->connectTimeout(10)->timeout(30)
                ->withOptions(['allow_redirects' => false]);
            $response = $method === 'GET'
                ? $http->get(self::BASE.$path, $data)
                : $http->post(self::BASE.$path, $data);
            $body = $response->json();
            if (! $response->successful() || ! is_array($body)
                || ($body['success'] ?? true) === false
                || (isset($body['httpStatus']) && (int) $body['httpStatus'] >= 400)) {
                return ['ok' => false, 'message' => 'پاسخ زیرساخت معتبر نیست (HTTP '.$response->status().').',
                    'http_status' => $response->status(),
                    'transient' => $response->status() === 429 || $response->serverError()];
            }
            return ['ok' => true, 'message' => '', 'data' => $body];
        } catch (\Throwable) {
            // Exception text and provider bodies may contain credentials.
            return ['ok' => false, 'message' => 'ارتباط با زیرساخت برقرار نشد.', 'transient' => true];
        }
    }

    private function rows(string $path, string $field, array $query = [], bool $paged = false): array
    {
        $all = []; $seen = [];
        for ($page = 1; $page <= 1000; $page++) {
            $r = $this->request('GET', $path, $query + ($paged ? ['page' => $page, 'pageSize' => 50] : []));
            if (! $r['ok']) { return $r; }
            $rows = $r['data'][$field] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                return ['ok' => false, 'message' => 'فهرست زیرساخت ناقص یا ناشناخته است.'];
            }
            foreach ($rows as $row) {
                if (! is_array($row) || array_is_list($row)) {
                    return ['ok' => false, 'message' => 'ردیف فهرست زیرساخت معتبر نیست.'];
                }
            }
            $hash = hash('sha256', json_encode($rows));
            if ($rows !== [] && isset($seen[$hash])) {
                return ['ok' => false, 'message' => 'صفحه‌بندی زیرساخت تکراری است؛ فهرست کامل تأیید نشد.'];
            }
            $seen[$hash] = true;
            $all = array_merge($all, $rows);
            // rowCount is not assumed to be a total: stop only on a short page.
            if (! $paged || count($rows) < 50) {
                return ['ok' => true, 'message' => '', 'rows' => $all];
            }
        }
        return ['ok' => false, 'message' => 'فهرست کامل زیرساخت دریافت نشد.'];
    }

    public function testConnection(): array
    {
        $r = $this->rows('/region/list', 'regions');
        return ['ok' => $r['ok'], 'message' => $r['ok'] ? 'اتصال برقرار است؛ قیمت و ظرفیت هنوز نیازمند تأیید است.' : $r['message'],
            'meta' => ['regions' => count($r['rows'] ?? [])]];
    }

    private function packages(string $region, string $zone, ?string $code = null): array
    {
        $query = ['regionCode' => $region, 'zoneCode' => $zone];
        if ($code !== null) { $query['packageCode'] = $code; }
        $r = $this->rows('/package/list', 'packages', $query);
        if (! $r['ok']) { return $r; }
        $unique = [];
        foreach ($r['rows'] as $p) {
            $ref = $p['packageCode'] ?? null;
            if (! $ref || ($p['regionCode'] ?? '') !== $region || ($p['zoneCode'] ?? '') !== $zone) {
                return ['ok' => false, 'message' => 'هویت بستهٔ منطقه معتبر نیست.'];
            }
            // Live Quito returns identical package rows twice. Conflicting rows
            // for one identity are unsafe; identical duplicates are harmless.
            if (isset($unique[$ref]) && $unique[$ref] != $p) {
                return ['ok' => false, 'message' => 'مشخصات یک شناسهٔ بسته متناقض است.'];
            }
            $unique[$ref] = $p;
        }
        return ['ok' => true, 'message' => '', 'rows' => array_values($unique)];
    }

    private function manifest(): array
    {
        $value = Setting::get('lightnode_manifest');
        $defaults = (array) config('lightnode.manifest', []);
        $reviewed = filled($value) ? (array) json_decode($value, true) : [];
        return ['regions' => array_replace($defaults['regions'] ?? [], $reviewed['regions'] ?? []),
            'packages' => $reviewed['packages'] ?? $defaults['packages'] ?? [],
            'bindings' => $reviewed['bindings'] ?? $defaults['bindings'] ?? []];
    }

    public function fetchCatalog(): array
    {
        if (! $this->fixtureRatesEnabled() && config('lightnode.public_pricing_enabled') !== true) {
            return ['ok' => false,
                'message' => 'دریافت خودکار قیمت LightNode هنوز فعال نشده است؛ قیمت دستی مبنای فروش نیست.',
                'locations' => [], 'plans' => [], 'images' => []];
        }
        try {
            return $this->catalog();
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'کاتالوگ و نرخ معتبر دریافت نشد.', 'locations' => [], 'plans' => [], 'images' => []];
        }
    }

    private function reviewedRate(string $ref, array $package): ?array
    {
        if (config('lightnode.public_pricing_enabled') === true) {
            $binding = $this->manifest()['bindings'][$ref] ?? [];
            // Package codes contain no authoritative CPU-series field. Do not
            // infer shared/dedicated from a name, or carry manual prices over.
            if (! in_array($binding['series'] ?? null, LightNodePublicPricing::SERIES, true)
                || ! in_array($binding['cpu_kind'] ?? null, ['shared', 'dedicated'], true)
                || ($binding['spec'] ?? null) != $this->packageSpec($package)
                || ! is_numeric($binding['verified_at'] ?? null)
                || $binding['verified_at'] > time() || $binding['verified_at'] < time() - 30 * 86400
                // No supported API setter for limitFlow has been verified.
                || ($package['publicIpChargeMode'] ?? '') !== 'PayByBandwidth') { return null; }
            $quote = app(LightNodePublicPricing::class)->quote($package, $binding['series']);
            if (($quote['ok'] ?? false) !== true || ($quote['source'] ?? '') !== 'public_calculator'
                || ($quote['currency'] ?? '') !== 'USD') { return null; }
            // One plan has one supplier price. Windows must have the same exact
            // quote before its images can be offered against that plan.
            if ((int) $package['cpu'] >= 2 && (int) $package['memory'] >= 4) {
                $windows = app(LightNodePublicPricing::class)->quote($package, $binding['series'], 'windows');
                if (($windows['ok'] ?? false) !== true || ($windows['source'] ?? '') !== 'public_calculator'
                    || ($windows['currency'] ?? '') !== 'USD'
                    || ($windows['hour_usd'] ?? null) !== $quote['hour_usd']
                    || ($windows['month_usd'] ?? null) !== $quote['month_usd']) { return null; }
            }
            return $quote + ['cpu_kind' => $binding['cpu_kind'], 'delivery_verified' => ($binding['delivery_verified'] ?? false) === true,
                'spec' => $this->packageSpec($package)];
        }
        if (! $this->fixtureRatesEnabled()) { return null; }
        $rate = $this->manifest()['packages'][$ref] ?? [];
        $at = $rate['verified_at'] ?? null;
        if (! is_numeric($at) || $at > time() || $at < time() - 7 * 86400
            || ! is_numeric($rate['hour_usd'] ?? null) || $rate['hour_usd'] <= 0
            || ! is_numeric($rate['month_usd'] ?? null) || $rate['month_usd'] <= 0
            || ! is_finite((float) $rate['hour_usd']) || ! is_finite((float) $rate['month_usd'])
            || ($rate['traffic_policy_verified'] ?? false) !== true
            || ($rate['spec'] ?? null) != $this->packageSpec($package)) {
            return null;
        }
        return $rate;
    }

    private function supplierCost(array $rate): ?array
    {
        $usd = (int) app(ExchangeRate::class)->toToman('USD');
        $pricing = app(CloudPricing::class); $eur = $pricing->eurToToman();
        if ($usd <= 0 || $eur <= 0) { return null; }
        return ['cost_eur_cents' => (int) ceil($pricing->costWithFee((float) $rate['month_usd'] * $usd / $eur, $this->slug()) * 100),
            'cost_hour_eur_micro' => (int) ceil($pricing->costWithFee((float) $rate['hour_usd'] * $usd / $eur, $this->slug()) * 1000000)];
    }

    private function purchasePriceMatches(string $ref, array $rate, array $package): bool
    {
        if (($rate['source'] ?? '') !== 'public_calculator') { return $this->fixtureRatesEnabled(); }
        $plan = CloudPlan::where('provider', $this->slug())->where('provider_ref', $ref)->first();
        $cost = $this->supplierCost($rate);
        // A fresh quote may be cheaper, but a price/FX/fee increase needs a new
        // catalog/order review before any irreversible supplier purchase.
        return $plan && $cost && (int) $plan->cost_eur_cents > 0 && (int) $plan->cost_hour_eur_micro > 0
            && $cost['cost_eur_cents'] <= (int) $plan->cost_eur_cents
            && $cost['cost_hour_eur_micro'] <= (int) $plan->cost_hour_eur_micro
            && (int) $plan->vcpu === (int) $package['cpu']
            && (int) $plan->ram_mb === (int) $package['memory'] * 1024
            && $plan->cpu_kind === $rate['cpu_kind']
            && (int) $plan->boot_disk_gb === (int) $package['systemDiskSize']
            && (int) $plan->data_disk_gb === (int) ($package['dataDiskSize'] ?? 0)
            && ($plan->network_terms['charge_mode'] ?? '') === $package['publicIpChargeMode']
            && (int) ($plan->network_terms['bandwidth_mbps'] ?? 0) === (int) $package['bandwidth'];
    }

    private function fixtureRatesEnabled(): bool
    {
        return app()->environment('testing') && config('lightnode.allow_fixture_rates') === true;
    }

    private function catalog(): array
    {
        $empty = ['locations' => [], 'plans' => [], 'images' => []];
        $regions = $this->rows('/region/list', 'regions');
        if (! $regions['ok']) { return $regions + $empty; }
        $manifest = $this->manifest();
        $usd = (int) app(ExchangeRate::class)->toToman('USD');
        $eur = app(CloudPricing::class)->eurToToman();
        if ($usd <= 0 || $eur <= 0) {
            return ['ok' => false, 'message' => 'نرخ معتبر دلار و یورو برای قیمت‌گذاری در دسترس نیست.'] + $empty;
        }
        $locations = []; $plans = []; $images = [];
        foreach ($regions['rows'] as $region) {
            $regionCode = (string) ($region['regionCode'] ?? '');
            $meta = $manifest['regions'][$regionCode] ?? null;
            if (! is_array($meta) || ! isset(CloudLocation::COUNTRIES[$meta['country'] ?? ''])
                || ($meta['country'] ?? '') === 'XX' || empty($meta['city'])
                || CloudLocation::isLegacyCode($meta['location_code'] ?? null)) { continue; }
            $code = $meta['location_code'];
            $locations[$code] = ['code' => $code, 'country' => $meta['country'], 'city' => $meta['city'],
                'provider_location' => $regionCode, 'latitude' => null, 'longitude' => null];
            $imageRows = $this->rows('/image/list', 'images', ['regionCode' => $regionCode, 'imageType' => 'System'], true);
            if (! $imageRows['ok']) { return $imageRows + $empty; }
            foreach ($imageRows['rows'] as $image) {
                if (($image['imageType'] ?? '') !== 'System' || empty($image['imageResourceUUID'])) { continue; }
                $label = (string) ($image['osVersionDetail'] ?? $image['imageName'] ?? '');
                $family = strtolower((string) ($image['osDistroVersion'] ?? ''));
                $family = match (true) {
                    str_contains($family, 'windows') => 'windows',
                    str_contains($family, 'alma') => 'almalinux',
                    str_contains($family, 'rocky') => 'rocky',
                    default => $family,
                };
                if (! in_array($family, ['ubuntu', 'debian', 'windows', 'centos', 'almalinux', 'rocky', 'fedora', 'freebsd', 'arch', 'opensuse', 'alpine'], true)
                    || ! preg_match('/\b(\d+(?:\.\d+)?)\b/', $label, $version)) { continue; }
                // EN and ZH otherwise collapse into the same customer image key.
                if ($family === 'windows' && preg_match('/\b(?:ZH|CN)\b/i', $label.' '.($image['imageName'] ?? ''))) { continue; }
                $images[] = ['provider_ref' => $regionCode.'|'.$image['imageResourceUUID'],
                    'key' => $family.'-'.$version[1], 'kind' => 'os', 'family' => $family,
                    'version' => $version[1], 'label' => $label, 'arch' => 'x86',
                    'min_disk_gb' => (int) ($image['imageSize'] ?? 50),
                    'min_ram_mb' => $family === 'windows' ? 4096 : 512];
            }
            foreach ((array) ($region['zones'] ?? []) as $zone) {
                $zoneCode = (string) ($zone['zoneCode'] ?? '');
                if ($zoneCode === '') { continue; }
                $packageRows = $this->packages($regionCode, $zoneCode);
                if (! $packageRows['ok']) { return $packageRows + $empty; }
                foreach ($packageRows['rows'] as $p) {
                    if (($p['regionCode'] ?? '') !== $regionCode || ($p['zoneCode'] ?? '') !== $zoneCode
                        || (int) ($p['cpu'] ?? 0) <= 0 || (int) ($p['memory'] ?? 0) <= 0
                        || (int) ($p['systemDiskSize'] ?? 0) <= 0 || empty($p['packageCode'])) { continue; }
                    if (! in_array($p['publicIpChargeMode'] ?? '', ['PayByTraffic', 'PayByBandwidth'], true)
                        || (($p['publicIpChargeMode'] ?? '') === 'PayByBandwidth' && (int) ($p['bandwidth'] ?? 0) <= 0)
                        || (($p['publicIpChargeMode'] ?? '') === 'PayByTraffic' && (int) ($p['freeFlow'] ?? 0) <= 0)) { continue; }
                    $ref = $regionCode.'|'.$zoneCode.'|'.$p['packageCode'];
                    $rate = $this->reviewedRate($ref, $p);
                    // Rates include compute, disk, IPv4 and the chosen network plan.
                    // Traffic overage must be resolved before sale, not hidden in a margin.
                    $hour = $rate['hour_usd'] ?? null;
                    $month = $rate['month_usd'] ?? null;
                    if ($rate === null) { continue; }
                    $pricing = app(CloudPricing::class);
                    $plans[] = ['provider_ref' => $ref, 'provider_location' => $regionCode.'|'.$zoneCode,
                        'location_code' => $code, 'vcpu' => (int) $p['cpu'], 'ram_mb' => (int) $p['memory'] * 1024,
                        // A separate data disk is NOT the boot volume.
                        'disk_gb' => (int) $p['systemDiskSize'] + (int) ($p['dataDiskSize'] ?? 0), 'disk_type' => 'ssd',
                        'boot_disk_gb' => (int) $p['systemDiskSize'], 'data_disk_gb' => (int) ($p['dataDiskSize'] ?? 0),
                        'traffic_gb' => (int) ($p['freeFlow'] ?? 0), 'cpu_kind' => $rate['cpu_kind'] ?? 'shared', 'arch' => 'x86',
                        'network_terms' => ['bandwidth_mbps' => isset($p['bandwidth']) ? (int) $p['bandwidth'] : null,
                            'monthly_allowance_gb' => isset($p['freeFlow']) ? (int) $p['freeFlow'] : null,
                            'hourly_prorated' => $p['publicIpChargeMode'] === 'PayByTraffic',
                            'boot_disk_gb' => (int) $p['systemDiskSize'], 'data_disk_gb' => (int) ($p['dataDiskSize'] ?? 0),
                            'charge_mode' => (string) $p['publicIpChargeMode']],
                        'cost_eur_cents' => (int) ceil($pricing->costWithFee((float) $month * $usd / $eur, $this->slug()) * 100),
                        'cost_hour_eur_micro' => (int) ceil($pricing->costWithFee((float) $hour * $usd / $eur, $this->slug()) * 1000000),
                        'in_stock' => config('lightnode.sales_enabled') === true && ($rate['delivery_verified'] ?? false) === true,
                        'name' => $p['packageCode']];
                }
            }
        }
        return ['ok' => true, 'message' => count($plans).' پلن با بهای تأییدشده؛ عرضه فقط پس از تأیید تحویل باز می‌شود.',
            'locations' => array_values($locations), 'plans' => $plans, 'images' => $images];
    }

    public function packageSpec(array $p): array
    {
        $spec = [];
        foreach (['cpu', 'memory', 'systemDiskSize', 'dataDiskSize', 'freeFlow', 'bandwidth', 'publicIpChargeMode'] as $field) {
            $spec[$field] = $p[$field] ?? null;
        }
        return $spec;
    }

    private function mapped(array $row): array
    {
        $status = match ($row['ecsStatus'] ?? '') {
            'STARTED' => ($row['ecsPendingStatus'] ?? 'NONE') === 'NONE' ? 'running' : 'building',
            'STOPPED' => ($row['ecsPendingStatus'] ?? 'NONE') === 'NONE' ? 'off' : 'building',
            'CREATING', 'STARTING', 'RESTARTING', 'REINSTALLING' => 'building',
            'RELEASED', 'DELETED' => 'deleted', default => 'unknown',
        };
        $ip = filter_var($row['publicIpAddress'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: null;
        return ['ok' => true, 'message' => '', 'status' => $status, 'ipv4' => $ip, 'ipv6' => null,
            'traffic_used_gb' => isset($row['usedFlow']) ? (float) $row['usedFlow'] : null];
    }

    public function listServers(): array
    {
        $regions = $this->rows('/region/list', 'regions');
        if (! $regions['ok']) { return $regions + ['servers' => []]; }
        $servers = [];
        foreach ($regions['rows'] as $region) {
            if (empty($region['regionCode']) || ! is_array($region['zones'] ?? null)) {
                return ['ok' => false, 'message' => 'مناطق زیرساخت کامل نیست.', 'servers' => []];
            }
            foreach ($region['zones'] as $zone) {
                if (empty($zone['zoneCode'])) { return ['ok' => false, 'message' => 'ناحیه نامعتبر است.', 'servers' => []]; }
                $r = $this->rows('/instance/list', 'instances', ['regionCode' => $region['regionCode'], 'zoneCode' => $zone['zoneCode']], true);
                if (! $r['ok']) { return $r + ['servers' => []]; }
                foreach ($r['rows'] as $row) {
                    if (empty($row['ecsResourceUUID']) || empty($row['instanceName'])) {
                        return ['ok' => false, 'message' => 'فهرست ماشین‌ها ناقص است.', 'servers' => []];
                    }
                    $ref = $row['ecsResourceUUID'];
                    $servers[$ref] = $this->mapped($row) + ['ref' => $ref, 'name' => $row['instanceName'],
                        'plan' => $row['packageCode'] ?? null, 'location' => $row['regionCode'] ?? $region['regionCode'],
                        'created' => $row['createTime'] ?? null];
                }
            }
        }
        return ['ok' => true, 'message' => '', 'servers' => array_values($servers)];
    }

    /** Complete inventory of the immutable region/zone recorded before purchase. */
    private function regionalServers(string $region, string $zone): array
    {
        $r = $this->rows('/instance/list', 'instances', ['regionCode' => $region, 'zoneCode' => $zone], true);
        if (! $r['ok']) { return $r + ['servers' => []]; }
        $servers = [];
        foreach ($r['rows'] as $row) {
            if (empty($row['ecsResourceUUID']) || empty($row['instanceName'])) {
                return ['ok' => false, 'message' => 'موجودی منطقه ناقص است.', 'servers' => []];
            }
            $servers[] = $this->mapped($row) + ['ref' => $row['ecsResourceUUID'], 'name' => $row['instanceName']];
        }
        return ['ok' => true, 'message' => '', 'servers' => $servers];
    }

    public function serverStatus(string $ref): array
    {
        try {
            $op = CloudProviderOperation::where('provider', $this->slug())->where('action', 'create')->where('resource_ref', $ref)->first();
            if ($op && ! $this->taskSucceeded($op)) {
                if ($op->state === 'failed') {
                    return ['ok' => false, 'message' => 'عملیات ساخت پایان یافته ولی موفق نشده است؛ بررسی مدیر لازم است.',
                        'status' => 'unknown', 'ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
                }
                return ['ok' => true, 'message' => 'ساخت هنوز تأیید نشده است.', 'status' => 'building', 'ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
            }
            $pending = $this->reconcileLifecycle($ref);
            if ($pending !== null) { return $pending; }
            $r = $this->request('GET', '/instance/detail', ['ecsResourceUUID' => $ref]);
            if (! $r['ok'] || ! is_array($r['data']['instance'] ?? null)
                || ($r['data']['instance']['ecsResourceUUID'] ?? '') !== $ref) {
                return ['ok' => false, 'message' => $r['message'] ?: 'جزئیات ماشین معتبر نیست.', 'status' => 'unknown', 'ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
            }
            if ($op && isset($op->payload['_expected_spec']) && ! $this->receiptMatches($op, $r['data']['instance'])) {
                return ['ok' => false, 'message' => 'منابع، منطقه یا کنترل هزینهٔ ترافیک ماشین با سفارش تأییدشده تطبیق ندارد؛ بررسی مدیر لازم است.',
                    'status' => 'unknown', 'ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
            }
            return $this->mapped($r['data']['instance']);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'وضعیت عملیات قابل بررسی نیست.', 'status' => 'unknown', 'ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
        }
    }

    private function taskSucceeded(CloudProviderOperation $op): bool
    {
        if ($op->state === 'success') { return true; }
        if ($op->state === 'failed') { return false; }
        if (! $op->task_ref) { return false; }
        $r = $this->request('GET', '/asynctask/getResult', ['asyncTaskUUID' => $op->task_ref]);
        if ($r['ok'] && ($r['data']['asyncTaskInfo']['processResult'] ?? '') === 'SUCCESS') {
            $op->update(['state' => 'success']);
            return true;
        }
        if ($r['ok'] && ($r['data']['asyncTaskInfo']['taskStatus'] ?? '') === 'FINISHED') {
            $op->update(['state' => 'failed']);
        }
        return false;
    }

    private function receiptMatches(CloudProviderOperation $op, array $row): bool
    {
        $expected = $op->payload['_expected_spec'];
        $config = $op->payload['packageConfig'];
        if (isset($op->payload['_expected_series'])
            && ($row['instanceSeries'] ?? null) !== $op->payload['_expected_series']) { return false; }
        if (($row['regionCode'] ?? '') !== $config['regionCode'] || ($row['zoneCode'] ?? '') !== $config['zoneCode']) { return false; }
        foreach (['cpu', 'memory', 'systemDiskSize', 'dataDiskSize'] as $field) {
            if ((int) ($row[$field] ?? 0) !== (int) ($expected[$field] ?? 0)) { return false; }
        }
        if (($expected['publicIpChargeMode'] ?? '') === 'PayByTraffic') {
            if (($row['internetChargeType'] ?? '') !== 'PAY_BY_TRAFFIC'
                || (int) ($row['freeFlow'] ?? 0) !== (int) $expected['freeFlow']
                || ! in_array($row['limitFlow'] ?? null, [true, 'TRUE'], true)) { return false; }
        } elseif (($row['internetChargeType'] ?? '') !== 'PAY_BY_BANDWIDTH'
            || (int) ($row['bandwidth'] ?? 0) !== (int) ($expected['bandwidth'] ?? 0)) { return false; }
        $delivered = CloudInstance::where('provider', $this->slug())->where('provider_ref', $op->resource_ref)
            ->whereNotNull('ready_notified_at')->exists();
        return $delivered || ($row['imageResourceUUID'] ?? '') === $config['imageResourceUUID'];
    }

    private function password(): string { return 'Sn9!'.bin2hex(random_bytes(10)); }

    /** Poll without repeating a mutation; apply credentials only after SUCCESS. */
    private function reconcileLifecycle(string $ref): ?array
    {
        $pending = ['ok' => true, 'message' => 'عملیات در حال انجام است.', 'status' => 'building',
            'ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
        $lock = Cache::lock('lightnode-resource-'.hash('sha256', $ref), 120);
        if (! $lock->get()) { return $pending; }
        try {
            $ops = CloudProviderOperation::where('provider', $this->slug())->where('resource_ref', $ref)
                ->whereNotIn('action', ['create', 'release'])->get();
            foreach ($ops as $op) {
                if (! $this->taskSucceeded($op)) {
                    return $op->state === 'failed'
                        ? array_replace($pending, ['ok' => false, 'status' => 'unknown', 'message' => 'عملیات پایان یافته ولی موفق نشده است؛ بررسی مدیر لازم است.'])
                        : $pending;
                }
                DB::transaction(function () use ($op, $ref): void {
                    $instance = CloudInstance::where('provider', $this->slug())->where('provider_ref', $ref)->lockForUpdate()->first();
                    if ($instance) {
                        $payload = $op->payload;
                        if ($op->action === 'reinstall') {
                            $image = CloudImage::where('provider', $this->slug())
                                ->where('provider_ref', $payload['regionCode'].'|'.$payload['imageResourceUUID'])->firstOrFail();
                            $instance->image_key = $image->key;
                        }
                        if (filled($payload['password'] ?? null)) {
                            $instance->setPassword($payload['password']);
                            $instance->service?->forceFill(['password' => $payload['password'],
                                'username' => $instance->isWindows() ? 'Administrator' : 'root',
                                'cloud_image_key' => $instance->image_key])->save();
                        }
                        $instance->last_error = null;
                        $instance->save();
                    }
                    // Atomic with local credential persistence: a crash cannot
                    // lose a new password between acknowledgement and saving it.
                    $op->delete();
                });
            }
            return null;
        } finally { $lock->release(); }
    }

    public function createServer(array $spec): array
    {
        $fail = ['ref' => null, 'ipv4' => null, 'ipv6' => null, 'root_password' => null, 'status' => 'unknown'];
        try {
            $name = (string) ($spec['name'] ?? '');
            $plan = explode('|', (string) ($spec['plan_ref'] ?? ''));
            $image = explode('|', (string) ($spec['image_ref'] ?? ''));
            if (! preg_match('/^[A-Za-z0-9-]{1,64}$/', $name) || count($plan) !== 3 || count($image) !== 2
                || $image[0] !== $plan[0] || ($spec['location_ref'] ?? '') !== $plan[0].'|'.$plan[1]
                || ! empty($spec['ssh_keys']) || ! empty($spec['user_data'])) {
                return ['ok' => false, 'message' => 'مشخصات ساخت یا منطقهٔ سیستم‌عامل ناسازگار است.'] + $fail;
            }
            $lock = Cache::lock('lightnode-create-'.hash('sha256', $name), 120);
            if (! $lock->get()) { return ['ok' => false, 'transient' => true, 'message' => 'ساخت دیگری در حال بررسی است.'] + $fail; }
            try {
                $key = hash('sha256', 'lightnode:create:'.$name);
                $op = CloudProviderOperation::where('operation_key', $key)->first();
                if (! $op) {
                    $list = $this->regionalServers($plan[0], $plan[1]);
                    if (! $list['ok']) { return $list + $fail; }
                    $existing = array_values(array_filter($list['servers'], fn ($s) => $s['name'] === $name));
                    if (count($existing) > 1) { return ['ok' => false, 'message' => 'نام ماشین یکتا نیست؛ نیازمند بررسی مدیر است.'] + $fail; }
                    if ($existing !== []) {
                        return ['ok' => false, 'message' => 'ماشین هم‌نام بدون سابقهٔ خرید معتبر پیدا شد؛ تطبیق مالکیت توسط مدیر لازم است.'] + $fail;
                    }
                    $packages = $this->packages($plan[0], $plan[1], $plan[2]);
                    $package = collect($packages['rows'] ?? [])->first(fn ($p) => ($p['packageCode'] ?? '') === $plan[2]
                        && ($p['regionCode'] ?? '') === $plan[0] && ($p['zoneCode'] ?? '') === $plan[1]);
                    if (! $packages['ok'] || ! $package || (isset($spec['vcpu']) && (int) $spec['vcpu'] !== (int) $package['cpu'])
                        || (isset($spec['ram_mb']) && (int) $spec['ram_mb'] !== (int) $package['memory'] * 1024)
                        || (isset($spec['disk_gb']) && (int) $spec['disk_gb'] !== (int) $package['systemDiskSize'] + (int) ($package['dataDiskSize'] ?? 0))) {
                        return ['ok' => false, 'message' => 'پلن جاری با سفارش تطبیق ندارد.'] + $fail;
                    }
                    $rate = $this->reviewedRate(implode('|', $plan), $package);
                    if ($rate === null || ! $this->purchasePriceMatches(implode('|', $plan), $rate, $package)) {
                        return ['ok' => false, 'message' => 'بهای جاری و سیاست ترافیک این پلن تأیید نشده است.'] + $fail;
                    }
                    $images = $this->rows('/image/list', 'images', ['regionCode' => $plan[0], 'imageType' => 'System'], true);
                    if (! $images['ok'] || ! collect($images['rows'] ?? [])->contains(fn ($i) => ($i['imageResourceUUID'] ?? '') === $image[1]
                        && ($i['imageType'] ?? '') === 'System' && (int) ($i['imageSize'] ?? 50) <= (int) $package['systemDiskSize'])) {
                        return ['ok' => false, 'message' => 'سیستم‌عامل برای این منطقه و پلن تأیید نشد.'] + $fail;
                    }
                    $chosenImage = collect($images['rows'])->firstWhere('imageResourceUUID', $image[1]);
                    if ((isset($chosenImage['regionCode']) && $chosenImage['regionCode'] !== $plan[0])
                        || (str_contains(strtolower((string) ($chosenImage['osDistroVersion'] ?? '')), 'windows')
                            && ((int) $package['memory'] < 4 || (int) $package['cpu'] < 2))) {
                        return ['ok' => false, 'message' => 'منطقه یا حافظهٔ سیستم‌عامل مناسب نیست.'] + $fail;
                    }
                    $payload = ['packageConfig' => ['packageCode' => $plan[2], 'regionCode' => $plan[0], 'zoneCode' => $plan[1],
                        'instanceName' => $name, 'imageResourceUUID' => $image[1], 'password' => $this->password()]];
                    $firewall = $this->manifest()['regions'][$plan[0]]['firewall_uuid'] ?? null;
                    if ($firewall) {
                        $rules = $this->rows('/firewall/list', 'firewalls', ['regionCode' => $plan[0]], true);
                        if (! $rules['ok'] || ! collect($rules['rows'] ?? [])->contains(fn ($f) =>
                            ($f['firewallUUID'] ?? '') === $firewall && ($f['regionCode'] ?? '') === $plan[0]
                            && ($f['firewallStatus'] ?? '') === 'AVAILABLE')) {
                            return ['ok' => false, 'message' => 'فایروال این منطقه تأیید نشد.'] + $fail;
                        }
                        $payload['packageConfig']['firewallUUID'] = $firewall;
                    }
                    // Journal BEFORE buying. Even a lost response remains blocked from repeat purchase.
                    $op = CloudProviderOperation::create(['operation_key' => $key, 'provider' => $this->slug(), 'action' => 'create',
                        'payload' => $payload + ['_expected_spec' => $this->packageSpec($package)]
                            + (($rate['source'] ?? '') === 'public_calculator' ? ['_expected_series' => $rate['series'],
                                '_supplier_quote' => array_intersect_key($rate, array_flip(['source', 'currency', 'hour_usd', 'month_usd', 'verified_at']))] : [])]);
                    $r = $this->request('POST', '/instance/create', $payload);
                    $task = $r['data']['asyncTaskInfo'] ?? [];
                    if (! $r['ok'] || empty($task['asyncTaskUUID']) || empty($task['ecsResourceUUID'])) {
                        return ['ok' => false, 'transient' => true, 'message' => 'نتیجهٔ خرید نامشخص است؛ تطبیق لازم است و خرید تکرار نمی‌شود.'] + $fail;
                    }
                    $op->update(['task_ref' => $task['asyncTaskUUID'], 'resource_ref' => $task['ecsResourceUUID'], 'state' => 'pending']);
                }
                $original = $op->payload['packageConfig'];
                if ($original['regionCode'] !== $plan[0] || $original['zoneCode'] !== $plan[1]
                    || $original['packageCode'] !== $plan[2] || $original['imageResourceUUID'] !== $image[1]) {
                    return ['ok' => false, 'message' => 'مشخصات خرید قبلی متفاوت است؛ ابتدا همان عملیات تعیین تکلیف شود.'] + $fail;
                }
                if (! $op->resource_ref) {
                    $list = $this->regionalServers($plan[0], $plan[1]);
                    $matches = array_values(array_filter($list['servers'] ?? [], fn ($s) => $s['name'] === $name));
                    if (! $list['ok'] || count($matches) !== 1) {
                        return ['ok' => false, 'transient' => true, 'message' => 'خرید قبلی هنوز تعیین تکلیف نشده؛ ساخت دوباره ممنوع است.'] + $fail;
                    }
                    // The exact async task is unavailable after a lost response.
                    // Keep the server building until an operator verifies it.
                    $op->update(['resource_ref' => $matches[0]['ref']]);
                }
                return ['ok' => true, 'message' => 'ساخت پذیرفته شد و در حال تأیید است.', 'ref' => $op->resource_ref,
                    'root_password' => $op->payload['packageConfig']['password'], 'status' => 'building', 'ipv4' => null, 'ipv6' => null];
            } finally { $lock->release(); }
        } catch (\Throwable) {
            return ['ok' => false, 'transient' => true, 'message' => 'ثبت عملیات ساخت انجام نشد؛ پیش از تکرار باید بررسی شود.'] + $fail;
        }
    }

    private function mutate(string $ref, string $action, string $path, array $extra = []): array
    {
        try {
            $key = hash('sha256', 'lightnode:'.$action.':'.$ref);
            $lock = Cache::lock('lightnode-resource-'.hash('sha256', $ref), 120);
            if (! $lock->get()) { return ['ok' => false, 'transient' => true, 'message' => 'عملیات در حال بررسی است.']; }
            try {
                $op = CloudProviderOperation::where('operation_key', $key)->first();
                if (! $op) {
                    $others = CloudProviderOperation::where('provider', $this->slug())->where('resource_ref', $ref)
                        ->where('operation_key', '!=', $key)->get();
                    foreach ($others as $other) {
                        if ($other->action !== 'create' || ! $this->taskSucceeded($other)) {
                            return ['ok' => false, 'transient' => true, 'message' => 'ابتدا عملیات قبلی این ماشین باید تعیین تکلیف شود.'];
                        }
                    }
                    $payload = ['ecsResourceUUID' => $ref] + $extra;
                    $op = CloudProviderOperation::create(['operation_key' => $key, 'provider' => $this->slug(), 'action' => $action,
                        'resource_ref' => $ref, 'payload' => $payload]);
                    $r = $this->request('POST', $path, $payload);
                    $task = $r['data']['asyncTaskUUID'] ?? $r['data']['asyncTaskInfo']['asyncTaskUUID'] ?? null;
                    if (! $r['ok'] || ! $task) {
                        return ['ok' => false, 'transient' => true, 'message' => 'نتیجه عملیات نامشخص است؛ تکرار خودکار انجام نمی‌شود.'];
                    }
                    $op->update(['task_ref' => $task, 'state' => 'pending']);
                }
                if (! $this->taskSucceeded($op)) {
                    return ['ok' => false, 'pending' => $op->state !== 'failed' && filled($op->task_ref),
                        'transient' => $op->state !== 'failed', 'message' => 'عملیات هنوز نزد زیرساخت تأیید نشده است.'];
                }
                if ($action === 'release') {
                    $purchase = CloudProviderOperation::where('provider', $this->slug())->where('action', 'create')
                        ->where('resource_ref', $ref)->first();
                    $region = $purchase?->payload['packageConfig']['regionCode'] ?? null;
                    $zone = $purchase?->payload['packageConfig']['zoneCode'] ?? null;
                    $list = $region && $zone ? $this->regionalServers($region, $zone) : $this->listServers();
                    if (! $list['ok'] || collect($list['servers'])->contains('ref', $ref)) {
                        return ['ok' => false, 'transient' => true, 'message' => 'حذف ماشین هنوز در موجودی کامل تأیید نشده است.'];
                    }
                }
                if (in_array($action, ['password', 'reinstall'], true)) {
                    // Even an immediately completed task must keep the encrypted
                    // journal until credentials are committed atomically locally.
                    return ['ok' => false, 'pending' => true, 'message' => 'عملیات زیرساخت تأیید شد؛ اطلاعات اتصال در حال همگام‌سازی است.'];
                }
                $result = ['ok' => true, 'message' => 'عملیات تأیید شد.', 'root_password' => $op->payload['password'] ?? null];
                if ($action !== 'release') { $op->delete(); }
                return $result;
            } finally { $lock->release(); }
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'عملیات قابل تأیید نیست.', 'transient' => true];
        }
    }

    public function power(string $ref, string $action): array
    {
        $path = ['on' => 'start', 'off' => 'stop', 'shutdown' => 'stop', 'reboot' => 'restart'][$action] ?? null;
        return $path ? $this->accepted($this->mutate($ref, $path, '/instance/'.$path)) : $this->unsupported();
    }

    private function accepted(array $result): array
    {
        return ($result['pending'] ?? false) ? array_replace($result, ['ok' => true]) : $result;
    }

    public function deleteServer(string $ref): array { return $this->mutate($ref, 'release', '/instance/release'); }

    public function rebuild(string $ref, string $imageRef, ?string $password = null): array
    {
        $image = explode('|', $imageRef);
        $detail = $this->request('GET', '/instance/detail', ['ecsResourceUUID' => $ref]);
        if (count($image) !== 2 || ! $detail['ok'] || ($detail['data']['instance']['ecsResourceUUID'] ?? '') !== $ref
            || ($detail['data']['instance']['regionCode'] ?? '') !== $image[0]
            || ($detail['data']['instance']['ecsStatus'] ?? '') !== 'STOPPED'
            || ($detail['data']['instance']['ecsPendingStatus'] ?? 'NONE') !== 'NONE') {
            return ['ok' => false, 'message' => 'برای نصب مجدد، سرور باید کاملاً خاموش و سیستم‌عامل متعلق به همان منطقه باشد.', 'root_password' => null];
        }
        $images = $this->rows('/image/list', 'images', ['regionCode' => $image[0], 'imageType' => 'System'], true);
        $selected = collect($images['rows'] ?? [])->firstWhere('imageResourceUUID', $image[1]);
        if (! $images['ok'] || ! $selected || ($selected['imageType'] ?? '') !== 'System'
            || (int) ($selected['imageSize'] ?? 50) > (int) ($detail['data']['instance']['systemDiskSize'] ?? 0)
            || (str_contains(strtolower((string) ($selected['osDistroVersion'] ?? '')), 'windows')
                && ((int) ($detail['data']['instance']['memory'] ?? 0) < 4 || (int) ($detail['data']['instance']['cpu'] ?? 0) < 2))) {
            return $this->unsupported() + ['root_password' => null];
        }
        if ($password !== null && ! preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^a-zA-Z0-9])[\x21-\x7e]{8,30}$/', $password)) {
            return $this->unsupported() + ['root_password' => null];
        }
        return $this->accepted($this->mutate($ref, 'reinstall', '/instance/reinstallSystem', ['regionCode' => $image[0],
            'imageResourceUUID' => $image[1], 'password' => $password ?: $this->password()])) + ['root_password' => null];
    }

    public function resetPassword(string $ref): array
    {
        $detail = $this->request('GET', '/instance/detail', ['ecsResourceUUID' => $ref]);
        if (! $detail['ok'] || ($detail['data']['instance']['ecsResourceUUID'] ?? '') !== $ref
            || ($detail['data']['instance']['ecsStatus'] ?? '') !== 'STOPPED'
            || ($detail['data']['instance']['ecsPendingStatus'] ?? 'NONE') !== 'NONE') {
            return ['ok' => false, 'message' => 'برای تغییر رمز ابتدا سرور باید کاملاً خاموش شود.', 'root_password' => null];
        }
        return $this->accepted($this->mutate($ref, 'password', '/instance/modifyInstancePassword', ['password' => $this->password()])) + ['root_password' => null];
    }

    private function unsupported(): array { return ['ok' => false, 'message' => 'این قابلیت برای این سرور در دسترس نیست.']; }
    public function console(string $ref): array { return $this->unsupported() + ['url' => null, 'password' => null]; }
    public function metrics(string $ref, string $window = '24h'): array { return $this->unsupported() + ['series' => []]; }
    public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array { return $this->unsupported(); }
    public function uploadSshKey(string $name, string $publicKey): array { return $this->unsupported() + ['ref' => null]; }
    public function addExtraIps(string $ref, int $count): array { return $this->unsupported() + ['ips' => []]; }

    public function capabilities(): array
    {
        return ['console' => false, 'rebuild' => true, 'resize' => false, 'snapshot' => false,
            'metrics' => false, 'reset_password' => true, 'ipv6' => false, 'ssh_key' => false, 'extra_ip' => false];
    }
}
