<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * صفحهٔ «تراکنش‌ها و اعتبار» — آمارِ اعتبارِ مشتریان + دفترِ ریز.
 */
class AdminTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 's'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    public function test_transactions_page_shows_customer_credit_and_ledger(): void
    {
        $c = Customer::create([
            'email' => 'a@x.com', 'phone' => '09120000000',
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);
        $inv = Invoice::create([
            'customer_id' => $c->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 100000, 'tax' => 0, 'total' => 100000, 'paid' => 100000,
            'status' => 'paid', 'issued_at' => now(), 'paid_at' => now(),
        ]);
        CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => 100000, 'balance_after' => 100000, 'reason' => 'topup', 'source_type' => Invoice::class, 'source_id' => $inv->id]);
        CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => -30000, 'balance_after' => 70000, 'reason' => 'invoice']);
        Payment::create(['customer_id' => $c->id, 'invoice_id' => $inv->id, 'gateway' => 'zarinpal', 'currency_code' => 'IRT', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);

        $res = $this->actingAs($this->staff(), 'web')->get('/admin/transactions');

        $res->assertOk();
        $res->assertSee('اعتبار کل مشتریان');
        $res->assertSee('دفترِ اعتبار', false);
        // موجودیِ خالصِ مشتری = ۱۰۰٬۰۰۰ − ۳۰٬۰۰۰ = ۷۰٬۰۰۰ (با ارقام فارسی)
        $res->assertSee(fa_num('70,000'));
    }

    public function test_transactions_page_ok_when_empty(): void
    {
        $this->actingAs($this->staff(), 'web')->get('/admin/transactions')
            ->assertOk()
            ->assertSee('اعتبار کل مشتریان');
    }
}
