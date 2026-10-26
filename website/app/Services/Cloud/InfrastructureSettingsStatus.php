<?php

namespace App\Services\Cloud;

use App\Models\CloudPlan;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/** Local-only administrator status. Stored credentials are never connection evidence. */
class InfrastructureSettingsStatus
{
    public const MAX_AGE_HOURS = 24;

    private const KEYS = [
        'hetzner' => ['hetzner_api_token'],
        'aeza' => ['aeza_api_token'],
        'arvan' => ['arvan_api_token'],
        'ovh' => ['ovh_region', 'ovh_app_key', 'ovh_app_secret', 'ovh_consumer_key'],
        'proxmox' => ['proxmox_api_url', 'proxmox_node', 'proxmox_token_id', 'proxmox_token_secret'],
        'salad' => ['salad_api_key', 'salad_org', 'salad_project'],
        'hetzner-robot' => ['hetzner_robot_user', 'hetzner_robot_pass'],
        'lightnode' => ['lightnode_api_token'],
        'vpstc' => ['cloud_vpstc_api_origin', 'cloud_vpstc_username', 'cloud_vpstc_password'],
    ];

    /** Called only after an explicit administrator connection test. Never stores a response body. */
    public function record(string $provider, bool $ok, string $testedFingerprint): void
    {
        if (! isset(self::KEYS[$provider]) || ! hash_equals($this->fingerprint($provider), $testedFingerprint)) {
            return;
        }
        Setting::put('infra_connection_check_'.$provider, json_encode([
            'version' => 1, 'ok' => $ok, 'at' => now()->toIso8601String(),
            'fingerprint' => $this->fingerprint($provider),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,array{number:int,name:string,state:string,label:string,detail:string,checked_at:?string}> */
    public function rows(): array
    {
        $manager = app(CloudManager::class);
        $rows = [];
        foreach ($manager->all() as $provider => $driver) {
            $configured = $driver->isConfigured();
            $disabled = CloudPlan::providerIsDisabled($provider)
                || ($provider === 'vpstc' && Setting::get('vpstc_sales_enabled') !== '1');
            $evidence = $this->evidence($provider);
            $state = 'review';
            $detail = 'برای تأیید وضعیت، آزمون اتصال را اجرا کنید.';
            if (! $configured) {
                $state = 'inactive';
                $detail = 'اطلاعات اتصال کامل نشده است.';
            } elseif ($disabled) {
                $state = 'inactive';
                $detail = 'فروش این زیرساخت غیرفعال است؛ سرویس‌های موجود جداگانه مدیریت می‌شوند.';
            } elseif ($provider === 'vpstc' && (! app(VpsTcSales::class)->enabled()
                || ! app(CloudPricingSnapshot::class)->current('vpstc'))) {
                $detail = 'اتصال، کاتالوگ و قیمت‌گذاری ماهانه باید تکمیل و همگام شوند.';
            } elseif ($evidence !== null && $evidence['ok'] === true) {
                $state = 'active';
                $detail = 'آخرین آزمون اتصال موفق است و زیرساخت غیرفعال نشده است.';
            } elseif ($evidence !== null) {
                $detail = 'آخرین آزمون اتصال موفق نبود؛ تنظیمات را بررسی و دوباره آزمایش کنید.';
            }
            $rows[$provider] = [
                'number' => array_search($provider, array_keys(CloudManager::DRIVERS), true) + 1,
                'name' => CloudManager::REAL_NAMES[$provider] ?? $provider,
                'state' => $state,
                'label' => match ($state) {
                    'active' => 'متصل و فعال', 'inactive' => 'غیرفعال', default => 'نیاز به بررسی',
                },
                'detail' => $detail,
                'checked_at' => $evidence['at'] ?? null,
            ];
        }
        return $rows;
    }

    private function evidence(string $provider): ?array
    {
        $raw = (string) Setting::get('infra_connection_check_'.$provider, '');
        if ($raw === '' || strlen($raw) > 2048) {
            return null;
        }
        try {
            $row = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($row) || ($row['version'] ?? null) !== 1 || ! is_bool($row['ok'] ?? null)
                || ! is_string($row['at'] ?? null) || ! is_string($row['fingerprint'] ?? null)
                || ! hash_equals($this->fingerprint($provider), $row['fingerprint'])) {
                return null;
            }
            $at = Carbon::parse($row['at']);
            if ($at->isBefore(now()->subHours(self::MAX_AGE_HOURS)) || $at->isAfter(now()->addMinute())) {
                return null;
            }
            return ['ok' => $row['ok'], 'at' => $at->toIso8601String()];
        } catch (\Throwable) {
            return null;
        }
    }

    public function fingerprint(string $provider): string
    {
        $values = [];
        foreach (self::KEYS[$provider] ?? [] as $key) {
            // Hash encrypted stored values; neither the digest nor status reveals credentials.
            $values[$key] = Setting::get($key);
        }
        if ($provider === 'vpstc') {
            $values['environment'] = config('services.vpstc', []);
        }
        return hash_hmac('sha256', json_encode($values, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
