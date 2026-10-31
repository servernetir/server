<?php

namespace App\Services\Hermes;

use App\Services\Cloud\CloudManager;

/** Owner-only presentation. No inventory reconciliation or state changes. */
final class HermesAlertSummary
{
    public static function render(array $alerts, array $businessLabels = [], int $limit = 8): string
    {
        $labels = [
            'unassigned_resource' => 'منبع بدون سرویس متصل',
            'service_resource_missing' => 'منبع سرویس در موجودی پیدا نشد',
            'closed_service_still_running' => 'منبع باقی‌مانده برای سرویس بسته',
            'recorded_ip_mismatch' => 'مغایرت آدرس',
            'detached_volume_review' => 'دیسک جداشده؛ نیازمند بررسی',
            'snapshot_origin_missing_review' => 'اسنپ‌شات با سرور مبدأ ناموجود؛ نیازمند بررسی',
            'operations_run_failed' => 'اجرای پایش عملیات با خطا مواجه شد',
            'inventory_unavailable' => 'موجودی زیرساخت قابل تأیید نیست',
            'traffic_threshold' => 'نزدیک‌شدن یا عبور از ترافیک پلن',
        ] + $businessLabels;
        $groups = [];
        foreach ($alerts as $alert) {
            if (empty($alert['active']) || ! empty($alert['acknowledged_at'])) { continue; }
            $code = (string) ($alert['code'] ?? '');
            $data = (array) ($alert['data'] ?? []);
            $provider = self::identifier($data['provider'] ?? null);
            $key = $code.'|'.$provider;
            $groups[$key] ??= ['code' => $code, 'provider' => $provider, 'count' => 0, 'refs' => [], 'services' => []];
            $groups[$key]['count']++;
            $ref = self::identifier($data['ref'] ?? null);
            if ($ref !== '') { $groups[$key]['refs'][$ref] = $ref; }
            $service = $data['service_id'] ?? null;
            if ((is_int($service) || is_string($service)) && ctype_digit((string) $service) && (int) $service > 0) {
                $groups[$key]['services'][(string) $service] = '#'.$service;
            }
        }
        $lines = [];
        $explanation = array_filter($groups, fn ($group) => $group['code'] === 'unassigned_resource')
            ? '«بدون سرویس متصل» یعنی منبع در موجودی زیرساخت دیده شده ولی به رکورد سرویس سرورنت تطبیق نخورده است؛ ممکن است داخلی، آزمایشی یا نیازمند اصلاح اتصال باشد. اضافی‌بودن، هزینه قطعی یا مجوز حذف از این هشدار نتیجه نمی‌شود.' : '';
        $used = mb_strlen($explanation) + 100;
        $shown = 0;
        foreach (array_slice($groups, 0, max(0, $limit), true) as $group) {
            $line = '• '.($labels[$group['code']] ?? 'نیازمند بررسی');
            $provider = $group['provider'];
            if ($provider !== '') {
                $storage = str_ends_with($provider, '-storage');
                $base = $storage ? substr($provider, 0, -8) : $provider;
                $line .= ' — '.(CloudManager::REAL_NAMES[$base] ?? 'زیرساخت ثبت‌نشده').($storage ? ' / ذخیره‌سازی' : '');
            }
            $line .= '؛ '.$group['count'].' مورد';
            if ($group['services'] !== []) {
                $line .= '؛ سرویس: '.implode('، ', array_slice($group['services'], 0, 3));
            }
            if ($group['refs'] !== []) {
                $line .= '؛ شناسه منبع: '.implode('، ', array_slice($group['refs'], 0, 3));
            }
            if ($used + mb_strlen($line) > 1800) { break; }
            $lines[] = $line;
            $used += mb_strlen($line) + 1;
            $shown++;
        }
        $remaining = count($groups) - $shown;
        if ($remaining > 0) { $lines[] = '• '.$remaining.' گروه هشدار دیگر در مینی‌اپ ← عملیات'; }
        if ($explanation !== '') { $lines[] = $explanation; }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    private static function identifier(mixed $value): string
    {
        return (is_string($value) || is_int($value))
            && preg_match('/^[a-zA-Z0-9._:\/-]{1,80}$/D', (string) $value) ? (string) $value : '';
    }
}
