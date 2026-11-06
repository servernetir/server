<?php

/** Owner-authorized expansion. Run privately: php FILE APP_ROOT TASK_DIR plan|apply. */
namespace ServerNet\VpsTcReview;

use App\Models\{CloudPlan, Setting};
use App\Services\Cloud\{CatalogRefreshRequests, CloudCatalogSync, CloudPricingSnapshot, VpsTcApi, VpsTcCatalog, VpsTcPricing};
use Illuminate\Support\Facades\{Cache, DB};

final class ReviewedCatalog
{
    /** Explicitly reviewed advertised resources, never guessed from the product name. */
    public const SPECS = [
        351 => [1,1,20,2], 352 => [1,2,40,3], 353 => [2,2,50,4], 354 => [3,3,70,4],
        355 => [4,4,80,4], 356 => [6,8,160,5], 357 => [8,10,200,5], 358 => [10,12,240,6],
        2169 => [1,2,40,0], 2170 => [2,3,50,0], 2171 => [3,4,60,0], 2172 => [4,6,70,0],
        2173 => [6,8,80,0], 2174 => [12,16,160,0], 2175 => [16,20,200,0], 2176 => [20,24,240,0],
    ];

    public static function build(array $products, string $account, string $at): array
    {
        $rows = [];
        foreach (self::SPECS as $id => [$cpu, $ram, $disk, $tb]) {
            $p = $products[$id] ?? []; $us = $id >= 2169;
            $index = $us ? $id - 2168 : $id - 350;
            $expectedName = ($us ? 'US - NVMe VDS #' : 'NVMe VDS #').$index;
            $description = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace(
                '/(?:<br\s*\/?\s*>|<\/p>|<\/li>|<\/tr>|<\/td>)/i', ' | ', $p['description'] ?? '')))));
            $network = $us ? 'Unlimited / 10 Gbit' : $tb.'TB Bandwidth / 2x25 Gbit';
            $location = $us ? 'New York / USA' : 'Istanbul / TR';
            // Match only these reviewed labels, allowing HTML whitespace and singular/plural core.
            $pattern = '/\ACPU:\s*'.$cpu.' Cores?\s*\|\s*RAM:\s*'.$ram.' GB DDR4\s*\|\s*DISK:\s*'.$disk
                .' GB NVMe SSD\s*\|\s*NETWORK:\s*'.preg_quote($network, '/').'\s*\|\s*LOCATION:\s*'
                .preg_quote($location, '/').'\s*\|?\z/uD';
            if ((string) ($p['id'] ?? '') !== (string) $id || ($p['name'] ?? '') !== $expectedName
                || (string) ($p['category_id'] ?? '') !== '56' || ($p['paytype'] ?? '') !== 'Regular'
                || ($p['billingtype'] ?? '') !== 'PrePay' || ($p['recurring'] ?? '') !== 'm'
                || ($p['out_of_stock'] ?? null) !== false || ! preg_match($pattern, $description)) {
                throw new \RuntimeException('Reviewed product changed: '.$id);
            }
            $custom = $included = $os = []; $seen = [];
            foreach ($p['config']['forms'] ?? [] as $f) {
                $fid = (string) ($f['id'] ?? ''); $name = $f['title'] ?? '';
                if (! preg_match('/\A[1-9][0-9]*\z/D', $fid) || isset($seen[$name])) { throw new \RuntimeException('Ambiguous form: '.$id); }
                $seen[$name] = true;
                if (($f['type'] ?? '') === 'slider' && in_array($name, ['Additional Storage [GB]', 'Backups', 'Snapshots'], true)) {
                    $n = $name === 'Snapshots' ? 2 : 0; $items = array_values($f['items'] ?? []); $item = $items[0] ?? [];
                    $iid = (string) ($item['id'] ?? '');
                    if (count($items) !== 1 || ! preg_match('/\A[1-9][0-9]*\z/D', $iid)
                        || (string) ($f['config']['minvalue'] ?? '') !== (string) $n
                        || (string) ($f['config']['initialval'] ?? '') !== (string) $n
                        || (string) ($f['config']['dontchargedefault'] ?? '') !== '1'
                        || (string) ($item['value'] ?? '') !== (string) $n
                        || (float) ($f['price'] ?? -1) !== 0.0 || (float) ($f['setup'] ?? -1) !== 0.0) {
                        throw new \RuntimeException('Included quantity changed: '.$id);
                    }
                    $custom[$fid] = [$iid => $n]; $included[$fid] = [$iid => $n];
                } elseif (($f['type'] ?? '') === 'select' && $name === 'OS Template') {
                    $items = array_values(array_filter($f['items'] ?? [], fn ($i) => ($i['title'] ?? '') === 'Ubuntu 24.04'));
                    if (count($items) !== 1 || ! self::free($items[0])) { throw new \RuntimeException('Linux selection changed: '.$id); }
                    $os[$fid] = [(string) $items[0]['id'] => 1];
                } elseif ($us && ($f['type'] ?? '') === 'servergroupselector' && $name === 'Location') {
                    $items = array_values($f['items'] ?? []);
                    if (count($items) !== 1 || ($items[0]['title'] ?? '') !== 'New York, USA'
                        || (string) ($items[0]['value'] ?? '') !== '1' || ! self::free($items[0])) {
                        throw new \RuntimeException('Location selection changed: '.$id);
                    }
                    $custom[$fid] = [(string) $items[0]['id'] => '1'];
                } else { throw new \RuntimeException('Unreviewed form: '.$id); }
            }
            foreach (['Additional Storage [GB]', 'Backups', 'Snapshots', 'OS Template'] as $required) {
                if (! isset($seen[$required])) { throw new \RuntimeException('Missing form: '.$id); }
            }
            $terms = $id === 351 ? ['charge_mode' => 'throttled', 'extra_charges' => false,
                'counting' => 'combined', 'period' => 'monthly', 'overuse_mbps' => 16]
                : ['charge_mode' => 'unconfirmed', 'extra_charges' => null];
            $row = ['product_id' => (string) $id, 'category_id' => '56', 'kind' => 'vps', 'cycle' => 'm',
                'renewal_mode' => 'manual_invoice_deduplicated', 'parent_cancel_stops_billing' => null,
                'country' => $us ? 'US' : 'TR', 'city' => $us ? 'New York' : 'Istanbul',
                'location_ref' => $us ? 'new-york' : 'istanbul', 'vcpu' => $cpu, 'ram_mb' => $ram * 1024,
                'disk_gb' => $disk, 'disk_type' => 'nvme', 'cpu_kind' => 'shared', 'arch' => 'x86',
                'traffic_gb' => $tb * 1024, 'network_terms' => $terms,
                'availability' => ['path' => 'out_of_stock', 'mode' => 'boolean_false'],
                'forms_sha256' => VpsTcCatalog::formFingerprint($p['config']['forms']),
                'terms_sha256' => VpsTcCatalog::termsFingerprint($p), 'payment_method' => '120',
                'custom' => $custom, 'included_quantities' => $included,
                'images' => [$id.'|ubuntu-24.04' => ['key' => 'ubuntu-24.04', 'family' => 'ubuntu',
                    'version' => '24.04', 'label' => 'Ubuntu 24.04', 'min_ram_mb' => 1024, 'min_disk_gb' => 20, 'custom' => $os]]];
            if ($id === 351) { $row['power_cost_mode'] = 'included'; }
            $row['commercial_launch_approval'] = ['version' => 1, 'decision' => 'launch_pending_supplier_clarification',
                'approved_by' => 'owner', 'account_fingerprint' => $account, 'product_id' => (string) $id,
                'approved_at' => $at, 'support_ticket' => '154596', 'pending' => $id === 351
                    ? ['supplier_autorenew', 'cancellation_billing']
                    : ['supplier_autorenew', 'cancellation_billing', 'traffic_overage'],
                'scope_sha256' => VpsTcCatalog::launchScopeFingerprint($row)];
            $rows[] = $row;
        }
        return ['account_fingerprint' => $account, 'products' => $rows];
    }

    private static function free(array $item): bool
    {
        foreach (['price', 'setup', 'unit_price'] as $k) {
            if (! is_numeric($item[$k] ?? null) || (float) $item[$k] !== 0.0) { return false; }
        }
        return preg_match('/\A[1-9][0-9]*\z/D', (string) ($item['id'] ?? '')) === 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) { return; }
