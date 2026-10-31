<?php

namespace Tests\Feature;

use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\ExchangeRate;
use App\Services\Payment\BscWatcher;
use App\Services\Payment\CryptoIssuer;
use App\Services\Payment\CryptoReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CryptoBep20Test extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = '0x1234567890abcdef1234567890abcdef12345678';

    private const TX = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const HASH = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private function invoice(): Invoice
    {
        $customer = Customer::create(['code' => 'SN-'.random_int(100000, 999999), 'email' => uniqid().'@example.test',
            'password' => bcrypt('test-only'), 'status' => 'active']);

        return Invoice::create(['customer_id' => $customer->id, 'number' => uniqid('INV-'), 'currency_code' => 'IRT',
            'subtotal' => 1000000, 'tax' => 0, 'total' => 1000000, 'paid' => 0, 'status' => 'unpaid',
            'issued_at' => now(), 'due_at' => now()->addDay()]);
    }

    private function rates(): void
    {
        $this->mock(ExchangeRate::class, function ($mock) {
            $mock->shouldReceive('toToman')->with('USDT')->andReturn(100000.0);
            $mock->shouldReceive('current')->with('USDT')->andReturn(['rate_toman' => 100000.0]);
        });
    }

    private function rpc(array $overrides = [], bool $failed = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([BscWatcher::RPC => function ($request) use ($overrides, $failed) {
            $method = $request['method'];
            $block = ['number' => '0x64', 'timestamp' => '0x'.dechex(time()), 'hash' => self::HASH];
            $log = array_replace(['address' => BscWatcher::USDT_CONTRACT, 'removed' => false,
                'topics' => [BscWatcher::TRANSFER_TOPIC, '0x'.str_repeat('0', 64), '0x'.str_pad(substr(self::ADDRESS, 2), 64, '0', STR_PAD_LEFT)],
                'blockNumber' => '0x64', 'transactionHash' => self::TX, 'blockHash' => self::HASH,
                'logIndex' => '0x0', 'data' => '0x'.str_pad('8ac7230489e80000', 64, '0', STR_PAD_LEFT)], $overrides);
            $result = match ($method) {
                'eth_chainId' => $overrides['_chain'] ?? '0x38',
                'eth_call' => '0x'.str_pad('12', 64, '0', STR_PAD_LEFT),
                'eth_getBlockByNumber' => $request['params'][0] === 'finalized' ? $block : array_replace($block,
                    ['number' => $request['params'][0], 'timestamp' => '0x'.dechex(time() - (100 - hexdec($request['params'][0])) * 30)]),
                'eth_getLogs' => [$log],
                'eth_getTransactionReceipt' => ['status' => $failed ? '0x0' : '0x1', 'transactionHash' => self::TX,
                    'blockNumber' => '0x64', 'blockHash' => self::HASH, 'logs' => [$log]],
                default => throw new \RuntimeException($method),
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        }]);
    }

    public function test_uint256_conversion_is_exact_without_overflow_or_rounding_up(): void
    {
        $this->assertSame(10000000, BscWatcher::microAmount('0x'.str_pad('8ac7230489e80000', 64, '0', STR_PAD_LEFT)));
        $this->assertSame(10000000, BscWatcher::microAmount('0x'.str_pad('8ac7230489e80001', 64, '0', STR_PAD_LEFT)));
        $this->assertNull(BscWatcher::microAmount('0x'.str_repeat('f', 64)));
        $this->assertNull(BscWatcher::microAmount('1e19'));
        $this->assertSame(0, BscWatcher::microAmount('0x'.str_repeat('0', 64)));
    }

    public function test_five_wallets_are_reserved_individually_and_sixth_is_unavailable(): void
    {
        $this->rates();
        for ($i = 1; $i <= 5; $i++) {
            CryptoWallet::create(['chain' => 'bsc', 'address' => '0x'.str_pad(dechex($i), 40, '0', STR_PAD_LEFT), 'is_active' => true]);
        }
        $issuer = app(CryptoIssuer::class);
        $wallets = [];
        for ($i = 0; $i < 5; $i++) {
            $invoice = $this->invoice();
            $cp = $issuer->issue($invoice, 'USDT_BEP20');
            $this->assertNotNull($cp);
            $this->assertSame('USDT', $cp->asset);
            $this->assertSame('BEP20', $cp->network);
            $this->assertSame(6, $cp->decimals);
            $this->assertSame($cp->id, $issuer->issue($invoice, 'USDT_BEP20')->id);
            $wallets[] = $cp->crypto_wallet_id;
            $this->assertArrayHasKey('USDT_BEP20', $issuer->checkout($invoice, $cp));
        }
        $this->assertCount(5, array_unique($wallets));
        $this->assertNull($issuer->issue($this->invoice(), 'USDT_BEP20'));
    }

    public function test_networks_do_not_reuse_each_others_open_payment(): void
    {
        $this->rates();
        CryptoWallet::create(['chain' => 'bsc', 'address' => self::ADDRESS, 'is_active' => true]);
        CryptoWallet::create(['chain' => 'tron', 'address' => 'TTestWallet', 'is_active' => true]);
        $invoice = $this->invoice();
        $issuer = app(CryptoIssuer::class);
        $bsc = $issuer->issue($invoice, 'USDT_BEP20');
        $tron = $issuer->issue($invoice, 'USDT');
        $this->assertNotSame($bsc->id, $tron->id);
        $this->assertSame('tron', $tron->chain);
        $this->assertSame($bsc->id, $issuer->issue($invoice, 'USDT_BEP20')->id);
    }

    public function test_confirmed_bep20_deposit_settles_once_and_cools_wallet(): void
    {
        $this->rates();
        $this->rpc();
        CryptoWallet::create(['chain' => 'bsc', 'address' => self::ADDRESS, 'is_active' => true]);
        $invoice = $this->invoice();
        $cp = app(CryptoIssuer::class)->issue($invoice, 'USDT_BEP20');
        $rec = app(CryptoReconciler::class);
        $rec->sweep();
        $rec->sweep();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('confirmed', $cp->fresh()->status);
        $this->assertSame(10000000, $cp->fresh()->received_atomic);
        $this->assertNull($cp->wallet->fresh()->busy_payment_id);
        $this->assertTrue($cp->wallet->fresh()->cooldown_until->isFuture());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_fake_contract_and_removed_logs_never_credit(): void
    {
        foreach ([['address' => '0x'.str_repeat('1', 40)], ['removed' => true], ['topics' => []]] as $overrides) {
            $this->rpc($overrides);
            $this->assertSame([], app(BscWatcher::class)->deposits(self::ADDRESS, 'USDT', time() - 120));
        }
    }

    public function test_failed_transaction_does_not_credit(): void
    {
        $this->rpc([], true);
        $this->assertSame([], app(BscWatcher::class)->deposits(self::ADDRESS, 'USDT', time() - 120));
    }

    public function test_rpc_failure_keeps_expired_wallet_reserved_for_reconciliation(): void
    {
        $this->rates();
        CryptoWallet::create(['chain' => 'bsc', 'address' => self::ADDRESS, 'is_active' => true]);
        $cp = app(CryptoIssuer::class)->issue($this->invoice(), 'USDT_BEP20');
        $cp->update(['expires_at' => now()->subMinute()]);
        Http::swap(new Factory);
        Http::fake([BscWatcher::RPC => Http::response(['error' => ['code' => -32000]])]);
        $stat = app(CryptoReconciler::class)->sweep();
        $this->assertSame(1, $stat['errors']);
        $this->assertSame('pending', $cp->fresh()->status);
        $this->assertSame($cp->id, $cp->wallet->fresh()->busy_payment_id);
    }

    public function test_customer_checkout_preserves_bep20_in_all_three_languages(): void
    {
        $this->rates();
        CryptoWallet::create(['chain' => 'bsc', 'address' => self::ADDRESS, 'is_active' => true]);
        $invoice = $this->invoice();
        $customer = $invoice->customer;
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            app()->setLocale($locale);
            $this->actingAs($customer, 'customer')->get($prefix.'/account/invoices/'.$invoice->id)
                ->assertOk()->assertSee('USDT_BEP20', false)->assertSee('BNB Smart Chain')
                ->assertSee(__('ui.cy_recommended'));
        }
        $this->actingAs($customer, 'customer')->post('/account/invoices/'.$invoice->id.'/crypto', ['asset' => 'USDT_BEP20'])->assertRedirect();
        $cp = CryptoPayment::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('bsc', $cp->chain);
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/account/invoices/'.$invoice->id)->assertOk()
                ->assertSee(self::ADDRESS, false)->assertSee('value="USDT_BEP20"', false)->assertSee('BEP20');
        }
    }

    public function test_unfinalized_block_is_not_credited(): void
    {
        $this->rpc(['blockNumber' => '0x65']);
        $this->assertSame([], app(BscWatcher::class)->deposits(self::ADDRESS, 'USDT', time() - 120));
    }

    public function test_wrong_destination_is_not_credited(): void
    {
        $this->rpc(['topics' => [BscWatcher::TRANSFER_TOPIC, '0x'.str_repeat('0', 64), '0x'.str_repeat('1', 64)]]);
        $this->assertSame([], app(BscWatcher::class)->deposits(self::ADDRESS, 'USDT', time() - 120));
    }

    public function test_wallet_admin_import_is_case_insensitive_and_rejects_wrong_networks(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/crypto-wallets', ['chain' => 'bsc', 'addresses' => self::ADDRESS."\n".'0x'.strtoupper(substr(self::ADDRESS, 2))."\ninvalid"])->assertRedirect();
        $this->assertSame(1, CryptoWallet::where('chain', 'bsc')->count());
        $this->post('/admin/crypto-wallets', ['chain' => 'bsc', 'addresses' => self::ADDRESS])->assertRedirect();
        $this->assertSame(1, CryptoWallet::where('chain', 'bsc')->count());
        $this->post('/admin/crypto-wallets', ['chain' => 'unknown', 'addresses' => self::ADDRESS])->assertSessionHasErrors('chain');
        $support = User::factory()->create(['role' => 'support']);
        $this->actingAs($support)->post('/admin/crypto-wallets', ['chain' => 'bsc', 'addresses' => self::ADDRESS])->assertForbidden();
    }

    public function test_wrong_chain_rpc_fails_closed(): void
    {
        $this->rpc(['_chain' => '0x1']);
        $this->expectException(\RuntimeException::class);
        app(BscWatcher::class)->deposits(self::ADDRESS,'USDT',time() - 120);
    }
}
