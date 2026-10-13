<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Server;
use App\Models\Setting;
use App\Services\CatalogPricing;
use App\Services\ObjectStorage\HetznerObjectCosts;
use App\Services\ObjectStorage\HetznerObjectPriceFeed;
use App\Services\Provisioning\ObjectGatewayClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HetznerObjectCatalog extends Command
{
    protected $signature = 'hetzner:object-catalog
        {--sync : Persist current prices and reconcile product activation with the live Gateway}
        {--server= : Object Storage delivery server ID or exact name}
        {--json : Return machine-readable public prices and synchronization result}';

    protected $description = 'Synchronize Hetzner Object Storage tariffs and sell only through the healthy ServerNet Gateway';

    public function handle(HetznerObjectPriceFeed $feed, HetznerObjectCosts $costs): int
    {
        $sync = (bool) $this->option('sync');
        $lock = $sync ? Cache::lock('hetzner.object.catalog.sync', 180) : null;
        if ($lock && ! $lock->get()) {
            return $this->result(['ok' => false, 'error' => 'Object Storage price synchronization is already running.'], self::FAILURE);
        }
        try {
            $server = $sync ? $this->resolveServer() : null;
            if ($sync && ! $server) {
                throw new RuntimeException('An unambiguous hetzner_object_gateway delivery server is required.');
            }
            $gatewayReady = false;
            if ($sync && $server?->canAcceptNew()) {
                $gatewayReady = (new ObjectGatewayClient($server))->testConnection()['ok'];
            }
            $book = $feed->fetch() + ['quoted_at' => now()->utc()->toIso8601String(), 'server_id' => $server?->id];
            $rows = [];
            foreach (HetznerObjectCosts::CAPACITIES as $capacity) {
                $quote = $costs->quoteFromBook($book, $capacity);
                if (! $quote) {
                    throw new RuntimeException('A fresh, valid price and EUR exchange rate are required.');
                }
                foreach (HetznerObjectCosts::LOCATIONS as $region) {
                    $plan = 'sn_object_'.$capacity.'tb_'.$region;
                    $rows[$plan] = [
                        'capacity_tb' => $capacity, 'egress_tb' => 1, 'location' => $region,
                        'country' => $region === 'hel1' ? 'FI' : 'DE',
                        'irt' => $quote['irt'], 'eur_cents' => $quote['eur_cents'],
                    ];
                }
            }
            if ($sync) {
                DB::transaction(function () use ($book, $rows, $server, $gatewayReady) {
                    foreach ($rows as $plan => $row) {
                        $slug = 'object-storage-'.$row['capacity_tb'].'tb-'.$row['location'];
                        $product = Product::query()->where('slug', $slug)->lockForUpdate()->first();
                        if ($product && ($product->group !== 'object-storage' || $product->plan !== $plan)) {
                            throw new RuntimeException('An existing product conflicts with the Object Storage catalog.');
                        }
                        $product ??= new Product([
                            'slug' => $slug, 'plan' => $plan, 'server_id' => $server->id,
                            'group' => 'object-storage', 'category' => 'other',
                            'name' => 'فضای آبجکت '.$row['capacity_tb'].' ترابایت — '.strtoupper($row['location']),
                            'name_en' => 'Object Storage '.$row['capacity_tb'].' TB — '.strtoupper($row['location']),
                            'name_tr' => 'Object Storage '.$row['capacity_tb'].' TB — '.strtoupper($row['location']),
                            'currency_code' => 'IRT', 'cycle' => 'monthly', 'requires_domain' => false,
                            'locations' => [$row['country']], 'is_active' => false, 'sort' => $row['capacity_tb'],
                            'specs' => [
                                ['label' => 'Storage', 'value' => $row['capacity_tb'].' TB'],
                                ['label' => 'Monthly egress', 'value' => '1 TB'],
                                ['label' => 'Protocol', 'value' => 'S3 API'],
                            ],
                            'description' => 'تعرفه فضای آبجکت؛ فعال‌سازی فروش پس از تأیید تحویل، ایزوله‌سازی و حسابداری مصرف.',
                        ]);
                        $product->fill([
                            'server_id' => $server->id,
                            'price' => $row['irt'], 'price_eur' => $row['eur_cents'], 'setup_fee' => 0,
                            'is_active' => $gatewayReady,
                        ])->save();
                    }
                    Setting::put(HetznerObjectCosts::SETTING, json_encode($book, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    Setting::put(HetznerObjectCosts::STATUS_SETTING, json_encode([
                        'ok' => true, 'last_attempt_at' => now()->utc()->toIso8601String(),
                        'last_success_at' => $book['quoted_at'],
                    ], JSON_THROW_ON_ERROR));
                });
                CatalogPricing::forget();
            }

            return $this->result([
                'ok' => true, 'synced' => $sync,
                'activated' => $sync && $gatewayReady ? count($rows) : 0,
                'gateway_ready' => $gatewayReady,
                'pricebook' => $book, 'plans' => $rows,
            ], self::SUCCESS);
        } catch (\Throwable $exception) {
            if ($sync) {
                // Keep previous success for audit, but stop its use for new sales immediately.
                $previous = json_decode((string) Setting::get(HetznerObjectCosts::STATUS_SETTING), true);
                Setting::put(HetznerObjectCosts::STATUS_SETTING, json_encode([
                    'ok' => false, 'last_attempt_at' => now()->utc()->toIso8601String(),
                    'last_success_at' => $previous['last_success_at'] ?? null,
                ], JSON_THROW_ON_ERROR));
            }
            $error = $exception instanceof RuntimeException
                ? $exception->getMessage() : 'Object Storage catalog synchronization failed.';

            return $this->result(['ok' => false, 'error' => $error], self::FAILURE);
        } finally {
            $lock?->release();
        }
    }

    private function resolveServer(): ?Server
    {
        $query = Server::query()->where('type', 'hetzner_object_gateway');
        $option = (string) $this->option('server');
        if ($option !== '') {
            $query->where(fn ($q) => $q->where('id', $option)->orWhere('name', $option));
        }
        $servers = $query->limit(2)->get();

        return $servers->count() === 1 ? $servers->first() : null;
    }

    private function result(array $result, int $status): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } elseif ($status === self::FAILURE) {
            $this->error($result['error']);
        } else {
            $this->info(($result['synced'] ? 'Synchronized' : 'Previewed').' '.count($result['plans'])
                .' Object Storage offers; activated '.(int) ($result['activated'] ?? 0).'.');
            $this->table(['Plan', 'Toman/month', 'EUR/month'], array_map(
                fn ($plan, $row) => [$plan, number_format($row['irt']), number_format($row['eur_cents'] / 100, 2)],
                array_keys($result['plans']), array_values($result['plans']),
            ));
        }

        return $status;
    }
}
