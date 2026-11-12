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
        $running = ['active', 'awaiting_provision'];
        $stopped = ['suspended', ...\App\Models\Service::DEAD_STATUSES];
        $rows = SmsSubscription::with('service')->whereHas('service', fn ($q) => $q->whereIn('status', [...$running, ...$stopped]))
            ->where(fn ($q) => $q->where('state', '!=', 'suspended')->orWhereHas('service', fn ($q) => $q->whereIn('status', $running)))
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(5)))
            ->orderByRaw('checked_at IS NOT NULL')->orderBy('checked_at')->limit(max(1, min(10, (int) $this->option('limit'))))->get();
        foreach ($rows as $row) {
            if (in_array($row->service->status, $stopped, true)) { $provisioner->changeAccess($row->service, false); }
            else { $provisioner->provision($row->service); }
        }
        return self::SUCCESS;
    }
}
