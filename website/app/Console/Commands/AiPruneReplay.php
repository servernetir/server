<?php

namespace App\Console\Commands;

use App\Models\AiCall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AiPruneReplay extends Command
{
    protected $signature = 'ai:prune-replay';
    protected $description = 'Erase expired AI response content while retaining financial audit records';

    public function handle(): int
    {
        if (! Schema::hasColumn('ai_calls', 'response_encrypted')) {
            return self::SUCCESS;
        }
        $count = AiCall::where('created_at', '<', now()->subHours((int) config('ai.replay_ttl_h', 24)))
            ->where(fn ($q) => $q->whereNotNull('response_encrypted')->orWhereNotNull('response_body')->orWhereNotNull('request_body'))
            ->update(['response_encrypted' => null, 'response_body' => null, 'request_body' => null]);
        $this->line('Expired response payloads erased: '.$count);
        return self::SUCCESS;
    }
}
