<?php

namespace Tests\Feature;

use Database\Seeders\PhysicalServerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareSeoOverlayTest extends TestCase
{
    use RefreshDatabase;

    public function test_hpe_server_has_localized_generation_part_routes_without_claiming_exact_compatibility(): void
    {
        $this->seed(PhysicalServerSeeder::class);

        foreach (['', '/en', '/tr'] as $prefix) {
            $html = $this->get($prefix.'/servers/hpe-proliant-dl380-gen10')->assertOk()->getContent();

            foreach (['cpu', 'ram', 'disk', 'raid'] as $category) {
                $this->assertStringContainsString($prefix.'/parts/'.$category.'?gen=gen10', $html);
            }
            $this->assertStringNotContainsString('shop_seo.', $html);
        }

        $this->get('/servers/dell-poweredge-r740')->assertOk()
            ->assertDontSee('/parts/cpu?gen=gen10', false);
    }
}
