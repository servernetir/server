<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\GiftCoupon;
use App\Models\Invoice;
use App\Models\MarketingCampaign;
use App\Models\MarketingGrant;
use App\Models\MarketingRedemption;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Marketing\DiscountEntryVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscountEntryVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        Http::preventStrayRequests();

        return Customer::create(['email' => uniqid('discount-', true).'@example.test', 'locale' => 'fa', 'status' => 'active']);
    }

    private function invoice(Customer $customer, array $extra = []): Invoice
    {
        $invoice = Invoice::create($extra + ['customer_id' => $customer->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 10000, 'tax' => 1000, 'total' => 11000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);
        $invoice->items()->create(['title' => 'Fixture service', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000, 'tax_amount' => 1000, 'tax_rate_bp' => 1000]);

        return $invoice;
    }

    private function admin(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_default_hides_code_entry_even_with_a_prefilled_session_or_personal_gift(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer);
        GiftCoupon::create(['customer_id' => $customer->id, 'code' => 'HB-TEST2345', 'currency_code' => 'IRT',
            'amount' => 1000, 'min_invoice' => 0, 'reason' => 'birthday', 'expires_at' => now()->addDay()]);
        $this->assertSame('inherit', $customer->fresh()->discount_entry_visibility);
        $this->actingAs($customer, 'customer')->withSession(['marketing_coupon' => 'SN-TEST2345'])
            ->get('/account/invoices/'.$invoice->id)->assertOk()->assertDontSee('data-discount-entry', false)
            ->assertDontSee('HB-TEST2345')->assertDontSee('SN-TEST2345');
        $this->assertFalse(app(DiscountEntryVisibility::class)->enabled($customer));
    }

    public function test_customer_overrides_take_priority_over_both_global_states(): void
    {
        $one = $this->customer();
        $two = $this->customer();
        $policy = app(DiscountEntryVisibility::class);
        foreach (['0', '1'] as $global) {
            Setting::put($policy::SETTING, $global);
            $one->forceFill(['discount_entry_visibility' => 'enabled'])->save();
            $two->forceFill(['discount_entry_visibility' => 'disabled'])->save();
            $this->assertTrue($policy->enabled($one));
            $this->assertFalse($policy->enabled($two));
            $this->actingAs($one, 'customer')->get('/account/invoices/'.$this->invoice($one)->id)
                ->assertOk()->assertSee('data-discount-entry', false);
            $this->actingAs($two, 'customer')->get('/account/invoices/'.$this->invoice($two)->id)
                ->assertOk()->assertDontSee('data-discount-entry', false);
            $one->forceFill(['discount_entry_visibility' => 'inherit'])->save();
            $this->assertSame($global === '1', $policy->enabled($one));
        }
    }

    public function test_compact_disclosure_is_closed_and_between_totals_and_payment_in_three_languages(): void
    {
        $customer = $this->customer();
        $customer->forceFill(['discount_entry_visibility' => 'enabled'])->save();
        $invoice = $this->invoice($customer);
        $before = $invoice->fresh()->getRawOriginal();
        $this->actingAs($customer, 'customer');
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $response = $this->get($prefix.'/account/invoices/'.$invoice->id)->assertOk()
                ->assertSee(trans('marketing.code_prompt', [], $locale));
            $html = $response->getContent();
            if ($previewDir = getenv('DISCOUNT_PREVIEW_DIR')) {
                file_put_contents($previewDir.'/invoice-'.$locale.'.html', $html);
            }
            preg_match('/<details[^>]*data-discount-entry[^>]*>/', $html, $m);
            $this->assertNotEmpty($m);
            $this->assertDoesNotMatchRegularExpression('/\sopen(?:\s|=|>)/', $m[0]);
            $entry = strpos($html, 'data-discount-entry');
            $this->assertGreaterThan(strpos($html, trans('ui.inv_payable', [], $locale)), $entry);
            $this->assertLessThan(strpos($html, trans('ui.inv_pay_heading', [], $locale)), $entry);
            $this->assertStringContainsString('id="invoice-discount-code"', $html);
            $this->assertStringNotContainsString('/account/offers', $html);
        }
        $this->assertSame($before, $invoice->fresh()->getRawOriginal());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame(0, BusinessEntry::count());
    }

    public function test_admin_checkbox_saves_only_visibility_and_other_tabs_cannot_reset_it(): void
    {
        $admin = $this->admin();
        Setting::put('bank_holder', 'Untouched fixture bank');
        $this->actingAs($admin)->get('/admin/settings?tab=accounts')->assertOk()->assertSee('name="enabled"', false);
        $this->post('/admin/settings/discount-entry', ['enabled' => '1'])->assertRedirect('/admin/settings?tab=accounts#discount-entry');
        $this->assertSame('1', Setting::get(DiscountEntryVisibility::SETTING));
        $this->assertSame('Untouched fixture bank', Setting::get('bank_holder'));
        $this->post('/admin/settings', ['tab' => 'pricing'])->assertRedirect();
        $this->assertSame('1', Setting::get(DiscountEntryVisibility::SETTING));
        $this->post('/admin/settings/discount-entry', ['enabled' => '0'])->assertRedirect();
        $this->assertSame('0', Setting::get(DiscountEntryVisibility::SETTING));
        $this->post('/admin/settings/discount-entry', [])->assertSessionHasErrors('enabled');
        $this->assertSame('0', Setting::get(DiscountEntryVisibility::SETTING));
    }

    public function test_admin_customer_radios_persist_and_reject_invalid_modes(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->admin())->get('/admin/customers/'.$customer->id)->assertOk()
            ->assertSee('type="radio" name="visibility" value="inherit" checked', false);
        foreach (DiscountEntryVisibility::MODES as $mode) {
            $this->post('/admin/customers/'.$customer->id.'/discount-entry', ['visibility' => $mode])
                ->assertRedirect('/admin/customers/'.$customer->id.'#finance');
            $this->assertSame($mode, $customer->fresh()->discount_entry_visibility);
        }
        $this->post('/admin/customers/'.$customer->id.'/discount-entry', ['visibility' => 'invalid'])->assertSessionHasErrors('visibility');
        $this->assertSame('disabled', $customer->fresh()->discount_entry_visibility);
    }

    public function test_support_and_customers_cannot_change_admin_visibility_controls(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->admin('support'))->post('/admin/settings/discount-entry', ['enabled' => 1])->assertForbidden();
        $this->post('/admin/customers/'.$customer->id.'/discount-entry', ['visibility' => 'enabled'])->assertForbidden();
        $customer->fill(['discount_entry_visibility' => 'enabled']);
        $this->assertNotSame('enabled', $customer->getAttribute('discount_entry_visibility'));
        $this->assertSame('inherit', $customer->fresh()->discount_entry_visibility);
        $this->assertNull(Setting::get(DiscountEntryVisibility::SETTING));
    }

    public function test_topups_and_paid_invoices_do_not_show_entry_even_when_enabled(): void
    {
        $customer = $this->customer();
        Setting::put(DiscountEntryVisibility::SETTING, '1');
        foreach ([['kind' => 'topup'], ['status' => 'paid', 'paid' => 11000]] as $extra) {
            $invoice = $this->invoice($customer, $extra);
            $this->actingAs($customer, 'customer')->get('/account/invoices/'.$invoice->id)
                ->assertOk()->assertDontSee('data-discount-entry', false);
        }
    }

    public function test_new_column_defaults_existing_customers_to_inherit_without_changing_the_account(): void
    {
        $customer = $this->customer();
        $migration = require database_path('migrations/2026_10_26_140000_add_discount_entry_visibility_to_customers.php');
        $migration->down();
        $before = $customer->fresh()->getRawOriginal();
        $this->assertFalse(app(DiscountEntryVisibility::class)->enabled($customer->fresh()));
        $migration->up();
        $after = $customer->fresh()->getRawOriginal();
        $this->assertSame('inherit', $after['discount_entry_visibility']);
        unset($after['discount_entry_visibility']);
        $this->assertSame($before, $after);
    }

    public function test_existing_zero_total_offer_keeps_its_completion_button_while_entry_is_hidden(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, ['subtotal' => 0, 'tax' => 0, 'total' => 0]);
        $campaign = MarketingCampaign::create(['name' => 'Existing fixture', 'currency_code' => 'IRT', 'rule' => [], 'audience' => [],
            'automation' => [], 'message' => [], 'budget' => 10000, 'max_uses' => 1]);
        $grant = MarketingGrant::create(['campaign_id' => $campaign->id, 'customer_id' => $customer->id,
            'code' => 'SN-FIXTURE2345', 'issue_key' => 'fixture', 'track_token' => str_repeat('a', 64), 'snapshot' => [],
            'liability' => 10000, 'starts_at' => now()->subHour(), 'expires_at' => now()->addHour()]);
        MarketingRedemption::create(['campaign_id' => $campaign->id, 'grant_id' => $grant->id, 'customer_id' => $customer->id,
            'invoice_id' => $invoice->id, 'status' => 'reserved', 'amount' => 10000, 'snapshot' => [], 'expires_at' => now()->addHour()]);
        $this->actingAs($customer, 'customer')->get('/account/invoices/'.$invoice->id)->assertOk()
            ->assertSee('data-discount-reservation', false)->assertSee(__('marketing.free'))
            ->assertDontSee('data-discount-entry', false);
        $this->assertSame('reserved', MarketingRedemption::first()->status);
        $this->assertSame(0, $invoice->fresh()->paid);
    }
}
