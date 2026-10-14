<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Services\Cloud\GpuCapacityOrders;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GpuCapacityCommandAuditTest extends TestCase
{
    private string $originalStorage;
    private string $auditStorage;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->originalStorage = storage_path();
        $this->auditStorage = storage_path('framework/testing/gpu-command-'.bin2hex(random_bytes(4)));
        app()->useStoragePath($this->auditStorage);
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStorage);
        (new Filesystem)->deleteDirectory($this->auditStorage);
        parent::tearDown();
    }

    private function receipt(): array
    {
        $files = glob($this->auditStorage.'/logs/gpu-capacity-*.log');
        $this->assertCount(1, $files);
        $lines = file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines);
        $line = end($lines);
        $this->assertStringContainsString('GPU capacity queue run finished', $line);
        return json_decode(substr($line, strpos($line, '{')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_empty_successful_run_leaves_a_timestamped_bounded_audit_without_supplier_calls(): void
    {
        $this->mock(GpuCapacityOrders::class, fn ($mock) => $mock->shouldReceive('candidates')->once()->with(20)->andReturn(collect()));
        $this->artisan('cloud:advance-gpu-capacity')->expectsOutput('[]')->assertSuccessful();
        $r = $this->receipt();
        $this->assertSame('ok', $r['status']);
        $this->assertSame(0, $r['candidates']);
        $this->assertSame([], $r['outcomes']);
        $this->assertNotEmpty($r['started_at']);
        $this->assertNotEmpty($r['finished_at']);
        $this->assertGreaterThanOrEqual(0, $r['duration_ms']);
        Http::assertNothingSent();
    }

    public function test_failed_item_is_visible_returns_failure_and_does_not_stop_the_remaining_batch(): void
    {
        $a = new Service(['id' => 701]); $a->id = 701;
        $b = new Service(['id' => 702]); $b->id = 702;
        $this->mock(GpuCapacityOrders::class, function ($mock) use ($a,$b) {
            $mock->shouldReceive('candidates')->once()->with(20)->andReturn(collect([$a,$b]));
            $mock->shouldReceive('advance')->once()->with($a)->andThrow(new \RuntimeException('secret-test-password-do-not-log'));
            $mock->shouldReceive('advance')->once()->with($b)->andReturn('waiting');
        });
        $this->artisan('cloud:advance-gpu-capacity')->assertFailed();
        $r = $this->receipt();
        $this->assertSame('failed', $r['status']);
        $this->assertSame(2, $r['candidates']);
        $this->assertSame(['error' => 1, 'waiting' => 1], $r['outcomes']);
        $this->assertStringNotContainsString('secret-test-password', json_encode($r));
        $this->assertArrayNotHasKey('service_id', $r);
        Http::assertNothingSent();
    }

    public function test_scan_failure_is_audited_without_leaking_exception_text(): void
    {
        $this->mock(GpuCapacityOrders::class, fn ($mock) => $mock->shouldReceive('candidates')->once()
            ->andThrow(new \RuntimeException('secret-test-database-password')));
        $this->artisan('cloud:advance-gpu-capacity')->assertFailed();
        $r = $this->receipt();
        $this->assertSame('failed', $r['status']);
        $this->assertSame(['error' => 1], $r['outcomes']);
        $this->assertStringNotContainsString('secret-test-database', json_encode($r));
        Http::assertNothingSent();
    }
}
