<?php

namespace Tests\Feature;

use App\Services\Cloud\GpuCapacityOrders;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GpuCapacityScheduleTest extends TestCase
{
    private function scheduled(string $command): Event
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => preg_match('/(?:^|\s)'.preg_quote($command, '/').'(?:\s|$)/', (string) $event->command));
        $this->assertCount(1, $events, $command.' must be scheduled exactly once');
        return $events->first();
    }

    public function test_capacity_progress_is_scheduled_every_minute_alongside_existing_delivery_jobs(): void
    {
        Http::preventStrayRequests();
        $this->assertArrayHasKey('cloud:advance-gpu-capacity', Artisan::all());
        foreach (['cloud:advance-gpu-capacity', 'cloud:recover-gpu-delivery', 'provision:run'] as $command) {
            $event = $this->scheduled($command);
            $this->assertSame('* * * * *', $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame(15, $event->expiresAt);
        }
        Http::assertNothingSent();
    }

    public function test_capacity_event_lock_prevents_parallel_dispatch_and_expires(): void
    {
        $event = $this->scheduled('cloud:advance-gpu-capacity');
        $event->mutex->forget($event);
        try {
            $this->assertTrue($event->mutex->create($event));
            $this->assertTrue($event->shouldSkipDueToOverlapping());
            $this->assertFalse($event->mutex->create($event));
            $this->travel(16)->minutes();
            $this->assertTrue($event->mutex->create($event));
        } finally {
            $event->mutex->forget($event);
            $this->travelBack();
        }
    }

    public function test_command_handles_empty_queue_without_supplier_calls(): void
    {
        Http::preventStrayRequests();
        $this->mock(GpuCapacityOrders::class, function ($mock) {
            $mock->shouldReceive('candidates')->once()->with(20)->andReturn(collect());
            $mock->shouldNotReceive('advance');
        });
        $this->artisan('cloud:advance-gpu-capacity')->expectsOutput('[]')->assertSuccessful();
        Http::assertNothingSent();
    }
}
