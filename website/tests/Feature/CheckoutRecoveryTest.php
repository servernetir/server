<?php

namespace Tests\Feature;

use App\Models\BankTransferReceipt;
use App\Models\CryptoPayment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\SnappPayOrder;
use App\Services\Marketing\CheckoutRecovery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CheckoutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Customer $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(9, 0));
        config(['billing.order_expiry_hours' => 24]);
        $this->owner = $this->customer();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Notification::fake();
    }

    private function customer(): Customer
    {
        return Customer::create(['code' => 'SN-'.random_int(100000, 999999), 'email' => bin2hex(random_bytes(6)).'@example.invalid', 'password' => bcrypt('synthetic-password'), 'status' => 'active']);
    }

    private function order(array $invoice = [], array $service = []): Invoice
    {
        $customer = $invoice['customer_id'] ?? $this->owner->id;
        $svc = Service::create($service + ['customer_id' => $customer, 'name' => 'Original Linux plan', 'currency_code' => $invoice['currency_code'] ?? 'IRT', 'price' => 120000, 'cycle' => 'monthly', 'status' => 'pending', 'provision_status' => 'none', 'password' => 'never-render-secret', 'provision_meta' => ['token' => 'never-render-private-token']]);
        $made = Invoice::create($invoice + ['customer_id' => $customer, 'service_id' => $svc->id, 'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 120000, 'total' => 132000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()->subHour(), 'due_at' => now()->addHour(), 'created_at' => now()->subHour()]);
        $made->forceFill(['created_at' => $invoice['created_at'] ?? now()->subHour()])->saveQuietly();

        return $made;
    }

    private function payment(Invoice $invoice, array $extra = []): Payment
    {
        return Payment::create($extra + ['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'gateway' => 'fixture', 'currency_code' => $invoice->currency_code, 'amount' => $invoice->total, 'status' => 'pending', 'external_ref' => 'never-render-private-reference-'.$invoice->id, 'error_message' => 'private provider error']);
    }

    private function rows()
    {
        return app(CheckoutRecovery::class)->forCustomer($this->owner->id);
    }

    public function test_only_owned_original_plan_and_invoice_currency_are_returned(): void
    {
        $good = $this->order(['currency_code' => 'EUR', 'total' => 1299], ['cycle' => 'yearly']);
        $this->order(['customer_id' => $this->customer()->id]);
        $this->order([], ['customer_id' => $this->customer()->id]);
        $this->assertSame([$good->id], $this->rows()->pluck('invoice_id')->all());
        $row = $this->rows()->first();
        $this->assertSame('EUR', $row['currency_code']);
        $this->assertSame(1299, $row['total']);
        $this->assertSame('Original Linux plan', $row['service_name']);
        $this->assertSame(Service::labelFor('yearly'), $row['cycle_label']);
        $this->assertStringNotContainsString('never-render', $this->rows()->toJson());
    }

    public function test_renewals_admin_created_cancelled_or_activated_services_are_excluded(): void
    {
        foreach (['active', 'suspended', 'cancelled', 'expired', 'awaiting_provision'] as $status) {
            $this->order([], ['status' => $status]);
        }
        $this->order([], ['activated_at' => now()->subYear()]);
        $this->order([], ['cancelled_at' => now()->subMinute()]);
        $this->order([], ['provision_status' => 'creating']);
        DB::table('services')->where('id', $this->order()->service_id)->update(['created_by' => 123]);
        $this->assertCount(0, $this->rows());
    }

    public function test_only_unpaid_positive_service_orders_without_any_amount_paid_are_eligible(): void
    {
        foreach (['paid', 'partial', 'canceled', 'draft', 'void', 'overdue'] as $status) {
            $this->order(['status' => $status]);
        }
        $this->order(['total' => 0]);
        $this->order(['paid' => 1]);
        $this->order(['kind' => 'topup']);
        $this->order(['kind' => 'domain']);
        $this->assertCount(0, $this->rows());
    }

    public function test_minimum_age_due_date_and_live_config_expiry_are_respected(): void
    {
        $this->order(['created_at' => now()->subMinutes(14)]);
        $this->order(['due_at' => now()]);
        $this->order(['created_at' => now()->subHours(24)]);
        $this->order(['created_at' => now()->subHours(25)]);
        $good = $this->order(['created_at' => now()->subMinutes(15)]);
        $this->assertSame([$good->id], $this->rows()->pluck('invoice_id')->all());
        config(['billing.order_expiry_hours' => 1]);
        $this->assertCount(1, $this->rows());
        $this->travel(46)->minutes();
        $this->assertCount(0, $this->rows());
    }

    public function test_paid_sibling_blocks_even_if_current_invoice_is_unpaid(): void
    {
        $invoice = $this->order();
        Invoice::create(['customer_id' => $this->owner->id, 'service_id' => $invoice->service_id, 'kind' => 'service', 'total' => 1, 'paid' => 1, 'status' => 'paid', 'currency_code' => 'IRT']);
        $this->assertCount(0, $this->rows());
    }

    public function test_unknown_pending_redirected_and_paid_attempts_block_even_after_expiry(): void
    {
        foreach (['pending', 'redirected', 'paid', 'manual', 'unknown'] as $status) {
            $this->payment($this->order(), ['status' => $status, 'expires_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        }
        $this->assertCount(0, $this->rows());
        $good = $this->order();
        $this->payment($good, ['status' => 'failed']);
        $this->assertSame([$good->id], $this->rows()->pluck('invoice_id')->all());
        $this->assertTrue($this->rows()->first()['failed_payment']);
        $this->assertStringNotContainsString('private provider error', $this->rows()->toJson());
    }

    public function test_pending_or_approved_bank_receipts_block_duplicate_payment_navigation(): void
    {
        foreach (['pending', 'approved'] as $status) {
            $invoice = $this->order();
            BankTransferReceipt::create(['customer_id' => $this->owner->id, 'invoice_id' => $invoice->id, 'amount' => $invoice->total, 'reference' => 'synthetic', 'status' => $status]);
        }
        $this->assertCount(0, $this->rows());
    }

    public function test_active_uncertain_or_received_crypto_blocks_but_empty_resolved_expiry_does_not(): void
    {
        $allowed = null;
        foreach ([['pending', 0], ['seen', 0], ['manual', 0], ['expired', 1], ['expired', 0]] as [$status,$received]) {
            $invoice = $this->order();
            CryptoPayment::create(['invoice_id' => $invoice->id, 'customer_id' => $this->owner->id, 'chain' => 'tron', 'asset' => 'USDT', 'network' => 'TRC20', 'address' => 'synthetic-address', 'amount_atomic' => 1000000, 'invoice_amount' => $invoice->total, 'invoice_currency' => 'IRT', 'rate_micro' => 1, 'received_atomic' => $received, 'status' => $status, 'expires_at' => now()->subHour()]);
            if ($status === 'expired' && $received === 0) {
                $allowed = $invoice->id;
            }
        }
        $this->assertSame([$allowed], $this->rows()->pluck('invoice_id')->all());
    }

    public function test_unsettled_snapp_order_blocks_despite_failed_generic_payment(): void
    {
        $invoice = $this->order();
        $payment = $this->payment($invoice, ['status' => 'failed']);
        SnappPayOrder::create(['invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'customer_id' => $this->owner->id, 'transaction_id' => 'QA123456', 'state' => 'verified', 'amount' => $invoice->total]);
        $this->assertCount(0, $this->rows());
    }

    public function test_marketing_payment_reconciliation_is_not_released_or_reused(): void
    {
        $invoice = $this->order();
        $payment = $this->payment($invoice, ['status' => 'failed']);
        DB::table('marketing_payment_reconciliations')->insert(['invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'credited_amount' => 1, 'currency_code' => 'IRT', 'created_at' => now()]);
        $this->assertCount(0, $this->rows());
        $this->assertDatabaseCount('marketing_payment_reconciliations', 1);
    }

    public function test_selection_is_bounded_and_read_only_with_no_outbound_calls(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->order();
        }
        $writes = [];
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$writes, &$queries) {
            $queries[] = $query->sql;
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $one = $this->rows();
        $this->assertCount(3, $one);
        $this->assertSame($one->pluck('invoice_id')->all(), $this->rows()->pluck('invoice_id')->all());
        $this->assertSame([], $writes);
        $this->assertLessThan(50, count($queries));
        $this->assertDatabaseCount('invoices', 6);
        $this->assertDatabaseCount('services', 6);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_panel_renders_safe_invoice_links_in_three_languages(): void
    {
        $invoice = $this->order([], ['name' => '<script>do_not_execute()</script>']);
        $this->payment($invoice, ['status' => 'failed']);
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $html = view('account.partials.checkout-recovery', ['checkoutRecovery' => $this->rows()])->render();
            $this->assertStringContainsString(e('<script>do_not_execute()</script>'), $html);
            $this->assertStringNotContainsString('<script>do_not_execute()</script>', $html);
            $this->assertStringContainsString('/invoices/'.$invoice->id, $html);
            $this->assertStringContainsString((string) __('conversion.checkout_recovery.resume'), $html);
            $this->assertStringNotContainsString('private provider error', $html);
            $this->assertStringNotContainsString('never-render', $html);
        }
    }

    public function test_latest_open_invoice_per_service_prevents_duplicate_resume_cards(): void
    {
        $older = $this->order();
        $newer = Invoice::create(['customer_id' => $this->owner->id, 'service_id' => $older->service_id, 'kind' => 'service', 'total' => 132000, 'paid' => 0, 'status' => 'unpaid', 'currency_code' => 'IRT', 'due_at' => now()->addHour()]);
        $newer->forceFill(['created_at' => now()->subMinutes(30)])->saveQuietly();
        $this->assertSame([$newer->id], $this->rows()->pluck('invoice_id')->all());
    }

    public function test_currency_mismatch_and_internal_infrastructure_do_not_become_sales_cards(): void
    {
        $this->order(['currency_code' => 'EUR'], ['currency_code' => 'IRT']);
        $internal = $this->order();
        config(['billing.internal_service_ids' => [$internal->service_id]]);
        $this->assertCount(0, $this->rows());
    }

    public function test_discount_commitments_or_expired_reservations_are_not_reused(): void
    {
        $campaign = DB::table('marketing_campaigns')->insertGetId(['name' => 'Synthetic only', 'currency_code' => 'IRT', 'rule' => '{}', 'audience' => '{}', 'automation' => '{}', 'message' => '{}', 'budget' => 1000, 'max_uses' => 10]);
        $allowed = [];
        foreach ([['reconciliation', false], ['committed', false], ['reserved', true], ['released', true], ['reserved', false]] as $index => [$status,$expired]) {
            $invoice = $this->order();
            $grant = DB::table('marketing_grants')->insertGetId(['campaign_id' => $campaign, 'customer_id' => $this->owner->id, 'code' => 'QA-'.$index, 'issue_key' => 'fixture-'.$index, 'track_token' => 'token-'.$index, 'snapshot' => '{}', 'liability' => 100, 'starts_at' => now()->subHour(), 'expires_at' => now()->addHour()]);
            DB::table('marketing_redemptions')->insert(['campaign_id' => $campaign, 'grant_id' => $grant, 'customer_id' => $this->owner->id, 'invoice_id' => $invoice->id, 'status' => $status, 'amount' => 100, 'snapshot' => '{}', 'expires_at' => $expired ? now()->subMinute() : now()->addHour()]);
            if ($status === 'released' || ($status === 'reserved' && ! $expired)) {
                $allowed[] = $invoice->id;
            }
        }
        $this->assertSame(array_reverse($allowed), $this->rows()->pluck('invoice_id')->all());
        $this->assertDatabaseCount('marketing_redemptions', 5);
    }

    public function test_real_account_dashboard_shows_only_owned_resume_orders_without_duplicate_todo_in_three_languages(): void
    {
        $invoice = $this->order([], ['name' => 'My recoverable original plan']);
        $foreign = $this->order(['customer_id' => $this->customer()->id], ['name' => 'Private foreign plan']);
        $renewal = $this->order([], ['name' => 'My live service', 'status' => 'active', 'activated_at' => now()->subYear()]);
        foreach (['fa', 'en', 'tr'] as $locale) {
            $this->owner->update(['locale' => $locale]);
            $path = ($locale === 'fa' ? '' : '/'.$locale).'/account';
            $response = $this->actingAs($this->owner, 'customer')->get($path)->assertOk();
            $response->assertSee('My recoverable original plan')->assertDontSee('Private foreign plan');
            $this->assertSame([$invoice->id], $response->viewData('checkoutRecovery')->pluck('invoice_id')->all());
            $this->assertStringNotContainsString($foreign->number, $response->getContent());
            $url = lroute('account.invoice', $invoice->id);
            $renewalUrl = lroute('account.invoice', $renewal->id);
            $todo = collect($response->viewData('todo'));
            $this->assertFalse($todo->contains('url', $url));
            $this->assertTrue($todo->contains('url', $renewalUrl));
            $this->assertStringContainsString((string) __('conversion.checkout_recovery.title'), $response->getContent());
            $this->assertStringNotContainsString('conversion.checkout_recovery', $response->getContent());
            Http::assertNothingSent();
            Mail::assertNothingSent();
        }
    }

    public function test_absent_required_schema_or_invalid_customer_fails_closed(): void
    {
        $this->order();
        $this->assertCount(0, app(CheckoutRecovery::class)->forCustomer(0));
        $this->assertCount(0, app(CheckoutRecovery::class)->forCustomer(-1));
        Schema::drop('payments');
        $this->assertCount(0, $this->rows());
    }
}
