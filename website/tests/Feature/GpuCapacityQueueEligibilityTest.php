<?php
namespace Tests\Feature;
use App\Services\Cloud\GpuCapacityOrders;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GpuCapacityQueueEligibilityTest extends TestCase
{
    public function test_closed_backlog_cannot_starve_live_orders_and_pending_stops_are_retained(): void
    {
        $orders = app(GpuCapacityOrders::class);
        $original = DB::getDefaultConnection();
        config(['database.connections.gpu_queue_regression' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('gpu_queue_regression');
        try {
            DB::connection()->getSchemaBuilder()->create('services', function (Blueprint $table) {
                $table->id();
                $table->string('status');
                $table->string('provision_status')->nullable();
                $table->text('provision_meta');
                $table->timestamp('updated_at')->nullable();
            });
            $meta = fn(array $extra = []) => json_encode(['gpu_capacity_order' => array_merge([
                'version' => 1, 'charge_pending' => true,
            ], $extra)]);
            for ($id = 1; $id <= 25; $id++) {
                DB::table('services')->insert(['id' => $id, 'status' => 'cancelled',
                    'provision_status' => 'none', 'provision_meta' => $meta(),
                    'updated_at' => now()->subDays(2)]);
            }
            DB::table('services')->insert([
                ['id' => 26, 'status' => 'awaiting_provision', 'provision_status' => 'gpu_wait',
                    'provision_meta' => $meta(), 'updated_at' => now()],
                ['id' => 27, 'status' => 'cancelled', 'provision_status' => 'none',
                    'provision_meta' => $meta(['charge_pending' => false, 'stop_pending' => true]),
                    'updated_at' => now()->subDay()],
            ]);
            $this->assertSame([27, 26], $orders->candidates(20)->pluck('id')->all());
        } finally {
            DB::disconnect('gpu_queue_regression');
            DB::setDefaultConnection($original);
        }
    }
}
