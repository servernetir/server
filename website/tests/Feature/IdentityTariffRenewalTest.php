<?php

namespace Tests\Feature;

use App\Models\IdentityPrice;
use App\Services\IdentityApi\Catalog;
use App\Services\IdentityApi\Controls;
use App\Services\IdentityApi\TariffRenewal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityTariffRenewalTest extends TestCase
{
    use RefreshDatabase;

    private function price(array $attributes = []): IdentityPrice
    {
        return IdentityPrice::create(array_merge(['service' => 'shahkar-inquiry', 'source_amount' => 14300,
            'source_currency' => 'IRR', 'purchase_tax_amount' => 1300, 'purchase_tax_included' => true,
            'markup_bps' => 500, 'daily_quota' => 1, 'billing_policy' => 'valid_data_v1',
            'evidence' => 'synthetic-billing', 'access_evidence' => 'synthetic-account-access',
            'effective_at' => now()->subDays(2), 'valid_until' => now()->subDay()], $attributes));
    }

    private function rows(int $price = 13000): array
    {
        return [['id' => '1', 'service_id' => 'ShahkarMatchPhoneNumberNationalId', 'price' => $price]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app(Controls::class)->put('price_sync_enabled', true, 0);
        app(Controls::class)->put('service:shahkar-inquiry', true, 0);
    }

    private function renew(?array $rows = null): array
    {
        return app(TariffRenewal::class)->renew($rows ?? $this->rows(), app(Catalog::class), app(Controls::class));
    }

    public function test_scheduled_catalog_command_renews_approved_expired_price_once_without_changing_access_or_money(): void
    {
        $old = $this->price();
        Http::fake(['https://service.zohal.io/api/v0/services/' => Http::response($this->rows())]);
        $this->artisan('identity:sync-catalog')->expectsOutput('price_sync=ok renewed=1')->assertSuccessful();
        $new = IdentityPrice::latest('id')->first();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(14300, $new->source_amount);
        $this->assertSame(1300, $new->purchase_tax_amount);
        $this->assertSame(500, $new->markup_bps);
        $this->assertSame('synthetic-account-access', $new->access_evidence);
        $this->assertTrue($new->valid_until->gt(now()->addDays(29)));
        $this->assertTrue($old->fresh()->valid_until->isPast());
        $this->assertSame([], $this->renew()['renewed']);
        $this->assertDatabaseCount('identity_prices', 2);
        $this->assertDatabaseCount('identity_requests', 0);
        $this->assertDatabaseHas('identity_audits', ['action' => 'price_renewed']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && ! $request->hasHeader('Authorization'));
    }

    public function test_opt_in_and_enabled_service_are_required(): void
    {
        $this->price();
        $controls = app(Controls::class);
        $controls->put('price_sync_enabled', false, 0);
        $this->assertSame('disabled', $this->renew()['status']);
        $controls->put('price_sync_enabled', true, 0);
        $controls->put('service:shahkar-inquiry', false, 0);
        $this->assertSame([], $this->renew()['renewed']);
        $this->assertDatabaseCount('identity_prices', 1);
    }

    public function test_changed_rate_missing_product_and_mismatched_identity_require_review(): void
    {
        $this->price();
        foreach ([$this->rows(14000), [['id' => '2', 'service_id' => 'Other', 'price' => 13000]],
            [['id' => '1', 'service_id' => 'Other', 'price' => 13000]]] as $rows) {
            $this->assertSame(['shahkar-inquiry'], $this->renew($rows)['review']);
        }
        $this->assertDatabaseCount('identity_prices', 1);
    }

    public function test_future_operator_price_wins_over_expired_price(): void
    {
        $this->price();
        $this->price(['effective_at' => now()->addDay(), 'valid_until' => now()->addDays(31)]);
        $this->assertSame([], $this->renew()['renewed']);
        $this->assertDatabaseCount('identity_prices', 2);
    }

    public function test_draft_and_zero_cost_prices_cannot_be_approved_by_renewal(): void
    {
        $this->price(['billing_policy' => 'pending_billing_evidence_v1']);
        $this->assertSame(['shahkar-inquiry'], $this->renew()['review']);
        $this->price(['source_amount' => 0, 'purchase_tax_amount' => 0, 'confirmed_zero_cost' => true]);
        $this->assertSame(['shahkar-inquiry'], $this->renew($this->rows(0))['review']);
        $this->assertDatabaseCount('identity_prices', 2);
    }

    public function test_invalid_catalog_is_rejected_before_any_price_is_written(): void
    {
        $this->price();
        foreach ([$this->rows() + [1 => $this->rows()[0]], [['id' => '1', 'service_id' => 'X', 'price' => 13000.5]], []] as $rows) {
            try {
                $this->renew($rows);
                $this->fail('Invalid catalog accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('invalid_tariff_catalog', $error->getMessage());
            }
        }
        $this->assertDatabaseCount('identity_prices', 1);
    }

    public function test_network_failure_does_not_refresh_expiry_or_observation(): void
    {
        $old = $this->price();
        Http::fake(['https://service.zohal.io/api/v0/services/' => Http::response([], 503)]);
        $this->artisan('identity:sync-catalog')->assertFailed();
        $this->assertTrue($old->fresh()->valid_until->isPast());
        $this->assertNull(app(Controls::class)->get('price_sync_state'));
        $this->assertDatabaseCount('identity_prices', 1);
    }

    public function test_admin_can_toggle_renewal_and_the_current_service_flag_is_selected_in_three_languages(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web')->post('/admin/identity-api/control', [
            'key' => 'price_sync_enabled', 'value' => 'false',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(app(Controls::class)->get('price_sync_enabled'));
        foreach (['' => 'fa', 'en/' => 'en', 'tr/' => 'tr'] as $prefix => $locale) {
            $html = $this->get('/'.$prefix.'admin/identity-api')->assertOk()
                ->assertSee(__('identity.price_sync_help', [], $locale))->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $selected = $xpath->query('//form[input[@name="key" and @value="service:shahkar-inquiry"]]/select/option[@selected]');
            $this->assertSame(1, $selected->length);
            $this->assertSame('true', $selected->item(0)->getAttribute('value'));
        }
        Http::assertNothingSent();
    }

    public function test_cheque_input_enforces_the_current_official_length_before_dispatch(): void
    {
        $service = app(Catalog::class)->find('bounced-cheque-inquiry');
        $contract = app(\App\Services\IdentityApi\Contract::class);
        foreach (['0000000019', '00000000019'] as $nationalCode) {
            $this->assertSame($nationalCode, $contract->input($service, [
                'national_code' => $nationalCode, 'nationality_type' => 1,
            ])['national_code']);
        }
        $this->expectException(\App\Services\IdentityApi\IdentityError::class);
        $contract->input($service, ['national_code' => '1', 'nationality_type' => 1]);
    }
}
