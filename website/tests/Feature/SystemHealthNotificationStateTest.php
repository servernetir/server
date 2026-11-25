<?php

namespace Tests\Feature;

use App\Services\Notify\AdminNotifier;
use App\Services\SystemHealth;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemHealthNotificationStateTest extends TestCase
{
    private string $originalStorage;
    private string $watchStorage;
    private array $checks = [];
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->originalStorage = storage_path();
        $this->watchStorage = sys_get_temp_dir().'/health-notification-'.bin2hex(random_bytes(8));
        mkdir($this->watchStorage.'/app', 0700, true);
        $this->app->useStoragePath($this->watchStorage);
        $this->mock(SystemHealth::class)->shouldReceive('checks')->andReturnUsing(fn () => $this->checks);
        $this->mock(AdminNotifier::class)->shouldReceive('event')->andReturnUsing(function ($title, $rows) {
            $this->sent[] = compact('title', 'rows');
        });
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->watchStorage);
        parent::tearDown();
    }

    private function check(string $key, string $level = 'warn', ?string $impact = null): array
    {
        return ['key' => $key, 'ok' => $level === 'ok', 'level' => $level,
            'title' => $key, 'detail' => 'Private detail must not be persisted in the receipt.',
            'impact_signature' => $impact];
    }

    private function state(): array
    {
        return json_decode(File::get(storage_path('app/health-state')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function tick(): void
    {
        $this->artisan('system:health')->assertSuccessful();
    }

    public function test_debounced_warning_is_not_mistaken_for_a_delivered_warning(): void
    {
        $this->checks = [$this->check('first_warning')];
        $this->tick();
        $this->travel(5)->minutes();
        $this->checks[] = $this->check('later_warning');
        $this->tick();

        $this->assertCount(1, $this->sent);
        $this->assertArrayHasKey('later_warning', $this->state()['bad']);
        $this->assertArrayNotHasKey('later_warning', $this->state()['attempted_at']);

        $this->travel(354)->minutes();
        $this->tick();
        $this->assertCount(1, $this->sent);
        $this->travel(1)->minutes();
        $this->tick();
        $this->assertCount(2, $this->sent);
        $this->assertSame(['later_warning'], array_keys($this->sent[1]['rows']));
        $this->assertSame(now()->timestamp, $this->state()['attempted_at']['later_warning']);
        $this->tick();
        $this->assertCount(2, $this->sent);
    }

    public function test_persistent_failure_and_warning_have_independent_bounded_reminders(): void
    {
        $this->checks = [$this->check('failure', 'fail'), $this->check('warning')];
        $this->tick();
        $this->travel(359)->minutes();
        $this->tick();
        $this->assertCount(1, $this->sent);

        $this->travel(1)->minutes();
        $this->tick();
        $this->assertCount(2, $this->sent);
        $this->assertSame(['failure'], array_keys($this->sent[1]['rows']));
        $this->tick();
        $this->assertCount(2, $this->sent);

        $this->travel(18)->hours();
        $this->tick();
        $this->assertCount(3, $this->sent);
        $this->assertSame(['failure', 'warning'], array_keys($this->sent[2]['rows']));
        $this->assertStringNotContainsString('Private detail', File::get(storage_path('app/health-state')));
    }

    public function test_new_failure_bypasses_warning_quiet_window_and_coalesces_pending_warning(): void
    {
        $this->checks = [$this->check('existing_warning')];
        $this->tick();
        $this->travel(1)->minutes();
        $this->checks[] = $this->check('pending_warning');
        $this->tick();
        $this->assertCount(1, $this->sent);

        $this->checks[0] = $this->check('existing_warning', 'fail');
        $this->tick();
        $this->assertCount(2, $this->sent);
        $this->assertSame(['existing_warning', 'pending_warning'], array_keys($this->sent[1]['rows']));
        $this->tick();
        $this->assertCount(2, $this->sent);
    }

    public function test_resolved_warning_is_forgotten_and_cannot_trigger_a_stale_notification(): void
    {
        $this->checks = [$this->check('first_warning')];
        $this->tick();
        $this->travel(5)->minutes();
        $this->checks[] = $this->check('short_warning');
        $this->tick();
        $this->assertArrayHasKey('short_warning', $this->state()['bad']);

        $this->checks = [$this->check('first_warning')];
        $this->travel(6)->hours();
        $this->tick();
        $this->assertCount(1, $this->sent);
        $this->assertArrayNotHasKey('short_warning', $this->state()['observed_since']);
        $this->checks = [$this->check('first_warning', 'ok')];
        $this->tick();
        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('عادی', $this->sent[1]['title']);
        $this->tick();
        $this->assertCount(2, $this->sent);
        $this->assertSame([], $this->state()['attempted_at']);
    }

    public function test_stable_impact_changes_are_delivered_without_repeating_unchanged_details(): void
    {
        $first = hash('sha256', 'provider:2');
        $second = hash('sha256', 'provider:3');
        $this->checks = [$this->check('catalogue', 'warn', $first)];
        $this->tick();
        $this->travel(5)->minutes();
        $this->checks[0]['impact_signature'] = $second;
        $this->tick();
        $this->assertCount(1, $this->sent);
        $this->assertSame($first, $this->state()['attempted_impact']['catalogue']);

        $this->travel(355)->minutes();
        $this->tick();
        $this->assertCount(2, $this->sent);
        $this->assertSame($second, $this->state()['attempted_impact']['catalogue']);
        $this->checks[0]['detail'] = 'Changing timestamp is not an impact change.';
        $this->travel(6)->hours();
        $this->tick();
        $this->assertCount(2, $this->sent);
    }

    public function test_failure_impact_change_has_one_hour_anti_churn_window(): void
    {
        $this->checks = [$this->check('release', 'fail', hash('sha256', 'impact1'))];
        $this->tick();
        $this->travel(5)->minutes();
        $this->checks[0]['impact_signature'] = hash('sha256', 'impact2');
        $this->tick();
        $this->assertCount(1, $this->sent);
        $this->travel(55)->minutes();
        $this->tick();
        $this->assertCount(2, $this->sent);
        $this->tick();
        $this->assertCount(2, $this->sent);
    }

    public function test_v2_receipt_migrates_without_duplicate_alert_and_retains_reminder_deadline(): void
    {
        $original = now()->subHour()->timestamp;
        File::put(storage_path('app/health-state'), json_encode([
            'version' => 2, 'bad' => ['release' => 'fail'],
            'last_announced_at' => now()->subHour()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        $digest = hash('sha256', 'impact');
        $this->checks = [$this->check('release', 'fail', $digest)];
        $this->tick();
        $this->assertCount(0, $this->sent);
        $this->assertSame(3, $this->state()['version']);
        $this->assertSame($original, $this->state()['attempted_at']['release']);
        $this->assertSame($digest, $this->state()['attempted_impact']['release']);
        $this->travel(5)->hours();
        $this->tick();
        $this->assertCount(1, $this->sent);
    }
}
