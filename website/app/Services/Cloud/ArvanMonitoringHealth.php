<?php

namespace App\Services\Cloud;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only coverage check; no customer names, IPs, provider IDs or raw errors in notifications. */
final class ArvanMonitoringHealth
{
    public function inspect(): array
    {
        $instances = DB::table('cloud_instances')->join('services', 'services.id', '=', 'cloud_instances.service_id')
            ->where('cloud_instances.provider', 'arvan')
            ->whereNotIn('cloud_instances.status', ['deleted', 'deleting', 'terminated', 'off', 'stopped'])
            ->whereNotIn('services.status', ['cancelled', 'terminated', 'expired'])
            ->orderBy('services.id')->get(['services.id as service_id', 'services.customer_id',
                'cloud_instances.id as instance_id', 'cloud_instances.provider_ref', 'cloud_instances.status']);
        $issues = [];
        foreach ($instances as $instance) {
            $sid = (int) $instance->service_id;
            if ($instance->status !== 'running') { $issues[$sid] = 'instance_state_unverified'; continue; }
            $reason = 'missing_enrollment';
            try {
                $enrollment = $this->record(ArvanObservationRegistry::ENROLLMENT.$sid);
                if (ArvanObservationState::enrollment($enrollment, $sid) && $enrollment['enabled']) {
                    $identity = $enrollment['identity'];
                    $reason = 'identity_changed';
                    if ($identity['instance_id'] === (int) $instance->instance_id
                        && $identity['customer_id'] === (int) $instance->customer_id
                        && $identity['provider_ref'] === $instance->provider_ref) {
                        $status = $this->record(ArvanObservationRegistry::STATUS.$sid);
                        $result = $status['result'] ?? [];
                        $reason = 'missing_or_stale_observation';
                        if (is_array($status['identity'] ?? null)
                            && ArvanTrafficObservation::sameIdentity($identity, $status['identity'])
                            && ($result['status'] ?? null) === 'observed'
                            && ($result['two_fresh_samples_verified'] ?? null) === true
                            && is_int($result['checked_at'] ?? null)
                            && $result['checked_at'] <= time()
                            && time() - $result['checked_at'] <= ArvanTrafficObservation::MAX_AGE_SECONDS) {
                            continue;
                        }
                    }
                }
            } catch (Throwable) { $reason = 'invalid_monitoring_record'; }
            $issues[$sid] = $reason;
        }
        ksort($issues);
        $labels = ['missing_enrollment' => 'پایش نصب/ثبت نشده', 'identity_changed' => 'هویت سرور تغییر کرده',
            'missing_or_stale_observation' => 'دادهٔ تازه در دسترس نیست', 'invalid_monitoring_record' => 'وضعیت پایش نامعتبر است',
            'instance_state_unverified' => 'وضعیت اجرای سرور تأیید نشده'];
        $parts = [];
        foreach (array_slice($issues, 0, 15, true) as $sid => $reason) { $parts[] = '#'.$sid.': '.$labels[$reason]; }
        if (count($issues) > 15) { $parts[] = 'و '.(count($issues) - 15).' سرویس دیگر'; }
        return ['checked' => count($instances), 'issues' => $issues, 'summary' => implode('؛ ', $parts)];
    }

    private function record(string $key): ?array
    {
        // Do not load an unbounded database value into the watchdog.
        $length = DB::getDriverName() === 'sqlite' ? 'LENGTH(CAST(value AS BLOB))' : 'OCTET_LENGTH(value)';
        $value = DB::table('settings')->where('key', $key)
            ->selectRaw('CASE WHEN '.$length.' <= ? THEN value ELSE NULL END AS value', [ArvanObservationState::MAX_RECORD_BYTES])->first();
        return ArvanObservationState::decode($value?->value);
    }
}