umask(0077);
[$script, $root, $dir, $phase] = $argv + [null, null, null, null];
if (! is_dir($root ?? '') || ! is_dir($dir ?? '') || ! in_array($phase, ['plan', 'apply'], true)) {
    throw new \RuntimeException('Usage: FILE APP_ROOT PRIVATE_TASK_DIR plan|apply');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php'; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$save = static function (string $name, array $data) use ($dir) {
    file_put_contents($dir.'/'.$name, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
};
$old = (string) Setting::query()->whereKey('vpstc_catalog_manifest')->value('value');
$api = app(VpsTcApi::class); $pricing = app(VpsTcPricing::class); $snapshot = app(CloudPricingSnapshot::class);
try {
    if ($phase === 'plan') {
        $products = json_decode(file_get_contents($dir.'/catalog-forms.json'), true, 512, JSON_THROW_ON_ERROR);
        $candidate = ReviewedCatalog::build($products, $api->accountFingerprint(), gmdate('Y-m-d\TH:i:s+00:00'));
        $catalog = new class($api, $pricing, $candidate) extends VpsTcCatalog {
            public function __construct(VpsTcApi $api, VpsTcPricing $pricing, private array $candidate) { parent::__construct($api, $pricing); }
            public function manifest(): ?array { return $this->candidate; }
        };
        $report = ['ok' => false, 'stage' => 'quoting', 'products' => []]; $accepted = [];
        foreach ($candidate['products'] as $row) {
            $q = $catalog->quote($row['product_id']);
            $report['products'][] = ['id' => $row['product_id'], 'ok' => $q !== null,
                'monthly_amount' => $q['monthly_amount'] ?? null, 'currency' => $q['currency'] ?? null,
                'cost_eur_cents' => $q['cost_eur_cents'] ?? null, 'in_stock' => $q['in_stock'] ?? null];
            $save('catalog-review-report.json', $report);
            if ($q) { $accepted[] = $row; }
        }
        $existing = array_column(json_decode($old, true, 512, JSON_THROW_ON_ERROR)['products'] ?? [], 'product_id');
        $acceptedIds = array_column($accepted, 'product_id');
        if (count($accepted) < 2 || array_diff($existing, $acceptedIds) !== []) {
            throw new \RuntimeException('Existing offer failed review or no verified expansion.');
        }
        $candidate['products'] = $accepted;
        $encoded = json_encode($candidate, JSON_THROW_ON_ERROR);
        $save('catalog-candidate.json', ['before_sha256' => hash('sha256', $old), 'candidate' => $candidate,
            'candidate_sha256' => hash('sha256', $encoded), 'pricing' => $pricing->fingerprint(), 'rates' => $snapshot->rates()]);
        $report['ok'] = true; $report['stage'] = 'reviewed'; $save('catalog-review-report.json', $report);
    } else {
        $plan = json_decode(file_get_contents($dir.'/catalog-candidate.json'), true, 512, JSON_THROW_ON_ERROR);
        $candidate = json_encode($plan['candidate'], JSON_THROW_ON_ERROR);
        if (! hash_equals($plan['before_sha256'], hash('sha256', $old))
            || ! hash_equals($plan['candidate_sha256'], hash('sha256', $candidate))
            || $plan['pricing'] !== $pricing->fingerprint() || $plan['rates'] !== $snapshot->rates()
            || ! $snapshot->current('vpstc')) { throw new \RuntimeException('Review or pricing changed.'); }
        $save('catalog-manifest-backup.json', ['value' => $old]);
        app(CatalogRefreshRequests::class)->exclusive(function () use ($app, $plan, $candidate, $old, $snapshot, $pricing, $save) {
            // Keep the standard synchronizer inside the already-held shared catalog lock.
            $original = app(CatalogRefreshRequests::class);
            $app->instance(CatalogRefreshRequests::class, new class extends CatalogRefreshRequests {
                public function exclusive(\Closure $callback): mixed { return $callback(); }
            });
            try {
                $result = DB::transaction(function () use ($plan, $candidate, $old, $snapshot, $pricing) {
                    $stored = (string) Setting::query()->whereKey('vpstc_catalog_manifest')->lockForUpdate()->value('value');
                    if ($stored !== $old) { throw new \RuntimeException('Concurrent manifest edit.'); }
                    Setting::put('vpstc_catalog_manifest', $candidate);
                    if (app(VpsTcCatalog::class)->manifest() === null) { throw new \RuntimeException('Invalid manifest.'); }
                    // The mapping is part of the pricing signature. Bind the new
                    // batch to that signature, after checking the old one above.
                    $fingerprint = $pricing->fingerprint();
                    $report = app(CloudCatalogSync::class)->sync('vpstc');
                    $r = $report['providers']['vpstc'] ?? [];
                    if (($r['ok'] ?? false) !== true || ($r['plans'] ?? 0) !== count($plan['candidate']['products'])
                        || $fingerprint !== $pricing->fingerprint() || $plan['rates'] !== $snapshot->rates()) {
                        throw new \RuntimeException('Catalog or pricing changed.');
                    }
                    $excluded = array_values(array_diff($snapshot->excludedProviders(), ['vpstc']));
                    $snapshot->publish($plan['rates'], $excluded, ['vpstc' => $fingerprint]);
                    return $report;
                });
                \App\Services\SiteMenu::forget(); \App\Services\Cloud\CloudCountry::forget();
                $save('catalog-apply-report.json', ['ok' => true, 'report' => $result,
                    'plans' => CloudPlan::where('provider', 'vpstc')->get(['id', 'provider_ref', 'location_code', 'price_irt', 'in_stock', 'is_active'])->toArray(),
                    'snapshot_current' => $snapshot->current('vpstc')]);
            } finally {
                $app->instance(CatalogRefreshRequests::class, $original);
                Cache::forget('settings.all'); $app->forgetInstance('settings.request-memo');
            }
        });
    }
    echo "Completed: ".$phase."\n";
} catch (\Throwable $e) {
    Cache::forget('settings.all'); $app->forgetInstance('settings.request-memo');
    $save('catalog-'.$phase.'-error.json', ['ok' => false, 'message' => $e->getMessage()]);
    echo "Failed; inspect private catalog-".$phase."-error.json\n"; exit(1);
}
