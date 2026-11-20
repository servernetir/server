<?php

namespace App\Services\Cloud;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only coverage check; no customer names, IPs, provider IDs or raw errors in notifications. */
final class ArvanMonitoringHealth
{
    public function __construct(private ?string $applicationRoot = null) {}

    /** Local transport metadata and the real runner must both be current. No remote I/O. */
    private function central(): array
    {
        try {
            $transport = (new ArvanObservationRegistry($this->applicationRoot ?? base_path()))->transport();
            if ($transport === null) {
                return ['status' => 'unverified', 'reason' => 'central_transport_unavailable'];
            }
            $approved = $transport['approved_at'] ?? null;
            $now = time();
            if (!is_int($approved) || $approved < 946684800 || $approved > $now) {
                return ['status' => 'unverified', 'reason' => 'central_transport_unavailable'];
            }
        } catch (Throwable) {
            return ['status' => 'unverified', 'reason' => 'central_transport_unavailable'];
        }
        try {
            $run = $this->record(ArvanObservationRegistry::RUN);
            $checked = $run['checked_at'] ?? null;
            $now = time();
            if (($run['version'] ?? null) !== 1 || ($run['status'] ?? null) !== 'enabled_observation_only'
                || !is_int($checked) || $checked < $approved || $checked > $now
                || $now - $checked > ArvanTrafficObservation::MAX_AGE_SECONDS) {
                throw new \RuntimeException('runner_not_current');
            }
            foreach (['checked', 'observed', 'unknown'] as $key) {
                if (!is_int($run[$key] ?? null) || $run[$key] < 0 || $run[$key] > 20) {
                    throw new \RuntimeException('runner_counts_invalid');
                }
            }
            if ($run['observed'] + $run['unknown'] !== $run['checked']) {
                throw new \RuntimeException('runner_counts_invalid');
            }
        } catch (Throwable) {
            return ['status' => 'unverified', 'reason' => 'central_runner_unavailable'];
        }
        return ['status' => 'current', 'reason' => null, 'approved_at' => $approved, 'transport' => $transport];
    }

