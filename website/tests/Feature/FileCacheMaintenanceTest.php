<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Notify\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class FileCacheMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheRoot = storage_path('framework/testing/cache-prune-'.Str::uuid());
        File::ensureDirectoryExists($this->cacheRoot);
        config([
            'cache.stores.file.path' => $this->cacheRoot,
            'cache.file_prune.state_path' => $this->cacheRoot.'.lock',
            'cache.file_prune.alert_size_mb' => 10000,
            'cache.file_prune.alert_growth_mb' => 10000,
            'cache.file_prune.alert_disk_percent' => 101,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cacheRoot);
        File::delete($this->cacheRoot.'.lock');
        parent::tearDown();
    }

    private function entry(string $key, string $header, string $payload = 's:4:"test";'): string
    {
        $hash = sha1($key);
        $dir = $this->cacheRoot.'/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2);
        File::ensureDirectoryExists($dir);
        $path = $dir.'/'.$hash;
        file_put_contents($path, $header.$payload);

        return $path;
    }

    public function test_prune_preserves_live_forever_recent_and_unrecognised_entries(): void
    {
        $expired = $this->entry('expired', (string) (time() - 600));
        $live = $this->entry('live', (string) (time() + 3600));
        $recent = $this->entry('recent', (string) (time() - 30));
        $forever = $this->entry('forever', '9999999999');
        $unknown = $this->entry('unknown', 'notatime10');
        $wrong = dirname($expired).'/unrelated.txt';
        file_put_contents($wrong, 'unrelated');
        $busy = $this->entry('busy', (string) (time() - 900));
        $lock = fopen($busy, 'rb');
        flock($lock, LOCK_EX | LOCK_NB);

        try {
            $this->mock(AdminNotifier::class, fn ($mock) => $mock->shouldNotReceive('baleEvent'));
            $this->artisan('cache:prune-expired-file', ['--dry-run' => true])->assertExitCode(0);
            $this->assertFileExists($expired);
            $this->assertNull(app(\App\Services\MaintenanceReceipt::class)->read('file_cache_prune'));
            $this->artisan('cache:prune-expired-file')->assertExitCode(0);
            $this->assertFileDoesNotExist($expired);
            $receipt = app(\App\Services\MaintenanceReceipt::class)->read('file_cache_prune');
            $this->assertSame('success', $receipt['status']);
            $this->assertSame(1, $receipt['metrics']['expired']);
            foreach ([$live, $recent, $forever, $unknown, $wrong, $busy] as $path) {
                $this->assertFileExists($path);
            }
        } finally {
            fclose($lock);
        }
    }

    public function test_high_cache_volume_uses_the_existing_bale_route(): void
    {
        config(['cache.file_prune.alert_size_mb' => 1]);
        $expired = $this->entry('large-expired', (string) (time() - 600), str_repeat('x', 2 * 1048576));
        $this->mock(AdminNotifier::class, function ($mock): void {
            $mock->shouldReceive('baleEvent')->once()->withArgs(fn ($title, $rows, $url) =>
                str_contains($title, 'Cache') && isset($rows['حجم پاک‌شده']) && str_contains($url, '/admin/errors'));
        });
        $this->artisan('cache:prune-expired-file')->assertExitCode(0);
        $this->assertFileDoesNotExist($expired);
    }

    public function test_cloud_plan_invalidation_only_on_real_changes(): void
    {
        CloudLocation::create(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);
        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cache-test', 'location_code' => 'de-frankfurt',
            'public_name' => 'CV-2-4', 'slug' => 'cv-cache-test',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 1440000,
            'is_active' => true, 'in_stock' => true,
        ]);
        $generation = Cache::get('page:gen');
        $this->assertGreaterThan(0, $generation);
        $plan->save();
        $plan->update(['synced_at' => now()->addMinute()]);
        $this->assertSame($generation, Cache::get('page:gen'));

        $plan->update(['price_irt' => 1500000]);
        $this->assertSame($generation + 1, Cache::get('page:gen'));
        $plan->save();
        $this->assertSame($generation + 1, Cache::get('page:gen'));
        $plan->update(['in_stock' => false]);
        $this->assertSame($generation + 2, Cache::get('page:gen'));
    }
}
