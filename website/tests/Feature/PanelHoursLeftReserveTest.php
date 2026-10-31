<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\HourlyHold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Running-time estimates must include the former retention reserve. */
class PanelHoursLeftReserveTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_card_includes_the_obsolete_active_reserve_like_the_meter_does(): void
    {
        $this->assertTrue(HourlyHold::enabled(), 'ستون‌های ذخیرهٔ نگهداری در تست نیستند — این تست چیزی نمی‌سنجد.');

        $c = Customer::create([
            'email' => 'hl'.random_int(1, 999999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
        CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => 100_000, 'balance_after' => 100_000,
            'reason' => 'topup', 'source_type' => Customer::class, 'source_id' => $c->id, 'note' => 't',
        ]);

        \App\Models\CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);
        $plan = \App\Models\CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 20480,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 379, 'price_eur_cents' => 570,
            'price_irt' => 720_000, 'is_active' => true, 'in_stock' => true,
        ]);

        $s = Service::create([
            'cloud_plan_id' => $plan->id,
            'customer_id' => $c->id, 'name' => 'سرور مجازی hl-test (ساعتی)', 'currency_code' => 'IRT',
            'price' => 720_000, 'tax_percent' => 0, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => 1_000, 'hold_rate_irt' => 1_000, 'hold_reserve_irt' => 24_000,
            'status' => 'active', 'provision_status' => 'done', 'activated_at' => now(), 'last_metered_at' => now(),
        ]);
        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'hetzner', 'provider_ref' => '9'.$s->id,
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04', 'hostname' => 'sn-svc-'.$s->id,
            'ipv4' => '203.0.113.9', 'status' => 'running', 'ready_notified_at' => now()->subDay(),
        ]);

        $this->assertSame(100, $s->fresh()->hoursLeft(), 'Active reserves must not reduce running time.');

        $html = (string) $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('~'.fa_num(100), $html, 'The full 100 usable hours must be shown.');
        $this->assertStringNotContainsString('~'.fa_num(76).' ', $html, 'The obsolete reserve must not hide 24 running hours.');
    }
}