    public function inspect(): array
    {
        $instances = DB::table('cloud_instances')->join('services', 'services.id', '=', 'cloud_instances.service_id')
            ->where('cloud_instances.provider', 'arvan')
            ->whereNotIn('cloud_instances.status', ['deleted', 'deleting', 'terminated', 'off', 'stopped'])
            ->whereNotIn('services.status', ['cancelled', 'terminated', 'expired'])
            ->orderBy('services.id')->get(['services.id as service_id', 'services.customer_id',
                'cloud_instances.id as instance_id', 'cloud_instances.provider_ref', 'cloud_instances.status']);
        $issues = [];
        $central = $instances->isEmpty()
            ? ['status' => 'not_needed', 'reason' => null] : $this->central();
        foreach ($instances as $instance) {
            $sid = (int) $instance->service_id;
            if ($instance->status !== 'running') { $issues[$sid] = 'instance_state_unverified'; continue; }
            if ($central['status'] !== 'current') { $issues[$sid] = $central['reason']; continue; }
            $reason = 'missing_enrollment';
            try {
                [$enrollment, $status, $checkpoint] = $this->evidence($sid);
                if (ArvanObservationState::enrollment($enrollment, $sid) && $enrollment['enabled']) {
                    $identity = $enrollment['identity'];
                    $reason = 'identity_changed';
                    if ($identity['instance_id'] === (int) $instance->instance_id
                        && $identity['customer_id'] === (int) $instance->customer_id
                        && $identity['provider_ref'] === $instance->provider_ref) {
                        $result = $status['result'] ?? [];
                        $now = time();
                        $reason = 'missing_or_stale_observation';
                        if (($status['version'] ?? null) === 1 && is_array($status['identity'] ?? null)
                            && ArvanTrafficObservation::sameIdentity($identity, $status['identity'])
                            && ($result['status'] ?? null) === 'unknown' && is_int($result['checked_at'] ?? null)
                            && $result['checked_at'] <= $now && $now - $result['checked_at'] <= ArvanTrafficObservation::MAX_AGE_SECONDS) {
                            $reason = match ($result['source_reason'] ?? null) {
                                'transport_unavailable' => 'central_query_unavailable',
                                'target_metrics_unavailable' => 'target_metrics_unavailable',
                                'target_metrics_stale' => 'target_metrics_stale',
                                'source_identity_mismatch' => 'source_identity_mismatch',
                                'source_protocol_invalid' => 'source_protocol_invalid',
                                default => $reason,
                            };
                        }
                        $sample = $checkpoint['sample'] ?? null;
                        $sampleAt = is_array($sample) ? ($sample['sample_at'] ?? null) : null;
                        $collectedAt = is_array($sample) ? ($sample['collected_at'] ?? null) : null;
                        $sampleValid = is_array($sample)
                            && (new ArvanTrafficObservation)->evaluate($identity, $sample, null, $now)['status'] === 'baseline';
                        if (is_int($sampleAt) && $sampleAt < $central['approved_at']) {
                            $reason = 'observation_predates_transport_approval';
                        } elseif (($status['version'] ?? null) === 1 && is_array($status['identity'] ?? null)
                            && ArvanTrafficObservation::sameIdentity($identity, $status['identity'])
                            && ($result['status'] ?? null) === 'observed'
                            && ($result['two_fresh_samples_verified'] ?? null) === true
                            && ($checkpoint['fresh_samples'] ?? null) === 2 && $sampleValid
                            && is_int($collectedAt) && $collectedAt <= ($result['checked_at'] ?? -1)
                            && is_int($result['checked_at'] ?? null)
                            && $result['checked_at'] <= $now
                            && $now - $result['checked_at'] <= ArvanTrafficObservation::MAX_AGE_SECONDS) {
                            continue;
                        }
                    }
                }
            } catch (Throwable) { $reason = 'invalid_monitoring_record'; }
            $issues[$sid] = $reason;
        }
        // A changed/disabled/replaced transport cannot reuse a fresh historical green result.
        if ($central['status'] === 'current') {
            $after = $this->central();
            if ($after['status'] !== 'current' || $after['transport'] !== $central['transport']) {
                $central = ['status' => 'unverified',
                    'reason' => $after['status'] === 'current' ? 'central_transport_changed' : $after['reason']];
                foreach ($instances as $instance) {
                    if ($instance->status === 'running') { $issues[(int) $instance->service_id] = $central['reason']; }
                }
            }
        }
        ksort($issues);
        $labels = ['missing_enrollment' => 'ثبت پایش مرکزی تأیید نشده',
            'central_query_unavailable' => 'اتصال جمع‌آورنده پاسخ نمی‌دهد؛ فایل سوکت به‌تنهایی نشانهٔ اتصال نیست',
            'source_protocol_invalid' => 'پاسخ مسیر جمع‌آوری نامعتبر است؛ قرارداد ارتباط بررسی شود',
            'target_metrics_unavailable' => 'دادهٔ عامل یا رابط شبکه در جمع‌آورنده موجود نیست',
            'target_metrics_stale' => 'نمونهٔ واقعی جمع‌آورنده کهنه است',
            'source_identity_mismatch' => 'هویت دادهٔ جمع‌آورنده با این سرور تطبیق ندارد',
            'central_transport_unavailable' => 'اتصال خصوصی پایش تأیید نشده',
            'central_runner_unavailable' => 'اجرای تازهٔ پایش مرکزی ثبت نشده',
            'central_transport_changed' => 'اتصال پایش هنگام بررسی تغییر کرده',
            'observation_predates_transport_approval' => 'پس از تأیید اتصال، نمونهٔ تازه ثبت نشده',
            'identity_changed' => 'هویت سرور تغییر کرده',
            'missing_or_stale_observation' => 'دادهٔ تازه در دسترس نیست', 'invalid_monitoring_record' => 'وضعیت پایش نامعتبر است',
            'instance_state_unverified' => 'وضعیت اجرای سرور تأیید نشده'];
        $parts = [];
        foreach (array_slice($issues, 0, 15, true) as $sid => $reason) { $parts[] = '#'.$sid.': '.$labels[$reason]; }
        if (count($issues) > 15) { $parts[] = 'و '.(count($issues) - 15).' سرویس دیگر'; }
        return ['checked' => count($instances), 'issues' => $issues, 'summary' => implode('؛ ', $parts),
            'central_status' => $central['status'], 'central_reason' => $central['reason'],
            'central_summary' => $central['reason'] === null ? '' : $labels[$central['reason']]];
    }

    /** Shared current reads keep enrollment, its hash-bound checkpoint and status coherent. */
    private function evidence(int $serviceId): array
    {
        return DB::transaction(function () use ($serviceId): array {
            $enrollment = $this->record(ArvanObservationRegistry::ENROLLMENT.$serviceId, true);
            if (!ArvanObservationState::enrollment($enrollment, $serviceId) || !$enrollment['enabled']) {
                return [$enrollment, null, null];
            }
            $status = $this->record(ArvanObservationRegistry::STATUS.$serviceId, true);
            $key = ArvanObservationRegistry::CHECKPOINT.$serviceId.':'.$enrollment['identity']['enrollment_id'];
            $checkpoint = ArvanObservationState::previous($enrollment, $this->raw($key, true));
            return [$enrollment, $status, $checkpoint];
        }, 3);
    }

    private function record(string $key, bool $lock = false): ?array
    {
        return ArvanObservationState::decode($this->raw($key, $lock));
    }

    private function raw(string $key, bool $lock = false): ?string
    {
        // Do not load an unbounded database value into the watchdog.
        $length = DB::getDriverName() === 'sqlite' ? 'LENGTH(CAST(value AS BLOB))' : 'OCTET_LENGTH(value)';
        $query = DB::table('settings')->where('key', $key);
        if ($lock) { $query->sharedLock(); }
        $value = $query->selectRaw('CASE WHEN '.$length.' <= ? THEN value ELSE NULL END AS value', [ArvanObservationState::MAX_RECORD_BYTES])->first();
        return $value?->value;
    }
}
