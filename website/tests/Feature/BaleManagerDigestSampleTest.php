<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\BaleSender;
use App\Services\Bale\Management\BaleManagerDailyDigest;
use App\Services\Bale\Management\BaleManagerDigestDelivery;
use App\Services\Bale\Management\ManagementCostChanges;
use App\Services\Bale\Management\ManagerTrafficSnapshot;
use App\Services\Bale\Management\ProviderBalanceSnapshot;
use App\Services\Notify\BaleManagerPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class BaleManagerDigestSampleTest extends TestCase
{
    private string $directory;
    private const TEXT = "🧪 نمونهٔ درخواستی گزارش روزانه\nfixture private report";
    private const BUTTONS = [[['text' => 'جزئیات بازدید', 'callback_data' => 'md:traffic']]];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', 'Asia/Tehran'));
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary(); $table->text('value')->nullable(); $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) { $table->id(); $table->string('role'); });
        \Illuminate\Support\Facades\DB::table('users')->insert(['id' => 100, 'role' => 'admin']);
        Setting::put(BaleManagerPolicy::SETTING, '1');
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');
        Setting::putSecret(AdminBaleGate::KEY_BIND, json_encode(['user_id' => 100, 'chat_id' => '200']));
        $this->directory = storage_path('framework/testing/manager-sample-'.bin2hex(random_bytes(8)));
        $costs = Mockery::mock(ManagementCostChanges::class);
        $costs->shouldNotReceive('snapshot');
        $this->app->instance(ManagementCostChanges::class, $costs);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(int $id = 100, string $role = 'admin'): User
    {
        return (new User)->forceFill(['id' => $id, 'role' => $role]);
    }

    private function delivery(?int $messageId = 901, int $calls = 1, ?array $freshBinding = null, ?User $freshUser = null, ?callable $duringText = null, bool $realGate = false): BaleManagerDigestDelivery
    {
        $digest = Mockery::mock(BaleManagerDailyDigest::class);
        $digest->shouldReceive('text')->times($calls)->andReturnUsing(static function () use ($duringText) {
            if ($duringText !== null) { $duringText(); }
            return 'fixture private report';
        });
        $blocked = $freshBinding !== null || $freshUser !== null || $duringText !== null;
        $digest->shouldReceive('buttons')->times($blocked ? 0 : $calls)->andReturn(self::BUTTONS);
        $balances = Mockery::mock(ProviderBalanceSnapshot::class);
        $balances->shouldReceive('snapshot')->times($calls)->andReturn([]);
        $traffic = Mockery::mock(ManagerTrafficSnapshot::class);
        $traffic->shouldReceive('refresh')->times($calls)->andReturn(['available' => true]);
        $this->app->instance(ProviderBalanceSnapshot::class, $balances);
        $this->app->instance(ManagerTrafficSnapshot::class, $traffic);
        if ($realGate) {
            $gate = app(AdminBaleGate::class);
        } else {
            $gate = Mockery::mock(AdminBaleGate::class);
            $gate->shouldReceive('enabled')->andReturn(true);
            $binding = ['user_id' => 100, 'chat_id' => '200'];
            $gate->shouldReceive('binding')->andReturn($binding, $freshBinding ?? $binding);
            $gate->shouldReceive('boundUser')->andReturn($this->user(), $freshUser ?? $this->user());
        }
        $sender = Mockery::mock(BaleSender::class);
        $sender->shouldReceive('enabled')->andReturn(true);
        if ($blocked) { $sender->shouldNotReceive('sendButtons'); }
        else { $sender->shouldReceive('sendButtons')->times($calls)->with('200', self::TEXT, self::BUTTONS)->andReturn($messageId); }

        return new BaleManagerDigestDelivery($digest, $gate, $sender, $this->directory);
    }

    public function test_requested_sample_keeps_daily_claim_exactly_and_sends_same_detail_buttons_once(): void
    {
        File::makeDirectory($this->directory, 0700, true);
        $daily = $this->directory.'/2026-11-01.json';
        $original = '{"version":1,"day":"2026-11-01","status":"sent","message_id":11}';
        File::put($daily, $original);
        $delivery = $this->delivery();
        $this->assertSame('sent', $delivery->sendSample('owner-request-20261101-a', 100)['status']);
        $again = $delivery->sendSample('owner-request-20261101-a', 100);
        $this->assertSame('already_claimed', $again['status']);
        $this->assertSame('sent', $again['prior_status']);
        $this->assertSame($original, File::get($daily));
        $paths = glob($this->directory.'/samples/*.json');
        $this->assertCount(1, $paths);
        $receipt = json_decode(File::get($paths[0]), true);
        $this->assertSame('sample', $receipt['kind']);
        $this->assertSame('sent', $receipt['status']);
        $this->assertSame(hash('sha256', self::TEXT), $receipt['report_sha256']);
        $this->assertStringNotContainsString('fixture private report', File::get($paths[0]));
        $this->assertStringNotContainsString('owner-request-20261101-a', File::get($paths[0]));
        $this->assertSame(0600, fileperms($paths[0]) & 0777);
        $this->assertSame(0700, fileperms(dirname($paths[0])) & 0777);
        Http::assertNothingSent();
    }

    public function test_ambiguous_sample_is_claimed_and_not_retried_even_on_another_day(): void
    {
        $delivery = $this->delivery(null);
        $this->assertSame('uncertain', $delivery->sendSample('ambiguous-request', 100)['status']);
        Carbon::setTestNow(now()->addDay());
        $again = $delivery->sendSample('ambiguous-request', 100);
        $this->assertSame('already_claimed', $again['status']);
        $this->assertSame('uncertain', $again['prior_status']);
        $this->assertFileDoesNotExist($this->directory.'/2026-11-01.json');
        $this->assertFileDoesNotExist($this->directory.'/2026-11-02.json');
        Http::assertNothingSent();
    }

    public function test_a_distinct_explicit_request_can_send_a_second_sample(): void
    {
        $delivery = $this->delivery(901, 2);
        $this->assertSame('sent', $delivery->sendSample('first-request', 100)['status']);
        $this->assertSame('sent', $delivery->sendSample('second-request', 100)['status']);
        $this->assertCount(2, glob($this->directory.'/samples/*.json'));
        $this->assertFileDoesNotExist($this->directory.'/2026-11-01.json');
    }

    public function test_rebinding_the_same_operator_to_another_chat_cannot_replay_an_uncertain_request(): void
    {
        $delivery = $this->delivery(null, realGate: true);
        $this->assertSame('uncertain', $delivery->sendSample('uncertain-before-rebind', 100)['status']);
        Setting::putSecret(AdminBaleGate::KEY_BIND, json_encode(['user_id' => 100, 'chat_id' => '201']));
        $again = $delivery->sendSample('uncertain-before-rebind', 100);
        $this->assertSame('already_claimed', $again['status']);
        $this->assertSame('uncertain', $again['prior_status']);
        $this->assertCount(1, glob($this->directory.'/samples/*.json'));
        Http::assertNothingSent();
    }

    public function test_another_operator_cannot_send_to_the_bound_owner(): void
    {
        $delivery = $this->delivery(calls: 0);
        $this->assertSame('operator_mismatch', $delivery->sendSample('wrong-operator', 101)['status']);
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function test_a_changed_binding_before_dispatch_blocks_the_sample(): void
    {
        $delivery = $this->delivery(freshBinding: ['user_id' => 101, 'chat_id' => '201']);
        $this->assertSame('owner_changed', $delivery->sendSample('changed-owner', 100)['status']);
        $paths = glob($this->directory.'/samples/*.json');
        $this->assertCount(1, $paths);
        $this->assertSame('owner_changed', json_decode(File::get($paths[0]), true)['status']);
        Http::assertNothingSent();
    }

    public function test_an_admin_demotion_before_dispatch_blocks_the_sample(): void
    {
        $delivery = $this->delivery(freshUser: $this->user(role: 'staff'));
        $this->assertSame('owner_changed', $delivery->sendSample('demoted-owner', 100)['status']);
        Http::assertNothingSent();
    }

    public function test_database_rebinding_is_detected_even_when_the_gate_keeps_a_memoized_binding(): void
    {
        $delivery = $this->delivery(duringText: static function (): void {
            \Illuminate\Support\Facades\DB::table('settings')->where('key', AdminBaleGate::KEY_BIND)
                ->update(['value' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['user_id' => 100, 'chat_id' => '201']))]);
        });
        $this->assertSame('owner_changed', $delivery->sendSample('rebound-during-report', 100)['status']);
        Http::assertNothingSent();
    }

    public function test_database_admin_demotion_is_detected_even_when_the_gate_keeps_the_old_user(): void
    {
        $delivery = $this->delivery(duringText: static function (): void {
            \Illuminate\Support\Facades\DB::table('users')->where('id', 100)->update(['role' => 'support']);
        });
        $this->assertSame('owner_changed', $delivery->sendSample('demoted-during-report', 100)['status']);
        Http::assertNothingSent();
    }

    public function test_a_stale_memoized_binding_is_blocked_before_upstream_reads_or_claims(): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->where('key', AdminBaleGate::KEY_ENABLED)->update(['value' => '0']);
        $delivery = $this->delivery(calls: 0);
        $this->assertSame('owner_changed', $delivery->sendSample('disabled-in-database', 100)['status']);
        $this->assertDirectoryDoesNotExist($this->directory);
        Http::assertNothingSent();
    }

    public function test_invalid_request_keys_and_nonpositive_operator_never_read_sources_or_create_claims(): void
    {
        $digest = Mockery::mock(BaleManagerDailyDigest::class);
        $digest->shouldNotReceive('text');
        $gate = Mockery::mock(AdminBaleGate::class);
        $gate->shouldNotReceive('binding');
        $sender = Mockery::mock(BaleSender::class);
        $sender->shouldNotReceive('sendButtons');
        $delivery = new BaleManagerDigestDelivery($digest, $gate, $sender, $this->directory);
        foreach (['', '../daily', str_repeat('a', 81), "line\nbreak"] as $key) {
            $this->assertSame('invalid_sample_request', $delivery->sendSample($key, 100)['status']);
        }
        $this->assertSame('invalid_sample_request', $delivery->sendSample('valid-request', 0)['status']);
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function test_disabled_policy_never_creates_sample_or_reads_sources(): void
    {
        Setting::put(BaleManagerPolicy::SETTING, '0');
        $delivery = $this->delivery(calls: 0);
        $this->assertSame('disabled', $delivery->sendSample('disabled-request', 100)['status']);
        $this->assertDirectoryDoesNotExist($this->directory);
    }
}
