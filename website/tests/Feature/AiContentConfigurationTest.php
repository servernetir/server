<?php

namespace Tests\Feature;

use App\Services\AiContent;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AiContentConfigurationTest extends TestCase
{
    public function test_incomplete_provider_returns_no_content_and_logs_only_configuration_metadata(): void
    {
        foreach (['key', 'base', 'model'] as $missing) {
            $provider = ['name' => 'test', 'key' => 'test-key', 'base' => 'https://example.invalid', 'model' => 'test-model'];
            $provider[$missing] = ' ';
            $client = new class($provider) extends AiContent
            {
                public function __construct(private array $configuration) {}

                protected function provider(string $purpose): array
                {
                    return $this->configuration;
                }

                public function invoke(): ?string
                {
                    return $this->call('system', 'private draft', 10, 1);
                }
            };
            Log::shouldReceive('warning')->once()->with('AiContent provider is not configured', [
                'purpose' => 'article', 'provider' => 'test',
            ]);
            $this->assertNull($client->invoke());
        }
    }
}
