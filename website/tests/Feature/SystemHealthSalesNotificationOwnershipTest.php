<?php

namespace Tests\Feature;

use App\Services\Notify\AdminNotifier;
use App\Services\SystemHealth;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemHealthSalesNotificationOwnershipTest extends TestCase
{
    private string $originalStorage;
    private string $watchStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->originalStorage = storage_path();
        $this->watchStorage = sys_get_temp_dir().'/health-sales-owner-'.bin2hex(random_bytes(8));
        mkdir($this->watchStorage.'/app', 0700, true);
        $this->app->useStoragePath($this->watchStorage);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->watchStorage);
        parent::tearDown();
    }

    private function gpu(): array
    {
        return ['key' => 'gpu_sales', 'ok' => false, 'level' => 'fail', 'title' => 'GPU test', 'detail' => 'No orderable offer'];
    }

    private function receipt(int $age = 0, bool $probeFailed = false): void
    {
        File::put(storage_path('app/cloud-sales-watch.json'), json_encode([
            'version' => 2, 'checked_at' => now()->subSeconds($age)->toIso8601String(),
            'providers' => [
                'salad' => ['observed_at' => now()->subSeconds($age)->timestamp, 'ok' => false],
                'catalogue' => ['ok' => ! $probeFailed],
            ],
        ]));
    }

    public function test_fresh_sales_monitor_prevents_duplicate_gpu_notification_and_keeps_console_check(): void
    {
        $this->receipt();
        $this->mock(SystemHealth::class)->shouldReceive('checks')->once()->andReturn([$this->gpu()]);
        $this->mock(AdminNotifier::class)->shouldNotReceive('event');
        $this->artisan('system:health')->expectsOutputToContain('GPU test')->assertSuccessful();
        $state = json_decode(File::get(storage_path('app/health-state')), true);
        $this->assertSame([], $state['bad']);
    }

    public function test_stale_missing_or_failed_probe_keeps_system_notification_as_fallback(): void
    {
        foreach (['stale', 'missing', 'probe_failed', 'stale_provider'] as $case) {
            File::delete(storage_path('app/health-state'));
            File::delete(storage_path('app/cloud-sales-watch.json'));
            if ($case !== 'missing') { $this->receipt($case === 'stale' ? 181 : 0, $case === 'probe_failed'); }
            if ($case === 'stale_provider') {
                $receipt = json_decode(File::get(storage_path('app/cloud-sales-watch.json')), true);
                $receipt['providers']['salad']['observed_at'] = now()->subMinutes(4)->timestamp;
                File::put(storage_path('app/cloud-sales-watch.json'), json_encode($receipt));
            }
            $this->mock(SystemHealth::class)->shouldReceive('checks')->once()->andReturn([$this->gpu()]);
            $this->mock(AdminNotifier::class)->shouldReceive('event')->once()->withArgs(
                fn ($title, $rows) => array_key_exists('GPU test', $rows));
            $this->artisan('system:health')->assertSuccessful();
        }
    }

    public function test_ownership_transfer_does_not_falsely_announce_system_recovery(): void
    {
        File::put(storage_path('app/health-state'), json_encode([
            'version' => 2, 'bad' => ['gpu_sales' => 'fail'], 'last_announced_at' => now()->subHour()->toIso8601String(),
        ]));
        $this->receipt();
        $this->mock(SystemHealth::class)->shouldReceive('checks')->once()->andReturn([$this->gpu()]);
        $this->mock(AdminNotifier::class)->shouldNotReceive('event');
        $this->artisan('system:health')->assertSuccessful();
    }

    public function test_unrelated_failure_still_alerts_while_fresh_sales_monitor_owns_gpu(): void
    {
        $this->receipt();
        $cron = ['key' => 'cron', 'ok' => false, 'level' => 'fail', 'title' => 'Cron test', 'detail' => 'Stopped'];
        $this->mock(SystemHealth::class)->shouldReceive('checks')->once()->andReturn([$this->gpu(), $cron]);
        $this->mock(AdminNotifier::class)->shouldReceive('event')->once()->withArgs(
            fn ($title, $rows) => array_key_exists('Cron test', $rows) && ! array_key_exists('GPU test', $rows));
        $this->artisan('system:health')->assertSuccessful();
    }

    public function test_explicit_notify_still_shows_all_failures(): void
    {
        $this->receipt();
        $this->mock(SystemHealth::class)->shouldReceive('checks')->once()->andReturn([$this->gpu()]);
        $this->mock(AdminNotifier::class)->shouldReceive('event')->once()->withArgs(
            fn ($title, $rows) => array_key_exists('GPU test', $rows));
        $this->artisan('system:health --notify')->assertSuccessful();
    }
    public function test_cron_recovery_does_not_announce_all_clear_while_owned_gpu_is_still_failed(): void
    {
        $this->receipt();
        $cron = ['key' => 'cron', 'ok' => false, 'level' => 'fail', 'title' => 'Cron test', 'detail' => 'Stopped'];
        $health = $this->mock(SystemHealth::class);
        $health->shouldReceive('checks')->once()->andReturn([$this->gpu(), $cron])->ordered();
        $health->shouldReceive('checks')->once()->andReturn([
            $this->gpu(), array_replace($cron, ['ok' => true, 'level' => 'ok', 'detail' => 'Running']),
        ])->ordered();
        $sent = [];
        $this->mock(AdminNotifier::class)->shouldReceive('event')->andReturnUsing(
            function ($title, $rows) use (&$sent) { $sent[] = compact('title', 'rows'); });
        $this->artisan('system:health')->assertSuccessful();
        $this->artisan('system:health')->expectsOutputToContain('GPU test')->assertSuccessful();
        $this->assertCount(1, $sent);
        $this->assertArrayHasKey('Cron test', $sent[0]['rows']);
        $this->assertArrayNotHasKey('GPU test', $sent[0]['rows']);
    }

    public function test_genuine_all_green_recovery_is_announced_with_gpu_notification_ownership(): void
    {
        $this->receipt();
        $cron = ['key' => 'cron', 'ok' => false, 'level' => 'fail', 'title' => 'Cron test', 'detail' => 'Stopped'];
        $health = $this->mock(SystemHealth::class);
        $health->shouldReceive('checks')->once()->andReturn([$this->gpu(), $cron])->ordered();
        $health->shouldReceive('checks')->once()->andReturn([
            array_replace($this->gpu(), ['ok' => true, 'level' => 'ok', 'detail' => 'Available']),
            array_replace($cron, ['ok' => true, 'level' => 'ok', 'detail' => 'Running']),
        ])->ordered();
        $sent = [];
        $this->mock(AdminNotifier::class)->shouldReceive('event')->andReturnUsing(
            function ($title) use (&$sent) { $sent[] = $title; });
        $this->artisan('system:health')->assertSuccessful();
        $this->artisan('system:health')->assertSuccessful();
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('عادی', $sent[1]);
    }

}
