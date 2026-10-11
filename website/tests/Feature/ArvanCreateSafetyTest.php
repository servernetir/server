<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\ArvanClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArvanCreateSafetyTest extends TestCase
{
    use RefreshDatabase;

    private array $spec = ['name' => 'sn-svc-safety', 'location_ref' => 'ir-tbz-sh1',
        'plan_ref' => 'small', 'image_ref' => 'ubuntu', 'disk_gb' => 30];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::putSecret('arvan_api_token', 'Apikey test');
        Http::preventStrayRequests();
    }

    private function fake(callable $servers): void
    {
        Http::fake(function ($request) use ($servers) {
            if (str_ends_with($request->url(), '/servers')) {
                return $servers($request);
            }
            if (str_contains($request->url(), '/networks')) {
                return Http::response(['data' => [['network_id' => 'public', 'enable_gateway' => true]]]);
            }
            if (str_contains($request->url(), '/securities')) {
                return Http::response(['data' => [['id' => 'default', 'default' => true]]]);
            }
            return Http::response(['data' => []]);
        });
    }

    public function test_failed_inventory_never_posts(): void
    {
        $this->fake(fn () => Http::response([], 503));
        $result = app(ArvanClient::class)->createServer($this->spec);
        $this->assertFalse($result['ok']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseCount('arvan_create_attempts', 0);
    }

    public function test_malformed_inventory_never_posts(): void
    {
        $this->fake(fn () => Http::response(['data' => ['servers' => []]]));
        $this->assertFalse(app(ArvanClient::class)->createServer($this->spec)['ok']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_timeout_then_empty_inventory_never_repeats_post_even_after_cache_flush(): void
    {
        $posts = 0;
        $this->fake(function ($r) use (&$posts) {
            if ($r->method() === 'POST') {
                $posts++;
                $this->assertDatabaseCount('arvan_create_attempts', 1);
                throw new ConnectionException('Response lost after provider created VM');
            }
            return Http::response(['data' => []]);
        });
        $this->assertFalse(app(ArvanClient::class)->createServer($this->spec)['ok']);
        Cache::flush();
        $this->assertFalse((new ArvanClient)->createServer($this->spec)['ok']);
        $this->assertSame(1, $posts);
    }

    public function test_late_visible_server_is_adopted_without_second_post(): void
    {
        $posts = 0;
        $this->fake(function ($r) use (&$posts) {
            if ($r->method() === 'POST') {
                $posts++;
                return Http::response([], 504);
            }
            return Http::response(['data' => $posts === 0 ? [] : [
                ['id' => 'late', 'name' => $this->spec['name'], 'status' => 'ACTIVE'],
            ]]);
        });
        $this->assertFalse(app(ArvanClient::class)->createServer($this->spec)['ok']);
        $result = app(ArvanClient::class)->createServer($this->spec);
        $this->assertTrue($result['ok']);
        $this->assertSame('ir-tbz-sh1:late', $result['ref']);
        $this->assertSame(1, $posts);
    }

    public function test_duplicate_names_are_not_arbitrarily_adopted(): void
    {
        $this->fake(fn () => Http::response(['data' => [
            ['id' => 'one', 'name' => $this->spec['name']],
            ['id' => 'two', 'name' => $this->spec['name']],
        ]]));
        $this->assertFalse(app(ArvanClient::class)->createServer($this->spec)['ok']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_preexisting_inflight_claim_blocks_post(): void
    {
        DB::table('arvan_create_attempts')->insert([
            'operation_key' => hash('sha256', $this->spec['location_ref'].'\0'.$this->spec['name']),
            'region' => $this->spec['location_ref'], 'hostname' => $this->spec['name'], 'created_at' => now(),
        ]);
        $this->fake(fn () => Http::response(['data' => []]));
        $this->assertFalse(app(ArvanClient::class)->createServer($this->spec)['ok']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_success_without_id_is_not_reported_as_delivery_or_retried(): void
    {
        $posts = 0;
        $this->fake(function ($r) use (&$posts) {
            if ($r->method() === 'POST') {
                $posts++;
            }
            return Http::response(['data' => []]);
        });
        $this->assertFalse(app(ArvanClient::class)->createServer($this->spec)['ok']);
        $this->assertFalse(app(ArvanClient::class)->createServer($this->spec)['ok']);
        $this->assertSame(1, $posts);
    }

    public function test_definitive_validation_rejection_releases_claim_for_corrected_retry(): void
    {
        $posts = 0;
        $this->fake(function ($r) use (&$posts) {
            if ($r->method() === 'POST') {
                $posts++;

                return Http::response(['message' => 'invalid image'], 422);
            }

            return Http::response(['data' => []]);
        });

        $this->assertFalse(app(ArvanClient::class)->createServer($this->spec)['ok']);
        $this->assertDatabaseCount('arvan_create_attempts', 0);
        $this->assertFalse(app(ArvanClient::class)->createServer($this->spec)['ok']);
        $this->assertSame(2, $posts);
    }
}
