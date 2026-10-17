<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\IdentityApplication;
use App\Models\IdentityPrice;
use App\Models\IdentityRequest;
use App\Models\IdentityVerification;
use App\Models\TaxRate;
use App\Services\Finance\Wallet;
use App\Services\IdentityApi\Controls;
use App\Services\IdentityApi\Engine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class IdentityMariaDbStressTest extends TestCase
{
    private Customer $customer;

    private IdentityApplication $application;

    private CustomerApiToken $token;

    protected function setUp(): void
    {
        parent::setUp();
        $cfg = config('database.connections.'.config('database.default'));
        if (! getenv('AI_RESERVATION_STRESS') || ! in_array($cfg['driver'], ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires private disposable MariaDB runner');
        }
        $this->assertSame('servernet_stress', $cfg['database']);
        $this->assertSame('db', $cfg['host']);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        Wallet::flushSchemaCache();
        config(['identity_api.token' => 'synthetic-provider-key']);
        $this->customer = Customer::create(['email' => 'race@example.test', 'status' => 'active', 'locale' => 'fa']);
        IdentityVerification::create(['customer_id' => $this->customer->id, 'status' => 'verified', 'national_id_enc' => 'synthetic', 'national_id_hash' => hash('sha256', 'synthetic'), 'birth_date' => '2000-01-01', 'mobile' => '00000000000']);
        $this->application = IdentityApplication::create(['customer_id' => $this->customer->id, 'name' => 'Race', 'status' => 'active', 'daily_cap_irt' => 1000000, 'concurrency' => 100, 'terms_version' => '2026-10-17', 'accepted_at' => now()]);
        [$this->token] = CustomerApiToken::issue($this->customer->id, 'Race', array_keys(CustomerApiToken::IDENTITY_ABILITIES), [], null, null, $this->application->id);
        $this->token->update(['daily_spend_cap_irt' => 1000000]);
        app(Controls::class)->put('customer_cap:'.$this->customer->id, 1000000, 1);
        app(Wallet::class)->credit($this->customer->id, 'IRT', 10000, 'topup', $this->customer);
        TaxRate::query()->delete();
        TaxRate::create(['country' => 'IR', 'name' => 'Synthetic', 'rate_bp' => 0, 'is_active' => true, 'priority' => 1]);
        IdentityPrice::create(['service' => 'shahkar-inquiry', 'source_amount' => 1000, 'source_currency' => 'IRT', 'purchase_tax_included' => true, 'purchase_tax_amount' => 0, 'markup_bps' => 0, 'daily_quota' => 1000, 'billing_policy' => 'valid_data_v1', 'evidence' => 'synthetic', 'access_evidence' => 'synthetic', 'effective_at' => now(), 'valid_until' => now()->addDay()]);
        foreach (['enabled' => true, 'egress_verified' => true, 'resale_evidence' => 'synthetic', 'service:shahkar-inquiry' => true, 'provider_daily_cap_irt' => 1000000, 'provider_concurrency' => 100, 'upstream_balance_irt' => 1000000, 'upstream_balance_verified_at' => now()->toIso8601String()] as $k => $v) {
            app(Controls::class)->put($k, $v, 1);
        }
    }

    private function workers(array $modes, ?int $id = null): void
    {
        $children = [];
        foreach ($modes as $mode) {
            $p = new Process([PHP_BINARY, base_path('tests/Support/identity-race-worker.php'), $mode, (string) $id], base_path(), null, null, 90);
            $p->start();
            $children[] = $p;
        }
        foreach ($children as $p) {
            $p->wait();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput().$p->getOutput());
        }
    }

    private function request(): IdentityRequest
    {
        return app(Engine::class)->accept($this->application, $this->token, 'shahkar-inquiry', ['mobile' => '09120000000', 'national_code' => '0000000019'], 'synthetic-single-request', 1000);
    }

    public function test_hundred_mixed_identity_ai_and_invoice_attempts_cannot_overspend(): void
    {
        $this->workers(array_fill(0, 10, 'mixed'));
        $w = app(Wallet::class);
        $this->assertSame(0, $w->availableOf($this->customer->id));
        $this->assertSame(10000, $w->reservedOf($this->customer->id) + 10000 - $w->balanceOf($this->customer->id));
        $this->assertGreaterThanOrEqual(0, $w->balanceOf($this->customer->id));
    }

    public function test_fifteen_dispatchers_claim_only_once(): void
    {
        $r = $this->request();
        $this->workers(array_fill(0, 15, 'dispatch'), $r->id);
        $this->assertSame(1, DB::table('identity_audits')->where('action', 'synthetic_dispatch')->count());
        $this->assertSame('captured', $r->fresh()->financial_status);
        $this->assertSame(9000, app(Wallet::class)->balanceOf($this->customer->id));
    }

    public function test_capture_release_race_has_one_terminal_financial_outcome(): void
    {
        $r = $this->request();
        $r->update(['processing_status' => 'completed', 'financial_status' => 'pending_reconciliation', 'provider_evidence' => ['http' => 200, 'json' => ['result' => 1, 'response_body' => ['data' => ['matched' => false], 'error_code' => null]]]]);
        $this->workers(['capture', 'release', 'capture', 'capture', 'release', 'capture', 'release', 'release', 'capture', 'capture', 'release', 'capture', 'release', 'release', 'capture'], $r->id);
        $final = $r->fresh();
        $this->assertContains($final->financial_status, ['captured', 'released']);
        $this->assertSame($final->financial_status === 'captured' ? 9000 : 10000, app(Wallet::class)->balanceOf($this->customer->id));
        $this->assertSame(0,app(Wallet::class)->reservedOf($this->customer->id));
    }
}
