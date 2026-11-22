<?php

namespace App\Console\Commands;

use App\Services\Cloud\SaladOllamaImage;
use Illuminate\Console\Command;

class RefreshOllamaRelease extends Command
{
    protected $signature = 'cloud:ollama-refresh';
    protected $description = 'Discover the latest stable official Ollama image for future GPU deliveries.';

    public function handle(SaladOllamaImage $image): int
    {
        $handles = [];
        try {
            foreach ([base_path('../.servernet-web-release.lock'), base_path('../servernet-release.lock'),
                storage_path('framework/servernet-release.lock')] as $path) {
                if (is_link($path)) { throw new \RuntimeException('invalid_release_lock'); }
                $exists = is_file($path);
                $handle = fopen($path, 'c');
                if ($handle === false) { throw new \RuntimeException('release_lock_unavailable'); }
                $handles[] = $handle;
                if (! $exists) { chmod($path, 0600); }
                if ((fstat($handle)['mode'] & 0170000) !== 0100000 || ! flock($handle, LOCK_SH | LOCK_NB)) {
                    $this->warn('A release is running; Ollama refresh deferred.');
                    return self::SUCCESS;
                }
            }
            $result = $image->discoverStable();
        } finally {
            foreach ($handles as $handle) { fclose($handle); }
        }
        if (! $result['ok']) {
            $this->warn('Ollama release check unavailable; retained '.$result['version'].'.');
            return self::SUCCESS;
        }
        $this->info('Ollama '.$result['version'].' verified; future deliveries use the pinned official image.');
        return self::SUCCESS;
    }
}
