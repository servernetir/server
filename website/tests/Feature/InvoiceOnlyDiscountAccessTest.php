<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\AccountController;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\GiftCoupon;
use App\Models\Invoice;
use App\Models\MarketingPreference;
use App\Models\Setting;
use App\Services\Marketing\CampaignAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvoiceOnlyDiscountAccessTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        Http::preventStrayRequests();

        return Customer::create(['email' => uniqid('invoice-only-', true).'@example.test', 'locale' => 'en', 'status' => 'active']);
    }

    public function test_offers_and_birthday_form_are_retired_in_every_customer_language(): void
    {
        $c = $this->customer();
        MarketingPreference::create(['customer_id' => $c->id, 'consent' => 'granted', 'birth_month' => 2, 'birth_day' => 29]);
        $this->actingAs($c, 'customer');
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            app()->setLocale($locale);
            $nav = collect(AccountController::shell('invoices')['pnlNav'])->flatMap(fn ($group) => $group['items']);
            $this->assertFalse($nav->contains('key', 'offers'));
            $this->get($prefix.'/account/offers')->assertRedirect($prefix.'/account/marketing/unsubscribe');
            $this->get($prefix.'/account/marketing/unsubscribe')->assertOk()
                ->assertDontSee('name="birth_', false)->assertDontSee('name="code"', false)
                ->assertDontSee('/account/offers', false);
            $this->post($prefix.'/account/offers/preferences', ['consent' => 'withdrawn', 'birth_month' => 10, 'birth_day' => 26])->assertGone();
        }
        $pref = MarketingPreference::where('customer_id', $c->id)->first();
        $this->assertSame('granted', $pref->consent);
        $this->assertSame(2, $pref->birth_month);
        $this->assertSame(29, $pref->birth_day);
        $this->assertDatabaseCount('marketing_audits', 0);
    }

    public function test_optout_is_explicit_idempotent_and_cannot_overwrite_birthday_or_enable_consent(): void
    {
        $c = $this->customer();
        $other = $this->customer();
        MarketingPreference::create(['customer_id' => $c->id, 'consent' => 'granted', 'birth_month' => 2, 'birth_day' => 29]);
        MarketingPreference::create(['customer_id' => $other->id, 'consent' => 'granted']);
        $this->actingAs($c, 'customer')->get('/account/marketing/unsubscribe')->assertOk();
        $this->assertSame('granted', MarketingPreference::where('customer_id', $c->id)->value('consent'));
        for ($i = 0; $i < 2; $i++) {
            $this->post('/account/marketing/unsubscribe', ['consent' => 'granted', 'birth_month' => 10, 'customer_id' => $other->id])->assertRedirect();
        }
        $pref = MarketingPreference::where('customer_id', $c->id)->first();
        $this->assertSame('withdrawn', $pref->consent);
        $this->assertSame(2, $pref->birth_month);
        $this->assertSame('granted', MarketingPreference::where('customer_id', $other->id)->value('consent'));
        $this->assertDatabaseCount('marketing_audits', 1);
    }

    public function test_only_profile_birthday_is_used_and_legacy_marketing_dates_are_ignored(): void
    {
        $c = $this->customer();
        MarketingPreference::create(['customer_id' => $c->id, 'birth_calendar' => 'gregorian', 'birth_month' => 10, 'birth_day' => 26]);
        $audience = app(CampaignAudience::class);
        $today = Carbon::parse('2026-10-26 12:00', 'Asia/Tehran');
        $this->assertFalse($audience->birthday($c, $today));
        $profile = CustomerProfile::create(['customer_id' => $c->id, 'type' => 'individual', 'is_default' => true, 'email' => $c->email, 'birth_date' => '2000-02-29']);
        $this->assertFalse($audience->birthday($c, $today));
        $this->assertTrue($audience->birthday($c, Carbon::parse('2026-02-28 12:00', 'Asia/Tehran')));
        $this->assertFalse($audience->birthday($c, Carbon::parse('2028-02-28 12:00', 'Asia/Tehran')));
        $this->assertTrue($audience->birthday($c, Carbon::parse('2028-02-29 12:00', 'Asia/Tehran')));
        $profile->update(['birth_date' => null]);
        $this->assertFalse($audience->birthday($c, $today));
    }

    public function test_denied_customers_cannot_submit_codes_directly_and_enabled_gift_entry_works(): void
    {
        $c = $this->customer();
        $invoice = Invoice::create(['customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 10000, 'tax' => 1000, 'total' => 11000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);
        $gift = GiftCoupon::create(['customer_id' => $c->id, 'code' => 'HB-PERMISSION2345', 'currency_code' => 'IRT',
            'amount' => 1000, 'min_invoice' => 0, 'reason' => 'birthday', 'expires_at' => now()->addDay()]);
        $this->actingAs($c, 'customer');
        foreach (['inherit', 'disabled'] as $mode) {
            Setting::put('invoice_discount_entry_enabled', $mode === 'disabled' ? '1' : '0');
            $c->forceFill(['discount_entry_visibility' => $mode])->save();
            foreach (['', '/en', '/tr'] as $prefix) {
                $this->post($prefix.'/account/invoices/'.$invoice->id.'/offer', ['code' => 'SN-PERMISSION2345'])->assertForbidden();
                $this->post($prefix.'/account/invoices/'.$invoice->id.'/coupon', ['code' => $gift->code])->assertForbidden();
            }
        }
        $this->assertSame(0, $invoice->fresh()->paid);
        $this->assertNull($gift->fresh()->used_at);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('marketing_redemptions', 0);
        Setting::put('invoice_discount_entry_enabled', '0');
        $c->forceFill(['discount_entry_visibility' => 'enabled'])->save();
        $this->post('/account/invoices/'.$invoice->id.'/coupon', ['code' => $gift->code])->assertRedirect();
        $this->assertNotNull($gift->fresh()->used_at);
        $this->assertSame(1000, $invoice->fresh()->paid);
        $this->assertSame(11000, $invoice->fresh()->total);
    }
}
