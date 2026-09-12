<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Server;
use App\Services\Provisioning\RcloneStorageCosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RcloneStorageMarginFloorTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_allocates_gateway_cost_over_sellable_capacity_and_keeps_margin(): void
    {
        config([
            'provisioning.hetzner_storage.plans' => [],
            'provisioning.rclone_storage.capacity_bytes' => 10 * 1024 ** 4,
            'provisioning.rclone_storage.reserve_pct' => 20,
            'provisioning.rclone_storage.min_margin_pct' => 20,
            'provisioning.rclone_storage.plans.sn_backup_3' => 1024 ** 4,
        ]);
        $server = Server::create([
            'name' => 'gateway', 'type' => 'rclone_storage', 'hostname' => 'gateway.test',
            'api_token' => 'secret', 'status' => 'active', 'monthly_cost' => 8_000_000,
            'cost_currency' => 'IRT',
        ]);
        $product = Product::create([
            'name' => 'BK-1T', 'slug' => 'bk-1t-rclone', 'category' => 'shared',
            'group' => 'backup', 'server_id' => $server->id, 'plan' => 'sn_backup_3',
            'currency_code' => 'IRT', 'price' => 100_000, 'cycle' => 'monthly',
            'tax_percent' => 10, 'is_active' => true,
        ]);

        // ۸ میلیون / ۸ ترابایت قابل‌فروش × ۱ ترابایت × ۱.۲ = ۱.۲ میلیون
        $this->assertSame(1_200_000, $product->priceForCycle('monthly'));
        $this->assertSame(14_400_000, $product->priceForCycle('yearly'));
    }

    public function test_floor_only_applies_to_products_assigned_to_this_gateway(): void
    {
        config([
            'provisioning.hetzner_storage.plans' => [],
            'provisioning.rclone_storage.capacity_bytes' => 10 * 1024 ** 4,
            'provisioning.rclone_storage.plans.sn_backup_1' => 100 * 1024 ** 3,
        ]);
        $product = Product::create([
            'name' => 'BK', 'slug' => 'bk-no-gateway', 'category' => 'shared',
            'group' => 'backup', 'plan' => 'sn_backup_1', 'currency_code' => 'IRT',
            'price' => 200_000, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
        ]);
        $this->assertSame(200_000, $product->priceForCycle('monthly'));
    }

    public function test_sales_are_blocked_until_cost_capacity_and_credentials_are_complete(): void
    {
        config(['provisioning.rclone_storage.capacity_bytes' => 0]);
        $server = Server::create([
            'name' => 'incomplete gateway', 'type' => 'rclone_storage',
            'hostname' => 'gateway.test', 'api_token' => 'secret', 'status' => 'active',
            'monthly_cost' => null, 'cost_currency' => 'IRT',
        ]);
        $product = Product::create([
            'name' => 'BK', 'slug' => 'bk-incomplete', 'category' => 'shared',
            'group' => 'backup', 'server_id' => $server->id, 'plan' => 'sn_backup_1',
            'currency_code' => 'IRT', 'price' => 200_000, 'cycle' => 'monthly',
            'tax_percent' => 10, 'is_active' => true,
        ]);

        $this->assertNotNull(app(RcloneStorageCosts::class)->configurationError($product));
    }
}
