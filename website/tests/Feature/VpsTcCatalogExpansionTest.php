<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Services\Cloud\VpsTcCatalog;
use ServerNet\VpsTcReview\ReviewedCatalog;
use Tests\TestCase;

require_once __DIR__.'/../../../scripts/vpstc-catalog-review-20261031.php';

class VpsTcCatalogExpansionTest extends TestCase
{
    private function products(): array
    {
        $result = [];
        foreach (ReviewedCatalog::SPECS as $id => [$cpu, $ram, $disk, $tb]) {
            $us = $id >= 2169; $forms = [];
            foreach (['Additional Storage [GB]', 'Backups', 'Snapshots'] as $index => $name) {
                $n = $index === 2 ? 2 : 0;
                $forms[] = ['id' => (string) ($index + 1), 'type' => 'slider', 'title' => $name,
                    'config' => ['minvalue' => (string) $n, 'initialval' => (string) $n, 'dontchargedefault' => '1'],
                    'price' => 0, 'setup' => 0, 'items' => [['id' => $index + 11, 'value' => $n]]];
            }
            $forms[] = ['id' => '4', 'type' => 'select', 'title' => 'OS Template',
                'items' => [['id' => 14, 'title' => 'Ubuntu 24.04', 'price' => 0, 'setup' => 0, 'unit_price' => 0],
                    ['id' => 15, 'title' => 'Windows', 'price' => 15, 'setup' => 0, 'unit_price' => 15]]];
            if ($id === 2171) { $forms[] = ['id' => '5', 'type' => 'servergroupselector', 'title' => 'Location',
                'items' => [['id' => 16, 'title' => 'New York, USA', 'value' => '1', 'price' => 0, 'setup' => 0, 'unit_price' => 0]]]; }
            $network = $us ? 'Unlimited / 10 Gbit' : $tb.'TB Bandwidth / 2x25 Gbit';
            $place = $us ? 'New York / USA' : 'Istanbul / TR';
            $result[$id] = ['id' => $id, 'category_id' => 56, 'name' => ($us ? 'US - NVMe VDS #' : 'NVMe VDS #').($us ? $id - 2168 : $id - 350),
                'paytype' => 'Regular', 'billingtype' => 'PrePay', 'recurring' => 'm', 'out_of_stock' => false,
                'description' => "CPU:{$cpu} Core<br>RAM:{$ram} GB DDR4<br>DISK:{$disk} GB NVMe SSD<br>NETWORK:{$network}<br>LOCATION:{$place}<br>",
                'config' => ['forms' => $forms]];
        }
        return $result;
    }

    public function test_expansion_binds_each_exact_configuration_and_does_not_extend_reference_traffic_guarantee(): void
    {
        $m = ReviewedCatalog::build($this->products(), str_repeat('a', 64), '2026-10-31T00:00:00+00:00');
        $this->assertCount(16, $m['products']);
        $tr = $m['products'][0]; $us = $m['products'][10];
        $this->assertSame('throttled', $tr['network_terms']['charge_mode']);
        $this->assertSame('included', $tr['power_cost_mode']);
        $this->assertSame('2171', $us['product_id']);
        $this->assertSame('unconfirmed', $us['network_terms']['charge_mode']);
        $this->assertNull($us['network_terms']['extra_charges']);
        $this->assertArrayNotHasKey('power_cost_mode', $us);
        $this->assertSame([16 => '1'], $us['custom'][5]);
        $this->assertSame([13 => 2], $us['included_quantities'][3]);
        $this->assertSame([14 => 1], $us['images']['2171|ubuntu-24.04']['custom'][4]);
        foreach ($m['products'] as $row) {
            $this->assertSame(VpsTcCatalog::launchScopeFingerprint($row), $row['commercial_launch_approval']['scope_sha256']);
        }
        $plan = new CloudPlan(['provider' => 'vpstc', 'traffic_gb' => 0, 'network_terms' => $us['network_terms']]);
        $this->assertSame('Traffic terms require confirmation', $plan->trafficLabel('en'));
        $this->assertStringNotContainsString('0', $plan->trafficLabel('tr'));
        $this->assertStringNotContainsString('نامحدود', $plan->trafficLabel('fa'));
    }

    public function test_changed_resources_paid_linux_or_unreviewed_controls_block_the_whole_review(): void
    {
        foreach (['resources', 'linux', 'quantity', 'control'] as $bad) {
            $p = $this->products();
            match ($bad) {
                'resources' => $p[352]['description'] = str_replace('40 GB NVMe', '30 GB NVMe', $p[352]['description']),
                'linux' => $p[352]['config']['forms'][3]['items'][0]['price'] = 1,
                'quantity' => $p[352]['config']['forms'][2]['config']['initialval'] = '3',
                'control' => $p[352]['config']['forms'][] = ['id' => '99', 'type' => 'select', 'title' => 'Paid license'],
            };
            try { ReviewedCatalog::build($p, str_repeat('a', 64), '2026-10-31T00:00:00+00:00'); $this->fail('Changed contract accepted.'); }
            catch (\RuntimeException) { $this->assertTrue(true); }
        }
    }
}
