<?php

namespace App\Services\Provisioning;

use App\Models\Service;

/** A shared host is delivered only after customer AND owned subscription match. */
class PleskHostingProvisioner
{
    public function __construct(private PleskClient $client) {}

    public function create(Service $service): ProvisionResult
    {
        $server = $service->server;
        $login = (string) $service->username;
        $domain = strtolower((string) $service->domain);
        $plan = (string) $service->plan;
        $ip = (string) ($server?->meta['plesk_hosting_ip'] ?? '');
        $email = strtolower(trim((string) $service->customer?->email));
        if (! $server || $server->type !== 'plesk' || ! $service->id || $service->is_reseller
            || ! preg_match('/\A[a-z][a-z0-9_]{2,31}\z/D', $login)
            || in_array(strtolower($login), ['admin', 'root', strtolower((string) $server->username)], true)
            || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! str_contains($domain, '.')
            || $plan === '' || ! filter_var($ip, FILTER_VALIDATE_IP) || $email === '' || blank($service->password)) {
            return ProvisionResult::manual('Plesk delivery settings are incomplete.');
        }
        $p = $this->client->planInfo($plan);
        if ($p['exists'] !== true || ($p['result']['name'] ?? null) !== $plan) {
            return ProvisionResult::manual('The exact purchased Plesk plan could not be confirmed.');
        }
        $binding = ['server_id' => (int) $server->id, 'service_id' => (int) $service->id,
            'customer_id' => (int) $service->customer_id, 'login' => $login, 'domain' => $domain, 'plan' => $plan, 'ip' => $ip];
        $meta = (array) ($service->provision_meta ?? []);
        $intent = (array) ($meta['plesk_intent'] ?? []);
        if ($intent && ($intent['binding'] ?? null) !== $binding) {
            return ProvisionResult::manual('Plesk delivery intent differs from this order.');
        }
        $account = $this->client->customerInfo($login);
        if ($account['exists'] === null) return ProvisionResult::manual('Plesk customer state is unknown; no create was attempted.');
        if ($account['exists'] === true && ! $intent) {
            return ProvisionResult::manual('An unbound Plesk login already exists; ownership review is required.');
        }
        if (! $intent) {
            $intent = ['binding' => $binding];
            $this->checkpoint($service, $intent);
        }
        if ($account['exists'] === false) {
            if ($intent['customer_uncertain'] ?? false) return ProvisionResult::manual('An earlier customer create is unresolved; no duplicate was attempted.');
            $intent['customer_uncertain'] = true;
            $this->checkpoint($service, $intent); // persist BEFORE the external mutation
            $created = $this->client->createCustomer(['login' => $login, 'password' => (string) $service->password,
                'email' => $email, 'name' => (string) ($service->customer?->displayName() ?: $login)]);
            $account = $this->client->customerInfo($login);
            if ($account['exists'] !== true) {
                if (($created['rejected'] ?? false) === true && $account['exists'] === false) {
                    $intent['customer_uncertain'] = false;
                    $this->checkpoint($service, $intent);
                    return ProvisionResult::fail('Plesk customer creation was rejected.');
                }
                return ProvisionResult::manual('Plesk customer creation requires reconciliation.');
            }
        }
        $row = $account['result'];
        $info = $row['data']['gen_info'] ?? [];
        $owner = (int) ($row['id'] ?? 0);
        if ($owner < 1 || ($info['login'] ?? null) !== $login
            || strtolower(trim((string) ($info['email'] ?? ''))) !== $email || (string) ($info['status'] ?? '') !== '0'
            || (isset($intent['owner_id']) && (int) $intent['owner_id'] !== $owner)) {
            return ProvisionResult::manual('Plesk customer identity or status did not match.');
        }
        $intent['owner_id'] = $owner;
        $intent['customer_uncertain'] = false;
        $this->checkpoint($service, $intent);
        $space = $this->client->subscriptionInfo($domain);
        if ($space['exists'] === null) return ProvisionResult::manual('Plesk subscription state is unknown; no create was attempted.');
        $reused = $space['exists'] === true;
        if ($space['exists'] === false) {
            if ($intent['subscription_uncertain'] ?? false) return ProvisionResult::manual('An earlier subscription create is unresolved; no duplicate was attempted.');
            $intent['subscription_uncertain'] = true;
            $this->checkpoint($service, $intent);
            $created = $this->client->createSubscription(['domain' => $domain, 'owner_id' => $owner, 'ip' => $ip,
                'login' => $login, 'password' => (string) $service->password, 'plan' => $plan]);
            $space = $this->client->subscriptionInfo($domain);
            if ($space['exists'] !== true) {
                if (($created['rejected'] ?? false) === true && $space['exists'] === false) {
                    $intent['subscription_uncertain'] = false;
                    $this->checkpoint($service, $intent);
                    return ProvisionResult::fail('Plesk subscription creation was rejected.');
                }
                return ProvisionResult::manual('Plesk subscription creation requires reconciliation.');
            }
        }
        $data = $space['result']['data'] ?? [];
        $gen = $data['gen_info'] ?? [];
        $props = $data['hosting']['vrt_hst']['property'] ?? [];
        if (isset($props['name'])) $props = [$props];
        $hosting = [];
        foreach ($props as $prop) if (is_array($prop) && isset($prop['name'])) $hosting[$prop['name']] = $prop['value'] ?? '';
        $subscription = $data['subscriptions']['subscription'] ?? [];
        $subscriptionId = (int) ($space['result']['id'] ?? 0);
        if ($subscriptionId < 1 || ($gen['name'] ?? null) !== $domain || (int) ($gen['owner-id'] ?? 0) !== $owner
            || ($gen['htype'] ?? null) !== 'vrt_hst' || (string) ($gen['status'] ?? '') !== '0'
            || ($hosting['ftp_login'] ?? null) !== $login
            || blank($p['result']['guid'] ?? null)
            || ($subscription['plan']['plan-guid'] ?? null) !== ($p['result']['guid'] ?? null)
            || ($data['hosting']['vrt_hst']['ip_address'] ?? null) !== $ip
            || (isset($intent['subscription_id']) && (int) $intent['subscription_id'] !== $subscriptionId)) {
            return ProvisionResult::manual('Plesk subscription owner, plan, hosting, or login did not match.');
        }
        $intent['subscription_id'] = $subscriptionId;
        $intent['subscription_uncertain'] = false;
        $this->checkpoint($service, $intent);
        return ProvisionResult::success($login, (string) $service->password,
            'https://'.$server->hostname.':'.$server->effectivePort(), [
                'reused' => $reused, 'server_ip' => $ip, 'plesk_intent' => $intent,
                'plesk' => ['server_id' => (int) $server->id, 'customer_id' => $owner,
                    'subscription_id' => $subscriptionId, 'login' => $login, 'domain' => $domain, 'plan' => $plan],
            ]);
    }

    private function checkpoint(Service $service, array $intent): void
    {
        $meta = (array) ($service->provision_meta ?? []);
        $meta['plesk_intent'] = $intent;
        $service->forceFill(['provision_meta' => $meta])->save();
    }
}
