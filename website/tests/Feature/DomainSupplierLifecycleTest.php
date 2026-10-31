<?php

namespace Tests\Feature;

use App\Models\{CreditEntry, Customer, Domain, DomainSupplierAccount, DomainSupplierOperation, Invoice, Payment, User};
use App\Services\Domain\{DomainProcurement, DomainRegistrar, DomainSupplierLifecycle};
use App\Services\Domain\Providers\{DomainProvider, DomainProviderFactory};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class DomainSupplierLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private DomainProvider $provider;
    private DomainSupplierLifecycle $lifecycle;
    private DomainProcurement $pricing;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['domain_suppliers.enabled' => true]);
        $this->owner = User::factory()->create(['role' => 'admin']);
        config(['domain_suppliers.approver_user_ids' => [$this->owner->id]]);
        $this->provider = Mockery::mock(DomainProvider::class);
        $this->provider->shouldReceive('probe')->andReturn(['healthy' => true, 'balance_minor' => 100000, 'currency' => 'EUR'])->byDefault();
        $this->pricing = Mockery::mock(DomainProcurement::class);
        $this->pricing->shouldReceive('revalidateDomain')->andReturn(['amount_minor' => 1000, 'currency' => 'EUR', 'period_months' => 12, 'wholesale_toman' => 100000, 'floor_toman' => 100000])->byDefault();
        $factory = Mockery::mock(DomainProviderFactory::class);
        $factory->shouldReceive('forAccount')->andReturn($this->provider)->byDefault();
        $this->lifecycle = new DomainSupplierLifecycle($factory, $this->pricing);
    }

    private function renewal(bool $withPayment = true, string $fqdn = 'fixture.example'): array
    {
        $c = Customer::create(['email' => uniqid().'@example.test', 'phone' => '0912'.str_pad((string) (Customer::count() + 1), 7, '0', STR_PAD_LEFT), 'password' => bcrypt('test'), 'status' => 'active']);
        $a = DomainSupplierAccount::create(['provider' => 'joker', 'environment' => 'production', 'label' => 'Synthetic account', 'active' => true]);
        $q = ['supplier_account_id' => $a->id, 'provider' => 'joker', 'operation' => 'renew', 'period_months' => 12,
            'amount_minor' => 1000, 'currency' => 'EUR', 'quote_id' => 100, 'honour_until' => now()->addMinutes(15)->toIso8601String(), 'options' => ['privacy' => 'none']];
        $d = Domain::create(['customer_id' => $c->id, 'domain' => $fqdn, 'sld' => Domain::splitFqdn($fqdn)[0], 'tld' => 'example',
            'registrar' => 'joker', 'supplier_account_id' => $a->id, 'supplier_object_id' => $fqdn, 'supplier_quote' => $q,
            'status' => 'active', 'provision_status' => 'pending', 'expires_at' => now()->addDays(10), 'period_years' => 1]);
        $i = Invoice::create(['customer_id' => $c->id, 'domain_id' => $d->id, 'kind' => 'domain', 'currency_code' => 'IRT',
            'subtotal' => 100000, 'tax' => 0, 'total' => 100000, 'paid' => 100000, 'status' => 'paid',
            'supplier_account_id' => $a->id, 'supplier_quote' => $q]);
        $d->putMeta(['renew_invoice_id' => $i->id, 'renew_years' => 1]);
        if ($withPayment) {
            Payment::create(['customer_id' => $c->id, 'invoice_id' => $i->id, 'currency_code' => 'IRT', 'amount' => 100000,
                'gateway' => 'fixture', 'status' => 'paid', 'external_ref' => uniqid('fixture-')]);
        }
        return [$d->refresh(), $a, $i];
    }

    private function authorize(Domain $d): DomainSupplierOperation
    {
        $r = $this->lifecycle->execute($d, 'renew', 12);
        $this->assertFalse($r['ok']);
        $op = DomainSupplierOperation::where('domain_id', $d->id)->firstOrFail();
        $this->assertSame('prepared', $op->status);
        $this->lifecycle->approve($op, $this->owner->id);
        return $op->refresh();
    }

    public function test_scoped_owner_approval_then_one_submission_and_reconciliation(): void
    {
        [$d] = $this->renewal();
        $this->provider->shouldReceive('submit')->once()->withArgs(function ($kind, $name, $months, $ctx) use ($d) {
            $this->assertSame('renew', $kind);
            $this->assertSame(12, $months);
            $this->assertSame(1000, $ctx['max_price_minor']);
            $this->assertSame((int) $d->expires_at->copy()->addYear()->format('Y'), $ctx['target_expiration_year']);
            $this->assertSame($d->supplier_object_id, $ctx['object_id']);
            return true;
        })->andReturn(['status' => 'queued', 'process_id' => 'fixture-process']);
        $op = $this->authorize($d);
        $this->assertSame('queued', $this->lifecycle->execute($d->fresh(), 'renew', 12)['status']);
        $this->assertSame('manual', $d->fresh()->provision_status);
        $this->assertSame(1, $op->fresh()->attempts);
        $this->assertDatabaseCount('domain_supplier_operations', 1);
        $this->provider->shouldReceive('reconcile')->once()->withArgs(fn ($data) => is_array($data['request_context']) && $data['process_id'] === 'fixture-process')
            ->andReturn(['status' => 'queued']);
        $this->lifecycle->execute($d->fresh(), 'renew', 12);
        $this->assertDatabaseCount('domain_supplier_operations', 1);
    }

    public function test_timeout_is_unknown_and_never_resubmitted(): void
    {
        [$d] = $this->renewal();
        $op = $this->authorize($d);
        $this->provider->shouldReceive('submit')->once()->andThrow(new \RuntimeException('private-session'));
        $this->assertSame('unknown', $this->lifecycle->execute($d->fresh(), 'renew', 12)['status']);
        $this->assertSame('submission_result_unknown', $op->fresh()->error_code);
        $this->assertStringNotContainsString('private-session', $d->fresh()->provision_error);
        $this->provider->shouldReceive('reconcile')->once()->andReturn(['status' => 'unknown', 'error_code' => 'not_yet_known']);
        $this->lifecycle->execute($d->fresh(), 'renew', 12);
        $this->assertSame(1, $op->fresh()->attempts);
    }

    public function test_real_expiry_is_required_for_renewal_success(): void
    {
        [$d] = $this->renewal();
        $op = $this->authorize($d);
        $this->provider->shouldReceive('submit')->once()->andReturn(['status' => 'succeeded']);
        $this->provider->shouldReceive('find')->once()->andReturn(['found' => true, 'object_id' => $d->supplier_object_id, 'expires_at' => $d->expires_at->toIso8601String()]);
        $this->assertSame('unknown', $this->lifecycle->execute($d->fresh(), 'renew', 12)['status']);
        $this->assertSame('renewal_expiry_not_confirmed', $op->fresh()->error_code);
        $this->assertTrue($d->fresh()->expires_at->equalTo($d->expires_at));
    }

    public function test_same_paid_invoice_is_idempotent_after_success(): void
    {
        [$d, , $i] = $this->renewal();
        $op = $this->authorize($d);
        $this->provider->shouldReceive('submit')->once()->andReturn(['status' => 'succeeded']);
        $this->provider->shouldReceive('find')->once()->andReturn(['found' => true, 'object_id' => $d->supplier_object_id, 'expires_at' => $d->expires_at->copy()->addYear()->toIso8601String()]);
        $this->assertTrue($this->lifecycle->execute($d->fresh(), 'renew', 12)['ok']);
        $this->assertTrue($this->lifecycle->execute($d->fresh(), 'renew', 12)['ok']);
        $this->assertSame('succeeded', $op->fresh()->status);
        $this->assertSame($i->id, (int) data_get($d->fresh()->meta, 'renew_invoice_id'));
        $this->assertDatabaseCount('domain_supplier_operations', 1);
    }

    public function test_foreign_customer_payment_does_not_authorize_spending(): void
    {
        [$d, , $i] = $this->renewal(false);
        $other = Customer::create(['email' => 'other@example.test', 'phone' => '09123334444', 'password' => bcrypt('test'), 'status' => 'active']);
        Payment::create(['customer_id' => $other->id, 'invoice_id' => $i->id, 'currency_code' => 'IRT', 'amount' => 100000, 'gateway' => 'fixture', 'status' => 'paid', 'external_ref' => 'foreign']);
        $this->provider->shouldNotReceive('submit');
        $this->lifecycle->execute($d, 'renew', 12);
        $this->assertDatabaseCount('domain_supplier_operations', 0);
        $this->assertStringContainsString('payment_reconciliation_required', $d->fresh()->provision_error);
    }

    public function test_reseller_wallet_requires_exact_invoice_pinned_ledger_id(): void
    {
        [$d, , $i] = $this->renewal(false);
        $debit = CreditEntry::create(['customer_id' => $d->customer_id, 'currency_code' => 'IRT', 'amount' => -100000, 'balance_after' => 0,
            'reason' => 'domain_renew_api', 'source_type' => $d->getMorphClass(), 'source_id' => $d->id]);
        $this->lifecycle->execute($d, 'renew', 12);
        $this->assertDatabaseCount('domain_supplier_operations', 0);
        $i->forceFill(['supplier_payment_ledger_id' => $debit->id])->save();
        $d->forceFill(['provision_status' => 'pending'])->save();
        $op = $this->authorize($d);
        $this->assertSame($debit->id, $op->payment_snapshot['credit_entry_id']);
        $this->assertSame([], $op->payment_snapshot['payment_ids']);
    }

    public function test_approval_is_invalidated_by_quote_mutation(): void
    {
        [$d, , $i] = $this->renewal();
        $op = $this->authorize($d);
        $i->forceFill(['supplier_quote' => array_merge($i->supplier_quote, ['amount_minor' => 9000])])->save();
        $this->provider->shouldNotReceive('submit');
        $this->assertSame('manual_review', $this->lifecycle->execute($d->fresh(), 'renew', 12)['status']);
        $this->assertSame('operation_snapshot_changed', $op->fresh()->error_code);
    }

    public function test_unlisted_admin_cannot_approve(): void
    {
        [$d] = $this->renewal();
        $this->lifecycle->execute($d, 'renew', 12);
        $other = User::factory()->create(['role' => 'admin']);
        $this->expectException(ValidationException::class);
        $this->lifecycle->approve(DomainSupplierOperation::firstOrFail(), $other->id);
    }

    public function test_quote_expiry_prevents_submit_after_approval(): void
    {
        [$d] = $this->renewal();
        $this->authorize($d);
        $this->pricing->shouldReceive('revalidateDomain')->once()->andThrow(ValidationException::withMessages(['domain' => 'expired']));
        $this->provider->shouldNotReceive('submit');
        $this->lifecycle->execute($d->fresh(), 'renew', 12);
        $this->assertSame(0, DomainSupplierOperation::firstOrFail()->attempts);
    }

    public function test_global_fqdn_lock_blocks_concurrent_worker(): void
    {
        [$d] = $this->renewal();
        $lock = Cache::lock(DomainSupplierLifecycle::domainLockKey($d->domain), 180);
        $this->assertTrue($lock->get());
        try {
            $this->provider->shouldNotReceive('submit');
            $this->assertSame('queued', $this->lifecycle->execute($d, 'renew', 12)['status']);
            $this->assertDatabaseCount('domain_supplier_operations', 0);
        } finally { $lock->release(); }
    }

    public function test_disabled_feature_never_routes_joker_to_legacy_openprovider(): void
    {
        [$d] = $this->renewal();
        config(['domain_suppliers.enabled' => false]);
        $this->assertFalse(DomainSupplierLifecycle::legacyCompatible($d));
        $this->assertFalse(app(DomainRegistrar::class)->renewPaid($d)['ok']);
        $this->assertStringContainsString('supplier_feature_disabled', $d->fresh()->provision_error);
    }
    public function test_refresh_quote_keeps_paid_total_and_invalidates_previous_approval(): void
    {
        [$d, , $i] = $this->renewal();
        $op = $this->authorize($d);
        $oldHash = $op->request_hash;
        $quote = \App\Models\DomainQuote::create(['domain' => $d->domain, 'tld' => $d->tld, 'registrar' => 'joker',
            'customer_id' => $d->customer_id, 'honour_until' => now()->addMinutes(5), 'sell_toman' => 100000, 'renew_toman' => 100000,
            'cost_amount' => 1000, 'cost_renew_amount' => 1000, 'cost_currency' => 'EUR']);
        $fresh = array_merge($op->quote_snapshot, ['quote_id' => $quote->id, 'amount_minor' => 1100, 'floor_toman' => 99000]);
        $this->pricing->shouldReceive('quote')->once()->andReturn($quote);
        $this->pricing->shouldReceive('snapshot')->once()->with($quote)->andReturn($fresh);
        $this->provider->shouldNotReceive('submit');
        $this->lifecycle->refreshQuote($op, $this->owner->id);
        $this->assertSame(100000, $i->fresh()->total);
        $this->assertSame('paid', $i->fresh()->status);
        $this->assertSame($quote->id, $i->fresh()->supplier_quote['quote_id']);
        $this->assertNotSame($oldHash, $op->fresh()->request_hash);
        $this->assertNull(data_get($op->fresh()->request_context, 'approval'));
        $this->assertSame('prepared', $op->fresh()->status);
        $this->assertSame('manual', $d->fresh()->provision_status);
    }

    public function test_refresh_quote_rejects_price_that_would_require_more_customer_money(): void
    {
        [$d, , $i] = $this->renewal();
        $op = $this->authorize($d);
        $quote = new \App\Models\DomainQuote;
        $fresh = array_merge($op->quote_snapshot, ['quote_id' => 1000, 'amount_minor' => 9000, 'floor_toman' => 100001]);
        $this->pricing->shouldReceive('quote')->once()->andReturn($quote);
        $this->pricing->shouldReceive('snapshot')->once()->andReturn($fresh);
        try {
            $this->lifecycle->refreshQuote($op, $this->owner->id);
            $this->fail('A paid invoice must not be silently increased.');
        } catch (ValidationException) {
            $this->assertSame(100000, $i->fresh()->total);
            $this->assertSame(100, $i->fresh()->supplier_quote['quote_id']);
        }
    }

    public function test_financial_evidence_is_separate_from_the_quote_cap(): void
    {
        [$d, , $i] = $this->renewal();
        $op = $this->authorize($d);
        $this->provider->shouldReceive('submit')->once()->andReturn(['status' => 'succeeded', 'cost_status' => 'provider_reported',
            'purchase_minor' => 925, 'currency' => 'EUR', 'provider_order_id' => 'order-fixture']);
        $this->provider->shouldReceive('find')->once()->andReturn(['found' => true, 'object_id' => $d->supplier_object_id,
            'expires_at' => $d->expires_at->copy()->addYear()->toIso8601String()]);
        $this->assertTrue($this->lifecycle->execute($d->fresh(), 'renew', 12)['ok']);
        $evidence = $op->fresh()->quote_snapshot['purchase_evidence'];
        $this->assertSame('provider_reported', $evidence['cost_status']);
        $this->assertSame(925, $evidence['purchase_minor']);
        $this->assertSame(1000, $op->fresh()->quote_snapshot['amount_minor']);
        $this->assertSame($evidence, $i->fresh()->supplier_quote['purchase_evidence']);
    }

    public function test_legacy_binding_uses_actual_account_domain_and_object_only(): void
    {
        [$d] = $this->renewal();
        config(['services.openprovider.username' => 'synthetic-login', 'services.openprovider.password' => 'synthetic-password']);
        $legacy = DomainSupplierAccount::where('legacy_config', true)->firstOrFail();
        $legacy->forceFill(['settings' => [\App\Services\Domain\Providers\LegacyAccountIdentity::SETTING => \App\Services\Domain\Providers\LegacyAccountIdentity::currentFingerprint()]])->save();
        $d->forceFill(['supplier_account_id' => null, 'supplier_object_id' => null, 'supplier_quote' => null, 'registrar' => 'openprovider', 'op_id' => 77])->save();
        DomainSupplierLifecycle::bindLegacyResult($d, ['id' => 77, 'domain' => ['name' => 'unrelated', 'extension' => 'example']]);
        $this->assertNull($d->fresh()->supplier_account_id);
        DomainSupplierLifecycle::bindLegacyResult($d, ['id' => 77, 'domain' => ['name' => 'fixture', 'extension' => 'example']]);
        $this->assertSame('77', $d->fresh()->supplier_object_id);
        $this->assertTrue($d->fresh()->supplierAccount->legacy_config);
        $this->assertSame(77, (int) $d->fresh()->op_id);
        $this->assertNull($d->fresh()->supplier_quote);
    }

    public function test_create_uses_only_verified_contact_of_selected_account(): void
    {
        [$d, $account, $i] = $this->renewal();
        $snapshot = array_merge($i->supplier_quote, ['operation' => 'create']);
        $d->forceFill(['status' => 'pending', 'invoice_id' => $i->id, 'supplier_object_id' => null,
            'name_servers' => ['ns1.example.test', 'ns2.example.test'], 'supplier_quote' => $snapshot])->save();
        $i->forceFill(['supplier_quote' => $snapshot])->save();
        $profile = \App\Models\CustomerProfile::create(['customer_id' => $d->customer_id, 'is_default' => true,
            'type' => 'individual', 'status' => 'verified', 'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'fixture@example.test']);
        $other = DomainSupplierAccount::create(['provider' => 'joker', 'environment' => 'production', 'label' => 'Other account']);
        \App\Models\DomainSupplierContact::create(['customer_profile_id' => $profile->id, 'supplier_account_id' => $other->id,
            'role' => 'registrant', 'handle' => 'wrong-account', 'status' => 'active', 'verified_at' => now()]);
        $this->lifecycle->execute($d, 'create', 12);
        $this->assertStringContainsString('contact_mapping_required', $d->fresh()->provision_error);
        $this->assertDatabaseCount('domain_supplier_operations', 0);
        \App\Models\DomainSupplierContact::create(['customer_profile_id' => $profile->id, 'supplier_account_id' => $account->id,
            'role' => 'registrant', 'handle' => 'real-account-handle', 'status' => 'active', 'verified_at' => now()]);
        $d->forceFill(['provision_status' => 'pending'])->save();
        $this->lifecycle->execute($d, 'create', 12);
        $op = DomainSupplierOperation::firstOrFail();
        $this->assertSame('real-account-handle', $op->request_context['contact_handles']['owner']);
        $this->lifecycle->approve($op, $this->owner->id);
        $this->provider->shouldReceive('submit')->once()->withArgs(fn ($kind, $name, $months, $ctx) => $kind === 'create'
            && $ctx['contact_handles']['owner'] === 'real-account-handle'
            && $ctx['name_servers'] === ['ns1.example.test', 'ns2.example.test'])->andReturn(['status' => 'queued']);
        $this->assertSame('queued', $this->lifecycle->execute($d->fresh(), 'create', 12)['status']);
    }

    public function test_definite_pre_submission_rejection_is_not_an_unknown_network_result(): void
    {
        [$d] = $this->renewal();
        $op = $this->authorize($d);
        $this->provider->shouldReceive('submit')->once()->andThrow(new \App\Services\Domain\Providers\DomainProviderException('price_cap_exceeded', false));
        $this->assertSame('failed', $this->lifecycle->execute($d->fresh(), 'renew', 12)['status']);
        $this->assertSame('failed', $op->fresh()->status);
        $this->assertSame('manual', $d->fresh()->provision_status);
        $this->assertSame('manual_review', $this->lifecycle->execute($d->fresh(), 'renew', 12)['status']);
    }

    public function test_prepared_operation_cannot_be_reused_for_a_different_period(): void
    {
        [$d] = $this->renewal();
        $this->authorize($d);
        $this->provider->shouldNotReceive('submit');
        $this->lifecycle->execute($d->fresh(), 'renew', 24);
        $this->assertStringContainsString('operation_binding_mismatch', $d->fresh()->provision_error);
    }

    public function test_reconciliation_command_keeps_reading_when_sales_are_disabled(): void
    {
        [$d] = $this->renewal();
        $op = $this->authorize($d);
        $op->forceFill(['status' => 'unknown', 'sent_at' => now(), 'attempts' => 1])->save();
        config(['domain_suppliers.enabled' => false]);
        $reader = Mockery::mock(DomainSupplierLifecycle::class);
        $reader->shouldReceive('reconcile')->once()->withArgs(fn ($arg) => $arg->id === $op->id)->andReturn(['status' => 'queued']);
        app()->instance(DomainSupplierLifecycle::class, $reader);
        $this->artisan('domains:supplier-reconcile', ['--id' => $op->id])->assertExitCode(0);
    }

    public function test_worker_losing_its_cache_lease_cannot_send_after_another_worker_claimed_the_journal(): void
    {
        [$d] = $this->renewal();
        $op = $this->authorize($d);
        $this->pricing->shouldReceive('revalidateDomain')->once()->andReturnUsing(function () use ($op) {
            // Simulate another worker completing preflight while this worker's
            // in-memory prepared snapshot is stale after a cache lease rollover.
            $op->fresh()->forceFill(['status' => 'submitting', 'sent_at' => now(), 'attempts' => 1])->save();
            return ['amount_minor' => 1000, 'currency' => 'EUR', 'period_months' => 12, 'wholesale_toman' => 100000, 'floor_toman' => 100000];
        });
        $this->provider->shouldNotReceive('submit');
        $this->provider->shouldReceive('reconcile')->once()->andReturn(['status' => 'queued']);
        $this->assertSame('queued', $this->lifecycle->execute($d->fresh(), 'renew', 12)['status']);
        $this->assertSame(1, $op->fresh()->attempts);
    }

    public function test_transfer_epp_is_encrypted_until_first_exact_approved_submission_then_removed(): void
    {
        [$d, $account, $i] = $this->renewal();
        $snapshot = array_merge($i->supplier_quote, ['operation' => 'transfer']);
        $d->forceFill(['status' => Domain::STATUS_TRANSFERRING, 'order_type' => 'transfer', 'transfer_status' => 'pending',
            'invoice_id' => $i->id, 'supplier_object_id' => null, 'supplier_quote' => $snapshot, 'name_servers' => []])->save();
        $i->forceFill(['supplier_quote' => $snapshot])->save();
        $profile = \App\Models\CustomerProfile::create(['customer_id' => $d->customer_id, 'is_default' => true,
            'type' => 'individual', 'status' => 'verified', 'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'fixture@example.test']);
        \App\Models\DomainSupplierContact::create(['customer_profile_id' => $profile->id, 'supplier_account_id' => $account->id,
            'role' => 'registrant', 'handle' => 'real-transfer-handle', 'status' => 'active', 'verified_at' => now()]);
        $this->lifecycle->execute($d, 'transfer', 12, ['auth_code' => 'synthetic-epp-secret']);
        $op = DomainSupplierOperation::firstOrFail();
        $this->assertSame('synthetic-epp-secret', $op->request_context['auth_code']);
        $this->assertStringNotContainsString('synthetic-epp-secret', (string) \Illuminate\Support\Facades\DB::table('domain_supplier_operations')->value('request_context'));
        $this->assertStringNotContainsString('synthetic-epp-secret', $op->toJson());
        $this->lifecycle->approve($op, $this->owner->id);
        $this->provider->shouldReceive('submit')->once()->withArgs(fn ($kind, $name, $months, $ctx) => $kind === 'transfer'
            && $ctx['auth_code'] === 'synthetic-epp-secret' && $ctx['name_servers'] === [])->andReturn(['status' => 'queued', 'process_id' => 'transfer-fixture']);
        $this->assertSame('queued', $this->lifecycle->execute($d->fresh(), 'transfer', 12)['status']);
        $this->assertNull(data_get($op->fresh()->request_context, 'auth_code'));
        $this->assertSame('submitted', $d->fresh()->transfer_status);
    }

    public function test_approved_dispatch_skips_older_unapproved_work_and_never_sends_it(): void
    {
        [$first] = $this->renewal();
        $this->lifecycle->execute($first, 'renew', 12);
        $unapproved = DomainSupplierOperation::firstOrFail();
        [$later] = $this->renewal(true, 'later.example');
        $approved = $this->authorize($later);
        $this->provider->shouldReceive('submit')->once()->withArgs(fn ($kind, $name) => $name === 'later.example')->andReturn(['status' => 'queued']);
        app()->instance(DomainSupplierLifecycle::class, $this->lifecycle);
        $this->artisan('domains:supplier-dispatch-approved', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame('prepared', $unapproved->fresh()->status);
        $this->assertSame(0, $unapproved->fresh()->attempts);
        $this->assertSame('queued', $approved->fresh()->status);
    }

    public function test_invoice_refunded_during_supplier_probe_cannot_be_submitted(): void
    {
        [$d, , $i] = $this->renewal();
        $op = $this->authorize($d);
        $this->provider->shouldReceive('probe')->once()->andReturnUsing(function () use ($i) {
            $i->forceFill(['status' => 'refunded'])->save();
            return ['healthy' => true, 'balance_minor' => 100000, 'currency' => 'EUR'];
        });
        $this->provider->shouldNotReceive('submit');
        $this->lifecycle->execute($d->fresh(), 'renew', 12);
        $this->assertSame('manual_review', $op->fresh()->status);
        $this->assertSame(0, $op->fresh()->attempts);
        $this->assertNull($op->fresh()->sent_at);
        $this->assertSame('refunded', $i->fresh()->status);
    }

    public function test_payment_revoked_during_supplier_probe_cannot_be_submitted(): void
    {
        [$d, , $i] = $this->renewal();
        $op = $this->authorize($d);
        $this->provider->shouldReceive('probe')->once()->andReturnUsing(function () use ($i) {
            Payment::where('invoice_id', $i->id)->update(['status' => 'refunded']);
            return ['healthy' => true, 'balance_minor' => 100000, 'currency' => 'EUR'];
        });
        $this->provider->shouldNotReceive('submit');
        $this->lifecycle->execute($d->fresh(), 'renew', 12);
        $this->assertSame('manual_review', $op->fresh()->status);
        $this->assertSame(0, $op->fresh()->attempts);
        $this->assertNull($op->fresh()->sent_at);
    }

    public function test_supplier_credentials_changed_during_probe_cannot_spend_approved_principal(): void
    {
        [$d, $account] = $this->renewal();
        $op = $this->authorize($d);
        $this->provider->shouldReceive('probe')->once()->andReturnUsing(function () use ($account) {
            $account->forceFill(['credentials' => ['api_key' => 'different-synthetic-principal']])->save();
            return ['healthy' => true, 'balance_minor' => 100000, 'currency' => 'EUR'];
        });
        $this->provider->shouldNotReceive('submit');
        $this->lifecycle->execute($d->fresh(), 'renew', 12);
        $this->assertSame('manual_review', $op->fresh()->status);
        $this->assertSame('supplier_account_changed', $op->fresh()->error_code);
        $this->assertSame(0, $op->fresh()->attempts);
    }

    public function test_paid_invoice_below_actual_purchase_floor_cannot_be_submitted(): void
    {
        [$d] = $this->renewal();
        $op = $this->authorize($d);
        $this->pricing->shouldReceive('revalidateDomain')->once()->andReturn(['amount_minor' => 1000, 'currency' => 'EUR',
            'period_months' => 12, 'wholesale_toman' => 100001, 'floor_toman' => 108001]);
        $this->provider->shouldNotReceive('submit');
        $this->lifecycle->execute($d->fresh(), 'renew', 12);
        $this->assertSame('manual_review', $op->fresh()->status);
        $this->assertSame(0, $op->fresh()->attempts);
    }

    public function test_quote_expiring_during_last_supplier_read_is_not_submitted(): void
    {
        [$d] = $this->renewal();
        $op = $this->authorize($d);
        $this->provider->shouldReceive('probe')->once()->andReturnUsing(function () {
            $this->travel(16)->minutes();
            return ['healthy' => true, 'balance_minor' => 100000, 'currency' => 'EUR'];
        });
        $this->provider->shouldNotReceive('submit');
        try {
            $this->lifecycle->execute($d->fresh(), 'renew', 12);
            $this->assertSame('manual_review', $op->fresh()->status);
            $this->assertSame('supplier_quote_expired', $op->fresh()->error_code);
            $this->assertSame(0, $op->fresh()->attempts);
        } finally { $this->travelBack(); }
    }

    public function test_customer_ownership_changed_during_probe_cannot_spend_original_payment(): void
    {
        [$d] = $this->renewal();
        $op = $this->authorize($d);
        $other = Customer::create(['email' => 'new-owner@example.test', 'phone' => '09123335555', 'password' => bcrypt('x'), 'status' => 'active']);
        $this->provider->shouldReceive('probe')->once()->andReturnUsing(function () use ($d, $other) {
            Domain::whereKey($d->id)->update(['customer_id' => $other->id]);
            return ['healthy' => true, 'balance_minor' => 100000, 'currency' => 'EUR'];
        });
        $this->provider->shouldNotReceive('submit');
        $this->lifecycle->execute($d->fresh(), 'renew', 12);
        $this->assertSame('manual_review', $op->fresh()->status);
        $this->assertSame('operation_binding_mismatch', $op->fresh()->error_code);
        $this->assertSame(0, $op->fresh()->attempts);
    }

    public function test_other_domain_claim_during_probe_reserves_account_balance_before_final_send(): void
    {
        [$d, $account] = $this->renewal();
        $op = $this->authorize($d);
        $this->provider->shouldReceive('probe')->once()->andReturnUsing(function () use ($account) {
            DomainSupplierOperation::create(['supplier_account_id' => $account->id, 'operation' => 'create',
                'idempotency_key' => 'concurrent-fixture', 'fqdn' => 'other.example', 'active_fqdn' => 'other.example',
                'period_months' => 12, 'status' => 'submitting', 'sent_at' => now(), 'attempts' => 1,
                'quote_snapshot' => ['amount_minor' => 99500, 'currency' => 'EUR']]);
            return ['healthy' => true, 'balance_minor' => 100000, 'currency' => 'EUR'];
        });
        $this->provider->shouldNotReceive('submit');
        $this->lifecycle->execute($d->fresh(), 'renew', 12);
        $this->assertSame('manual_review', $op->fresh()->status);
        $this->assertSame('supplier_balance_unavailable', $op->fresh()->error_code);
        $this->assertSame(0, $op->fresh()->attempts);
    }

}
