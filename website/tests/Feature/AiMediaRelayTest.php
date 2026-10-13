<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Services\Ai\AiMediaRelay;
use App\Services\Ai\AiNative;
use App\Services\Ai\AiVideoInput;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiMediaRelayTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    private string $originalStorage;
    private string $testStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->testStorage = sys_get_temp_dir().'/ai-relay-test-'.bin2hex(random_bytes(8));
        mkdir($this->testStorage, 0700);
        $this->app->useStoragePath($this->testStorage);
        foreach (['logs', 'framework/views', 'framework/sessions'] as $directory) mkdir(storage_path($directory), 0700, true);
        if ($binary = getenv('AI_TEST_FFMPEG_BINARY')) config(['ai.media_ffmpeg_binary' => $binary]);
        $this->sellableCatalog(['fx_fee_bp' => 0], cached: false);
        \App\Models\Setting::put('ai_margin_pct', '5');
        $contracts = json_decode(file_get_contents(resource_path('content/ai-native-contracts.json')), true);
        $this->model->update(['upstream_model' => 'Bria/video_increase_resolution', 'category' => 'video',
            'capabilities' => ['native_tariff_hash' => $contracts['deepinfra:Bria/video_increase_resolution']['tariff_hash']]]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->app->useStoragePath($this->originalStorage);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->testStorage,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isLink() || ! $file->isDir()) unlink($file->getPathname());
            else rmdir($file->getPathname());
        }
        rmdir($this->testStorage);
        parent::tearDown();
    }

    private function bytes(): string
    {
        return file_get_contents(base_path('tests/Fixtures/ai/one-second-h264.mp4'));
    }

    private function payload(): array
    {
        return ['video' => 'data:video/mp4;base64,'.base64_encode($this->bytes()), 'desired_increase' => 2];
    }

    private function files(): array
    {
        return glob(storage_path('app/private/ai-media/relay/*.mp4')) ?: [];
    }

    private function publish(): array
    {
        if (! AiVideoInput::available()) $this->markTestSkipped('Trusted decoder is required for relay input.');
        return app(AiMediaRelay::class)->preparePayload($this->payload());
    }

    public function test_signed_supplier_get_head_and_bounded_byte_ranges_match_private_validated_bytes(): void
    {
        $published = $this->publish();
        $this->assertSame(2, $published['desired_increase']);
        $this->assertStringStartsWith('https://servernet.cloud/api/ai-media/', $published['video']);
        $this->assertSame(1, count($this->files()));
        $this->assertSame(0600, fileperms($this->files()[0]) & 0777);
        $this->assertSame(0700, fileperms(dirname($this->files()[0])) & 0777);
        $this->get($published['video'])->assertOk()->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeaderMissing('Set-Cookie')
            ->assertStreamedContent($this->bytes());
        $this->head($published['video'])->assertOk()->assertHeader('Content-Length', (string) strlen($this->bytes()))
            ->assertContent('')->assertHeaderMissing('Set-Cookie');
        $this->get($published['video'], ['Range' => 'bytes=10-39'])->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 10-39/'.strlen($this->bytes()))->assertStreamedContent(substr($this->bytes(), 10, 30));
        $this->get($published['video'], ['Range' => 'bytes=-20'])->assertStatus(206)->assertStreamedContent(substr($this->bytes(), -20));
        $this->get($published['video'], ['Range' => 'bytes=999999-'])->assertStatus(416);
        $this->get($published['video'], ['Range' => 'bytes=0-1,3-4'])->assertStatus(416);
    }

    public function test_signature_tampering_expiry_traversal_and_missing_file_are_rejected(): void
    {
        $url = $this->publish()['video'];
        $this->get($url.'&extra=1')->assertStatus(403);
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=invalid', $url))->assertStatus(403);
        $missing = \Illuminate\Support\Facades\URL::temporarySignedRoute('ai.media.relay', now()->addSeconds(60),
            ['id' => str_repeat('f', 64)], absolute: false);
        $this->get($missing)->assertStatus(403);
        $this->get(str_replace('/api/ai-media/', '/api/ai-media/../', $url))->assertStatus(404);
        $this->travel(AiMediaRelay::TTL_SECONDS + 1)->seconds();
        $this->get($url)->assertStatus(403);
        $this->assertSame(1, app(AiMediaRelay::class)->cleanupExpired());
        $this->assertSame([], $this->files());
    }

    public function test_remote_corrupt_and_invalid_second_inputs_never_create_relay_files_or_supplier_calls(): void
    {
        if (! AiVideoInput::available()) $this->markTestSkipped('Trusted decoder is required.');
        Http::fake();
        foreach ([['video' => 'http://127.0.0.1/private'], ['video' => 'file:///etc/passwd'],
            ['video' => 'data:video/mp4;base64,'.base64_encode('not-mp4')],
            $this->payload() + ['mask' => 'https://supplier.example.test/private.mp4']] as $payload) {
            try { app(AiMediaRelay::class)->preparePayload($payload); $this->fail('Invalid relay media admitted.'); }
            catch (\RuntimeException|\App\Services\Ai\AiRequestException) { $this->assertSame([], $this->files()); }
        }
        Http::assertNothingSent();
    }

    public function test_cleanup_only_removes_exact_expired_relay_files_and_open_reader_survives_cleanup(): void
    {
        $url = $this->publish()['video'];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $id = basename(parse_url($url, PHP_URL_PATH));
        $opened = app(AiMediaRelay::class)->open($id, (int) $query['expires']);
        $directory = dirname($this->files()[0]);
        file_put_contents($directory.'/unrelated.mp4', 'preserve');
        mkdir($directory.'/unrelated-directory');
        $link = $directory.'/'.($query['expires'] - 1000).'-'.str_repeat('a', 64).'.mp4';
        symlink($directory.'/unrelated.mp4', $link);
        $this->assertSame(0, app(AiMediaRelay::class)->cleanupExpired());
        $this->travel(AiMediaRelay::TTL_SECONDS + 1)->seconds();
        $this->assertSame(1, app(AiMediaRelay::class)->cleanupExpired());
        $this->assertSame($this->bytes(), stream_get_contents($opened['handle']));
        fclose($opened['handle']);
        $this->assertFileExists($directory.'/unrelated.mp4');
        $this->assertTrue(is_link($link));
        $this->assertDirectoryExists($directory.'/unrelated-directory');
    }

    public function test_symlink_destination_is_rejected_without_writing_outside_private_root(): void
    {
        if (! AiVideoInput::available()) $this->markTestSkipped('Trusted decoder is required.');
        mkdir(storage_path('app/private/ai-media'), 0700, true);
        $outside = storage_path('outside'); mkdir($outside);
        symlink($outside, storage_path('app/private/ai-media/relay'));
        try { app(AiMediaRelay::class)->preparePayload($this->payload()); $this->fail('Symlink relay root admitted.'); }
        catch (\RuntimeException) { $this->assertSame([], glob($outside.'/*')); }
    }

    public function test_existing_replay_prune_command_also_removes_expired_supplier_media(): void
    {
        $this->publish();
        $this->travel(AiMediaRelay::TTL_SECONDS + 1)->seconds();
        $this->artisan('ai:prune-replay')->expectsOutput('Expired supplier media files erased: 1')->assertExitCode(0);
        $this->assertSame([], $this->files());
    }

    public function test_reservation_precedes_publication_and_replay_does_not_publish_or_spend_again(): void
    {
        if (! AiVideoInput::available()) $this->markTestSkipped('Trusted decoder is required.');
        [$customer, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:native']]);
        $prepared = app(AiNative::class)->prepare($this->payload(), $this->model);
        $this->assertSame(100000, $prepared->native['max_cost_micro']);
        $this->assertSame([], $this->files());
        Http::fake(function ($request) {
            $this->assertSame('sending', AiUsage::sole()->status);
            $this->assertNotNull(AiUsage::sole()->ai_reservation_id);
            $this->assertSame(1, count($this->files()));
            $this->assertStringStartsWith('https://servernet.cloud/api/ai-media/', $request['video']);
            $this->assertArrayNotHasKey('model', $request->data());
            return Http::response(['status' => 'COMPLETED', 'video_url' => 'https://media.example.test/output.mp4',
                'mask_url' => '', 'inference_status' => ['status' => 'succeeded', 'cost' => 0.05, 'runtime_ms' => 1000]]);
        });
        $body = ['model' => $this->model->slug] + $this->payload();
        $accepted = $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Idempotency-Key' => 'relay-once'])
            ->postJson('/v1/inference', $body)->assertStatus(202);
        $usage = AiUsage::sole();
        $this->assertSame('settled', $usage->status, 'Settlement failure: '.$usage->error_code.'; HTTP calls: '.Http::recorded()->count());
        $this->withHeaders(['Authorization' => 'Bearer '.$key])->getJson('/v1/inference/'.$accepted->json('id'))->assertOk();
        $this->assertSame(50000, (int) $usage->provider_cost_micro);
        $this->assertSame(5250, (int) $usage->sell_irt);
        $this->assertSame(500, (int) $usage->margin_bp);
        $this->assertSame($prepared->sha256, $usage->request_sha256);
        $this->assertSame($prepared->native['max_cost_micro'], $usage->native_snapshot['max_cost_micro']);
        $balance = $this->balance($customer);
        $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Idempotency-Key' => 'relay-once'])->postJson('/v1/inference', $body)
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($balance, $this->balance($customer));
        $this->assertSame(1, count($this->files()));
        Http::assertSentCount(1);
    }

    public function test_admission_and_failed_relay_release_never_spend_supplier_funds(): void
    {
        if (! AiVideoInput::available()) $this->markTestSkipped('Trusted decoder is required.');
        [, $emptyKey] = $this->greenKey(0, tokenOver: ['abilities' => ['ai:native']]);
        Http::fake();
        $body = ['model' => $this->model->slug] + $this->payload();
        $this->withHeaders(['Authorization' => 'Bearer '.$emptyKey])->postJson('/v1/inference', $body)->assertStatus(402);
        $this->assertSame([], $this->files());
        [$customer, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:native']]);
        mkdir(storage_path('app/private/ai-media/relay'), 0700, true);
        symlink(storage_path('logs/lock'), storage_path('app/private/ai-media/relay/.lock'));
        $accepted = $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/v1/inference', $body)->assertStatus(202);
        $this->withHeaders(['Authorization' => 'Bearer '.$key])->getJson('/v1/inference/'.$accepted->json('id'))->assertStatus(409)
            ->assertJsonPath('code', 'media_relay_unavailable');
        $this->assertSame('released', AiUsage::sole()->status);
        $this->assertSame(1000000, $this->balance($customer));
        $this->assertSame(1000000, $this->available($customer));
        Http::assertNothingSent();
    }

    public function test_application_error_tracking_redacts_relay_id_query_and_referrer(): void
    {
        $request = Request::create('https://servernet.cloud/api/ai-media/'.str_repeat('a', 64).'?expires=1791729999&signature=SECRET');
        $request->headers->set('Referer', $request->fullUrl());
        ErrorTracker::notFound($request);
        ErrorTracker::exception(new \RuntimeException('Read failed.'), $request);
        foreach ([ErrorTracker::recent(1, 'notfound'), ErrorTracker::recent(1)] as $rows) {
            $this->assertSame('https://servernet.cloud/api/ai-media/[redacted]', $rows[0]['url']);
            $this->assertSame('[redacted supplier media]', $rows[0]['referer']);
            $this->assertStringNotContainsString('SECRET', json_encode($rows));
            $this->assertStringNotContainsString(str_repeat('a', 64), json_encode($rows));
        }
    }
}
