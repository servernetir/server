<?php

namespace Tests\Feature;

use App\Console\Commands\HermesKnowledgeRefresh;
use App\Console\Commands\HermesOperationsReview;
use App\Services\Bale\Admin\HermesMiniAppStore;
use App\Services\Cloud\CloudManager;
use App\Services\Hermes\HermesKnowledge;
use App\Services\Hermes\HermesOperations;
use App\Services\Hermes\HermesOperationsStore;
use App\Support\ErrorTracker;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/** Tests real command control flow with a captured knowledge parser and isolated encrypted store. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class HermesBackgroundReliabilityTest extends TestCase
{
    private HermesOperations $operations;
    private HermesOperationsStore $store;
    private HermesMiniAppStore $chat;
    private string $directory;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3).'/infra/hermes/';
        require_once $root.'error-reliability-20261028/fixtures/RuntimeDoubles.php';
        require_once $root.'error-reliability-20261028/fixtures/HermesKnowledge.php';
        require_once $root.'support-learning-20261028/HermesOperationsStore.php';
        require_once $root.'error-reliability-20261028/HermesKnowledgeRefresh.php';
        require_once $root.'error-reliability-20261028/HermesOperationsReview.php';
        parent::setUp();
        Http::preventStrayRequests();
        $this->directory = storage_path('framework/testing/hermes-reliability-'.getmypid());
        $this->store = new HermesOperationsStore($this->directory);
        $this->operations = new HermesOperations();
        $this->chat = new HermesMiniAppStore();
        $this->app->instance(HermesOperations::class, $this->operations);
        $this->app->instance(HermesOperationsStore::class, $this->store);
        $this->app->instance(HermesMiniAppStore::class, $this->chat);
        $this->mock(CloudManager::class)->shouldReceive('configured')->andReturn(['hetzner' => new \stdClass(), 'ovh' => new \stdClass()]);
        $this->app->make(Kernel::class)->registerCommand(new HermesKnowledgeRefresh());
        $this->app->make(Kernel::class)->registerCommand(new HermesOperationsReview());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function feeds(?string $bad = null, string $kind = 'http'): void
    {
        $responses = [];
        foreach (HermesKnowledge::SOURCES as $key => $source) {
            if ($key === $bad) {
                $responses[$source['url']] = match ($kind) {
                    'connection' => Http::failedConnection('private-diagnostic-marker'),
                    'xml' => Http::response('not an XML feed', 200),
                    default => Http::response('private-diagnostic-marker', 403),
                };
                continue;
            }
            $xml = '<rss><channel><item><title>Release</title><link>https://'.$source['host'].'/release</link><pubDate>'
                .gmdate('r').'</pubDate></item></channel></rss>';
            $responses[$source['url']] = Http::response($xml, 200);
        }
        Http::fake($responses);
    }

    private function failure(): array
    {
        $rows = ErrorTracker::recent(50);
        $this->assertNotEmpty($rows);
        $this->assertStringNotContainsString('private-diagnostic-marker', json_encode($rows));
        return $rows[0];
    }

    public function test_feed_http_failure_keeps_last_good_knowledge_and_records_safe_cause(): void
    {
        $this->store->change($this->operations->owner(), function (&$state) {
            $state['knowledge']['sources']['plesk'] = ['status' => 'unavailable', 'checked_at' => time() - 4000, 'last_success_at' => 12345];
            $state['knowledge']['items']['retained'] = ['source' => 'plesk', 'title' => 'Retained reference', 'published_at' => time() - 1000];
        });
        $this->feeds('plesk');
        $this->artisan('hermes:knowledge-refresh')->assertFailed();
        $saved = $this->store->read($this->operations->owner())['knowledge'];
        $this->assertSame(12345, $saved['sources']['plesk']['last_success_at']);
        $this->assertSame('http_error', $saved['sources']['plesk']['failure']['reason']);
        $this->assertSame(403, $saved['sources']['plesk']['failure']['http_status']);
        $this->assertArrayHasKey('retained', $saved['items']);
        $this->assertSame('plesk', $this->failure()['ctx']['source']);
    }

    public function test_connection_failure_exposes_no_exception_message(): void
    {
        $this->feeds('cpanel', 'connection');
        $this->artisan('hermes:knowledge-refresh')->assertFailed();
        $this->assertSame('connection_error', $this->failure()['ctx']['reason']);
        $this->assertNull($this->failure()['ctx']['http_status']);
    }

    public function test_invalid_xml_is_distinct_from_network_or_http_failure(): void
    {
        $this->feeds('php', 'xml');
        $this->artisan('hermes:knowledge-refresh')->assertFailed();
        $this->assertSame('invalid_feed', $this->failure()['ctx']['reason']);
        $this->assertSame(200, $this->failure()['ctx']['http_status']);
    }

    public function test_internal_memory_failure_is_attributed_without_any_feed_request(): void
    {
        $this->chat->fail = true;
        $this->artisan('hermes:knowledge-refresh')->assertFailed();
        $this->assertSame('owner_memory', $this->failure()['ctx']['stage']);
        Http::assertNothingSent();
    }

    public function test_successful_feeds_persist_ok_and_create_no_failure_event(): void
    {
        $this->feeds();
        $this->artisan('hermes:knowledge-refresh')->expectsOutputToContain('"feeds_ok":6')->assertSuccessful();
        $state = $this->store->read($this->operations->owner());
        $this->assertCount(6, array_filter($state['knowledge']['sources'], fn ($source) => $source['status'] === 'ok'));
        $this->assertEmpty(ErrorTracker::recent(50));
    }

    public function test_partial_inventory_is_never_recorded_as_healthy(): void
    {
        $this->operations->scans['ovh'] = ['status' => 'unavailable'];
        $this->artisan('hermes:operations')->expectsOutputToContain('"status":"partial"')->assertFailed();
        $this->assertSame([false], $this->operations->health);
        $this->assertSame('ovh', $this->failure()['ctx']['provider']);
        Http::assertNothingSent();
    }

    public function test_inventory_exception_is_safe_and_marks_the_run_unhealthy(): void
    {
        $this->operations->scans['hetzner'] = new \RuntimeException('private-diagnostic-marker');
        $this->artisan('hermes:operations')->assertFailed();
        $this->assertSame([false], $this->operations->health);
        $this->assertSame(\RuntimeException::class, $this->failure()['ctx']['exception_class']);
    }

    public function test_complete_inventory_and_real_sample_contract_record_health_success(): void
    {
        $this->artisan('hermes:operations')->expectsOutputToContain('"status":"complete"')->assertSuccessful();
        $this->assertSame([true], $this->operations->health);
        $this->assertEmpty(ErrorTracker::recent(50));
        Http::assertNothingSent();
    }

    public function test_unavailable_sample_is_not_relabelled_as_complete_inventory_health(): void
    {
        $this->operations->sample = ['status' => 'disabled'];
        $this->artisan('hermes:operations')->assertFailed();
        $this->assertSame([false], $this->operations->health);
        $this->assertSame('service_sample', $this->failure()['ctx']['stage']);
    }

    public function test_sampling_failure_is_attributed_and_preserves_negative_health(): void
    {
        $this->operations->sampleError = new \RuntimeException('private-diagnostic-marker');
        $this->artisan('hermes:operations')->assertFailed();
        $this->assertSame([false], $this->operations->health);
        $this->assertSame('service_sample', $this->failure()['ctx']['stage']);
    }

    public function test_busy_command_never_releases_another_workers_lock(): void
    {
        $lock = Cache::lock('hermes:knowledge-refresh', 600);
        $this->assertTrue($lock->get());
        $this->artisan('hermes:knowledge-refresh')->expectsOutput('busy')->assertSuccessful();
        $this->assertFalse(Cache::lock('hermes:knowledge-refresh', 600)->get());
        $lock->release();
        Http::assertNothingSent();
    }

    public function test_disabled_commands_do_not_scan_send_or_fetch(): void
    {
        $this->operations->active = false;
        $this->artisan('hermes:knowledge-refresh')->assertSuccessful();
        $this->artisan('hermes:operations')->assertSuccessful();
        $this->assertSame([], $this->operations->health);
        $this->assertSame(0, $this->operations->digestCalls);
        $this->assertDirectoryDoesNotExist($this->directory);
        Http::assertNothingSent();
    }
}
