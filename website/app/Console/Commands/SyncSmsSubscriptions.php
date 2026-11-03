<?php

namespace App\Console\Commands;

use App\Models\SmsSubscription;
use App\Services\SmsResale\Controls;
use App\Services\SmsResale\Provisioner;
use Illuminate\Console\Command;

class SyncSmsSubscriptions extends Command
{
    protected $signature = 'sms:sync-subscriptions {--limit=5}';
    protected $description = 'Reconcile SMS accounts without sending messages or transferring money';

    public function handle(Controls $controls, Provisioner $provisioner): int
    {
        if (! $controls->runtime()) { return self::SUCCESS; }
        $rows = SmsSubscription::with('service')->whereHas('service', fn ($q) => $q->whereIn('status', ['active', 'awaiting_provision']))
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(5)))
            ->orderByRaw('checked_at IS NOT NULL')->orderBy('checked_at')->limit(max(1, min(10, (int) $this->option('limit'))))->get();
        foreach ($rows as $row) { $provisioner->provision($row->service); }
        return self::SUCCESS;
    }
}
