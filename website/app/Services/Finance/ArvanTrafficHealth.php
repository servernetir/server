<?php

declare(strict_types=1);

namespace App\Services\Finance;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only review of the meter's saved receipt, not a billing or enforcement job.
 *
 * This does not load ArvanTrafficMeter, call run(), contact a provider, write cache,
 * or notify anyone. Supplier data freshness and full traffic coverage are separate.
 */
final class ArvanTrafficHealth
{
    public const STATE_KEY = 'arvan_traffic_meter_v1';
    public const EXPECTED_INTERVAL_SECONDS = 120 * 60;
    public const GRACE_SECONDS = 30 * 60;
    public const FUTURE_SKEW_SECONDS = 5 * 60;

    public function report(): array
    {
        try {
            // Direct SELECT avoids Setting::cached() creating a cache entry on view.
            $encrypted = DB::table('settings')->where('key', self::STATE_KEY)->value('value');
            if ($encrypted === null || $encrypted === '') {
                return self::evaluate(null, time());
            }
            if (!is_string($encrypted) || strlen($encrypted) > 4 * 1024 * 1024) {
                return self::evaluate(null, time(), 'unreadable');
            }
            $state = json_decode(Crypt::decryptString($encrypted), true, 128, JSON_THROW_ON_ERROR);
            return self::evaluate($state, time());
        } catch (Throwable) {
            // Neither a decryption/query exception nor any raw source value reaches the UI.
            return self::evaluate(null, time(), 'unreadable');
        }
    }

    public function check(): array
    {
        return self::healthRow($this->report());
    }

    /**
     * Pure evaluator. The 120+30 minute window is a local receipt-review policy,
     * not a provider data-latency promise. Unknown counts never become zero.
     */
    public static function evaluate(mixed $state, int $now, string $readStatus = 'available'): array
    {
        $r = [
            'scope' => 'meter_receipt_and_unresolved_costs',
            'observed_at_utc' => gmdate('c', $now),
            'state_status' => 'available',
            'enabled' => null, 'sequence' => null,
            'last_success_utc' => null, 'success_age_seconds' => null,
            'last_result_mode' => null, 'last_result_total_irt' => null,
            'invoice_count' => null, 'held_count' => null, 'unallocated_count' => null,
            'margin_bp' => null, 'tax_bp' => null,
            'recorded_error_present' => false,
            'policy' => ['interval_seconds' => self::EXPECTED_INTERVAL_SECONDS, 'grace_seconds' => self::GRACE_SECONDS],
            'verification' => [
                'supplier_usage_freshness' => 'unverified',
                'all_server_agent_enrollment' => 'unverified',
                'credit_or_network_enforcement' => 'unverified',
            ],
            'financial_control_complete' => false,
            'issue_codes' => [], 'level' => 'fail',
        ];
        $issues = [];
        if ($readStatus !== 'available' || !is_array($state) || array_is_list($state)) {
            $missing = $state === null && $readStatus === 'available';
            $r['state_status'] = $missing ? 'missing' : 'unreadable';
            $issues[$missing ? 'meter_state_missing' : 'meter_state_unreadable'] = 'fail';
            return self::finish($r, $issues);
        }

        if (is_bool($state['enabled'] ?? null)) {
            $r['enabled'] = $state['enabled'];
            if (!$r['enabled']) { $issues['meter_disabled'] = 'fail'; }
        } else {
            $issues['enabled_unknown'] = 'fail';
        }
        if (self::nonnegativeInt($state['sequence'] ?? null) !== null) {
            $r['sequence'] = $state['sequence'];
        } else {
            $issues['sequence_unknown'] = 'fail';
        }
        foreach (['margin_bp', 'tax_bp'] as $key) {
            $v = self::nonnegativeInt($state[$key] ?? null);
            $r[$key] = $v !== null && $v <= 100000 ? $v : null;
        }
        $success = self::timestamp($state['last_success'] ?? null);
        if ($success === null) {
            $issues['success_timestamp_unknown'] = 'fail';
        } else {
            $r['last_success_utc'] = gmdate('c', $success);
            if ($success > $now + self::FUTURE_SKEW_SECONDS) {
                $issues['success_timestamp_in_future'] = 'fail';
            } elseif ($success > $now) {
                $issues['success_clock_ahead'] = 'warn';
            } else {
                $r['success_age_seconds'] = $now - $success;
                if ($r['success_age_seconds'] > self::EXPECTED_INTERVAL_SECONDS + self::GRACE_SECONDS) {
                    $issues['meter_receipt_stale'] = 'fail';
                }
            }
        }

        // Presence is enough to request review; do not expose or interpret the error text.
        $error = $state['last_error'] ?? null;
        $r['recorded_error_present'] = (is_string($error) && trim($error) !== '')
            || (is_array($error) && $error !== []) || $error === true;
        if ($r['recorded_error_present']) { $issues['recorded_error_needs_review'] = 'warn'; }

        $result = $state['last_result'] ?? null;
        if (!is_array($result) || array_is_list($result)) {
            $issues['last_result_unknown'] = 'warn';
        } else {
            if (in_array($result['mode'] ?? null, ['apply', 'preview', 'dry-run', 'dry_run', 'baseline', 'report'], true)) {
                $r['last_result_mode'] = $result['mode'];
            }
            if ($r['last_result_mode'] !== 'apply') { $issues['last_result_not_apply'] = 'warn'; }
            $r['last_result_total_irt'] = self::nonnegativeInt($result['total_irt'] ?? null);
            if ($r['last_result_total_irt'] === null) { $issues['last_total_unknown'] = 'warn'; }
            foreach (['invoices' => 'invoice_count', 'held' => 'held_count', 'unallocated' => 'unallocated_count'] as $arrayKey => $countKey) {
                $r[$countKey] = self::countEvidence($result, $arrayKey, $countKey);
                if ($r[$countKey] === null) { $issues[$countKey.'_unknown'] = 'warn'; }
            }
            if (($r['held_count'] ?? 0) > 0) { $issues['held_costs_need_review'] = 'warn'; }
            if (($r['unallocated_count'] ?? 0) > 0) { $issues['unallocated_costs_need_review'] = 'warn'; }
        }
        return self::finish($r, $issues);
    }

