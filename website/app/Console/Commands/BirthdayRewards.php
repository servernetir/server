<?php

namespace App\Console\Commands;

use App\Models\BirthdayRedemption;
use App\Models\BirthdayReward;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Birthday\BirthdayInvoices;
use App\Services\Birthday\BirthdayMessages;
use App\Services\Birthday\BirthdayProgram;
use App\Services\Marketing\CampaignAudience;
use Illuminate\Console\Command;

class BirthdayRewards extends Command
{
    protected $signature = 'birthday:rewards {--dry-run : Read eligibility without creating rewards or sending messages}';
    protected $description = 'Prepare the annual 24-hour at-cost birthday window and approved-pattern outbox';

    public function handle(BirthdayProgram $program, BirthdayMessages $messages): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && \Illuminate\Support\Facades\Schema::hasTable('birthday_redemptions')) {
            // Process only applied rows: old in-flight locks must not starve later expirations.
            $expired = BirthdayRedemption::where('status', 'applied')->where(function ($q) {
                $q->where('expires_at', '<=', now())->orWhereIn('birthday_reward_id',
                    BirthdayReward::whereNotNull('revoked_at')->select('id'));
            })->orderBy('id')->limit(100)->get();
            foreach ($expired as $redemption) {
                app(BirthdayInvoices::class)->synchronize(Invoice::findOrFail($redemption->invoice_id));
            }
        }
        if (! $program->enabled()) {
            $this->line('New birthday rewards and SMS disabled.');
            return self::SUCCESS;
        }
        $cursor = (int) Setting::get('birthday_rewards_cursor', '0');
        $customers = Customer::where('status', 'active')->where('id', '>', $cursor)->orderBy('id')
            ->limit((int) config('birthday_rewards.batch_size', 100))->get();
        $local = now()->setTimezone(config('birthday_rewards.timezone'));
        $count = 0;
        foreach ($customers as $customer) {
            if ($dry) {
                $count += app(CampaignAudience::class)->birthday($customer, $local) ? 1 : 0;
            } else {
                $before = $program->current($customer);
                $reward = $program->ensure($customer);
                $count += $reward && ! $before ? 1 : 0;
            }
        }
        if (! $dry) {
            Setting::put('birthday_rewards_cursor', $customers->count() < config('birthday_rewards.batch_size', 100)
                ? '0' : (string) $customers->last()->id);
            foreach (BirthdayReward::whereIn('sms_status', ['pending_pattern', 'scheduled'])->where('expires_at', '>', now())
                ->orderBy('id')->limit(100)->get() as $reward) {
                $messages->dispatch($reward);
            }
            // An interrupted network attempt is uncertain, never automatically sent again.
            BirthdayReward::where('sms_status', 'processing')->where('sms_attempted_at', '<', now()->subMinutes(10))
                ->update(['sms_status' => 'unknown', 'updated_at' => now()]);
            Setting::put('birthday_rewards_last_tick', now()->toIso8601String());
        }
        $this->line(json_encode(['dry_run' => $dry, 'eligible_or_created' => $count, 'scanned' => $customers->count(),
            'pattern_ready' => $messages->ready()]));

        return self::SUCCESS;
    }
}
