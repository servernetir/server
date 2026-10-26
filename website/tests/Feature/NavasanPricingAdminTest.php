<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class NavasanPricingAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'FX Admin', 'email' => 'fx-admin@example.test',
            'password' => bcrypt('test-password'), 'role' => 'admin',
        ]);
    }

    public function test_pricing_shows_distinct_cached_rates_source_times_and_six_hour_sync(): void
    {
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        Setting::put('pricing_baseline_rate', '304000');
        Setting::put('pricing_rate_override', null);
        Setting::put('pricing_usd_rate_override', null);
        Setting::put('pricing_usdt_rate_override', null);
        $sourceAt = now()->subMinute()->toIso8601String();
        $receivedAt = now()->toIso8601String();
        NavasanRates::store(['USD' => 92500, 'EUR' => 110000, 'USDT' => 94000, 'TRY' => 5640], $sourceAt);
        $rows = Cache::get('fx.navasan.rates');
        foreach ($rows as &$row) { $row['at'] = $receivedAt; }
        unset($row);
        Cache::put('fx.navasan.rates', $rows, now()->addHours(6));

        $response = $this->actingAs($this->admin())->get('/admin/settings?tab=pricing')->assertOk();
        foreach (['USD', 'EUR', 'USDT', 'TRY', 'navasan.net', 'دلار آمریکا (شخص)', 'لیر ترکیه (حواله)', 'نرخ فروش حواله و ارز دیجیتال',
            '۶ ساعت', $sourceAt, $receivedAt, fa_num('92,500'), fa_num('110,000'), fa_num('94,000'), fa_num('5,640')] as $text) {
            $response->assertSee($text);
        }
        foreach (['pricing_baseline_rate', 'pricing_rate_override', 'pricing_usd_rate_override', 'pricing_usdt_rate_override'] as $field) {
            $response->assertDontSee('name="'.$field.'"', false);
        }
        $response->assertDontSee('نرخِ مبنای یورو')->assertDontSee('نرخِ دستی');
        foreach (['pricing_fx_fee_pct', 'price_margin_pct', 'cloud_margin_pct', 'domain_margin_pct', 'cloud_storage_margin_pct'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
        Http::assertNothingSent();
    }

    public function test_removed_rate_fields_cannot_be_changed_by_a_legacy_or_forged_post(): void
    {
        $unchanged = ['pricing_baseline_rate' => '304000', 'pricing_rate_override' => null,
            'pricing_usd_rate_override' => null, 'pricing_usdt_rate_override' => null];
        foreach ($unchanged as $key => $value) { Setting::put($key, $value); }
        $this->actingAs($this->admin())->post('/admin/settings', [
            'tab' => 'pricing', 'pricing_baseline_rate' => '1', 'pricing_rate_override' => '1',
            'pricing_usd_rate_override' => '1', 'pricing_usdt_rate_override' => '1',
            'pricing_fx_fee_pct' => '2.5', 'price_margin_pct' => '12',
            'cloud_margin_pct' => '15', 'domain_margin_pct' => '9',
        ])->assertRedirect()->assertSessionHasNoErrors();

        foreach ($unchanged as $key => $value) { $this->assertSame($value, Setting::get($key)); }
        $this->assertSame('2.5', Setting::get('pricing_fx_fee_pct'));
        $this->assertSame('12', Setting::get('price_margin_pct'));
        $this->assertSame('15', Setting::get('cloud_margin_pct'));
        $this->assertSame('9', Setting::get('domain_margin_pct'));
    }

    public function test_saving_visible_pricing_settings_preserves_internal_anchor(): void
    {
        Setting::put('pricing_baseline_rate', '304000');
        $this->actingAs($this->admin())->post('/admin/settings', [
            'tab' => 'pricing', 'cloud_margin_pct' => '18',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('304000', Setting::get('pricing_baseline_rate'));
        $this->assertSame('18', Setting::get('cloud_margin_pct'));
    }
}
