<?php

namespace App\Support;

use App\Models\CloudInstance;
use App\Models\ExitUpstream;
use App\Models\Service;
use Illuminate\Support\Facades\Schema;

/** Customer egress is a distinct entitlement, not an automatic Proxmox feature. */
class ExitEntitlement
{
    public static function optional(Service $service, ?CloudInstance $instance): bool
    {
        if (! $instance || $instance->provider !== 'proxmox'
            || (int) $instance->service_id !== (int) $service->id
            || (int) $service->customer_id <= 0
            || ! in_array($service->status, ['active', 'suspended'], true)
            || $service->isInternalInfrastructure() || ! $instance->isDelivered()
            || self::dedicated($instance)
            || ! self::safeVm($instance)) {
            return false;
        }
        return true;
    }

    private static function safeVm(CloudInstance $instance): bool
    {
        $ref = (string) $instance->provider_ref;
        $protected = config('servernet.exit.protected_vmids', ['108']);
        $protected = is_array($protected) ? $protected : explode(',', (string) $protected);
        return preg_match('/\A[1-9][0-9]{0,9}\z/D', $ref) === 1
            && ! in_array($ref, array_map(fn ($v) => trim((string) $v), $protected), true);
    }

    public static function dedicated(CloudInstance $instance): bool
    {
        return str_starts_with((string) $instance->location_code, 'exit-');
    }

    public static function allows(?CloudInstance $instance): bool
    {
        if (! $instance || $instance->provider !== 'proxmox'
            || ! $instance->isDelivered() || ! self::safeVm($instance)) {
            return false;
        }
        if (self::dedicated($instance)) {
            return true;
        }
        $meta = $instance->meta ?? [];
        // A manager's explicit revoke always wins, even if a stale old exit
        // assignment remains. Legacy assigned customers are not cut on deploy.
        if (array_key_exists('exit_entitled', $meta)) {
            return $meta['exit_entitled'] === true;
        }
        return $instance->exitCountryCode() !== null;
    }

    /** Only paid-ready, explicitly enabled dedicated upstreams with fresh health evidence. */
    public static function availableCountries(): array
    {
        if (! Schema::hasTable('exit_upstreams')) {
            return [];
        }
        $ready = ExitUpstream::query()->where('role', 'exit')->where('enabled', true)
            ->where('health', 'up')->where('last_seen_at', '>=', now()->subMinutes(10))
            ->pluck('country_code')->map(fn ($v) => strtolower(trim((string) $v)))->all();

        return array_values(array_intersect(ExitCountries::codes(), array_unique($ready)));
    }

    public static function options(CloudInstance $instance, ?string $locale = 'fa'): array
    {
        if (self::dedicated($instance)) {
            return ExitCountries::options($locale);
        }
        $ready = self::availableCountries();
        return array_values(array_filter(ExitCountries::options($locale), fn ($o) =>
            $o['code'] === ExitCountries::NONE || in_array($o['code'], $ready, true)));
    }

    public static function accepts(CloudInstance $instance, string $country): bool
    {
        return self::dedicated($instance)
            ? ExitCountries::accepts($country)
            : (ExitCountries::isNone($country) || in_array($country, self::availableCountries(), true));
    }
}
