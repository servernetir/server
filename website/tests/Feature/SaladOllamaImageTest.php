<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\CloudImage;
use App\Services\Cloud\SaladOllamaImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaladOllamaImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_deliveries_pin_the_approved_official_image_without_network_calls(): void
    {
        Http::fake();
        Setting::put(SaladOllamaImage::SETTING, '0.24.0');
        $image = app(SaladOllamaImage::class);
        $this->assertSame('ollama/ollama:0.40.17', $image->ref());
        $this->assertSame('0.40.17', $image->refresh());
        $this->assertSame('0.40.17', Setting::get(SaladOllamaImage::SETTING));
        Http::assertNothingSent();
    }

    public function test_catalog_refresh_does_not_silently_ship_an_untested_release(): void
    {
        Http::fake();
        Setting::put(SaladOllamaImage::SETTING, '9.99.9');
        $image = app(SaladOllamaImage::class);
        $this->assertSame('ollama/ollama:0.40.17', $image->ref());
        $this->assertSame('0.40.17', $image->refresh());
        Http::assertNothingSent();
    }

    public function test_saved_legacy_and_official_ollama_references_are_recognized(): void
    {
        foreach (['ollama/ollama:0.40.17', 'ollama/ollama@sha256:abc',
            'saladtechnologies/ollama:0.24.0',
            'saladtechnologies/ollama-llama3.1-recipe:1.0.0'] as $ref) {
            $this->assertTrue(SaladOllamaImage::isOurs($ref), $ref);
        }
        $this->assertFalse(SaladOllamaImage::isOurs('saladtechnologies/comfyui:x'));
        $this->assertFalse(SaladOllamaImage::isOurs('untrusted/ollama:0.40.17'));
    }

    public function test_registry_ports_and_digests_are_parsed_without_changing_the_repository(): void
    {
        $this->assertSame('reg.example.com:5000/team/app', SaladOllamaImage::repoOf('reg.example.com:5000/team/app'));
        $this->assertSame('reg.example.com:5000/team/app', SaladOllamaImage::repoOf('reg.example.com:5000/team/app:2.1.0'));
        $this->assertSame('ollama/ollama', SaladOllamaImage::repoOf('ollama/ollama@sha256:abc'));
    }

    private bool $stableFakeRegistered = false;
    private array $releaseFixture = [];
    private array $imageFixture = [];
    private int $releaseStatus = 200;

    private function stable(string $version = '0.41.0', array $release = [], array $image = [], int $status = 200): void
    {
        $this->releaseFixture = array_replace(['tag_name' => 'v'.$version, 'draft' => false, 'prerelease' => false], $release);
        $this->imageFixture = array_replace(['name' => $version, 'tag_status' => 'active', 'images' => [[
            'os' => 'linux', 'architecture' => 'amd64', 'status' => 'active', 'digest' => 'sha256:'.str_repeat('a', 64),
        ]]], $image);
        $this->releaseStatus = $status;
        if (! $this->stableFakeRegistered) {
            Http::preventStrayRequests();
            Http::fake(function ($request) {
                if ($request->url() === SaladOllamaImage::RELEASE_URL) {
                    return Http::response($this->releaseFixture, $this->releaseStatus);
                }
                if (str_starts_with($request->url(), SaladOllamaImage::TAG_URL)) {
                    return Http::response($this->imageFixture);
                }
                return Http::response([], 599);
            });
            $this->stableFakeRegistered = true;
        }
    }

    private function catalog(): CloudImage
    {
        return CloudImage::create(['provider' => 'salad', 'provider_ref' => 'saladtechnologies/ollama:0.24.0',
            'key' => 'gpu-ollama', 'family' => 'gpu-ollama', 'kind' => 'app', 'label' => 'Ollama',
            'arch' => 'x86', 'is_active' => true]);
    }

    public function test_stable_refresh_pins_the_amd64_digest_and_aligns_only_ollama(): void
    {
        $old = $this->catalog(); $this->stable();
        $other = CloudImage::create(['provider' => 'salad', 'provider_ref' => 'saladtechnologies/comfyui:kept',
            'key' => 'gpu-comfyui', 'kind' => 'app', 'label' => 'ComfyUI', 'arch' => 'x86', 'is_active' => true]);
        $service = app(SaladOllamaImage::class);
        $result = $service->discoverStable();
        $this->assertTrue($result['ok']); $this->assertTrue($result['changed']);
        $this->assertSame('ollama/ollama:0.41.0@sha256:'.str_repeat('a', 64), $service->ref());
        $this->assertSame('0.41.0', Setting::get(SaladOllamaImage::SETTING));
        $this->assertFalse($old->fresh()->is_active); $this->assertTrue($other->fresh()->is_active);
        $this->assertSame(1, CloudImage::where('key', 'gpu-ollama')->where('is_active', true)->count());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === SaladOllamaImage::RELEASE_URL);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.salad.com'));
    }

    public function test_prerelease_draft_invalid_version_and_downgrade_are_rejected(): void
    {
        foreach ([['prerelease' => true], ['draft' => true], ['tag_name' => 'v0.24.0'],
            ['tag_name' => 'v0.41.0-rc1'], ['tag_name' => 'other/image:latest']] as $bad) {
            $this->stable(release: $bad);
            $result = app(SaladOllamaImage::class)->discoverStable();
            $this->assertFalse($result['ok']);
            $this->assertNull(Setting::get(SaladOllamaImage::RELEASE_SETTING));
        }
    }

    public function test_an_unavailable_or_non_amd64_image_does_not_replace_the_current_release(): void
    {
        $this->catalog(); $this->stable();
        $service = app(SaladOllamaImage::class); $this->assertTrue($service->discoverStable()['ok']);
        $before = $service->ref();
        foreach ([['images' => []], ['tag_status' => 'inactive'], ['name' => 'wrong'],
            ['images' => [['os' => 'linux', 'architecture' => 'arm64', 'status' => 'active', 'digest' => 'sha256:'.str_repeat('b', 64)]]]] as $bad) {
            $this->stable('0.42.0', image: $bad);
            $this->assertFalse($service->discoverStable()['ok']);
            $this->assertSame($before, $service->ref());
        }
        $this->stable(status: 503);
        $this->assertFalse($service->discoverStable()['ok']);
        $this->assertSame($before, $service->ref());
    }

    public function test_refresh_never_downgrades_or_retags_an_approved_release(): void
    {
        $this->catalog(); $this->stable(); $service = app(SaladOllamaImage::class);
        $service->discoverStable(); $before = $service->ref();
        $this->stable('0.40.17');
        $this->assertFalse($service->discoverStable()['changed']);
        $this->assertSame($before, $service->ref());
        $this->stable(image: ['images' => [['os' => 'linux', 'architecture' => 'amd64', 'status' => 'active', 'digest' => 'sha256:'.str_repeat('b', 64)]]]);
        $this->assertFalse($service->discoverStable()['changed']);
        $this->assertSame($before, $service->ref());
        $this->assertSame(2, CloudImage::where('key', 'gpu-ollama')->count());
    }

    public function test_automatic_refresh_respects_the_existing_sales_pause(): void
    {
        $this->catalog(); Setting::put('salad_ollama_sales_paused', '1'); $this->stable();
        $result = app(SaladOllamaImage::class)->discoverStable();
        $this->assertTrue($result['ok']);
        $this->assertSame(0, CloudImage::where('key', 'gpu-ollama')->where('is_active', true)->count());
        $this->assertSame('1', Setting::get('salad_ollama_sales_paused'));
    }

    public function test_checkout_ignores_corrupt_approval_metadata_without_network_access(): void
    {
        Http::fake();
        Setting::put(SaladOllamaImage::RELEASE_SETTING, json_encode(['source' => 'official-stable-linux-amd64',
            'version' => '0.42.0', 'digest' => 'unverified']));
        $this->assertSame('ollama/ollama:0.40.17', app(SaladOllamaImage::class)->ref());
        Http::assertNothingSent();
    }

    public function test_the_hourly_refresh_command_is_registered_and_does_not_modify_existing_groups(): void
    {
        $this->catalog(); $this->stable();
        $this->artisan('cloud:ollama-refresh')->assertSuccessful();
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $event = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'cloud:ollama-refresh'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }

}
