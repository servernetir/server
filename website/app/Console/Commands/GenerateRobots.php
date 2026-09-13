<?php

namespace App\Console\Commands;

use App\Support\RobotsPolicy;
use Illuminate\Console\Command;

class GenerateRobots extends Command
{
    protected $signature = 'seo:robots {--check : Fail when public/robots.txt differs from configured policy}';

    protected $description = 'Generate the deterministic search/AI crawler policy in public/robots.txt';

    public function handle(): int
    {
        try {
            $expected = RobotsPolicy::render((array) config('seo.crawler_policy', []));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $path = public_path('robots.txt');

        if ($this->option('check')) {
            if (is_file($path) && file_get_contents($path) === $expected) {
                $this->info('robots.txt matches config/seo.php');

                return self::SUCCESS;
            }

            $this->error('robots.txt is stale; run php artisan seo:robots');

            return self::FAILURE;
        }

        $temporary = $path.'.tmp';
        $written = @file_put_contents($temporary, $expected, LOCK_EX);

        if ($written !== strlen($expected) || ! @rename($temporary, $path)) {
            @unlink($temporary);
            $this->error('Could not atomically write '.$path);

            return self::FAILURE;
        }

        $this->info('Generated '.$path);

        return self::SUCCESS;
    }
}
