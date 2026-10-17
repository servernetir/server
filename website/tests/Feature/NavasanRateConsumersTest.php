<?php

namespace Tests\Feature;

use App\Models\CryptoWallet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Domain\DomainSearch;
use App\Services\Domain\TldPriceBook;
use App\Services\Payment\CryptoIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class NavasanRateConsumersTest extends TestCase
{
    use RefreshDatabase;

    public function test_tether_payment_uses_its_own_rate_and_keeps_an_existing_quote_after_refresh(): void
    {
        Http::preventStrayRequests();
        NavasanRates::store(['USD' => 200000, 'EUR' => 300000, 'USDT' => 100000]);
        $customer = Customer::create(['code' => 'SN-900001', 'email' => 'navasan@example.test',
            'password' => bcrypt('test-password'), 'status' => 'active']);
        $invoice = Invoice::create(['customer_id' => $customer->id, 'number' => 'INV-NAVASAN',
            'currency_code' => 'IRT', 'subtotal' => 1000000, 'tax' => 0, 'total' => 1000000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(), 'due_at' => now()->addDays(7)]);
        CryptoWallet::create(['chain' => 'tron', 'address' => 'T'.str_repeat('A', 33), 'is_active' => true]);
        $payment = app(CryptoIssuer::class)->issue($invoice, 'USDT');
        $this->assertNotNull($payment);
        $this->assertSame(100000 * 1000000, (int) $payment->rate_micro);
        $this->assertGreaterThanOrEqual(10000000, (int) $payment->amount_atomic);
        $this->assertLessThan(10100000, (int) $payment->amount_atomic);
        NavasanRates::store(['USD' => 250000, 'EUR' => 350000, 'USDT' => 120000]);
        $again = app(CryptoIssuer::class)->issue($invoice, 'USDT');
        $this->assertSame($payment->id, $again->id);
        $this->assertSame($payment->amount_atomic, $again->amount_atomic);
        $this->assertSame(1000000, (int) $invoice->fresh()->total);
        Http::assertNothingSent();
    }

    public function test_domain_cache_is_invalidated_by_a_changed_exchange_snapshot(): void
    {
        Http::preventStrayRequests();
        $search = $this->mock(DomainSearch::class);
        $search->shouldReceive('search')->once()->andReturn([
            ['tld' => 'com', 'orderable' => true, 'is_premium' => false, 'price_toman' => 1000000],
        ]);
        NavasanRates::store();
        $book = app(TldPriceBook::class);
        $this->assertSame(['com' => 1000000], $book->forTlds(['com']));
        $this->assertSame(['com' => 1000000], $book->cachedForTlds(['com']));
        NavasanRates::store(['USD' => 110000, 'EUR' => 220000, 'USDT' => 160000]);
        $this->assertSame([], $book->cachedForTlds(['com']));
        Http::assertNothingSent();
    }
}
