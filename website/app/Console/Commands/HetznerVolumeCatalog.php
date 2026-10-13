<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Server;
use App\Services\CatalogPricing;
use App\Services\Cloud\HetznerClient;
use App\Services\Provisioning\HetznerVolumeCosts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HetznerVolumeCatalog extends Command
{
    protected $signature = 'hetzner:volume-catalog {--sync} {--json}';
    protected $description = 'Synchronize sellable Hetzner Block Storage volumes';

    public function handle(HetznerClient $client, HetznerVolumeCosts $costs): int
    {
        $price = $client->volumePrice();
        if (! $price['ok']) return $this->out(['ok' => false, 'error' => $price['message']], self::FAILURE);
        $server = Server::query()->firstOrNew(['name' => 'HZ-BLOCK-CATALOG']);
        $server->fill(['type' => 'hetzner_volume', 'country' => 'DE', 'city' => 'Hetzner Cloud',
            'hostname' => 'api.hetzner.cloud', 'port' => 443, 'verify_tls' => true,
            'status' => 'active', 'max_accounts' => null, 'vendor' => 'Hetzner']);
        if ($this->option('sync')) {
            DB::transaction(function () use ($server, $price, $costs) {
                $server->save(); HetznerVolumeCosts::remember($price['net_eur_per_gb_month'], (int) $server->id);
                foreach (HetznerVolumeCosts::LOCATIONS as $location) foreach (HetznerVolumeCosts::SIZES as $size) {
                    $plan = "sn_volume_{$size}gb_{$location}"; $slug = "block-storage-{$size}gb-{$location}";
                    $country = $location === 'hel1' ? 'FI' : 'DE'; $quote = $costs->quoteForPlan($plan);
                    Product::updateOrCreate(['slug' => $slug], [
                        'plan' => $plan, 'server_id' => $server->id, 'group' => 'block-storage', 'category' => 'other',
                        'name' => "بلاک استوریج {$size} گیگابایت — ".strtoupper($location),
                        'name_en' => "Block Storage {$size} GB — ".strtoupper($location),
                        'name_tr' => "Block Storage {$size} GB — ".strtoupper($location),
                        'currency_code' => 'IRT', 'price' => $quote['irt'] ?? 0, 'price_eur' => $quote['eur_cents'] ?? 0,
                        'setup_fee' => 0, 'cycle' => 'monthly', 'tax_percent' => 10, 'requires_domain' => false,
                        'locations' => [$country], 'is_active' => $quote !== null, 'sort' => $size,
                        'specs' => [['label' => 'Capacity', 'value' => $size.' GB'], ['label' => 'Storage', 'value' => 'NVMe replicated']],
                        'description' => 'والیوم مستقل هتزنر؛ ساخت و حذف خودکار و قابل اتصال به سرورهای ابری همان منطقه.',
                    ]);
                }
                CatalogPricing::forget();
            });
        }
        return $this->out(['ok' => true, 'synced' => (bool) $this->option('sync'), 'products' => 9,
            'net_eur_per_gb_month' => $price['net_eur_per_gb_month']], self::SUCCESS);
    }

    private function out(array $data, int $status): int
    {
        $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->option('json') ? $this->line($line) : ($status ? $this->error($data['error']) : $this->info($line));
        return $status;
    }
}
