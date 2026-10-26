<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MarketingCampaign;
use App\Models\MarketingDelivery;
use App\Models\MarketingGrant;
use App\Models\MarketingPreference;
use App\Models\MarketingRedemption;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Marketing\CampaignAudience;
use App\Services\Marketing\CampaignManager;
use App\Services\Marketing\CampaignMessages;
use App\Services\Marketing\CampaignReport;
use App\Services\Marketing\CampaignRunner;
use App\Services\Marketing\DiscountEngine;
use App\Services\Payment\PaymentService;
use App\Services\Sms\SmsSender;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarketingCampaignEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CampaignTestSms $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-25 08:00:00', 'UTC'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'birthday.enabled' => false,
            'marketing.sms_enabled' => false, 'marketing.sms_country_reviewed' => false]);
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->sms = new CampaignTestSms;
        $this->app->instance(SmsSender::class, $this->sms);
    }

    private function customer(string $locale = 'fa', bool $consent = true): Customer
    {
        $c = Customer::create(['email' => 'customer-'.bin2hex(random_bytes(6)).'@example.test',
            'phone' => '0912'.random_int(1000000, 9999999), 'password' => 'secret-password',
            'locale' => $locale, 'timezone' => 'Asia/Tehran', 'status' => 'active']);
        $c->forceFill(['phone_verified_at' => now()])->save();
        CustomerProfile::create(['customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'draft', 'email' => $c->email, 'mobile' => $c->phone, 'first_name' => 'آزمایش', 'country' => 'IR']);
        MarketingPreference::create(['customer_id' => $c->id, 'consent' => $consent ? 'granted' : 'unknown']);

        return $c;
    }

    private function data(array $over = []): array
    {
        return array_replace_recursive([
            'name' => 'Test campaign', 'currency_code' => 'IRT', 'trigger' => 'manual', 'timezone' => 'Asia/Tehran',
            'budget' => 3_000_000, 'max_uses' => 10, 'max_per_customer' => 1,
            'rule' => ['type' => 'percent', 'value' => 2000, 'max_discount' => 300_000,
                'min_purchase' => 0, 'per_customer_cap' => 300_000, 'locales' => ['fa', 'en', 'tr'],
                'operations' => ['purchase', 'renewal'], 'families' => ['vps'], 'stackable' => false,
                'min_margin_bp' => 0, 'subsidy_approved' => true, 'subsidy_reason' => 'Test bounded subsidy'],
            'audience' => [], 'automation' => ['valid_hours' => 72, 'at' => '09:00', 'reminder_hours' => 24],
            'message' => ['enabled' => false, 'template' => '{{first_name}} {{discount_label}} {{service_name}} {{coupon_code}} {{expires_at_local}} {{redeem_url}} {{unsubscribe_instructions}}',
                'landing_path' => '/account/cloud-store', 'service_name' => 'VPS', 'limit' => 10, 'budget' => 100_000],
        ], $over);
    }

    private function campaign(array $over = []): MarketingCampaign
    {
        $manager = app(CampaignManager::class);
        $c = $manager->save($this->data($over), $this->admin);
        $manager->transition($c, $this->admin, 'activate', 'Test approval');

        return $c->fresh();
    }

    private function grant(MarketingCampaign $c, ?Customer $customer, string $key = 'first'): MarketingGrant
    {
        return app(CampaignManager::class)->issue($c, $customer, $key, $customer === null);
    }

    private function invoice(Customer $c, int $amount = 1_000_000, string $currency = 'IRT', int $extra = 0): Invoice
    {
        $i = Invoice::create(['customer_id' => $c->id, 'kind' => 'service', 'currency_code' => $currency,
            'subtotal' => $amount + $extra, 'tax' => intdiv($amount + $extra, 10),
            'total' => $amount + $extra + intdiv($amount + $extra, 10), 'paid' => 0, 'status' => 'unpaid']);
        InvoiceItem::create(['invoice_id' => $i->id, 'title' => 'VPS', 'quantity' => 1, 'unit_price' => $amount,
            'line_total' => $amount, 'discount' => 0, 'tax_rate_bp' => 1000, 'tax_amount' => intdiv($amount, 10),
            'marketing_context' => ['eligible' => true, 'operation' => 'purchase', 'family' => 'vps',
                'product_id' => 1, 'plan' => 'test-plan', 'provider' => 'test-provider', 'country' => 'DE']]);
        if ($extra) {
            InvoiceItem::create(['invoice_id' => $i->id, 'title' => 'Setup', 'quantity' => 1, 'unit_price' => $extra,
                'line_total' => $extra, 'tax_rate_bp' => 1000, 'tax_amount' => intdiv($extra, 10)]);
        }

        return $i;
    }

    private function reserve(Invoice $i, Customer $c, MarketingGrant $g): MarketingRedemption
    {
        return app(DiscountEngine::class)->reserve($i, $c, $g->code);
    }

    private function rejected(callable $fn, ?string $message = null): void
    {
        try {
            $fn();
            $this->fail('Expected validation rejection');
        } catch (ValidationException $e) {
            if ($message) {
                $this->assertContains(__('marketing.'.$message), collect($e->errors())->flatten()->all());
            } else {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    private function pay(Invoice $i): Payment
    {
        $p = Payment::create(['invoice_id' => $i->id, 'customer_id' => $i->customer_id,
            'currency_code' => $i->currency_code, 'amount' => $i->fresh()->due(), 'gateway' => 'credit',
            'status' => 'redirected', 'external_ref' => 'test-'.bin2hex(random_bytes(8))]);
        $this->assertTrue(app(PaymentService::class)->settleConfirmed($p, $p->external_ref)->ok);

        return $p->fresh();
    }

    private function enableSms(MarketingCampaign $c): void
    {
        config(['marketing.sms_enabled' => true, 'marketing.sms_country_reviewed' => true,
            'marketing.sms_daily_budget' => 100_000, 'marketing.sms_segment_cost_irt' => 100,
            'marketing.sms_hours' => [0, 24]]);
        $c->update(['messages_paused' => false]);
    }

    public function test_percentage_only_discounts_eligible_lines_and_their_frozen_tax(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign();
        $g = $this->grant($campaign, $c);
        $i = $this->invoice($c, extra: 500_000);
        $r = $this->reserve($i, $c, $g);
        $this->assertSame(200_000, $r->amount);
        $this->assertSame(1_430_000, $i->fresh()->total);
        $this->assertSame(130_000, $i->fresh()->tax);
        $this->assertSame(0, $i->fresh()->paid);
        $this->assertSame(0, $i->items()->where('title', 'Setup')->first()->discount);
        $this->assertSame('reserved', $r->status);
        $this->assertSame(300_000, $campaign->fresh()->held);
    }

    public function test_fixed_discount_and_order_cap_never_create_negative_payable(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign(['rule' => ['type' => 'fixed', 'value' => 300_000]]);
        $i = $this->invoice($c, 100_000);
        $this->reserve($i, $c, $this->grant($campaign, $c));
        $this->assertSame(0, $i->fresh()->total);
        $this->assertSame(0, $i->fresh()->tax);
        $this->assertSame(0, $c->creditBalance());
    }

    public function test_minimum_uses_eligible_subtotal_rather_than_mixed_cart_total(): void
    {
        $c = $this->customer();
        $g = $this->grant($this->campaign(['rule' => ['min_purchase' => 1_500_000]]), $c);
        $this->rejected(fn () => $this->reserve($this->invoice($c, extra: 2_000_000), $c, $g), 'minimum');
    }

    public function test_other_account_cannot_reserve_or_view_private_grant(): void
    {
        $c = $this->customer();
        $other = $this->customer();
        $g = $this->grant($this->campaign(), $c);
        $this->rejected(fn () => $this->reserve($this->invoice($other), $other, $g), 'unknown');
        $this->actingAs($other, 'customer')->get('/account/offers/'.$g->id.'/open')->assertNotFound();
    }

    public function test_currency_does_not_silently_convert_rial_toman_or_euros(): void
    {
        $c = $this->customer();
        $g = $this->grant($this->campaign(), $c);
        foreach (['IRR', 'EUR'] as $currency) {
            $this->rejected(fn () => $this->reserve($this->invoice($c, currency: $currency), $c, $g), 'currency');
        }
    }

    public function test_plan_provider_country_and_service_family_filters_are_enforced(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign(['rule' => ['providers' => ['wrong'], 'countries' => ['DE']]]);
        $this->rejected(fn () => $this->reserve($this->invoice($c), $c, $this->grant($campaign, $c)), 'scope');
    }

    public function test_topups_and_unmarked_hourly_or_legacy_lines_are_ineligible(): void
    {
        $c = $this->customer();
        $g = $this->grant($this->campaign(), $c);
        $i = $this->invoice($c);
        $i->update(['kind' => 'topup']);
        $this->rejected(fn () => $this->reserve($i, $c, $g), 'state');
        $i->update(['kind' => 'service']);
        $i->items()->update(['marketing_context' => null]);
        $this->rejected(fn () => $this->reserve($i, $c, $g), 'scope');
    }

    public function test_existing_discounts_cannot_stack_without_explicit_permission(): void
    {
        $c = $this->customer();
        $g = $this->grant($this->campaign(), $c);
        $i = $this->invoice($c);
        $i->items()->update(['discount' => 10_000, 'tax_amount' => 99_000]);
        $i->recalculateTotals();
        $i->save();
        $this->rejected(fn () => $this->reserve($i, $c, $g), 'stacking');
    }

    public function test_unknown_cost_is_not_treated_as_zero_and_rolls_back(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign(['rule' => ['subsidy_approved' => false]]);
        $i = $this->invoice($c);
        $this->rejected(fn () => $this->reserve($i, $c, $this->grant($campaign, $c)), 'unknown_cost');
        $this->assertSame(1_100_000, $i->fresh()->total);
        $this->assertDatabaseCount('marketing_redemptions', 0);
    }

    public function test_supplier_margin_blocks_loss_even_when_cost_is_known(): void
    {
        $c = $this->customer();
        $g = $this->grant($this->campaign(['rule' => ['subsidy_approved' => false, 'min_margin_bp' => 1000]]), $c);
        $i = $this->invoice($c);
        $line = $i->items()->first();
        $line->update(['marketing_context' => $line->marketing_context + ['cost_minor' => 900_000, 'cost_currency' => 'IRT']]);
        $this->rejected(fn () => $this->reserve($i, $c, $g), 'margin');
    }

    public function test_personal_commitment_is_reserved_once_and_budget_prevents_overissue(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign(['budget' => 300_000]);
        $g = $this->grant($campaign, $c);
        $this->assertSame($g->id, $this->grant($campaign, $c)->id);
        $this->assertSame(300_000, $campaign->fresh()->held);
        $this->rejected(fn () => $this->grant($campaign, $this->customer()), 'budget');
    }

    public function test_repeated_checkout_is_idempotent_and_a_second_invoice_cannot_take_the_code(): void
    {
        $c = $this->customer();
        $g = $this->grant($this->campaign(), $c);
        $i = $this->invoice($c);
        $r = $this->reserve($i, $c, $g);
        $this->assertSame($r->id, $this->reserve($i, $c, $g)->id);
        $this->rejected(fn () => $this->reserve($this->invoice($c), $c, $g), 'capacity');
        $this->assertDatabaseCount('marketing_redemptions', 1);
    }

    public function test_public_last_capacity_and_personal_guarantees_share_one_budget(): void
    {
        $campaign = $this->campaign(['budget' => 300_000, 'max_uses' => 1]);
        $a = $this->customer();
        $b = $this->customer();
        $public = $this->grant($campaign, null);
        $this->reserve($this->invoice($a), $a, $public);
        $this->rejected(fn () => $this->reserve($this->invoice($b), $b, $public), 'capacity');
        $this->assertSame(200_000, $campaign->fresh()->held);
    }

    public function test_paid_callback_commits_once_and_reports_actual_cash_once(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign();
        $g = $this->grant($campaign, $c);
        $i = $this->invoice($c);
        $this->reserve($i, $c, $g);
        $p = $this->pay($i);
        app(PaymentService::class)->settleConfirmed($p, $p->external_ref);
        $this->assertSame(880_000, $i->fresh()->paid);
        $this->assertSame('committed', MarketingRedemption::first()->status);
        $this->assertSame(200_000, $campaign->fresh()->spent);
        $this->assertSame(0, $campaign->fresh()->held);
        $report = app(CampaignReport::class)->for($campaign->fresh());
        $this->assertSame(1, $report['paid_orders']);
        $this->assertSame(880_000, $report['sales_after']);
        $this->assertNull($report['estimated_profit']);
    }

    public function test_failed_payment_releases_reservation_without_burning_personal_grant(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign();
        $g = $this->grant($campaign, $c);
        $i = $this->invoice($c);
        $this->reserve($i, $c, $g);
        Payment::create(['invoice_id' => $i->id, 'customer_id' => $c->id, 'gateway' => 'credit',
            'currency_code' => 'IRT', 'amount' => 880_000, 'status' => 'failed']);
        $this->travel(31)->minutes();
        app(DiscountEngine::class)->sync($i);
        $this->assertSame('released', MarketingRedemption::first()->status);
        $this->assertSame(1_100_000, $i->fresh()->total);
        $this->assertSame(300_000, $campaign->fresh()->held);
        $this->assertSame('reserved', $this->reserve($this->invoice($c), $c, $g)->status);
    }

    public function test_late_verified_payment_credits_only_real_funds_and_requires_reconciliation(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign();
        $g = $this->grant($campaign, $c);
        $i = $this->invoice($c);
        $this->reserve($i, $c, $g);
        $this->travel(31)->minutes();
        $this->pay($i);
        $this->assertSame(880_000, $c->creditBalance());
        $this->assertSame(0, $i->fresh()->paid);
        $this->assertSame('reconciliation', MarketingRedemption::first()->status);
        $this->assertSame(300_000, $campaign->fresh()->held);
    }

    public function test_in_flight_unknown_payment_is_never_silently_released(): void
    {
        $c = $this->customer();
        $g = $this->grant($this->campaign(), $c);
        $i = $this->invoice($c);
        $this->reserve($i, $c, $g);
        Payment::create(['invoice_id' => $i->id, 'customer_id' => $c->id, 'gateway' => 'credit',
            'currency_code' => 'IRT', 'amount' => 880_000, 'status' => 'redirected']);
        $this->travel(31)->minutes();
        app(DiscountEngine::class)->sync($i);
        $this->assertSame('reconciliation', MarketingRedemption::first()->status);
        $this->assertSame(880_000, $i->fresh()->total);
    }

    public function test_expiry_releases_personal_commitment_once_without_touching_wallet(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign();
        $g = $this->grant($campaign, $c);
        $this->travel(73)->hours();
        app(DiscountEngine::class)->expireGrants();
        app(DiscountEngine::class)->expireGrants();
        $this->assertSame(0, $campaign->fresh()->held);
        $this->assertSame(0, $c->creditBalance());
        $this->rejected(fn () => $this->reserve($this->invoice($c), $c, $g), 'expired');
    }

    public function test_pause_does_not_revoke_existing_promises_and_issued_rules_are_immutable(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign();
        $g = $this->grant($campaign, $c);
        app(CampaignManager::class)->transition($campaign, $this->admin, 'pause', 'Pause issuance');
        $this->assertSame(200_000, $this->reserve($this->invoice($c), $c, $g)->amount);
        $this->rejected(fn () => app(CampaignManager::class)->save($this->data(), $this->admin, $campaign));
    }

    public function test_refund_cannot_turn_the_discount_into_cash_or_restore_coupon_capacity(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign();
        $g = $this->grant($campaign, $c);
        $i = $this->invoice($c);
        $this->reserve($i, $c, $g);
        $this->pay($i);
        $i->refresh()->update(['status' => 'refunded']);
        $this->assertSame('refunded', MarketingRedemption::first()->status);
        $this->assertSame(880_000, $i->fresh()->paid);
        $this->assertSame(0, $c->creditBalance());
        $this->rejected(fn () => $this->reserve($this->invoice($c), $c, $g), 'capacity');
    }

    public function test_free_order_requires_explicit_authenticated_confirmation_and_is_idempotent(): void
    {
        $c = $this->customer();
        $g = $this->grant($this->campaign(['rule' => ['type' => 'fixed', 'value' => 300_000]]), $c);
        $i = $this->invoice($c, 100_000);
        $this->reserve($i, $c, $g);
        $this->actingAs($c, 'customer')->post('/account/invoices/'.$i->id.'/offer/free')->assertRedirect();
        $this->actingAs($c, 'customer')->post('/account/invoices/'.$i->id.'/offer/free')->assertRedirect();
        $this->assertSame('paid', $i->fresh()->status);
        $this->assertSame(0, $i->fresh()->paid);
        $this->assertSame('committed', MarketingRedemption::first()->status);
        $this->assertSame(1, $i->payments()->count());
    }

    public function test_full_birthday_flow_with_mock_sms_reminder_payment_and_expiry(): void
    {
        $c = $this->customer();
        $c->defaultProfile()->update(['birth_date' => now()->toDateString()]);
        $campaign = $this->campaign(['trigger' => 'birthday', 'message' => ['enabled' => true]]);
        $this->enableSms($campaign);
        app(CampaignRunner::class)->tick();
        app(CampaignRunner::class)->tick();
        $this->assertDatabaseCount('marketing_grants', 1);
        $this->assertCount(1, $this->sms->sent);
        $g = MarketingGrant::first();
        $this->assertStringContainsString($g->code, $this->sms->sent[0]['text']);
        $this->assertSame('accepted', MarketingDelivery::where('kind', 'offer')->first()->status);
        $this->assertNull(MarketingDelivery::where('kind', 'offer')->first()->delivered_at);
        $i = $this->invoice($c);
        $this->reserve($i, $c, $g);
        $this->pay($i);
        $this->travel(49)->hours();
        app(CampaignRunner::class)->tick();
        $this->assertCount(1, array_filter($this->sms->sent, fn ($message) => str_contains($message['text'], $g->code)));
        $this->assertSame('canceled', MarketingDelivery::where('kind', 'reminder')->first()->status);
        $this->travel(24)->hours();
        $this->rejected(fn () => $this->reserve($this->invoice($c), $c, $g));
    }

    public function test_birthday_date_change_or_second_campaign_cannot_issue_another_gift(): void
    {
        $c = $this->customer();
        [, $m, $d] = Jalali::ofMoment(now(), 'Asia/Tehran');
        $c->defaultProfile()->update(['birth_date' => now()->toDateString()]);
        $a = $this->campaign(['trigger' => 'birthday']);
        $b = $this->campaign(['trigger' => 'birthday']);
        $this->assertTrue(app(CampaignRunner::class)->issueDue($a, $c));
        $c->defaultProfile()->update(['birth_date' => implode('-', Jalali::toGregorian(1370, $m, $d))]);
        $this->assertFalse(app(CampaignRunner::class)->issueDue($b, $c));
        $this->assertDatabaseCount('marketing_grants', 1);
    }

    public function test_jalali_leap_day_and_tehran_day_boundary(): void
    {
        $c = $this->customer();
        $c->defaultProfile()->update(['birth_date' => implode('-', Jalali::toGregorian(1399, 12, 30))]);
        $date = Jalali::startOfDay(1404, 12, 29, 'Asia/Tehran')->addHours(1)->utc();
        $this->assertTrue(app(CampaignAudience::class)->birthday($c, $date->setTimezone('Asia/Tehran')));
        $this->assertFalse(app(CampaignAudience::class)->birthday($c, $date->copy()->subDays(1)->setTimezone('Asia/Tehran')));
    }

    public function test_dry_run_has_no_grants_deliveries_commitments_or_wallet_writes(): void
    {
        $c = $this->customer();
        $c->defaultProfile()->update(['birth_date' => now()->toDateString()]);
        $campaign = $this->campaign(['trigger' => 'birthday']);
        $this->artisan('marketing:tick --dry-run')->assertExitCode(0);
        foreach (['marketing_grants', 'marketing_deliveries', 'marketing_runs', 'credit_ledger'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, $campaign->fresh()->held);
        $this->assertNull(Setting::get('marketing_last_tick'));
    }

    public function test_optout_after_queue_blocks_sms_and_does_not_disable_otp(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign(['message' => ['enabled' => true]]);
        $this->enableSms($campaign);
        $this->grant($campaign, $c);
        $this->actingAs($c, 'customer')->post('/account/marketing/unsubscribe', ['consent' => 'granted', 'birth_month' => 1])->assertRedirect();
        $this->assertSame('withdrawn', MarketingPreference::where('customer_id', $c->id)->value('consent'));
        app(CampaignMessages::class)->dispatch(MarketingDelivery::where('kind', 'offer')->first());
        $this->assertCount(0, $this->sms->sent);
        $this->assertSame('blocked', MarketingDelivery::where('kind', 'offer')->first()->status);
        $this->assertTrue(app(SmsSender::class)->sendOtp($c->phone, '123456'));
    }

    public function test_unknown_consent_never_queues_a_sendable_message(): void
    {
        $c = $this->customer(consent: false);
        $campaign = $this->campaign(['message' => ['enabled' => true]]);
        $this->grant($campaign, $c);
        $this->assertSame('blocked', MarketingDelivery::first()->status);
    }

    public function test_timeout_or_worker_restart_never_retries_an_unknown_sms(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign(['message' => ['enabled' => true]]);
        $this->enableSms($campaign);
        $this->grant($campaign, $c);
        $this->sms->throws = true;
        $d = MarketingDelivery::where('kind', 'offer')->first();
        app(CampaignMessages::class)->dispatch($d);
        app(CampaignMessages::class)->dispatch($d->fresh());
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('unknown', $d->fresh()->status);
    }

    public function test_unknown_sms_tariff_is_not_zero_and_sending_is_blocked(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign(['message' => ['enabled' => true]]);
        $this->enableSms($campaign);
        config(['marketing.sms_segment_cost_irt' => null]);
        $this->grant($campaign, $c);
        $d = MarketingDelivery::first();
        app(CampaignMessages::class)->dispatch($d);
        $this->assertNull($d->fresh()->estimated_cost);
        $this->assertSame('unknown_tariff', $d->fresh()->reason);
        $this->assertCount(0, $this->sms->sent);
    }

    public function test_tracking_and_open_do_not_redeem_or_expose_another_customers_details(): void
    {
        $c = $this->customer();
        $g = $this->grant($this->campaign(), $c);
        $this->get('/m/'.$g->track_token)->assertRedirect();
        $this->assertDatabaseCount('marketing_redemptions', 0);
        $this->actingAs($c, 'customer')->get('/account/offers/'.$g->id.'/open')->assertRedirect('/account/cloud-store');
        $this->actingAs($c, 'customer')->get('/account/offers/'.$g->id.'/open')->assertRedirect();
        $this->assertDatabaseCount('marketing_events', 1);
        $this->assertDatabaseCount('marketing_redemptions', 0);
    }

    public function test_customer_and_author_cannot_publish_or_view_campaign_financials(): void
    {
        $c = $this->campaign();
        $author = User::factory()->create(['role' => 'author']);
        $this->actingAs($author, 'web')->post('/admin/marketing/campaigns/'.$c->id.'/transition',
            ['action' => 'activate', 'reason' => 'unauthorized'])->assertForbidden();
        $this->actingAs($author, 'web')->get('/admin/marketing/campaigns/'.$c->id.'/export')->assertForbidden();
    }

    public function test_retired_customer_page_redirects_in_all_three_languages_and_admin_preview_renders(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign();
        $this->grant($campaign, $c);
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->actingAs($c, 'customer')->get($prefix.'/account/offers')->assertRedirect($prefix.'/account/marketing/unsubscribe');
            $this->get($prefix.'/account/marketing/unsubscribe')->assertOk()->assertDontSee('birth_month', false)->assertDontSee('marketing.unsubscribe_title', false);
        }
        $this->actingAs($this->admin, 'web')->get('/admin/marketing/campaigns/'.$campaign->id)->assertOk();
        $this->actingAs($this->admin, 'web')->get('/admin/marketing/campaigns/create')->assertOk();
    }

    public function test_only_an_admin_enabled_customer_can_submit_a_campaign_code_on_an_invoice(): void
    {
        $c = $this->customer();
        $g = $this->grant($this->campaign(), $c);
        $invoice = $this->invoice($c);
        $this->actingAs($c, 'customer')->post('/account/invoices/'.$invoice->id.'/offer', ['code' => $g->code])->assertForbidden();
        $this->assertDatabaseCount('marketing_redemptions', 0);
        $c->forceFill(['discount_entry_visibility' => 'enabled'])->save();
        $this->post('/account/invoices/'.$invoice->id.'/offer', ['code' => $g->code])->assertRedirect();
        $this->assertDatabaseCount('marketing_redemptions', 1);
        $this->assertSame('reserved', MarketingRedemption::first()->status);
        $this->assertSame(0, $invoice->fresh()->paid);
    }

    public function test_discounted_invoice_can_use_existing_wallet_checkout(): void
    {
        $c = $this->customer();
        CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => 2_000_000,
            'balance_after' => 2_000_000, 'reason' => 'adjustment']);
        $i = $this->invoice($c);
        $this->reserve($i, $c, $this->grant($this->campaign(), $c));
        $this->actingAs($c, 'customer')->post('/account/invoices/'.$i->id.'/pay-credit')
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('paid', $i->fresh()->status);
        $this->assertSame(1_120_000, $c->creditBalance());
        $this->assertSame('committed', MarketingRedemption::first()->status);
    }

    public function test_late_payment_is_wallet_liability_until_manual_review_and_never_service_revenue(): void
    {
        $c = $this->customer();
        $i = $this->invoice($c);
        $this->reserve($i, $c, $this->grant($this->campaign(), $c));
        $this->travel(31)->minutes();
        $this->pay($i);
        $this->assertDatabaseCount('marketing_payment_reconciliations', 1);
        $this->assertSame(0, (int) BusinessEntry::where('kind', 'revenue')->sum('amount'));
        app(DiscountEngine::class)->releaseAfterReview(MarketingRedemption::first());
        $this->assertSame('released', MarketingRedemption::first()->status);
        $this->assertSame(1_100_000, $i->fresh()->total);
        $this->assertSame(880_000, $c->creditBalance());
    }

    public function test_support_permissions_do_not_grant_publication_or_financial_access_implicitly(): void
    {
        $campaign = $this->campaign();
        $support = User::factory()->create(['role' => 'support']);
        DB::table('marketing_permissions')->insert(['user_id' => $support->id, 'ability' => 'manage']);
        $this->actingAs($support, 'web')->get('/admin/marketing/campaigns')->assertOk();
        $this->actingAs($support, 'web')->post('/admin/marketing/campaigns/'.$campaign->id.'/transition',
            ['action' => 'activate', 'reason' => 'Not permitted'])->assertForbidden();
        $this->actingAs($support, 'web')->get('/admin/marketing/campaigns/'.$campaign->id.'/export')->assertForbidden();
    }

    public function test_unicode_and_gsm_sms_segments_use_real_encoding_lengths(): void
    {
        $messages = app(CampaignMessages::class);
        foreach ([[str_repeat('a', 160), 1], [str_repeat('a', 161), 2], [str_repeat('ش', 70), 1],
            [str_repeat('ش', 71), 2], [str_repeat('🎉', 35), 1], [str_repeat('🎉', 36), 2],
            [str_repeat('^', 80), 1], [str_repeat('^', 81), 2]] as [$body,$expected]) {
            $this->assertSame($expected, $messages->estimate($body)['segments']);
        }
    }

    public function test_personal_guarantees_cannot_be_oversubscribed_by_more_codes_or_public_checkout(): void
    {
        $c = $this->customer();
        $campaign = $this->campaign();
        $personal = $this->grant($campaign, $c);
        $this->rejected(fn () => $this->grant($campaign, $c, 'another-personal'), 'capacity');
        $public = $this->grant($campaign, null);
        $this->rejected(fn () => $this->reserve($this->invoice($c), $c, $public), 'capacity');
        $this->assertSame(200_000, $this->reserve($this->invoice($c), $c, $personal)->amount);
    }

    public function test_birthday_can_issue_and_redeem_again_in_next_annual_cycle(): void
    {
        $c = $this->customer('en');
        $c->defaultProfile()->update(['birth_date' => '2000-10-25']);
        $campaign = $this->campaign(['trigger' => 'birthday']);
        app(CampaignRunner::class)->issueDue($campaign, $c);
        $g = MarketingGrant::first();
        $i = $this->invoice($c);
        $this->reserve($i, $c, $g);
        $this->pay($i);
        $this->travelTo(Carbon::parse('2027-10-25 08:00:00', 'UTC'));
        $this->assertTrue(app(CampaignRunner::class)->issueDue($campaign->fresh(), $c));
        $next = MarketingGrant::latest('id')->first();
        $this->assertNotSame($g->id, $next->id);
        $this->assertSame(200_000, $this->reserve($this->invoice($c), $c, $next)->amount);
    }

    public function test_template_variables_and_external_redirects_are_rejected(): void
    {
        $this->rejected(fn () => app(CampaignManager::class)->save(
            $this->data(['message' => ['enabled' => true, 'template' => '{{password}}']]), $this->admin));
        $this->rejected(fn () => app(CampaignManager::class)->save(
            $this->data(['message' => ['landing_path' => '//evil.example/account/store']]), $this->admin));
    }
}

class CampaignTestSms implements SmsSender
{
    public array $sent = [];

    public bool $throws = false;

    public function enabled(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'ippanel';
    }

    public function send(string $mobile, string $text): bool
    {
        $this->sent[] = compact('mobile', 'text');
        if ($this->throws) {
            throw new \RuntimeException('Simulated unknown transport outcome');
        }

        return true;
    }

    public function sendOtp(string $mobile, string $code): bool
    {
        return true;
    }
}
