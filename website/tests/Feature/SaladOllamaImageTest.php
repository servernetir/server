<?php

namespace Tests\Feature;

use App\Models\Setting;
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
}
