<?php

namespace App\Services\Provisioning;

use App\Models\Service;

/** Observe exact account identity and plan before AND after every mutation. */
class HostingPlanChanger
{
    public function inspect(Service $s, string $target): array
    {
        if ($s->server->type === 'whm') {
            $c = new WhmClient($s->server);
            $r = $c->accountSummary($s->username);
            $rows = $r['data']['acct'] ?? [];
            $a = count($rows) === 1 ? $rows[0] : [];
            if (! $r['ok'] || ($a['user'] ?? null) !== $s->username
                || strtolower((string) ($a['domain'] ?? '')) !== strtolower($s->domain)
                || (string) ($a['suspended'] ?? '1') !== '0') {
                return ['state' => 'unknown'];
            }
            $plan = $a['plan'] ?? null;
            if ($plan === $target) {
                return ['state' => 'target'];
            }
            if ($plan !== $s->plan) {
                return ['state' => 'unknown'];
            }
            $packages = $c->listPackages();
            $matches = array_filter($packages['data']['pkg'] ?? [], fn ($p) => ($p['name'] ?? null) === $target);

            return ['state' => $packages['ok'] && count($matches) === 1 ? 'source' : 'unknown'];
        }
        if ($s->server->type !== 'plesk') {
            return ['state' => 'unknown'];
        }
        $b = data_get($s->provision_meta, 'plesk', []);
        if (($b['login'] ?? null) !== $s->username || ($b['domain'] ?? null) !== strtolower($s->domain)
            || (int) ($b['server_id'] ?? 0) !== (int) $s->server_id
            || (int) ($b['subscription_id'] ?? 0) < 1 || (int) ($b['customer_id'] ?? 0) < 1) {
            return ['state' => 'unknown'];
        }
        $c = new PleskClient($s->server);
        $r = $c->subscriptionInfo(strtolower($s->domain));
        $a = $r['result'] ?? [];
        $g = $a['data']['gen_info'] ?? [];
        $props = $a['data']['hosting']['vrt_hst']['property'] ?? [];
        if (isset($props['name'])) {
            $props = [$props];
        }
        $login = null;
        foreach ($props as $prop) {
            if (($prop['name'] ?? null) === 'ftp_login') {
                $login = $prop['value'] ?? null;
            }
        }
        if ($r['exists'] !== true || (int) ($a['id'] ?? 0) !== (int) $b['subscription_id']
            || ($g['name'] ?? null) !== strtolower($s->domain) || (int) ($g['owner-id'] ?? 0) !== (int) $b['customer_id']
            || (string) ($g['status'] ?? '') !== '0' || ($g['htype'] ?? null) !== 'vrt_hst' || $login !== $s->username) {
            return ['state' => 'unknown'];
        }
        $p = $c->planInfo($target);
        $guid = $p['result']['guid'] ?? null;
        if ($p['exists'] !== true || ($p['result']['name'] ?? null) !== $target || ! is_string($guid) || $guid === '') {
            return ['state' => 'unknown'];
        }
        $subscription = $a['data']['subscriptions']['subscription'] ?? [];
        if (($subscription['locked'] ?? null) !== 'false' || ($subscription['synchronized'] ?? null) !== 'true') {
            return ['state' => 'unknown'];
        }
        // Add-on/custom subscriptions need a separate compatibility review.
        $actual = $subscription['plan']['plan-guid'] ?? null;
        if ($actual === $guid) {
            return ['state' => 'target', 'guid' => $guid];
        }
        $old = $c->planInfo($s->plan);

        return ['state' => $old['exists'] === true && ($old['result']['name'] ?? null) === $s->plan && filled($old['result']['guid'] ?? null)
            && $actual === $old['result']['guid'] ? 'source' : 'unknown', 'guid' => $guid];
    }

    public function change(Service $s, string $target, array $observed): void
    {
        if ($observed['state'] !== 'source') {
            throw new \LogicException('Unverified hosting account');
        }
        if ($s->server->type === 'whm') {
            (new WhmClient($s->server))->call('changepackage', ['user' => $s->username, 'pkg' => $target]);
        } else {
            $result = (new PleskClient($s->server))->switchSubscription((int) data_get($s->provision_meta, 'plesk.subscription_id'), $observed['guid']);
            // Plesk may return status=ok while listing settings it could not apply.
            if (filled(data_get($result, 'data.webspace.switch-subscription.result.problems'))) {
                throw new \RuntimeException('Hosting upgrade reported unapplied subscription settings.');
            }
        }
    }
}
