<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\Growth\GoogleDiscovery;
use Illuminate\Console\Command;

class GoogleDiscoverBlog extends Command
{
    protected $signature = 'seo:google-discovery {--status : Read-only connection and receipts} {--post= : Queue an existing published trilingual post} {--limit=1}';

    protected $description = 'Submit the published blog sitemap and monitor indexing; never claims direct article indexing requests.';

    public function handle(GoogleDiscovery $delivery): int
    {
        if ($this->option('status')) {
            $result = $delivery->status();
        } else {
            if ($this->option('post')) {
                $delivery->enqueue(Post::with('translations')->findOrFail((int) $this->option('post')));
            }
            $result = $delivery->run((int) $this->option('limit'));
        }
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
