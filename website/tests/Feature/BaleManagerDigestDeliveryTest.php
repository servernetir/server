<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\BaleSender;
use App\Services\Bale\Management\BaleManagerDailyDigest;
use App\Services\Bale\Management\BaleManagerDigestDelivery;
use App\Services\Bale\Management\ManagementCostChanges;
use App\Services\Bale\Management\ProviderBalanceSnapshot;
use App\Services\Notify\BaleManagerPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BaleManagerDigestDeliveryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-01 09:00:00', 'Asia/Tehran'));
        Schema::create('settings', function (Blueprint $t) { $t->string('key')->primary(); $t->text('value')->nullable(); $t->timestamps(); });
        Setting::put(BaleManagerPolicy::SETTING, '1');
        $this->directory = storage_path('framework/testing/manager-digest-'.bin2hex(random_bytes(8)));
        $balances = \Mockery::mock(ProviderBalanceSnapshot::class);
        $balances->shouldReceive('snapshot')->andReturn([]);
        $this->app->instance(ProviderBalanceSnapshot::class, $balances);
        $traffic = \Mockery::mock(\App\Services\Bale\Management\ManagerTrafficSnapshot::class);
        $traffic->shouldReceive('refresh')->andReturn(['available' => false]);
        $this->app->instance(\App\Services\Bale\Management\ManagerTrafficSnapshot::class, $traffic);
        $costs = \Mockery::mock(ManagementCostChanges::class);
        $costs->shouldReceive('snapshot')->with(true)->andReturn(['state_persisted' => true]);
        $this->app->instance(ManagementCostChanges::class, $costs);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function delivery(?int $messageId, ?array $secondBinding = null): BaleManagerDigestDelivery
    {
        $digest = \Mockery::mock(BaleManagerDailyDigest::class);
        $digest->shouldReceive('text')->andReturn('fixture private digest');
        $digest->shouldReceive('buttons')->andReturn([]);
        $gate = \Mockery::mock(AdminBaleGate::class);
        $gate->shouldReceive('enabled')->andReturn(true);
        $binding = ['user_id' => 100, 'chat_id' => '200'];
        $gate->shouldReceive('binding')->andReturn($binding, $secondBinding ?? $binding);
        $gate->shouldReceive('boundUser')->andReturn(new User(['id' => 100, 'role' => 'admin']));
        $sender = \Mockery::mock(BaleSender::class);
        $sender->shouldReceive('enabled')->andReturn(true);
        if ($secondBinding !== null) { $sender->shouldNotReceive('sendButtons'); }
        else { $sender->shouldReceive('sendButtons')->once()->with('200', 'fixture private digest', [])->andReturn($messageId); }
        return new BaleManagerDigestDelivery($digest, $gate, $sender, $this->directory);
    }

    public function test_daily_send_is_claimed_once_and_receipt_contains_no_report_body(): void
    {
        $delivery = $this->delivery(901);
        $this->assertSame('sent', $delivery->send()['status']);
        $again = $delivery->send();
        $this->assertSame('already_claimed', $again['status']);
        $this->assertSame('sent', $again['prior_status']);
        $receipt = File::get($this->directory.'/2026-11-01.json');
        $this->assertStringNotContainsString('fixture private digest', $receipt);
        $this->assertStringNotContainsString('chat_id', $receipt);
        $this->assertSame('sent', json_decode($receipt, true)['status']);
        $this->assertSame(0600, fileperms($this->directory.'/2026-11-01.json') & 0777);
        Http::assertNothingSent();
    }

    public function test_uncertain_result_is_never_retried_automatically(): void
    {
        $delivery = $this->delivery(null);
        $this->assertSame('uncertain', $delivery->send()['status']);
        $again = $delivery->send();
        $this->assertSame('already_claimed', $again['status']);
        $this->assertSame('uncertain', $again['prior_status']);
        Http::assertNothingSent();
    }

    public function test_owner_change_before_dispatch_blocks_delivery(): void
    {
        $delivery = $this->delivery(null, ['user_id' => 101, 'chat_id' => '201']);
        $this->assertSame('owner_changed', $delivery->send()['status']);
        Http::assertNothingSent();
    }

    public function test_disabled_policy_never_creates_a_claim_or_refreshes_balances(): void
    {
        Setting::put(BaleManagerPolicy::SETTING, '0');
        $digest = \Mockery::mock(BaleManagerDailyDigest::class); $digest->shouldNotReceive('text');
        $gate = \Mockery::mock(AdminBaleGate::class); $gate->shouldNotReceive('binding');
        $sender = \Mockery::mock(BaleSender::class); $sender->shouldNotReceive('sendButtons');
        $delivery = new BaleManagerDigestDelivery($digest, $gate, $sender, $this->directory);
        $this->assertSame('disabled', $delivery->send()['status']);
        $this->assertDirectoryDoesNotExist($this->directory);
        Http::assertNothingSent();
    }
}
