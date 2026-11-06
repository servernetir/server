<?php

namespace App\Services\Hermes;

/** Owner-only projection of the independent notifier; never executes recovery. */
final class HermesRelayMonitor
{
    public const SCHEMA = 'servernet.hermes.relay-monitor.v1';
    public const STATUS = '/home/servernetcloud/.hermes-assistant-20261102/relay-notifier/status.json';
    public const EVENTS = ['relay_outage', 'relay_recovered', 'relay_monitor_unavailable', 'relay_monitor_restored', 'relay_access_unavailable', 'relay_access_restored'];

    public static function snapshot(int $now, string $path = self::STATUS): array
    {
        $unknown = ['state' => 'unknown', 'at' => null, 'observation_at' => null, 'reason' => 'monitor_unavailable'];
        try {
            if (! is_file($path) || is_link($path) || filesize($path) > 8192 || (fileperms($path) & 0077) !== 0) { return $unknown; }
            $row = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            if (($row['schema'] ?? '') !== self::SCHEMA || ! is_int($row['at'] ?? null)
                || $row['at'] > $now || $now - $row['at'] > 120) {
                return $unknown + ['stale' => true];
            }
            $state = in_array($row['state'] ?? '', ['unknown', 'suspect', 'down', 'recovering', 'healthy'], true) ? $row['state'] : 'unknown';
            $observed = $row['observation_at'] ?? null;
            if ($state !== 'unknown' && (! is_int($observed) || $observed > $now || $now - $observed > 120)) { return $unknown + ['stale' => true]; }
            $access = is_array($row['access_path'] ?? null) ? $row['access_path'] : [];
            $accessAt = $access['at'] ?? null;
            $accessState = is_int($accessAt) && $accessAt <= $now && $now - $accessAt <= 120
                && in_array($access['state'] ?? '', ['available', 'unavailable'], true) ? $access['state'] : 'unknown';
            return ['state' => $state, 'at' => $row['at'], 'observation_at' => is_int($observed) ? $observed : null,
                'source' => 'independent_relay_controller_observer', 'stale' => false,
                'access_path' => ['state' => $accessState, 'at' => $accessState === 'unknown' ? null : $accessAt],
                'outage_open' => ($row['outage_open'] ?? false) === true,
                'notification' => is_array($row['notification'] ?? null) ? array_intersect_key($row['notification'], array_flip(['type', 'at', 'status'])) : null];
        } catch (\Throwable) { return $unknown; }
    }

    /** Validate fixed local event fields before composing an owner notification. */
    public static function text(array $event, int $now): string
    {
        if (($event['schema'] ?? '') !== self::SCHEMA || ! in_array($event['type'] ?? '', self::EVENTS, true)
            || ! is_string($event['event_id'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $event['event_id'])
            || ! is_int($event['at'] ?? null) || $event['at'] > $now || $now - $event['at'] > 180) {
            throw new \InvalidArgumentException('Invalid relay event');
        }
        $line = match ($event['type']) {
            'relay_outage' => "🔴 قطعی رله تأیید شد\nمشاهدهٔ ناموفق پیوسته از آستانهٔ پایش گذشته است.",
            'relay_recovered' => "🟢 ارتباط رله بازیابی شد\nپایداری ارتباط در دورهٔ پایش تأیید شد.",
            'relay_monitor_restored' => "📡 دریافت اطلاعات پایش رله برقرار شد\nوضعیت ارتباط در حال بررسی است؛ این پیام به‌تنهایی تأیید سلامت رله نیست.",
            'relay_access_unavailable' => "🔴 مسیر مدیریتی رله در دسترس نیست\nبررسی مستقل از سرور پایش چند بار ناموفق بوده است؛ علت می‌تواند رله، شبکه یا مقصد پراکس‌ماکس باشد.",
            'relay_access_restored' => "🟢 مسیر مدیریتی رله دوباره در دسترس است\nدو بررسی مستقل تازه موفق بود؛ این نتیجه مربوط به همین مسیر است.",
            default => "⚠️ اطلاعات تازهٔ پایش رله در دسترس نیست\nقطعی یا سلامت خود رله هنوز تأیید نشده است؛ پایش مستقل به بررسی نیاز دارد.",
        };
        $date = (new \DateTimeImmutable('@'.$event['at']))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i');
        return "🤖 نوا\n\n".$line."\n\nزمان بررسی: ".$date.' تهران';
    }
}
