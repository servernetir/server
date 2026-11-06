<?php

namespace Tests\Feature;

use App\Services\Bale\Management\BaleManagerDailyDigest;
use App\Services\Bale\Management\BaleManagerDigestDelivery;
use Mockery;
use Tests\TestCase;

class BaleManagerDigestSampleCommandTest extends TestCase
{
    public function test_sample_command_dispatches_only_the_explicit_sample_method(): void
    {
        $digest = Mockery::mock(BaleManagerDailyDigest::class);
        $digest->shouldNotReceive('text'); $digest->shouldNotReceive('detail');
        $delivery = Mockery::mock(BaleManagerDigestDelivery::class);
        $delivery->shouldNotReceive('send');
        $delivery->shouldReceive('sendSample')->once()->with('owner-request-20261031', 100)
            ->andReturn(['status' => 'sent', 'day' => '2026-10-31']);
        $this->app->instance(BaleManagerDailyDigest::class, $digest);
        $this->app->instance(BaleManagerDigestDelivery::class, $delivery);
        $this->artisan('bale:manager-digest', ['--sample-request' => 'owner-request-20261031', '--operator-user-id' => '100'])
            ->assertSuccessful();
    }

    public function test_incomplete_invalid_and_preview_sample_options_fail_before_any_report_access(): void
    {
        $digest = Mockery::mock(BaleManagerDailyDigest::class);
        $digest->shouldNotReceive('text'); $digest->shouldNotReceive('detail');
        $delivery = Mockery::mock(BaleManagerDigestDelivery::class);
        $delivery->shouldNotReceive('send'); $delivery->shouldNotReceive('sendSample');
        $this->app->instance(BaleManagerDailyDigest::class, $digest);
        $this->app->instance(BaleManagerDigestDelivery::class, $delivery);
        foreach ([
            ['--sample-request' => 'request'],
            ['--operator-user-id' => '100'],
            ['--sample-request' => '../daily', '--operator-user-id' => '100'],
            ['--sample-request' => 'request', '--operator-user-id' => '0'],
            ['--sample-request' => 'request', '--operator-user-id' => '01'],
            ['--sample-request' => 'request', '--operator-user-id' => '100', '--preview' => true],
            ['--sample-request' => 'request', '--operator-user-id' => '100', '--topic' => 'traffic'],
        ] as $options) {
            $this->artisan('bale:manager-digest', $options)->assertFailed();
        }
    }

    public function test_ambiguous_or_changed_owner_sample_results_are_failure_without_daily_fallback(): void
    {
        $digest = Mockery::mock(BaleManagerDailyDigest::class);
        $delivery = Mockery::mock(BaleManagerDigestDelivery::class);
        $delivery->shouldNotReceive('send');
        $delivery->shouldReceive('sendSample')->times(4)->with('same-request', 100)->andReturn(
            ['status' => 'uncertain'], ['status' => 'owner_changed'],
            ['status' => 'already_claimed', 'prior_status' => 'uncertain'],
            ['status' => 'already_claimed', 'prior_status' => 'sent']);
        $this->app->instance(BaleManagerDailyDigest::class, $digest);
        $this->app->instance(BaleManagerDigestDelivery::class, $delivery);
        foreach (range(1, 3) as $_) {
            $this->artisan('bale:manager-digest', ['--sample-request' => 'same-request', '--operator-user-id' => '100'])->assertFailed();
        }
        $this->artisan('bale:manager-digest', ['--sample-request' => 'same-request', '--operator-user-id' => '100'])->assertSuccessful();
    }
}
