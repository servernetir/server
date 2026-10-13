<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Server;
use App\Services\Cloud\CloudPricing;
use App\Services\Provisioning\HetznerStorageClient;
use App\Services\Provisioning\HetznerStorageCosts;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Reads upstream data. Sync writes only local catalogue records, never paid resources. */
class HetznerStorageCatalog extends Command
{
    protected $signature = 'hetzner:storage-catalog
        {--server= : Delivery server ID or name; its token selects the existing project}
        {--location= : Filter read-only output to one upstream region}
        {--json : Sanitized JSON; no database writes}
        {--sync : Synchronize all Storage Box types and regions into local products}
        {--activate : Activate available products; requires --sync}';

    protected $description = 'Read Hetzner Storage Box prices and optionally synchronize the complete local catalogue';

    public function handle(CloudPricing $pricing): int
    {
        if (($this->option('activate') && ! $this->option('sync'))
            || ($this->option('sync') && ($this->option('json') || $this->option('location')))) {
            $this->error('--activate requires --sync; --sync cannot be filtered or combined with --json.');
            return self::FAILURE;
        }
        $server = $this->resolveServer();
        if (! $server) {
            $this->error('Select one configured hetzner_storage server with --server.');
            return self::FAILURE;
        }
        $client = new HetznerStorageClient($server);
        $res = $client->types();
        if (! $res['ok']) {
            $this->error('Storage Box catalogue unavailable: '.$res['reason']);
            return self::FAILURE;
        }
        $locationResult = $client->locations();
        if (! $locationResult['ok']) {
            $this->error('Location metadata unavailable: '.$locationResult['reason']);
            return self::FAILURE;
        }
        $locations = array_column($locationResult['data']['locations'], null, 'name');
        try {
            $plans = $this->normalize($res['data']['storage_box_types'], $locations);
        } catch (\UnexpectedValueException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        if ($plans === []) {
            $this->error('No complete available offers; existing products were preserved.');
            return self::FAILURE;
        }
        $visible = array_filter($plans, fn ($p) => ! $this->option('location') || $p['location'] === $this->option('location'));
        if ($this->option('json')) {
            $this->line(json_encode(['server_id' => $server->id, 'currency' => 'EUR',
                'at' => now()->toIso8601String(), 'plans' => $visible], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }
        if ($visible === []) {
            $this->error('No offers exist for the requested location.');
            return self::FAILURE;
        }
        $rows = [];
        foreach ($visible as $p) {
            $quote = app(HetznerStorageCosts::class)->quoteNetCents($p['net_monthly_cents']);
            $rows[] = [$p['type'], $p['location'], $p['size'], $p['subaccounts_limit'],
                $p['snapshot_limit'] ?? '∞', $p['automatic_snapshot_limit'] ?? '∞',
                number_format($p['net_monthly_cents'] / 100, 2), number_format($p['net_setup_cents'] / 100, 2),
                ($quote['irt'] ?? 0) > 0 ? number_format($quote['irt']) : 'unavailable'];
        }
        $this->table(['Type', 'Region', 'Bytes', 'Subaccounts', 'Manual snapshots', 'Auto snapshots', 'Net EUR/mo', 'Setup EUR', 'Toman/mo before tax'], $rows);

        if ($this->option('sync')) {
            if ($server->status !== 'active' || ! $client->isConfigured() || $pricing->eurToToman() <= 0) {
                $this->error('Sync requires an active configured delivery server and a valid EUR conversion rate.');
                return self::FAILURE;
            }
            foreach ($plans as $plan => $p) {
                $existing = Product::where('slug', $p['slug'])->first();
                if ($existing && ($existing->group !== 'storage-box' || $existing->plan !== $plan
                    || (int) $existing->server_id !== (int) $server->id)) {
                    $this->error('Existing product has different ownership: '.$p['slug']);
                    return self::FAILURE;
                }
            }
            DB::transaction(function () use ($server, $plans) {
                HetznerStorageCosts::rememberCatalog($plans, $server->id);
                $costs = app(HetznerStorageCosts::class);
                foreach ($plans as $plan => $p) {
                    $product = Product::firstOrNew(['slug' => $p['slug']]);
                    $quote = $costs->quoteForPlan($plan);
                    if (! $quote) throw new \RuntimeException('Storage Box price unavailable during sync.');
                    $size = rtrim(rtrim(number_format($p['size'] / (1024 ** 4), 2, '.', ''), '0'), '.').' TiB';
                    $product->fill([
                        'name' => 'فضای بکاپ — '.strtoupper($p['type']).' / '.$p['location'],
                        'name_en' => 'Backup storage — '.strtoupper($p['type']).' / '.$p['location'],
                        'name_tr' => 'Yedekleme depolama — '.strtoupper($p['type']).' / '.$p['location'],
                        'category' => 'other', 'group' => 'storage-box', 'server_id' => $server->id,
                        'locations' => [$p['country']], 'plan' => $plan, 'currency_code' => 'IRT',
                        'price' => $quote['irt'], 'price_eur' => $quote['eur_cents'],
                        'setup_fee' => $quote['setup_irt'], 'cycle' => 'monthly',
                        'tax_percent' => $product->exists ? $product->tax_percent : (int) config('billing.default_tax_percent', 10),
                        'requires_domain' => false,
                        'specs' => [
                            ['label' => 'Storage', 'value' => $size],
                            ['label' => 'Protocols', 'value' => 'SFTP / FTPS / WebDAV / rsync / Borg'],
                            ['label' => 'Subaccounts', 'value' => (string) $p['subaccounts_limit']],
                            ['label' => 'Region', 'value' => $p['location']],
                        ],
                        'description' => 'فضای بکاپ با احراز هویت؛ API سازگار با S3 و دانلود عمومی HTTP ندارد.',
                        'is_active' => $this->option('activate') ? true : ($product->exists && $product->is_active),
                    ])->save();
                }
                Product::where('group', 'storage-box')->where('server_id', $server->id)
                    ->whereNotIn('plan', array_keys($plans))->update(['is_active' => false]);
            });
            $this->info(count($plans).' type/region products synchronized. No upstream resources were created.');
        } else {
            $legacyLocation = (string) config('provisioning.hetzner_storage.location', 'fsn1');
            $book = [];
            foreach ($plans as $p) if ($p['location'] === $legacyLocation) $book[$p['type']] = $p['net_monthly_cents'];
            if ($book !== []) HetznerStorageCosts::remember($book, $legacyLocation);
        }
        return self::SUCCESS;
    }

    private function resolveServer(): ?Server
    {
        $q = Server::where('type', 'hetzner_storage');
        $opt = (string) $this->option('server');
        if ($opt !== '') return $q->where(fn ($q) => $q->where('id', $opt)->orWhere('name', $opt))->first();
        $servers = $q->get();
        return $servers->count() === 1 ? $servers->first() : null;
    }

    private function normalize(array $types, array $locations): array
    {
        $plans = [];
        foreach ($types as $t) {
            $name = (string) ($t['name'] ?? '');
            if (! preg_match('/^[a-z0-9]+$/', $name) || (int) ($t['size'] ?? 0) <= 0
                || ! isset($t['subaccounts_limit']) || ! array_key_exists('snapshot_limit', $t)
                || ! array_key_exists('automatic_snapshot_limit', $t) || ! is_array($t['prices'] ?? null)) {
                throw new \UnexpectedValueException('Incomplete Storage Box type; catalogue was not changed.');
            }
            if (! empty($t['deprecation']['unavailable_after'])) {
                try { $expired = Carbon::parse($t['deprecation']['unavailable_after'])->lte(now()); }
                catch (\Throwable) { throw new \UnexpectedValueException('Invalid upstream deprecation date.'); }
                if ($expired) continue;
            }
            foreach ($t['prices'] as $price) {
                $location = (string) ($price['location'] ?? '');
                $country = strtoupper((string) ($locations[$location]['country'] ?? ''));
                if (! preg_match('/^[a-z0-9]+$/', $location) || ! preg_match('/^[A-Z]{2}$/', $country)
                    || strtoupper((string) ($price['currency'] ?? 'EUR')) !== 'EUR') {
                    throw new \UnexpectedValueException('Unverified location or currency for '.$name.'.');
                }
                $plan = 'sn_storage_box_'.$name.'_'.$location;
                if (isset($plans[$plan])) throw new \UnexpectedValueException('Duplicate upstream offer: '.$plan);
                $monthly = $this->cents($price['price_monthly']['net'] ?? null);
                $setup = $this->cents($price['setup_fee']['net'] ?? null);
                if ($monthly <= 0) throw new \UnexpectedValueException('Non-positive monthly price for '.$plan);
                $plans[$plan] = [
                    'slug' => 'storage-box-'.$name.'-'.$location, 'type' => $name, 'location' => $location,
                    'country' => $country, 'size' => (int) $t['size'],
                    'subaccounts_limit' => (int) $t['subaccounts_limit'],
                    'snapshot_limit' => $t['snapshot_limit'], 'automatic_snapshot_limit' => $t['automatic_snapshot_limit'],
                    'net_monthly_cents' => $monthly, 'net_setup_cents' => $setup,
                    'unavailable_after' => $t['deprecation']['unavailable_after'] ?? null,
                ];
            }
        }
        return $plans;
    }

    private function cents(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            throw new \UnexpectedValueException('Invalid or missing EUR price; catalogue was not changed.');
        }
        return (int) ceil((float) $value * 100 - 0.0000001);
    }
}
