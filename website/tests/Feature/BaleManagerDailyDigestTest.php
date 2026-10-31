<?php

namespace Tests\Feature;

use App\Services\Bale\Management\BaleManagerDailyDigest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BaleManagerDailyDigestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-01 09:00:00', 'Asia/Tehran'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_signup_month_bounds_use_tehran_and_cloud_delivery_is_not_double_counted(): void
    {
        Schema::create('customers', fn (Blueprint $t) => $t->timestamp('created_at'));
        foreach (['2026-09-30 20:29:59', '2026-09-30 20:30:00', '2026-10-30 05:30:00',
            '2026-10-31 05:30:00', '2026-10-31 20:30:00', '2026-11-01 05:30:00'] as $at) {
            DB::table('customers')->insert(['created_at' => $at]);
        }
        Schema::create('services', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cloud_plan_id')->nullable();
            $t->timestamp('provisioned_at')->nullable(); $t->string('provision_status');
        });
        Schema::create('cloud_instances', function (Blueprint $t) {
            $t->unsignedBigInteger('service_id'); $t->timestamp('ready_notified_at')->nullable();
        });
        foreach ([1, 2, 3, 4] as $id) {
            DB::table('services')->insert(['id' => $id, 'cloud_plan_id' => null,
                'provisioned_at' => '2026-10-31 05:30:00', 'provision_status' => 'done']);
        }
        DB::table('cloud_instances')->insert([
            ['service_id' => 1, 'ready_notified_at' => '2026-10-31 05:30:00'],
            ['service_id' => 2, 'ready_notified_at' => null],
            ['service_id' => 4, 'ready_notified_at' => '2026-10-31 05:30:00'],
        ]);
        config()->set('billing.internal_service_ids', [4]);

        $row = app(BaleManagerDailyDigest::class)->section('stats');
        $this->assertTrue($row['available']);
        $this->assertSame(2, $row['signups_24h']);
        $this->assertSame(3, $row['signups_previous_month']);
        $this->assertSame(3, $row['signups_30d']);
        $this->assertSame(6, $row['customers_total']);
        $this->assertSame('2026-10 میلادی', $row['month_label']);
        $this->assertSame(2, $row['delivered_24h']);
        $this->assertSame(2, $row['delivered_previous_month']);
        Http::assertNothingSent();
    }

    public function test_successful_settlements_are_counted_once_per_row_and_currencies_are_not_added_together(): void
    {
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->string('status'); $t->timestamp('paid_at'); $t->string('currency_code'); $t->bigInteger('amount');
        });
        DB::table('payments')->insert([
            ['status' => 'paid', 'paid_at' => '2026-10-31 05:30:00', 'currency_code' => 'IRT', 'amount' => 1000],
            ['status' => 'paid', 'paid_at' => '2026-11-01 04:30:00', 'currency_code' => 'IRT', 'amount' => 2000],
            ['status' => 'paid', 'paid_at' => '2026-10-31 05:30:00', 'currency_code' => 'USD', 'amount' => 1234],
            ['status' => 'failed', 'paid_at' => '2026-10-31 05:30:00', 'currency_code' => 'USD', 'amount' => 999],
            ['status' => 'paid', 'paid_at' => '2026-11-01 05:30:00', 'currency_code' => 'IRT', 'amount' => 500],
        ]);
        $digest = app(BaleManagerDailyDigest::class);
        $row = $digest->section('payments');
        $amounts = array_column($row['currencies'], 'amount_minor', 'currency');
        $this->assertSame(3, $row['count']);
        $this->assertSame(3000, $amounts['IRT']);
        $this->assertSame(1234, $amounts['USD']);
        $text = $digest->detail('payments');
        $this->assertStringContainsString('3000 IRT', $text);
        $this->assertStringContainsString('12.34 USD', $text);
        $this->assertStringContainsString('درآمد فروش یا سود نیست', $text);
        Http::assertNothingSent();
    }

    public function test_daily_mail_is_date_bounded_and_never_stamps_reported_or_handled_fields(): void
    {
        Schema::create('mailbox_messages', function (Blueprint $t) {
            $t->id(); $t->boolean('is_system'); $t->boolean('needs_reply'); $t->integer('importance');
            $t->string('category'); $t->string('subject'); $t->text('summary');
            $t->timestamp('received_at'); $t->timestamp('handled_at')->nullable(); $t->timestamp('reported_at')->nullable();
        });
        $base = ['is_system' => false, 'needs_reply' => true, 'importance' => 5, 'category' => 'customer',
            'subject' => 'Fixture customer request', 'summary' => 'Fixture summary', 'received_at' => '2026-10-31 06:00:00',
            'handled_at' => null, 'reported_at' => null];
        foreach ([$base, array_replace($base, ['handled_at' => '2026-10-31 07:00:00']),
            array_replace($base, ['received_at' => '2026-10-30 06:00:00']),
            array_replace($base, ['is_system' => true]), array_replace($base, ['category' => 'spam'])] as $row) {
            DB::table('mailbox_messages')->insert($row);
        }
        $before = DB::table('mailbox_messages')->get()->toJson();
        $row = app(BaleManagerDailyDigest::class)->section('mail');
        $this->assertSame(2, $row['received_24h']);
        $this->assertSame(2, $row['pending']);
        $this->assertCount(2, $row['items']);
        $this->assertSame($before, DB::table('mailbox_messages')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_missing_traffic_remains_unknown_and_never_labels_search_clicks_as_visitors(): void
    {
        $traffic = \Mockery::mock(\App\Services\Bale\Management\ManagerTrafficSnapshot::class);
        $traffic->shouldReceive('cachedSnapshot')->twice()->andReturn(['available' => false, 'status' => 'unknown',
            'ga4' => ['periods' => ['yesterday' => ['status' => 'unknown', 'total_users' => null]]],
            'gsc' => ['periods' => ['yesterday' => ['status' => 'unknown', 'clicks' => null, 'impressions' => null]]]]);
        $traffic->shouldNotReceive('refresh');
        $this->app->instance(\App\Services\Bale\Management\ManagerTrafficSnapshot::class, $traffic);
        $digest = app(BaleManagerDailyDigest::class);
        $row = $digest->section('traffic');
        $this->assertFalse($row['available']);
        $this->assertNull($row['ga4']['periods']['yesterday']['total_users']);
        $this->assertNull($row['gsc']['periods']['yesterday']['clicks']);
        $text = $digest->detail('traffic');
        $this->assertStringContainsString('نامشخص', $text);
        $this->assertStringContainsString('بازدیدکننده GA4', $text);
        $this->assertStringContainsString('کلیک/نمایش GSC', $text);
        Http::assertNothingSent();
    }

    public function test_balances_preview_uses_only_cached_observations_and_callbacks_are_bounded(): void
    {
        $reader = \Mockery::mock(\App\Services\Bale\Management\ProviderBalanceSnapshot::class);
        $reader->shouldReceive('cachedSnapshot')->once()->andReturn(['fixture' => ['provider' => 'fixture', 'label' => 'Fixture',
            'status' => 'unknown', 'value' => null, 'currency' => null, 'reason' => 'no_observation']]);
        $reader->shouldNotReceive('snapshot');
        $this->app->instance(\App\Services\Bale\Management\ProviderBalanceSnapshot::class, $reader);
        $digest = app(BaleManagerDailyDigest::class);
        $this->assertStringContainsString('Fixture: نامشخص', $digest->detail('balances'));
        foreach ($digest->buttons() as $row) {
            foreach ($row as $button) {
                $this->assertLessThanOrEqual(64, strlen($button['data']));
                $this->assertStringStartsWith('v1:md:', $button['data']);
            }
        }
        Http::assertNothingSent();
    }

    public function test_cost_details_keep_each_rates_true_source_dates_separate_from_baseline_capture(): void
    {
        $costs = \Mockery::mock(\App\Services\Bale\Management\ManagementCostChanges::class);
        $costs->shouldReceive('snapshot')->once()->with(false)->andReturn(['available' => true, 'status' => 'compared',
            'increase_count' => 1, 'unknown_count' => 0, 'baseline_at' => '2026-10-30T06:00:00Z',
            'provider_increases' => [['label' => 'Fixture', 'increase_count' => 1, 'items' => [['plan_id' => 7,
                'period' => 'month', 'scale' => 100, 'before' => 100, 'after' => 200,
                'before_source_at' => '2026-10-28T05:00:00Z', 'source_at' => '2026-10-31T04:00:00Z']]]]]);
        $this->app->instance(\App\Services\Bale\Management\ManagementCostChanges::class, $costs);
        $text = app(BaleManagerDailyDigest::class)->detail('costs');
        $this->assertStringContainsString('زمان منبع قبلی 2026-10-28T05:00:00Z', $text);
        $this->assertStringContainsString('فعلی 2026-10-31T04:00:00Z', $text);
        $this->assertStringContainsString('ثبت خط مبنا: 2026-10-30T06:00:00Z', $text);
    }

    public function test_compact_report_names_each_unreadable_provider_without_inventing_zero_balances(): void
    {
        $rows = [];
        foreach (['Hetzner Cloud', 'Aeza', 'OVHcloud', 'SaladCloud'] as $label) {
            $rows[] = ['label' => $label, 'status' => 'unknown', 'value' => null];
        }
        $text = app(BaleManagerDailyDigest::class)->text(['sections' => [
            'balances' => ['available' => true, 'rows' => $rows],
        ]]);
        foreach ($rows as $row) { $this->assertStringContainsString($row['label'], $text); }
        $this->assertStringContainsString('نامشخص: Hetzner Cloud، Aeza، OVHcloud، SaladCloud', $text);
        $this->assertStringNotContainsString('0 USD', $text);
        $this->assertStringNotContainsString('0 EUR', $text);
    }

    public function test_growth_failed_jobs_and_unknown_cost_backlog_are_included_without_rerunning_jobs(): void
    {
        Schema::create('posts', function (Blueprint $t) { $t->string('status'); $t->timestamp('published_at')->nullable(); });
        Schema::create('growth_jobs', function (Blueprint $t) {
            $t->id(); $t->string('status'); $t->timestamp('finished_at')->nullable();
            $t->bigInteger('actual_cost_irt')->nullable(); $t->bigInteger('max_cost_irt'); $t->string('error_code')->nullable();
        });
        DB::table('posts')->insert(['status' => 'published', 'published_at' => '2026-10-31 06:00:00']);
        DB::table('growth_jobs')->insert([
            ['status' => 'failed', 'finished_at' => '2026-10-30 06:00:00', 'actual_cost_irt' => null, 'max_cost_irt' => 100, 'error_code' => 'generation_failed'],
            ['status' => 'failed', 'finished_at' => '2026-10-31 06:00:00', 'actual_cost_irt' => 0, 'max_cost_irt' => 100, 'error_code' => 'quote_day_expired'],
            ['status' => 'failed', 'finished_at' => '2026-10-31 07:00:00', 'actual_cost_irt' => null, 'max_cost_irt' => 100, 'error_code' => 'generation_failed'],
            ['status' => 'completed', 'finished_at' => '2026-10-31 07:00:00', 'actual_cost_irt' => null, 'max_cost_irt' => 100, 'error_code' => null],
        ]);
        $before = DB::table('growth_jobs')->get()->toJson();
        $digest = app(BaleManagerDailyDigest::class);
        $row = $digest->section('content');
        $this->assertSame(2, $row['jobs']['failed_24h']);
        $this->assertSame(3, $row['jobs']['uncertain_cost']);
        $text = $digest->detail('content');
        $this->assertStringContainsString('هزینه تطبیق‌نشده 3', $text);
        $this->assertStringContainsString('به معنی هزینه صفر نیست', $text);
        $this->assertSame($before, DB::table('growth_jobs')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_traffic_shows_source_dates_and_distinguishes_users_from_sessions_and_search_metrics(): void
    {
        $ga = ['status' => 'known', 'total_users' => 11, 'sessions' => 22, 'start_date' => '2026-10-31', 'end_date' => '2026-10-31', 'timezone' => 'Asia/Tehran'];
        $gsc = ['status' => 'known', 'clicks' => 33, 'impressions' => 444, 'start_date' => '2026-10-30', 'end_date' => '2026-10-30', 'timezone' => 'America/Los_Angeles'];
        $traffic = \Mockery::mock(\App\Services\Bale\Management\ManagerTrafficSnapshot::class);
        $traffic->shouldReceive('cachedSnapshot')->once()->andReturn(['available' => true, 'as_of' => '2026-11-01T05:30:00Z',
            'ga4' => ['periods' => ['yesterday' => $ga, 'previous_month' => array_replace($ga, ['total_users' => 111, 'sessions' => 222])]],
            'gsc' => ['periods' => ['yesterday' => $gsc, 'previous_month' => array_replace($gsc, ['clicks' => 333, 'impressions' => 4444])]]]);
        $traffic->shouldNotReceive('refresh');
        $this->app->instance(\App\Services\Bale\Management\ManagerTrafficSnapshot::class, $traffic);
        $text = app(BaleManagerDailyDigest::class)->detail('traffic');
        $this->assertStringContainsString('بازدیدکننده یکتای GA4: 2026-10-31 11 | ماه قبل 111', $text);
        $this->assertStringContainsString('جستجوی GSC (کلیک/نمایش): 2026-10-30 33/444', $text);
        $this->assertStringContainsString('America/Los_Angeles', $text);
        $this->assertStringContainsString('/admin/bale/reporting', $text);
        Http::assertNothingSent();
    }
}
