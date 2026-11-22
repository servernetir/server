<?php

namespace App\Console\Commands;

use App\Services\Marketing\EmailMarketingCampaigns;
use DomainException;
use Illuminate\Console\Command;

class EmailMarketingSend extends Command
{
    protected $signature = 'email-marketing:send {--limit=25 : Maximum recipients in this batch}';
    protected $description = 'Send explicitly queued email campaigns and retain per-recipient delivery results';

    public function handle(EmailMarketingCampaigns $campaigns): int
    {
        if (! config('email-marketing.enabled') || ! $campaigns->ready()) {
            $this->line('Email marketing is unavailable; no messages were attempted.');
            return self::SUCCESS;
        }
        try {
            $stats = $campaigns->tick((int) $this->option('limit'));
        } catch (DomainException) {
            $this->error('Email campaign transport is not configured. No messages were attempted.');
            return self::FAILURE;
        }
        $this->line(json_encode($stats, JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
