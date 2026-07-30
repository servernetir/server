<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\SiteMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProbeTest extends TestCase
{
    use RefreshDatabase;

    private const OUT = 'C:\\Users\\ADMINI~1\\AppData\\Local\\Temp\\claude\\C--Users-Administrator-Desktop-ServerNet\\b2335ae7-d8a2-4e82-924e-860162d1c822\\scratchpad\\probe.txt';

    public function test_probe(): void
    {
        Cache::flush();

        CloudLocation::create(['code' => 'sg-singapore', 'country' => 'SG', 'city' => 'Singapore', 'is_active' => true]);
        CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22-sg', 'location_code' => 'sg-singapore',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-sg', 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 40, 'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);

        $items = fn () => array_column(
            collect(app(SiteMenu::class)->mega()['vps']['groups'])
                ->firstWhere('en', 'Locations')['items'] ?? [],
            'fa'
        );

        $log = [];
        $log[] = 'PASS1: '.implode(' | ', $items());
        $log[] = 'PASS2: '.implode(' | ', $items());
        $log[] = 'PASS3: '.implode(' | ', $items());

        $this->get('/')->assertOk();
        $log[] = 'AFTER RENDER 1: '.implode(' | ', $items());

        $this->get('/')->assertOk();
        $log[] = 'AFTER RENDER 2: '.implode(' | ', $items());

        $log[] = 'CONFIG NOW: '.implode(' | ', array_column(
            collect(config('servernet.mega')['vps']['groups'])->firstWhere('en', 'Locations')['items'] ?? [],
            'fa'
        ));

        file_put_contents(self::OUT, implode("\n", $log)."\n");

        $this->assertTrue(true);
    }
}
