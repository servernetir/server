<?php

namespace App\Console\Commands;

use App\Services\Growth\DailyBlogPublisher;
use App\Services\Growth\DailyBlogSources;
use Illuminate\Console\Command;

class PublishDailyBlog extends Command
{
    protected $signature = 'growth:daily-blog {--retry-reviewed : Explicitly authorize a new bounded attempt after reviewing prior cost receipts} {--actor= : Existing delegated admin ID for reviewed retry} {--resume= : Revalidate saved completed responses for a date without paid generation} {--status : Read-only execution status} {--discover : Read-only official-source preview; no paid generation}';

    protected $description = 'Research and automatically publish one complete Persian/English/Turkish blog post per Tehran day.';

    public function handle(DailyBlogPublisher $publisher): int
    {
        if ($this->option('resume')) {
            $result = $publisher->resumeSaved((string) $this->option('resume'), (int) $this->option('actor'));
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return ($result['stage'] ?? '') === 'published' ? self::SUCCESS : self::FAILURE;
        }
        if ($this->option('retry-reviewed')) {
            $publisher->retryReviewed((int) $this->option('actor'));
        }
        if ($this->option('discover')) {
            $result = app(DailyBlogSources::class)->discover();
            unset($result['text']);
        } else {
            $result = $this->option('status') ? $publisher->status() : $publisher->tick();
            unset($result['source']['text'], $result['today']['source']['text']);
        }
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ($result['stage'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
