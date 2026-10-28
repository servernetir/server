<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Cloud\CatalogRefreshBusy;
use App\Services\Cloud\CatalogRefreshRequests;
use App\Services\Cloud\CloudCatalogSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class CatalogRefreshRequestsTest extends TestCase
{
    use RefreshDatabase;

    private CatalogRefreshRequests $requests;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        NavasanRates::store(['USD' => 100000, 'EUR' => 100000, 'USDT' => 100000]);
        $this->directory = storage_path('framework/testing/catalog-'.getmypid().'-'.bin2hex(random_bytes(4)));
        $this->requests = new CatalogRefreshRequests($this->directory);
        $this->app->instance(CatalogRefreshRequests::class, $this->requests);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function admin(string $role = 'admin'): User
    {
        return User::create(['name' => 'Catalog admin', 'email' => bin2hex(random_bytes(5)).'@example.invalid',
            'password' => bcrypt('test-password'), 'role' => $role]);
    }

    private function report(array $extra = []): array
    {
        return $extra + ['ok' => true, 'rate' => 100000, 'providers' => [
            'hetzner' => ['ok' => true, 'plans' => 7, 'locations' => 1, 'images' => 2],
        ]];
    }

    public function test_admin_post_only_enqueues_and_duplicate_clicks_keep_the_same_work(): void
    {
        $this->mock(CloudCatalogSync::class)->shouldNotReceive('sync', 'reprice');
        $this->actingAs($this->admin())->from('/admin/cloud')->post('/admin/cloud/sync')
            ->assertStatus(303)->assertRedirect('/admin/cloud')->assertSessionHas('ok');
        $first = $this->requests->snapshot();
        $this->post('/admin/cloud/sync', ['prices_only' => 1])
            ->assertStatus(303)->assertRedirect('/admin/cloud');
        $this->assertSame($first, $this->requests->snapshot());
        $this->assertSame('queued', $first['state']);
        $this->assertFalse($first['prices_only']);
        Http::assertNothingSent();
    }

    public function test_get_sync_url_recovers_to_catalogue_without_starting_provider_work(): void
    {
        $this->mock(CloudCatalogSync::class)->shouldNotReceive('sync', 'reprice');

        $this->actingAs($this->admin())->get('/admin/cloud/sync')
            ->assertStatus(303)
            ->assertRedirect('/admin/cloud')
            ->assertSessionHas('err');

        $this->assertNull($this->requests->snapshot());
        $this->assertDirectoryDoesNotExist($this->directory);
        Http::assertNothingSent();
    }

    public function test_anonymous_and_staff_cannot_queue_catalog_work(): void
    {
        $this->post('/admin/cloud/sync')->assertRedirect();
        $this->actingAs($this->admin('staff'))->post('/admin/cloud/sync')->assertForbidden();
        $this->assertNull($this->requests->snapshot());
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function test_worker_persists_completion_and_never_replays_a_finished_request(): void
    {
        $queued = $this->requests->request(false, 1);
        $sync = $this->mock(CloudCatalogSync::class);
        $sync->shouldReceive('sync')->once()->andReturnUsing(function () use ($queued) {
            $this->assertSame('running', $this->requests->snapshot()['state']);
            $this->assertSame($queued['id'], $this->requests->request(true, 2)['id']);
            return $this->report();
        });
        $this->artisan('cloud:refresh-requested')->assertSuccessful();
        $finished = $this->requests->snapshot();
        $this->assertSame('completed', $finished['state']);
        $this->assertSame(7, $finished['result']['plans']);
        $this->assertNotEmpty($finished['finished_at']);
        $this->artisan('cloud:refresh-requested')->assertSuccessful();
        $this->assertSame($finished, $this->requests->snapshot());
    }

    public function test_repricing_uses_the_same_background_receipt(): void
    {
        $this->requests->request(true, 1);
        $this->mock(CloudCatalogSync::class)->shouldReceive('reprice')->once()->andReturn(9);
        $this->artisan('cloud:refresh-requested')->assertSuccessful();
        $this->assertSame(9, $this->requests->snapshot()['result']['plans']);
    }

    public function test_partial_provider_failure_is_not_reported_as_full_success(): void
    {
        $report = $this->report();
        $report['providers']['arvan'] = ['ok' => false, 'plans' => 0, 'message' => 'private supplier detail'];
        $report['providers']['__cost'] = 'Existing services need cost review.';
        $this->requests->request(false, 1);
        $this->mock(CloudCatalogSync::class)->shouldReceive('sync')->once()->andReturn($report);
        $this->artisan('cloud:refresh-requested')->assertFailed();
        $state = $this->requests->snapshot();
        $this->assertSame('partial', $state['state']);
        $this->assertSame(['arvan'], $state['result']['failed_providers']);
        $this->assertContains('Existing services need cost review.', $state['result']['warnings']);
        $this->assertStringNotContainsString('private supplier detail', json_encode($state));
        $this->artisan('cloud:refresh-requested')->assertSuccessful();
    }

    public function test_exception_is_receipted_without_secret_or_automatic_retry(): void
    {
        $this->requests->request(false, 1);
        $this->mock(CloudCatalogSync::class)->shouldReceive('sync')->once()
            ->andThrow(new \RuntimeException('secret-from-supplier'));
        $this->artisan('cloud:refresh-requested')->assertFailed();
        $state = $this->requests->snapshot();
        $this->assertSame('failed', $state['state']);
        $this->assertSame('exception', $state['result']['reason']);
        $this->assertStringNotContainsString('secret-from-supplier', json_encode($state));
        $this->artisan('cloud:refresh-requested')->assertSuccessful();
        $this->assertNotSame($state['id'], $this->requests->request(false, 1)['id']);
    }

    public function test_interrupted_worker_requires_an_explicit_new_request(): void
    {
        $state = $this->requests->request(false, 1);
        $state['state'] = 'running';
        file_put_contents($this->directory.'/state.json', json_encode($state));
        $this->mock(CloudCatalogSync::class)->shouldNotReceive('sync', 'reprice');
        $this->artisan('cloud:refresh-requested')->assertFailed();
        $this->assertSame('interrupted', $this->requests->snapshot()['result']['reason']);
        $this->artisan('cloud:refresh-requested')->assertSuccessful();
    }

    public function test_stale_queue_expires_without_supplier_calls(): void
    {
        $this->requests->request(false, 1);
        $this->travel(16)->minutes();
        $this->mock(CloudCatalogSync::class)->shouldNotReceive('sync', 'reprice');
        $this->artisan('cloud:refresh-requested')->assertFailed();
        $this->assertSame('expired', $this->requests->snapshot()['result']['reason']);
        $this->artisan('cloud:refresh-requested')->assertSuccessful();
    }

    public function test_shared_catalog_lock_protects_real_sync_and_reprice_entrypoints(): void
    {
        $this->requests->request(false, 1);
        $lock = fopen($this->directory.'/catalog.lock', 'c+b');
        flock($lock, LOCK_EX);
        try {
            foreach (['sync', 'reprice'] as $method) {
                try {
                    app(CloudCatalogSync::class)->{$method}();
                    $this->fail('The concurrent catalog operation must be refused.');
                } catch (CatalogRefreshBusy) {
                    $this->addToAssertionCount(1);
                }
            }
            $this->artisan('cloud:refresh-requested')->assertSuccessful();
            $this->assertSame('queued', $this->requests->snapshot()['state']);
        } finally {
            fclose($lock);
        }
        Http::assertNothingSent();
    }

    public function test_scheduler_processes_requested_catalog_work_in_background(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'cloud:refresh-requested'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->runInBackground);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_missing_exchange_rate_is_not_a_successful_zero_plan_reprice(): void
    {
        $this->requests->request(true, 1);
        NavasanRates::store(['USD' => 100000, 'EUR' => 0, 'USDT' => 100000]);
        $this->mock(CloudCatalogSync::class)->shouldNotReceive('reprice');
        $this->artisan('cloud:refresh-requested')->assertFailed();
        $this->assertSame('pricing_unavailable', $this->requests->snapshot()['result']['reason']);
    }

    public function test_corrupt_receipt_is_visible_and_is_not_silently_overwritten(): void
    {
        $this->requests->request(false, 1);
        file_put_contents($this->directory.'/state.json', '{broken');
        $this->assertSame('unavailable', $this->requests->snapshot()['state']);
        try {
            $this->requests->request(false, 1);
            $this->fail('Corrupt work state must be reviewed before a new request.');
        } catch (\JsonException) {
            $this->assertSame('{broken', file_get_contents($this->directory.'/state.json'));
        }
    }

    public function test_pending_and_completed_receipts_render_in_admin_panel(): void
    {
        $this->actingAs($this->admin());
        $queued = $this->requests->request(false, 1);
        $this->get('/admin/cloud')->assertOk()->assertSee('در صف اجرای پس‌زمینه')->assertSee($queued['id']);
        $this->mock(CloudCatalogSync::class)->shouldReceive('sync')->once()->andReturn($this->report());
        $this->artisan('cloud:refresh-requested')->assertSuccessful();
        $this->get('/admin/cloud')->assertOk()->assertSee('تکمیل شد')->assertSee('تعداد پلن‌های پردازش‌شده');
    }

    public function test_stalled_or_failed_work_is_visible_in_error_page_health(): void
    {
        $this->requests->request(false, 1);
        $health = fn () => collect(app(\App\Services\SystemHealth::class)->checks())->keyBy('key')['catalogue_refresh'];
        $this->assertTrue($health()['ok']);
        $this->travel(16)->minutes();
        $this->assertFalse($health()['ok']);
        $this->assertSame('warn', $health()['level']);
        $this->mock(CloudCatalogSync::class)->shouldNotReceive('sync');
        $this->artisan('cloud:refresh-requested')->assertFailed();
        $this->assertFalse($health()['ok']);
        $this->actingAs($this->admin())->get('/admin/errors')->assertOk()
            ->assertSee('همگام‌سازی درخواستی کاتالوگ')->assertSee('نیازمند بررسی');
    }
}