    public static function healthRow(array $r): array
    {
        $messages = [
            'meter_state_missing' => 'وضعیت ذخیره‌شدهٔ متر یافت نشد.',
            'meter_state_unreadable' => 'وضعیت ذخیره‌شدهٔ متر قابل خواندن یا اعتبارسنجی نیست.',
            'meter_disabled' => 'پرچم متر خاموش است.',
            'enabled_unknown' => 'فعال‌بودن متر قابل تأیید نیست.',
            'sequence_unknown' => 'شمارهٔ اجرای متر قابل تأیید نیست.',
            'success_timestamp_unknown' => 'زمان آخرین موفقیت معتبر نیست.',
            'success_timestamp_in_future' => 'زمان موفقیت به‌طور نامعتبر در آینده ثبت شده است.',
            'success_clock_ahead' => 'ساعت رسید جلوتر از ساعت بررسی است.',
            'meter_receipt_stale' => 'رسید موفق متر از بازهٔ بررسی ۱۵۰ دقیقه‌ای قدیمی‌تر است.',
            'recorded_error_needs_review' => 'خطای ثبت‌شدهٔ متر نیازمند بررسی است.',
            'last_result_unknown' => 'نتیجهٔ آخرین اجرا قابل تأیید نیست.',
            'last_result_not_apply' => 'آخرین نتیجه، اجرای apply تأییدشده نیست.',
            'last_total_unknown' => 'مبلغ آخرین نتیجه قابل تأیید نیست.',
            'invoice_count_unknown' => 'شمار فاکتورهای نتیجه نامشخص است.',
            'held_count_unknown' => 'شمار هزینه‌های نگه‌داشته‌شده نامشخص است.',
            'unallocated_count_unknown' => 'شمار هزینه‌های بدون تخصیص نامشخص است.',
            'held_costs_need_review' => 'هزینهٔ نگه‌داشته‌شده برای بررسی وجود دارد.',
            'unallocated_costs_need_review' => 'هزینهٔ بدون تخصیص برای بررسی وجود دارد.',
        ];
        $parts = [];
        $success = self::timestamp($r['last_success_utc'] ?? null);
        if ($success !== null) {
            $localTime = (new DateTimeImmutable('@'.$success))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s');
            $parts[] = 'آخرین رسید موفق: '.$localTime.' به وقت تهران.';
        }
        if (self::nonnegativeInt($r['last_result_total_irt'] ?? null) !== null
            && self::nonnegativeInt($r['invoice_count'] ?? null) !== null) {
            $parts[] = 'آخرین نتیجهٔ ثبت‌شده: '.$r['last_result_total_irt'].' تومان در '.$r['invoice_count'].' فاکتور.';
        }
        $parts[] = 'نگه‌داشته‌شده: '.(self::nonnegativeInt($r['held_count'] ?? null) ?? 'نامشخص')
            .'؛ بدون تخصیص: '.(self::nonnegativeInt($r['unallocated_count'] ?? null) ?? 'نامشخص').'.';
        foreach (($r['issue_codes'] ?? []) as $code) {
            if (is_string($code) && isset($messages[$code])) { $parts[] = $messages[$code]; }
        }
        $parts[] = 'این بررسی فقط رسید متر را می‌خواند؛ تازگی دادهٔ آروان، پوشش agentها و محدودیت اعتبار یا شبکه را تأیید نمی‌کند.';
        $level = in_array($r['level'] ?? null, ['ok', 'warn', 'fail'], true) ? $r['level'] : 'fail';
        return ['key' => 'arvan_traffic_meter', 'ok' => $level === 'ok', 'level' => $level,
            'title' => 'رسید متر ترافیک آروان و هزینه‌های نیازمند بررسی',
            'detail' => implode(' ', $parts), 'links' => []];
    }

    private static function nonnegativeInt(mixed $v): ?int
    {
        return is_int($v) && $v >= 0 ? $v : null;
    }

    private static function countEvidence(array $result, string $arrayKey, string $countKey): ?int
    {
        $hasArray = array_key_exists($arrayKey, $result);
        $hasCount = array_key_exists($countKey, $result);
        if ($hasArray && (!is_array($result[$arrayKey]) || !array_is_list($result[$arrayKey]))) { return null; }
        $fromArray = $hasArray ? count($result[$arrayKey]) : null;
        $fromCount = $hasCount ? self::nonnegativeInt($result[$countKey]) : null;
        if ($hasCount && $fromCount === null) { return null; }
        if ($hasArray && $hasCount && $fromArray !== $fromCount) { return null; }
        return $fromArray ?? $fromCount;
    }

    private static function timestamp(mixed $v): ?int
    {
        if (!is_string($v) || strlen($v) > 40
            || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/D', $v) !== 1) {
            return null;
        }
        try {
            $d = new DateTimeImmutable($v);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) { return null; }
            return $d->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private static function finish(array $r, array $issues): array
    {
        $r['issue_codes'] = array_keys($issues);
        $r['level'] = in_array('fail', $issues, true) ? 'fail'
            : (in_array('warn', $issues, true) ? 'warn' : 'ok');
        return $r;
    }
}
