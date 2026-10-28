<?php

namespace App\Services\Hermes;

/** Narrow contradictions with application facts, not general semantic verification. */
final class HermesSupportEvidenceGuard
{
    public static function accepts(array $review, array $evidence, ?int $now = null): bool
    {
        $now ??= time(); $account = $evidence['service_investigation'] ?? [];
        $serviceId = $evidence['dossier']['service']['id'] ?? null;
        $at = $account['checked_at'] ?? null;
        if (is_int($serviceId) && ($account['service_id'] ?? null) === $serviceId
            && ($account['result'] ?? '') === 'verified' && ($account['source'] ?? '') === 'servernet_database_and_wallet'
            && is_int($at) && $at >= $now - 900 && $at <= $now + 60
            && ($account['wallet']['unit'] ?? '') === 'IRT' && is_int($account['wallet']['ledger_balance_irt'] ?? null)
            && $account['wallet']['ledger_balance_irt'] > 0) {
            $text = str_replace(['‌', 'ي', 'ك'], [' ', 'ی', 'ک'], ($review['analysis'] ?? '').' '.($review['reply'] ?? ''));
            if (preg_match('/موجودی(?: کل)?(?: کیف پول)?(?: شما)? (?:به )?صفر (?:است|شده)|اعتبار کل(?: شما)? تمام شده|total (?:wallet )?balance (?:is|has reached) (?:zero|0)\b/iu', $text)) { return false; }
        }
        return true;
    }
}
