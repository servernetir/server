<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainSupplierAccount;
use App\Models\Invoice;
use App\Services\Finance\BusinessLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainSupplierWholesaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function domain(): Domain
    {
        $customer = Customer::create(['email' => uniqid('wholesale-').'@example.test',
            'password' => bcrypt('synthetic'), 'status' => 'active']);
        $account = DomainSupplierAccount::create(['provider' => 'joker', 'environment' => 'production',
            'label' => 'Synthetic wholesale', 'active' => true]);
        return Domain::create(['customer_id' => $customer->id, 'domain' => 'wholesale-synthetic.com',
            'sld' => 'wholesale-synthetic', 'tld' => 'com', 'registrar' => 'joker',
            'supplier_account_id' => $account->id, 'supplier_object_id' => 'wholesale-synthetic.com',
            'status' => 'active', 'provision_status' => 'done', 'period_years' => 2,
            'cost_amount' => 90000, 'cost_currency' => 'EUR', 'meta' => ['live_price' => ['wholesale_toman' => 900000000]]]);
    }

    private function invoice(Domain $domain, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'domain_id' => $domain->id, 'customer_id' => $domain->customer_id,
            'kind' => 'domain', 'currency_code' => 'IRT', 'status' => 'paid',
            'subtotal' => 4000000, 'total' => 4000000, 'paid' => 4000000,
            'issued_at' => now(), 'paid_at' => now(), 'supplier_account_id' => $domain->supplier_account_id,
            'supplier_quote' => ['supplier_account_id' => $domain->supplier_account_id,
                'operation' => 'create', 'period_months' => 24, 'amount_minor' => 3000,
                'currency' => 'EUR', 'fx_snapshot' => ['numerator' => 200000, 'denominator' => 100],
                'purchase_evidence' => ['cost_status' => 'provider_reported', 'purchase_minor' => 1850, 'currency' => 'EUR']],
        ], $overrides));
    }

    public function test_expense_uses_reported_purchase_and_frozen_invoice_fx_instead_of_mutable_domain_quote(): void
    {
        $domain = $this->domain();
        $invoice = $this->invoice($domain);
        $domain->forceFill(['supplier_quote' => ['amount_minor' => 999999,
            'fx_snapshot' => ['numerator' => 500000, 'denominator' => 100]]])->save();
        app(BusinessLedger::class)->recordDomainWholesale($domain, 'register', 2, $invoice);
        $expense = BusinessEntry::where('category', 'domain_wholesale')->firstOrFail();
        $this->assertSame(3700000, $expense->amount);
        $this->assertSame(Invoice::class, $expense->source_type);
        $this->assertSame($invoice->id, $expense->source_id);
        Http::assertNothingSent();
    }

    public function test_spending_ceiling_without_provider_debit_evidence_cannot_become_an_expense(): void
    {
        $domain = $this->domain();
        $invoice = $this->invoice($domain);
        $snapshot = $invoice->supplier_quote;
        unset($snapshot['purchase_evidence']);
        $invoice->forceFill(['supplier_quote' => $snapshot])->save();
        app(BusinessLedger::class)->recordDomainWholesale($domain, 'register', 2, $invoice);
        $this->assertDatabaseCount('business_ledger', 0);
        Http::assertNothingSent();
    }

    public function test_foreign_domain_or_customer_invoice_cannot_post_an_expense(): void
    {
        $domain = $this->domain();
        $otherCustomer = Customer::create(['email' => 'other-wholesale@example.test', 'password' => bcrypt('synthetic')]);
        $foreign = $this->invoice($domain, ['customer_id' => $otherCustomer->id]);
        app(BusinessLedger::class)->recordDomainWholesale($domain, 'register', 2, $foreign);
        $wrongDomain = $this->invoice($domain, ['domain_id' => null]);
        app(BusinessLedger::class)->recordDomainWholesale($domain, 'register', 2, $wrongDomain);
        $this->assertDatabaseCount('business_ledger', 0);
        Http::assertNothingSent();
    }

    public function test_duplicate_completion_cannot_post_the_same_supplier_purchase_twice(): void
    {
        $domain = $this->domain();
        $invoice = $this->invoice($domain);
        $ledger = app(BusinessLedger::class);
        $ledger->recordDomainWholesale($domain, 'register', 2, $invoice);
        $ledger->recordDomainWholesale($domain, 'register', 2, $invoice);
        $this->assertSame(1, BusinessEntry::where('category', 'domain_wholesale')->count());
        $this->assertSame(3700000, (int) BusinessEntry::where('category', 'domain_wholesale')->sum('amount'));
        Http::assertNothingSent();
    }

    public function test_missing_invoice_snapshot_on_a_new_provider_never_falls_back_to_mutable_legacy_cost(): void
    {
        $domain = $this->domain();
        $invoice = $this->invoice($domain, ['supplier_account_id' => null, 'supplier_quote' => null]);
        app(BusinessLedger::class)->recordDomainWholesale($domain, 'register', 2, $invoice);
        $this->assertDatabaseCount('business_ledger', 0);
        Http::assertNothingSent();
    }

    public function test_invoice_and_domain_supplier_identity_must_match_before_posting(): void
    {
        $domain = $this->domain();
        $other = DomainSupplierAccount::create(['provider' => 'joker', 'environment' => 'production',
            'label' => 'Different synthetic account', 'active' => true]);
        $invoice = $this->invoice($domain, ['supplier_account_id' => $other->id]);
        app(BusinessLedger::class)->recordDomainWholesale($domain, 'register', 2, $invoice);
        $this->assertDatabaseCount('business_ledger', 0);
        Http::assertNothingSent();
    }
}
