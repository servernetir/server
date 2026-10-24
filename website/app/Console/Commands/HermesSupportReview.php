<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Hermes\HermesSupportReviewService;
use App\Services\Hermes\HermesSupportState;
use Illuminate\Console\Command;

class HermesSupportReview extends Command
{
    protected $signature = 'hermes:support-review {--limit=5 : Maximum ticket reviews, capped at ten} {--dry-run : Read-only preview; no model, draft, reply or progress update} {--no-send : Generate internal drafts only} {--status : Show sanitized durable progress}';

    protected $description = 'Review new customer ticket messages and Hermes errors with bounded, idempotent delivery';

    public function handle(HermesSupportReviewService $reviewer, HermesSupportState $state): int
    {
        $result = $this->option('status')
            ? ['enabled' => Setting::get('hermes_support_enabled') === '1', 'auto_send' => Setting::get('hermes_support_auto_send') === '1'] + $state->status()
            : $reviewer->run((int) $this->option('limit'), (bool) $this->option('dry-run'), (bool) $this->option('no-send'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return ($result['completed'] ?? true) && ! isset($result['error']) ? self::SUCCESS : self::FAILURE;
    }
}
