<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Ticket;
use App\Services\Finance\Wallet;
use App\Services\Hermes\HermesOperationsStore;
use App\Services\Hermes\HermesServiceInvestigation;
use App\Services\Hermes\HermesSupportExperience;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/infra/hermes/support-learning-20261028/HermesOperationsStore.php';
require_once dirname(__DIR__, 3).'/infra/hermes/support-firewall-20261028/HermesSupportPolicy.php';

class HermesSupportLearningIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('tickets', function (Blueprint $t) { $t->id(); $t->integer('customer_id'); $t->string('subject'); $t->string('department'); $t->string('status'); $t->timestamp('last_reply_at')->nullable(); });
        Schema::create('ticket_messages', function (Blueprint $t) { $t->id(); $t->integer('ticket_id'); $t->string('author_role'); $t->string('author_name')->nullable(); $t->text('body'); $t->boolean('is_internal')->default(false); $t->timestamp('created_at'); });
        Schema::create('services', function (Blueprint $t) { $t->id(); $t->integer('customer_id'); $t->string('status'); $t->string('billing_mode'); $t->string('provision_status'); $t->integer('cloud_plan_id')->nullable(); $t->integer('hourly_rate_irt')->nullable(); $t->integer('hold_reserve_irt')->nullable(); $t->text('provision_meta')->nullable(); });
        Schema::create('activity_logs', function (Blueprint $t) { $t->id(); $t->integer('service_id'); $t->integer('customer_id'); $t->string('actor'); $t->string('action'); $t->string('description'); $t->timestamp('created_at'); });
        Schema::create('credit_ledger', function (Blueprint $t) { $t->id(); $t->integer('customer_id'); $t->string('currency_code'); $t->integer('amount'); $t->string('reason'); $t->string('source_type')->nullable(); $t->integer('source_id')->nullable(); $t->timestamp('created_at'); });
        DB::table('tickets')->insert([
            ['id' => 1, 'customer_id' => 10, 'subject' => 'UDP', 'department' => 'technical', 'status' => 'open'],
            ['id' => 2, 'customer_id' => 10, 'subject' => 'سایت', 'department' => 'technical', 'status' => 'answered'],
            ['id' => 3, 'customer_id' => 20, 'subject' => 'UDP', 'department' => 'technical', 'status' => 'answered'],
        ]);
        DB::table('ticket_messages')->insert([
            ['id' => 1, 'ticket_id' => 2, 'author_role' => 'staff', 'author_name' => 'Support', 'body' => 'own-history-marker بررسی سایت انجام شد.', 'created_at' => '2026-01-01'],
            ['id' => 2, 'ticket_id' => 3, 'author_role' => 'staff', 'author_name' => 'Support', 'body' => 'private-other-person پورت UDP مجاز است.', 'created_at' => '2026-01-02'],
        ]);
        DB::table('services')->insert(['id' => 1, 'customer_id' => 10, 'status' => 'suspended', 'billing_mode' => 'hourly', 'provision_status' => 'done', 'hourly_rate_irt' => 4000, 'hold_reserve_irt' => 47000, 'provision_meta' => '{}']);
    }

    public function test_actual_collector_learns_new_published_answers_and_customer_outcomes_without_cross_customer_text(): void
    {
        $store = new HermesOperationsStore(dirname(__DIR__, 3).'/output/learning-tests/'.bin2hex(random_bytes(8)));
        $owner = ['user_id' => '100', 'chat_id' => '200'];
        $store->change($owner, function (&$s) { $s['rules']['keep'] = ['status' => 'approved', 'text' => 'preserve']; });
        $learner = new HermesSupportExperience($store);
        $r = $learner->context(Ticket::findOrFail(1), $owner, 'UDP');
        $this->assertSame(2, $r['learned_case_count']);
        $this->assertStringContainsString('own-history-marker', $r['same_customer_history'][0]['published_reply_excerpt']);
        $this->assertStringNotContainsString('private-other-person', json_encode($r));
        $this->assertSame(10, $r['same_customer_history'][0]['customer_id']);
        $this->assertSame(1, $r['owned_service_inventory']['services'][0]['id']);
        DB::table('ticket_messages')->insert(['id' => 3, 'ticket_id' => 3, 'author_role' => 'customer', 'body' => 'مشکل حل شد', 'created_at' => '2026-01-03']);
        $next = $learner->context(Ticket::findOrFail(1), $owner, 'UDP');
        $this->assertSame(1, $next['confirmed_case_count']);
        $this->assertSame('customer_confirmed_report', $next['related_case_patterns'][0]['prior_outcome']);
        $saved = $store->read($owner);
        $this->assertSame('preserve', $saved['rules']['keep']['text']);
        $this->assertStringNotContainsString('private-other-person', json_encode($saved['support_experience']));
    }

    public function test_lifecycle_adapter_reads_only_the_owned_service_and_its_scoped_events(): void
    {
        DB::table('activity_logs')->insert(['service_id' => 1, 'customer_id' => 10, 'actor' => 'system', 'action' => 'suspend', 'description' => 'اتمام اعتبار ساعتی', 'created_at' => '2026-01-01']);
        DB::table('credit_ledger')->insert([
            ['id' => 1, 'customer_id' => 10, 'currency_code' => 'IRT', 'amount' => 54000, 'reason' => 'topup', 'source_type' => null, 'source_id' => null, 'created_at' => '2026-01-01'],
            ['id' => 2, 'customer_id' => 20, 'currency_code' => 'IRT', 'amount' => 999999, 'reason' => 'topup', 'source_type' => null, 'source_id' => null, 'created_at' => '2026-01-01'],
            ['id' => 3, 'customer_id' => 10, 'currency_code' => 'IRT', 'amount' => -4000, 'reason' => 'cloud_hourly', 'source_type' => Service::class, 'source_id' => 1, 'created_at' => '2026-01-01'],
            ['id' => 4, 'customer_id' => 10, 'currency_code' => 'IRT', 'amount' => -2000, 'reason' => 'cloud_hourly', 'source_type' => Service::class, 'source_id' => 2, 'created_at' => '2026-01-01'],
        ]);
        $wallet = \Mockery::mock(Wallet::class);
        $wallet->shouldReceive('balanceOf')->once()->with(10, 'IRT')->andReturn(50000);
        $wallet->shouldReceive('reservedOf')->once()->with(10, 'IRT')->andReturn(47000);
        app()->instance(Wallet::class, $wallet);
        $before = DB::table('services')->first();
        $r = (new HermesServiceInvestigation())->read(Service::findOrFail(1));
        $this->assertSame('verified', $r['result']);
        $this->assertSame('hourly_credit_out_recorded', $r['cause_code']);
        $this->assertSame(3000, $r['wallet']['available_irt']);
        $this->assertCount(1, $r['ledger_history']['recent_account_topups']);
        $this->assertSame(54000, $r['ledger_history']['recent_account_topups'][0]['amount_irt']);
        $this->assertCount(1, $r['ledger_history']['recent_service_debits']);
        $this->assertSame(-4000, $r['ledger_history']['recent_service_debits'][0]['amount_irt']);
        $this->assertEquals($before, DB::table('services')->first());
        $wrong = new Service(); $wrong->forceFill(['id' => 1, 'customer_id' => 20]);
        $this->assertSame('unavailable', (new HermesServiceInvestigation())->read($wrong)['result']);
    }

    public function test_same_customer_billing_history_is_available_without_becoming_cross_customer_training_text(): void
    {
        DB::table('tickets')->insert(['id' => 4, 'customer_id' => 10, 'subject' => 'صورتحساب', 'department' => 'billing', 'status' => 'answered']);
        DB::table('ticket_messages')->insert(['id' => 4, 'ticket_id' => 4, 'author_role' => 'staff', 'author_name' => 'Support', 'body' => 'own-billing-history ثبت پرداخت بررسی شد.', 'created_at' => '2026-01-04']);
        $store = new HermesOperationsStore(dirname(__DIR__, 3).'/output/learning-tests/'.bin2hex(random_bytes(8)));
        $r = (new HermesSupportExperience($store))->context(Ticket::findOrFail(1), ['user_id' => '100', 'chat_id' => '200'], 'UDP');
        $this->assertSame(2, $r['learned_case_count']);
        $this->assertSame('billing', $r['same_customer_history'][0]['department']);
        $this->assertStringContainsString('own-billing-history', $r['same_customer_history'][0]['published_reply_excerpt']);
        $this->assertStringNotContainsString('own-billing-history', json_encode($r['related_case_patterns']));
    }
}
