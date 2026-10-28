<?php

namespace Tests\Feature;

use App\Models\BirthdayProductCost;
use App\Models\BirthdayRedemption;
use App\Models\BirthdayReward;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MarketingPreference;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Birthday\BirthdayCalculator;
use App\Services\Birthday\BirthdayCosts;
use App\Services\Birthday\BirthdayInvoices;
use App\Services\Birthday\BirthdayMessages;
use App\Services\Birthday\BirthdayProgram;
use App\Services\Marketing\InvoiceContext;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SupportsPatterns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BirthdayRewardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-29 10:00:00', 'UTC'));
        config(['birthday.enabled' => false, 'birthday_rewards.enabled' => false,
            'birthday_rewards.pattern_reference' => null, 'birthday_rewards.sms_from_hour' => 0,
            'birthday_rewards.sms_until_hour' => 24]);
        Http::preventStrayRequests();
    }

    private function born(string $date = '2000-10-29'): Customer
    {
        $c = Customer::create(['email' => bin2hex(random_bytes(6)).'@example.test',
            'phone' => '0912'.random_int(1000000, 9999999), 'password' => 'secret-password',
            'status' => 'active', 'locale' => 'en']);
        $c->forceFill(['phone_verified_at' => now()])->save();
        CustomerProfile::create(['customer_id' => $c->id, 'type' => 'individual', 'status' => 'verified',
            'is_default' => true, 'email' => $c->email, 'mobile' => $c->phone, 'country' => 'IR',
            'first_name' => 'آزمایش', 'last_name' => 'تولد', 'birth_date' => $date]);
        MarketingPreference::create(['customer_id' => $c->id, 'consent' => 'granted']);
        return $c->fresh();
    }

    private function reward(Customer $c): BirthdayReward
    {
        Setting::put('birthday_offer_enabled', '1');
        return app(BirthdayProgram::class)->ensure($c);
    }

    private function invoice(Customer $c, bool $known = true): Invoice
    {
        $product = Product::create(['name' => 'Birthday fixture', 'plan' => 'birthday-'.bin2hex(random_bytes(5)),
            'category' => 'other', 'price' => 1000, 'base_price_eur' => 1, 'currency_code' => 'IRT',
            'is_active' => true]);
        $s = Service::create(['customer_id' => $c->id, 'name' => 'Birthday fixture', 'plan' => $product->plan,
            'status' => 'active', 'cycle' => 'monthly', 'price' => 1000, 'currency_code' => 'IRT']);
        $i = Invoice::create(['customer_id' => $c->id, 'service_id' => $s->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 1000, 'tax' => 100, 'total' => 1100,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);
        InvoiceItem::create(array_merge(['invoice_id' => $i->id, 'title' => 'Service',
            'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000, 'discount' => 0,
            'tax_rate_bp' => 1000, 'tax_amount' => 100],
            app(InvoiceContext::class)->attributes($s, 'purchase', $product)));
        if ($known) {
            BirthdayProductCost::create(['product_id' => $product->id,
                'rule_key' => $product->id.':0:monthly:service', 'cycle' => 'monthly', 'component' => 'service',
                'currency_code' => 'IRT', 'amount' => 800, 'reference' => 'TEST-SUPPLIER-INVOICE',
                'verified_by' => User::factory()->create(['role' => 'admin'])->id,
                'verified_at' => now(), 'valid_until' => now()->addDays(2)]);
        }
        return $i->fresh();
    }

    private function rejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a price review or policy rejection');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_defaults_do_not_issue_windows_or_send_sms(): void
    {
        $defaults = require base_path('config/birthday_rewards.php');
        $this->assertFalse($defaults['enabled']);
        $this->assertFalse($defaults['sms_enabled']);
        $c = $this->born();
        $this->assertNull(app(BirthdayProgram::class)->ensure($c));
        $this->artisan('birthday:rewards')->assertSuccessful();
        $this->assertDatabaseCount('birthday_rewards', 0);
        $this->assertFalse(app(BirthdayMessages::class)->ready());
    }

    public function test_window_is_exactly_24_hours_and_never_extended_or_reissued(): void
    {
        $c = $this->born();
        $r = $this->reward($c);
        $this->assertSame(86400, $r->expires_at->getTimestamp() - $r->starts_at->getTimestamp());
        $this->travel(3)->hours();
        $this->assertSame($r->id, app(BirthdayProgram::class)->ensure($c)->id);
        $this->assertTrue($r->expires_at->equalTo($r->fresh()->expires_at));
        $this->travel(22)->hours();
        $this->assertNull(app(BirthdayProgram::class)->ensure($c));
        $this->assertDatabaseCount('birthday_rewards', 1);
    }

    public function test_only_existing_profile_birthday_qualifies_and_legacy_gift_prevents_a_second_gift(): void
    {
        Setting::put('birthday_offer_enabled', '1');
        $this->assertNull(app(BirthdayProgram::class)->ensure($this->born('2000-10-28')));
        $c = $this->born();
        \App\Models\GiftCoupon::create(['customer_id' => $c->id, 'code' => 'HB-OLD-TEST',
            'amount' => 100, 'currency_code' => 'IRT', 'reason' => 'birthday',
            'expires_at' => now()->addDay()]);
        $this->assertNull(app(BirthdayProgram::class)->ensure($c));
    }

    public function test_documented_cost_removes_only_markup_recomputes_tax_and_preserves_renewal_price(): void
    {
        $c = $this->born(); $this->reward($c); $i = $this->invoice($c);
        $engine = app(BirthdayInvoices::class);
        $engine->prepare($i, true);
        $this->assertSame(200, $i->discount);
        $this->assertSame(80, $i->tax);
        $this->assertSame(880, $i->total);
        $this->assertSame(1000, (int) $i->service->price);
        $engine->prepare($i, true);
        $this->assertSame(880, $i->total);
        $this->assertDatabaseCount('birthday_redemptions', 1);
        $r = $engine->redemption($i);
        $this->assertSame(800, $r->snapshot['costs'][(string) $i->items()->first()->id]['amount']);
    }

    public function test_unknown_and_expired_costs_do_not_invent_a_discount(): void
    {
        $c = $this->born(); $this->reward($c); $i = $this->invoice($c, false);
        app(BirthdayInvoices::class)->prepare($i, true);
        $this->assertSame(1100, $i->total);
        $expired = $this->invoice($c);
        BirthdayProductCost::query()->update(['valid_until' => now()->subSecond()]);
        app(BirthdayInvoices::class)->prepare($expired, true);
        $this->assertSame(1100, $expired->total);
        $this->assertDatabaseCount('birthday_redemptions', 0);
        $this->assertNull(app(BirthdayCosts::class)->convert(100, 'IRR', 'IRT'));
    }

    public function test_old_partial_and_wallet_invoices_stay_unchanged(): void
    {
        $c = $this->born(); $this->reward($c);
        foreach (['old', 'partial', 'wallet'] as $type) {
            $i = $this->invoice($c);
            if ($type === 'old') $i->forceFill(['issued_at' => now()->subDay()])->saveQuietly();
            if ($type === 'partial') $i->forceFill(['paid' => 10])->saveQuietly();
            if ($type === 'wallet') $i->forceFill(['kind' => 'credit'])->saveQuietly();
            app(BirthdayInvoices::class)->prepare($i, true);
            $this->assertSame(1100, $i->total);
        }
        $this->assertDatabaseCount('birthday_redemptions', 0);
    }

    public function test_expiry_restores_unpaid_quote_and_rejects_its_stale_payment_token(): void
    {
        $c = $this->born(); $this->reward($c); $i = $this->invoice($c);
        $engine = app(BirthdayInvoices::class); $engine->prepare($i, true); $old = $engine->token($i);
        $this->travel(25)->hours();
        $engine->synchronize($i);
        $this->assertSame(1100, $i->total);
        $this->assertSame(100, $i->tax);
        $this->assertSame('released', $engine->redemption($i)->status);
        $this->rejected(fn () => $engine->assertPresented($i, $old));
        $this->assertDatabaseCount('credit_entries', 0);
    }

    public function test_pending_payment_keeps_agreed_amount_and_late_settlement_commits_it(): void
    {
        $c = $this->born(); $this->reward($c); $i = $this->invoice($c);
        $engine = app(BirthdayInvoices::class); $engine->prepare($i, true);
        Payment::create(['invoice_id' => $i->id, 'customer_id' => $c->id, 'currency_code' => 'IRT',
            'amount' => 880, 'gateway' => 'zarinpal', 'status' => 'redirected', 'external_ref' => 'TEST-ONLY']);
        $this->travel(25)->hours(); $engine->synchronize($i);
        $this->assertSame(880, $i->total);
        $this->assertSame('locked', $engine->redemption($i)->status);
        $i->forceFill(['paid' => 880, 'status' => 'paid'])->saveQuietly();
        $engine->synchronize($i);
        $this->assertSame('committed', $engine->redemption($i)->status);
    }

    public function test_changed_cost_requires_review_and_a_new_presented_price(): void
    {
        $c = $this->born(); $this->reward($c); $i = $this->invoice($c);
        $engine = app(BirthdayInvoices::class); $engine->prepare($i, true); $old = $engine->token($i);
        BirthdayProductCost::query()->update(['amount' => 900]);
        $this->rejected(fn () => $engine->prepare($i));
        $this->assertSame(880, $i->fresh()->total);
        $engine->prepare($i, true);
        $this->assertSame(990, $i->total);
        $this->rejected(fn () => $engine->assertPresented($i, $old));
        $this->assertDatabaseCount('birthday_redemptions', 1);
    }

    public function test_presented_invoice_token_is_required_on_web_post_without_debiting_customer(): void
    {
        $c = $this->born(); $this->reward($c); $i = $this->invoice($c);
        app(BirthdayInvoices::class)->prepare($i, true);
        $this->actingAs($c, 'customer')->post('/account/invoices/'.$i->id.'/pay-credit')
            ->assertSessionHasErrors('birthday');
        $this->assertSame(0, $i->fresh()->paid);
        $this->assertDatabaseCount('credit_entries', 0);
    }

    public function test_patterns_stay_blocked_until_approved_and_are_attempted_only_once(): void
    {
        $c = $this->born(); $r = $this->reward($c);
        $fake = new BirthdayTestSender; $this->app->instance(SmsSender::class, $fake);
        Setting::put('birthday_sms_enabled', '1');
        $m = app(BirthdayMessages::class);
        $this->assertSame('pending_pattern', $m->dispatch($r));
        $this->assertSame([], $fake->calls);
        config(['birthday_rewards.pattern_reference' => 'test-approved']);
        Setting::put('birthday_pattern_reference', 'test-approved');
        Setting::put('birthday_pattern_approved', '1');
        $this->assertSame('accepted', $m->dispatch($r));
        $m->dispatch($r->fresh());
        $this->assertCount(1, $fake->calls);
        $this->assertSame('birthday_surprise', $fake->calls[0][1]);
        $this->assertSame(['name' => 'آزمایش'], $fake->calls[0][2]);
        $this->assertSame('accepted', $r->fresh()->sms_status);
    }

    public function test_unknown_transport_response_is_not_retried_and_optout_is_respected(): void
    {
        $c = $this->born(); $r = $this->reward($c);
        config(['birthday_rewards.pattern_reference' => 'test-approved']);
        Setting::put('birthday_pattern_reference', 'test-approved');
        Setting::put('birthday_pattern_approved', '1');
        Setting::put('birthday_sms_enabled', '1');
        $fake = new BirthdayTestSender; $fake->timeout = true; $this->app->instance(SmsSender::class, $fake);
        $m = app(BirthdayMessages::class);
        $this->assertSame('unknown', $m->dispatch($r));
        $m->dispatch($r->fresh()); $this->assertCount(1, $fake->calls);
        $other = $this->born(); $second = $this->reward($other);
        MarketingPreference::where('customer_id', $other->id)->update(['consent' => 'denied']);
        $m->dispatch($second);
        $this->assertCount(1, $fake->calls);
        $this->assertSame('blocked_consent', $second->fresh()->sms_status);
    }

    public function test_admin_configuration_blocks_unapproved_sms_and_records_exact_currency_units(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/settings/birthday-rewards', ['enabled' => 1, 'sms_enabled' => 1])
            ->assertSessionHasErrors('sms_enabled');
        $this->assertFalse(app(BirthdayMessages::class)->ready());
        $c = $this->born(); $i = $this->invoice($c, false);
        $p = Product::where('plan', $i->service->plan)->firstOrFail();
        $this->actingAs($admin)->post('/admin/settings/birthday-costs', [
            'product_id' => $p->id, 'cycle' => 'monthly', 'component' => 'service',
            'currency_code' => 'EUR', 'amount' => '12.34', 'reference' => 'TEST-ONLY', 'valid_days' => 7,
        ])->assertRedirect();
        $this->assertSame(1234, BirthdayProductCost::firstOrFail()->amount);
        $support = User::factory()->create(['role' => 'support']);
        $this->actingAs($support)->post('/admin/settings/birthday-rewards', ['enabled' => 1, 'sms_enabled' => 0])->assertForbidden();
    }

    public function test_customer_invoice_renders_savings_without_offers_menu_or_birthday_input(): void
    {
        $c = $this->born(); $this->reward($c); $i = $this->invoice($c);
        foreach (['fa', 'en', 'tr'] as $locale) {
            $c->update(['locale' => $locale]);
            $this->actingAs($c, 'customer')->get('/account/invoices/'.$i->id)
                ->assertOk()->assertSee('data-birthday-discount', false)
                ->assertSee('birthday_price_token', false)->assertDontSee('name="birth_date"', false)
                ->assertDontSee('href="https://console.servernet.cloud/account/offers"', false);
        }
    }

    public function test_dry_run_does_not_issue_or_send(): void
    {
        $this->born(); Setting::put('birthday_offer_enabled', '1');
        $this->artisan('birthday:rewards', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('birthday_rewards', 0);
    }

    public function test_integer_cost_and_tax_math_never_drops_below_cost_or_adds_markup(): void
    {
        $calculator = app(BirthdayCalculator::class);
        for ($cost = 1; $cost <= 100; $cost++) {
            $q = $calculator->line($cost + 7, $cost, 1000);
            $this->assertSame($cost, $q['net']);
            $this->assertSame(7, $q['discount']);
            $this->assertSame(intdiv($cost * 1000 + 5000, 10000), $q['tax']);
        }
        $this->assertSame(0, $calculator->line(100, 120, 1000)['discount']);
        $this->expectException(\InvalidArgumentException::class);
        $calculator->line(100, -1, 1000);
    }
}

class BirthdayTestSender implements SmsSender, SupportsPatterns
{
    public array $calls = [];
    public bool $timeout = false;
    public function enabled(): bool { return true; }
    public function name(): string { return 'test'; }
    public function hasPattern(string $event): bool { return $event === 'birthday_surprise'; }
    public function send(string $mobile, string $text): bool { throw new \LogicException('Free text forbidden'); }
    public function sendOtp(string $mobile, string $code): bool { throw new \LogicException('OTP forbidden'); }
    public function sendPattern(string $mobile, string $event, array $values): ?bool
    {
        $this->calls[] = [$mobile, $event, $values];
        if ($this->timeout) throw new \RuntimeException('Simulated unknown transport outcome');
        return true;
    }
}
