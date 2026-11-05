<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\HostingUpgrade;
use App\Models\Service;
use App\Services\Provisioning\HostingPlanChanger;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HostingUpgradeFulfillment
{
    public function __construct(private HostingUpgrades $upgrades, private HostingPlanChanger $changer) {}

    public function process(HostingUpgrade $upgrade): void
    {
        Cache::lock('hosting-upgrade:'.$upgrade->service_id, 900)->get(function () use ($upgrade) {
            $u = $upgrade->fresh(['invoice', 'service.server']);
            if (! $u->open_service_id || in_array($u->status, ['complete', 'canceled'], true)
                || ($u->status === 'review' && ! $u->attempted_at)) {
                return;
            }
            $i = $u->invoice;
            if (in_array($i->status, ['canceled', 'refunded'], true) && ! $u->attempted_at) {
                $u->update(['status' => 'canceled', 'open_service_id' => null]);

                return;
            }
            if ($i->status !== 'paid' || $i->paid < $i->total) {
                return;
            }
            if ($i->kind !== HostingUpgrades::KIND
                || $i->customer_id !== $u->service->customer_id || $i->currency_code !== $u->quote['from']['currency']
                || $i->total !== $u->quote['total'] || ! $i->paid_at || ! $i->due_at || $i->paid_at->gt($i->due_at)) {
                $this->review($u);

                return;
            }
            if ($u->checked_at?->gt(now()->subMinutes(5))) {
                return;
            }
            $u->update(['checked_at' => now(), 'status' => 'applying']);
            $s = $u->service;
            $q = $u->quote;
            if (! $this->upgrades->eligible($s) || $this->upgrades->fingerprint($s) !== $q['from']
                || $s->invoices()->outstanding()->exists()) {
                $this->review($u);

                return;
            }
            try {
                $observed = $this->changer->inspect($s, $q['plan']);
                if ($observed['state'] === 'source' && ! $u->attempted_at) {
                    // Commit BEFORE contacting the provider. A crashed/ambiguous call is read-only on retry.
                    $u->update(['attempted_at' => now()]);
                    $this->changer->change($s, $q['plan'], $observed);
                    $observed = $this->changer->inspect($s, $q['plan']);
                }
                if ($observed['state'] !== 'target') {
                    if ($u->attempted_at) {
                        $this->review($u);
                    } else {
                        ErrorTracker::noteOnce('hosting-upgrade', 'Paid hosting upgrade is awaiting verified panel access.', 3600,
                            ['upgrade' => $u->id, 'service' => $s->id]);
                    }

                    return;
                }
                DB::transaction(function () use ($u, $q) {
                    $locked = HostingUpgrade::whereKey($u->id)->lockForUpdate()->firstOrFail();
                    if ($locked->completed_at) {
                        return;
                    }
                    $s = Service::whereKey($u->service_id)->lockForUpdate()->firstOrFail();
                    if ($this->upgrades->fingerprint($s) !== $q['from'] || $s->status !== 'active'
                        || $s->invoices()->outstanding()->exists()) {
                        $this->review($u);

                        return;
                    }
                    $meta = (array) $s->provision_meta;
                    if (isset($meta['plesk'])) {
                        $meta['plesk']['plan'] = $q['plan'];
                    }
                    $meta['last_hosting_upgrade'] = ['id' => $u->id, 'invoice_id' => $u->invoice_id, 'at' => now()->toIso8601String()];
                    $s->update(['plan' => $q['plan'], 'name' => $q['name'], 'price' => $q['price'], 'provision_meta' => $meta]);
                    $u->update(['status' => 'complete', 'open_service_id' => null, 'completed_at' => now()]);
                    ActivityLog::forService($s, 'provision', 'Hosting upgrade completed after payment; renewal date preserved.', 'system');
                });
            } catch (\Throwable $e) {
                ErrorTracker::note('hosting-upgrade', $e, ['upgrade' => $u->id, 'service' => $s->id]);
                if ($u->attempted_at) {
                    $this->review($u);
                }
            }
        });
    }

    private function review(HostingUpgrade $u): void
    {
        $u->update(['status' => 'review']);
        ErrorTracker::noteOnce('hosting-upgrade', 'Paid hosting upgrade requires reconciliation; no repeat mutation was attempted.', 3600,
            ['upgrade' => $u->id, 'service' => $u->service_id, 'invoice' => $u->invoice_id]);
    }
}
