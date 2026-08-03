<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TmpDomainBlockProbeTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        $c = Customer::create([
            'email' => 'd'.random_int(1000, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'verified', 'email' => $c->email, 'mobile' => '09123456789',
            'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
            'postal_code' => '1234567890', 'first_name' => 'احسان', 'last_name' => 'ابراهیمی',
        ]);

        return $c;
    }

    private function quote(): DomainQuote
    {
        return DomainQuote::create([
            'domain' => 'example.com', 'tld' => 'com', 'registrar' => 'openprovider',
            'is_premium' => false, 'cost_amount' => 950, 'cost_currency' => 'EUR',
            'sell_toman' => 2500000, 'renew_toman' => 2500000,
            'honour_until' => now()->addMinutes(15),
        ]);
    }

    private function order(Customer $c, DomainQuote $q)
    {
        return $this->actingAs($c, 'customer')
            ->post(route('account.domains.order'), ['quote_id' => $q->id]);
    }

    public function test_probe_abandoned_then_reorder_same_customer(): void
    {
        $a = $this->customer();
        $this->order($a, $this->quote())->assertRedirect();

        $this->assertSame(1, Domain::count());
        $this->assertSame('pending', Domain::first()->status);

        // A never pays. A tries again with a fresh quote.
        $r = $this->order($a, $this->quote());

        dump([
            'case'            => 'same customer re-order, never paid',
            'session_errors'  => session('errors') ? session('errors')->all() : [],
            'invoice_count'   => Invoice::count(),
            'domain_status'   => Domain::first()->status,
        ]);
    }

    public function test_probe_cancel_invoice_then_reorder(): void
    {
        $a = $this->customer();
        $this->order($a, $this->quote())->assertRedirect();

        $inv = Invoice::first();

        $this->actingAs($a, 'customer')
            ->post(route('account.invoice.cancel', $inv))
            ->assertRedirect();

        $d = Domain::first()->fresh();

        dump([
            'case'                  => 'after customer cancels the invoice',
            'invoice_status'        => $inv->fresh()->status,
            'domain_status'         => $d->status,
            'domain_isDead'         => $d->isDead(),
            'domain_row_still_here' => Domain::count(),
        ]);

        // now re-order the same name
        $this->order($a, $this->quote());

        dump([
            'case'           => 'A re-orders after cancelling',
            'session_errors' => session('errors') ? session('errors')->all() : [],
            'invoice_count'  => Invoice::count(),
        ]);

        // and a completely different customer tries the same name
        $b = $this->customer();
        $this->order($b, $this->quote());

        dump([
            'case'           => 'customer B orders the same name',
            'session_errors' => session('errors') ? session('errors')->all() : [],
            'invoice_count'  => Invoice::count(),
            'owner_id'       => Domain::first()->customer_id,
            'a_id'           => $a->id,
            'b_id'           => $b->id,
        ]);
    }
}
