<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Bale\Management\BaleManagerDailyDigest;
use App\Services\Bale\Management\BaleManagerDigestDelivery;
use App\Services\Notify\BaleManagerPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BaleManagerDigestCommandTest extends TestCase
{
    public function test_preview_builds_text_without_claiming_or_sending(): void
    {
        $digest = \Mockery::mock(BaleManagerDailyDigest::class);
        $digest->shouldReceive('text')->once()->andReturn('fixture preview');
        $delivery = \Mockery::mock(BaleManagerDigestDelivery::class);
        $delivery->shouldNotReceive('send');
        $this->app->instance(BaleManagerDailyDigest::class, $digest);
        $this->app->instance(BaleManagerDigestDelivery::class, $delivery);
        $this->artisan('bale:manager-digest', ['--preview' => true])->expectsOutput('fixture preview')->assertSuccessful();
    }

    public function test_invalid_preview_topic_is_rejected_before_reading_sources_or_sending(): void
    {
        $digest = \Mockery::mock(BaleManagerDailyDigest::class);
        $digest->shouldNotReceive('text'); $digest->shouldNotReceive('detail');
        $delivery = \Mockery::mock(BaleManagerDigestDelivery::class);
        $delivery->shouldNotReceive('send');
        $this->app->instance(BaleManagerDailyDigest::class, $digest);
        $this->app->instance(BaleManagerDigestDelivery::class, $delivery);
        $this->artisan('bale:manager-digest', ['--preview' => true, '--topic' => '../private'])->assertFailed();
    }

    public function test_manager_digest_is_daily_at_nine_tehran_and_health_cadence_changes_only_under_gate(): void
    {
        Schema::create('settings', function (Blueprint $t) { $t->string('key')->primary(); $t->text('value')->nullable(); $t->timestamps(); });
        $events = collect(app(Schedule::class)->events());
        $digest = $events->filter(fn ($e) => str_contains((string) $e->command, 'bale:manager-digest'))->sole();
        $health = $events->filter(fn ($e) => str_contains((string) $e->command, 'system:health'));
        Setting::put(BaleManagerPolicy::SETTING, '1');
        $this->assertSame('0 9 * * *', $digest->expression);
        $this->assertSame('Asia/Tehran', $digest->timezone);
        $this->assertTrue($digest->filtersPass($this->app));
        $this->assertSame('*/30 * * * *', $health->filter(fn ($e) => $e->filtersPass($this->app))->sole()->expression);
        Setting::put(BaleManagerPolicy::SETTING, '0');
        $this->assertFalse($digest->filtersPass($this->app));
        $this->assertSame('*/15 * * * *', $health->filter(fn ($e) => $e->filtersPass($this->app))->sole()->expression);
    }

    public function test_legacy_bale_mailbox_push_is_suppressed_without_reading_or_stamping_mail(): void
    {
        Schema::create('settings', function (Blueprint $t) { $t->string('key')->primary(); $t->text('value')->nullable(); $t->timestamps(); });
        Setting::put(BaleManagerPolicy::SETTING, '1');
        $bale = \Mockery::mock(\App\Services\Bale\BaleNotifier::class);
        $bale->shouldNotReceive('toAdmin');
        // No mailbox table exists: the merged mode must return before querying it.
        $result = (new \App\Services\Mail\MailboxDigest($bale))->send();
        $this->assertFalse($result['sent']);
        $this->assertSame('included_in_manager_digest', $result['reason']);
    }

    public function test_existing_ambiguous_daily_claim_is_failure_and_is_not_resent(): void
    {
        $digest = \Mockery::mock(BaleManagerDailyDigest::class);
        $digest->shouldNotReceive('text');
        $delivery = \Mockery::mock(BaleManagerDigestDelivery::class);
        $delivery->shouldReceive('send')->times(4)->andReturn(
            ['status' => 'already_claimed', 'prior_status' => 'uncertain'],
            ['status' => 'already_claimed', 'prior_status' => 'dispatching'],
            ['status' => 'already_claimed', 'prior_status' => 'owner_changed'],
            ['status' => 'already_claimed', 'prior_status' => 'sent']);
        $this->app->instance(BaleManagerDailyDigest::class, $digest);
        $this->app->instance(BaleManagerDigestDelivery::class, $delivery);
        foreach (range(1, 3) as $_) { $this->artisan('bale:manager-digest')->assertFailed(); }
        $this->artisan('bale:manager-digest')->assertSuccessful();
    }
}
