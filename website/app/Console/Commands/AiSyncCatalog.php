<?php

namespace App\Console\Commands;

use App\Services\Ai\DeepInfraCatalog;
use App\Services\Ai\AdditionalProviderCatalog;
use App\Models\AiProvider;
use Illuminate\Console\Command;

class AiSyncCatalog extends Command
{
    protected $signature = 'ai:sync-catalog {--provider=all : all, deepinfra, openrouter, deepseek, gapgpt or openai} {--check-access : Verify OpenRouter credentials without inference}';
    protected $description = 'Import official provider catalogues and versioned prices';

    public function handle(DeepInfraCatalog $catalog): int
    {
        $selected = (string) $this->option('provider');
        $providers = ['deepinfra', 'openrouter', 'deepseek', 'gapgpt', AiProvider::DIRECT_OPENAI];
        if ($selected !== 'all' && ! in_array($selected, $providers, true)) {
            $this->error('Unsupported provider.');
            return self::FAILURE;
        }
        $failed = false;
        foreach ($selected === 'all' ? $providers : [$selected] as $slug) {
            if ($slug !== 'deepinfra' && ! AiProvider::where('slug', $slug)->where('enabled', true)->exists()) continue;
            try {
                $result = match ($slug) {
                    'deepinfra' => $catalog->sync(),
                    'openrouter' => app(\App\Services\Ai\OpenRouterCatalog::class)->sync(),
                    default => app(AdditionalProviderCatalog::class)->sync($slug),
                };
                if ($slug === 'openrouter' && $this->option('check-access')) {
                    $result['access'] = app(\App\Services\Ai\OpenRouterCatalog::class)->checkAccess();
                }
                $this->line(json_encode(['provider' => $slug] + $result, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                $failed = true;
                $this->error($slug.' catalogue sync failed; existing data preserved.');
                \App\Support\ErrorTracker::noteOnce('ai', $slug.' catalogue sync failed: '.get_class($e), 3600);
            }
        }
        if (app(\App\Services\Ai\AiProviderRouting::class)->enabled()) {
            app(\App\Services\Ai\AiProviderRouting::class)->recordRefresh();
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
