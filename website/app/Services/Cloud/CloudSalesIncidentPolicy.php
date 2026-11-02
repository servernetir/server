<?php

namespace App\Services\Cloud;

/** Persistent incident decisions. Does not change catalogue or send notifications. */
final class CloudSalesIncidentPolicy
{
    public static function next(array $check, ?array $previous, int $at): array
    {
        $previous ??= [];
        $last = (int) ($previous['last_attempt_at'] ?? 0);
        // Migrate the existing receipt without announcing an already reported failure again.
        $open = array_key_exists('incident_open', $previous)
            ? (bool) $previous['incident_open']
            : (($previous['ok'] ?? true) === false && ($previous['reason'] ?? '') !== 'admin_disabled' && $last > 0);
        $started = (int) ($previous['incident_started_at'] ?? ($open ? $last : 0));
        $reminders = (int) ($previous['reminders_sent'] ?? 0);
        $healthy = 0;
        $event = null;

        if (($check['reason'] ?? '') === 'admin_disabled') {
            // An intentional stop is not an outage or a recovery.
            $open = false;
            $started = 0;
            $reminders = 0;
        } elseif (! $check['ok']) {
            if (! $open) {
                $open = true;
                $started = $at;
                $reminders = 0;
                $event = 'failure';
            } else {
                // Cause changes belong to the same incident and update the private receipt.
                $interval = match (true) {
                    $reminders === 0 => 7200,
                    $reminders === 1 => 21600,
                    default => 86400,
                };
                if ($at - $last >= $interval) {
                    $event = 'reminder';
                    $reminders++;
                }
            }
        } elseif ($open) {
            $healthy = (int) ($previous['healthy_checks'] ?? 0) + 1;
            if ($healthy >= 2) {
                $event = 'recovery';
                $open = false;
                $healthy = 0;
            }
        }

        if ($event !== null) { $last = $at; }
        return ['event' => $event, 'state' => $check + [
            'observed_at' => $at,
            'incident_open' => $open,
            'incident_started_at' => $started,
            'healthy_checks' => $healthy,
            'reminders_sent' => $reminders,
            'last_attempt_at' => $last,
        ]];
    }
}
