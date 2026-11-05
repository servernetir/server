<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use RuntimeException;

/** Bounded persistence envelope. Guest evidence never authorizes money or power actions. */
final class ArvanObservationState
{
    public const MAX_RECORD_BYTES = 16384;

    public static function decode(?string $raw): ?array
    {
        if ($raw === null) { return null; }
        if (strlen($raw) > self::MAX_RECORD_BYTES) { throw new RuntimeException('observation_record_limit'); }
        $value = json_decode($raw, true, 20, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) { throw new RuntimeException('observation_record_invalid'); }
        return $value;
    }

    public static function encode(array $value): string
    {
        $raw = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($raw) > self::MAX_RECORD_BYTES) { throw new RuntimeException('observation_record_limit'); }
        return $raw;
    }

    public static function enrollment(?array $value, int $serviceId): bool
    {
        return $value !== null && ($value['version'] ?? null) === 1 && ($value['provider'] ?? null) === 'arvan'
            && is_bool($value['enabled'] ?? null) && is_array($value['identity'] ?? null)
            && ArvanTrafficObservation::validIdentity($value['identity']) && $value['identity']['service_id'] === $serviceId
            && array_key_exists('checkpoint_sha256', $value)
            && ($value['checkpoint_sha256'] === null || self::sha($value['checkpoint_sha256']))
            && self::sha($value['operator_acceptance_sha256'] ?? null);
    }

    public static function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    /** Missing or corrupt previously started history cannot silently become a baseline. */
    public static function previous(array $enrollment, ?string $raw): ?array
    {
        $expected = $enrollment['checkpoint_sha256'];
        if ($expected === null) {
            if ($raw !== null) { throw new RuntimeException('unexpected_checkpoint_requires_review'); }
            return null;
        }
        if ($raw === null || !hash_equals($expected, hash('sha256', $raw))) {
            throw new RuntimeException('checkpoint_history_missing_or_changed');
        }
        $state = self::decode($raw);
        if (($state['version'] ?? null) !== 1 || !is_int($state['sequence'] ?? null) || $state['sequence'] < 1
            || !in_array($state['fresh_samples'] ?? null, [1, 2], true)
            || !is_array($state['sample'] ?? null)
            || !is_array($state['sample']['identity'] ?? null)
            || !ArvanTrafficObservation::sameIdentity($enrollment['identity'], $state['sample']['identity'])) {
            throw new RuntimeException('checkpoint_history_invalid');
        }
        return $state;
    }

    public static function advance(array $identity, ?array $previous, ?array $sample, int $now): array
    {
        $result = (new ArvanTrafficObservation)->evaluate($identity, $sample, $previous['sample'] ?? null, $now);
        $checkpoint = null;
        if ($result['checkpoint'] !== null) {
            if ($previous !== null && $previous['sequence'] >= PHP_INT_MAX - 1) {
                throw new RuntimeException('checkpoint_sequence_limit');
            }
            $checkpoint = ['version' => 1, 'sequence' => ($previous['sequence'] ?? 0) + 1,
                'fresh_samples' => min(2, ($previous['fresh_samples'] ?? 0) + 1),
                'sample' => $result['checkpoint'],
                'previous_sha256' => $previous === null ? null : hash('sha256', self::encode($previous))];
        }
        unset($result['checkpoint']);
        $result['fresh_samples'] = $checkpoint['fresh_samples'] ?? ($previous['fresh_samples'] ?? 0);
        $result['two_fresh_samples_verified'] = $result['status'] === 'observed' && $result['fresh_samples'] === 2;
        $result['checked_at'] = $now;
        return ['result' => $result, 'checkpoint' => $checkpoint];
    }

    public static function unknown(string $reason, int $now): array
    {
        $value = self::advance([], null, null, $now)['result'];
        $value['reason'] = $reason;
        return $value;
    }
}
