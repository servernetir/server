<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Server;
use PHPUnit\Framework\TestCase;

class RefundPolicyScopeTest extends TestCase
{
    public function test_only_iran_hosting_can_advertise_the_voluntary_guarantee(): void
    {
        foreach (['shared', 'reseller', 'plesk', 'directadmin'] as $category) {
            foreach (['whm', 'plesk', 'directadmin'] as $type) {
                $product = new Product(['category' => $category]);
                $product->setRelation('server', new Server(['country' => 'IR', 'type' => $type]));
                $this->assertTrue($product->isRefundable(), "$category/$type/IR");
                $product->server->country = 'DE';
                $this->assertFalse($product->isRefundable(), "$category/$type/DE");
            }
        }
        foreach (['vps', 'dedicated', 'license', 'other'] as $category) {
            $product = new Product(['category' => $category]);
            $product->setRelation('server', new Server(['country' => 'IR', 'type' => 'whm']));
            $this->assertFalse($product->isRefundable(), $category);
        }
    }

    public function test_missing_or_mixed_delivery_locations_do_not_advertise_a_blanket_guarantee(): void
    {
        $product = new Product(['category' => 'shared']);
        $product->setRelation('server', null);
        $this->assertFalse($product->isRefundable());
        $product->setRelation('server', new Server(['country' => 'IR', 'type' => 'whm']));
        $product->locations = ['IR', 'DE'];
        $this->assertFalse($product->isRefundable());
        $product->locations = ['ir'];
        $this->assertTrue($product->isRefundable());
        $product->server->type = 'vps';
        $this->assertFalse($product->isRefundable());
    }
}
