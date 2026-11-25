<?php

namespace Tests\Feature;

use App\Services\DiskPressure;
use App\Services\ExchangeRate;
use App\Services\MaintenanceReceipt;
use App\Services\SystemHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class OperationsHealthTest extends TestCase
{
    use RefreshDatabase;

    private function health(string $key): array
    {
        return collect(app(SystemHealth::class)->checks())->keyBy('key')[$key];
    }

    public function test_disk_pressure_has_proportional_and_absolute_thresholds(): void
    {
        $gib = 1073741824;
        $this->assertSame('ok', DiskPressure::classify(100 * $gib, 20 * $gib)['level']);
        $this->assertSame('warn', DiskPressure::classify(100 * $gib, 14 * $gib)['level']);
        $this->assertSame('critical', DiskPressure::classify(100 * $gib, 4 * $gib)['level']);
        $this->assertSame('ok', DiskPressure::classify(4 * $gib, $gib)['level']);
        $this->assertSame('unknown', DiskPressure::classify(false, false)['level']);
        $this->assertSame('unknown', DiskPressure::classify($gib, 2 * $gib)['level']);
        $this->assertNotSame('unknown', app(DiskPressure::class)->inspect(sys_get_temp_dir().'/missing-directory/subdir')['level']);

        $this->mock(DiskPressure::class, fn ($mock) => $mock->shouldReceive('inspect')->andReturn(DiskPressure::classify(75 * $gib, 6 * $gib)));
        $this->assertSame('warn', $this->health('disk_pressure')['level']);
    }

    public function test_receipt_distinguishes_missing_running_failed_and_stale_evidence(): void
    {
        $receipts = app(MaintenanceReceipt::class);
        $this->assertSame('warn', $this->health('site_gate')['level']);
        $initial = $receipts->start('site_gate');
        $this->assertNotNull($initial);
        $this->assertNull($receipts->start('site_gate'), 'An overlapping invocation must not mask the active one.');
        $this->assertSame('warn', $this->health('site_gate')['level']);
        $this->travel(121)->minutes();
        $this->assertSame('fail', $this->health('site_gate')['level']);
        $retry = $receipts->start('site_gate');
        $this->assertNotNull($retry);
        $receipts->finish('site_gate', $initial, true);
        $this->assertSame('running', $receipts->read('site_gate')['status']);
        $receipts->finish('site_gate', $retry, true, ['peak_memory_bytes' => 1, 'memory_limit_bytes' => 100]);
        $success = $receipts->read('site_gate')['last_success_at'];
        $this->assertSame('ok', $this->health('site_gate')['level']);
        $this->travel(27)->hours();
        $this->assertSame('warn', $this->health('site_gate')['level']);
        $this->travel(10)->hours();
        $this->assertSame('fail', $this->health('site_gate')['level']);
        $receipts->finish('site_gate', $receipts->start('site_gate'), false);
        $this->assertSame($success, $receipts->read('site_gate')['last_success_at']);
        $this->assertSame('failed', $receipts->read('site_gate')['status']);
    }

    public function test_fx_receipt_does_not_hide_stale_procurement_rates(): void
    {
        config(['services.exchange.enabled' => true]);
        $receipts = app(MaintenanceReceipt::class);
        $receipts->finish('fx_refresh', $receipts->start('fx_refresh'), true, ['peak_memory_bytes' => 1, 'memory_limit_bytes' => 100]);
        $at = now()->subHours(13)->toIso8601String();
        $this->mock(ExchangeRate::class, fn ($m) => $m->shouldReceive('current')->andReturn(['at' => $at]));
        $this->assertSame('fail', $this->health('fx_refresh')['level']);
    }

    public function test_domain_receipt_cannot_hide_missing_publication_or_partial_supplier_failure(): void
    {
        config(['services.exchange.enabled' => true, 'domain_suppliers.enabled' => false]);
        Storage::fake('local');
        $receipts = app(MaintenanceReceipt::class);
        $receipts->finish('domain_catalogue', $receipts->start('domain_catalogue'), true, ['count' => 20, 'peak_memory_bytes' => 1, 'memory_limit_bytes' => 100]);
        $this->assertSame('fail', $this->health('domain_catalogue')['level']);
        Storage::disk('local')->put(\App\Services\Domain\TldPriceBook::SNAPSHOT_PATH, '{}');
        $this->assertSame('ok', $this->health('domain_catalogue')['level']);
        $receipts->finish('domain_catalogue', $receipts->start('domain_catalogue'), true, ['count' => 20, 'excluded_accounts' => 1, 'peak_memory_bytes' => 1, 'memory_limit_bytes' => 100]);
        $this->assertSame('warn', $this->health('domain_catalogue')['level']);
    }

    public function test_gate_bulk_renders_bypass_cache_and_only_the_full_scheduled_run_writes_receipt(): void
    {
        config(['app.url' => 'https://servernet.cloud', 'pagecache.enabled' => true, 'hosting.products' => []]);
        $observed = [];
        $this->mock(Kernel::class, function ($mock) use (&$observed) {
            $mock->shouldReceive('handle')->andReturnUsing(function ($request) use (&$observed) {
                $path = '/'.ltrim($request->getPathInfo(), '/');
                $enabled = (bool) config('pagecache.enabled');
                $observed[$path][] = $enabled;
                if ($path === '/sitemap.xml') {
                    return new Response('<urlset><url><loc>https://servernet.cloud/audit-only</loc></url></urlset>', 200, ['Content-Type' => 'text/xml']);
                }
                if (in_array($path, ['/panel-preview', '/share/url', '/sharing/share-offsite'], true)) {
                    return new Response('', 410);
                }
                return new Response('test content', 200, ['Content-Type' => 'text/plain', 'X-Cache' => $enabled ? 'HIT' : 'BYPASS',
                    'Strict-Transport-Security' => 'max-age=1', 'Content-Security-Policy' => 'default-src none',
                    'X-Frame-Options' => 'DENY', 'X-Content-Type-Options' => 'nosniff',
                    'Referrer-Policy' => 'same-origin', 'Permissions-Policy' => 'geolocation=()']);
            });
        });
        $receipts = app(MaintenanceReceipt::class);
        $this->artisan('site:gate', ['--scheduled' => true])->assertSuccessful();
        $this->assertSame([false], $observed['/sitemap.xml']);
        $this->assertSame([false], $observed['/audit-only']);
        $this->assertSame([true, true, false], $observed['/']);
        $this->assertTrue(config('pagecache.enabled'));
        $full = $receipts->read('site_gate');
        $this->assertSame('success', $full['status']);
        $this->travel(2)->minutes();
        $this->artisan('site:gate', ['--limit' => 1])->assertSuccessful();
        $this->assertSame($full, $receipts->read('site_gate'));
        $this->artisan('site:gate', ['--scheduled' => true, '--limit' => 1])->assertFailed();
        $this->assertSame($full, $receipts->read('site_gate'));
        $this->assertCount(1, collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'site:gate --scheduled')));
    }
}
