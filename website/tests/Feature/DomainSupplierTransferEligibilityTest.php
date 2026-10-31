<?php

namespace Tests\Feature;

use App\Models\{Customer, Domain, DomainQuote, DomainSupplierAccount};
use App\Services\Domain\{DomainProcurement, DomainRegistrar, DomainTransfer, OpenProviderClient};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DomainSupplierTransferEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private DomainProcurement $pricing;
    private DomainTransfer $transfer;
    private DomainSupplierAccount $destination;

    protected function setUp(): void
    {
        parent::setUp();
        config(['domain_suppliers.enabled' => true]);
        $this->destination = DomainSupplierAccount::create(['provider' => 'joker', 'environment' => 'production', 'label' => 'Explicit destination']);
        config(['domain_suppliers.transfer_account_id' => $this->destination->id]);
        $this->pricing = Mockery::mock(DomainProcurement::class);
        $this->pricing->shouldReceive('enabled')->andReturn(true);
        app()->instance(DomainProcurement::class, $this->pricing);
        $op = Mockery::mock(OpenProviderClient::class);
        $op->shouldNotReceive('check');
        $this->transfer = new DomainTransfer($op, new DomainRegistrar($op));
    }

    public function test_incoming_transfer_uses_explicit_destination_instead_of_global_openprovider(): void
    {
        $quote = new DomainQuote(['domain' => 'external.example', 'operation' => 'transfer']);
        $quote->id = 123;
        $this->pricing->shouldReceive('quoteTransfer')->once()->withArgs(fn ($name, $months, $account) => $name === 'external.example'
            && $months === 12 && $account->id === $this->destination->id)->andReturn($quote);
        $result = $this->transfer->eligibility('external', 'example');
        $this->assertTrue($result['ok']);
        $this->assertSame(123, $result['quote_id']);
    }

    public function test_no_configured_destination_is_an_actionable_unavailable_state(): void
    {
        config(['domain_suppliers.transfer_account_id' => null]);
        $this->pricing->shouldNotReceive('quoteTransfer');
        $this->assertSame('transfer_destination_required', $this->transfer->eligibility('external', 'example')['reason']);
    }

    public function test_existing_local_domain_never_becomes_an_automatic_incoming_transfer(): void
    {
        $customer = Customer::create(['email' => 'local@example.test', 'phone' => '09123456789', 'password' => bcrypt('x'), 'status' => 'active']);
        Domain::create(['customer_id' => $customer->id, 'domain' => 'external.example', 'sld' => 'external', 'tld' => 'example', 'registrar' => 'openprovider', 'status' => 'active']);
        $this->pricing->shouldNotReceive('quoteTransfer');
        $this->assertSame('already_ours', $this->transfer->eligibility('external', 'example')['reason']);
    }

    public function test_transfer_quote_error_does_not_claim_domain_is_free_or_transferable(): void
    {
        $this->pricing->shouldReceive('quoteTransfer')->once()->andThrow(new \RuntimeException('private-provider-data'));
        $result = $this->transfer->eligibility('external', 'example');
        $this->assertFalse($result['ok']);
        $this->assertSame('transfer_eligibility_unconfirmed', $result['reason']);
        $this->assertStringNotContainsString('private-provider-data', $result['message']);
    }
}
