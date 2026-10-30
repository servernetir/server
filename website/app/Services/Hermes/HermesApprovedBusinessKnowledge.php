<?php

namespace App\Services\Hermes;

use App\Services\Growth\Redactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Reviewed procedural guidance only; never current service facts or execution rights. */
final class HermesApprovedBusinessKnowledge
{
    public const VERSION = 'approved-business-knowledge-v1';
    public const MAX_BYTES = 3500;
    private const COLUMNS = ['id', 'title', 'body', 'status', 'classification', 'scope', 'kind', 'version', 'verified_at', 'review_at'];

    public function read(string $query = '', bool $ownerPrivate = false): array
    {
        try {
            if (! Schema::hasTable('growth_documents')) {
                return self::unavailable('schema_unavailable');
            }
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('growth_documents', $column)) {
                    return self::unavailable('schema_unavailable');
                }
            }
            // Internal documents may contain another customer's examples. They
            // are strictly owner-only even when their scope is general.
            $rows = DB::table('growth_documents')->where('status', 'verified')
                ->where('scope', 'general')->where('kind', 'runbook')
                ->whereIn('classification', $ownerPrivate ? ['public', 'internal'] : ['public'])
                ->whereNotNull('verified_at')->where('review_at', '>', now())
                ->orderByDesc('verified_at')->orderByDesc('id')->limit(60)->get(self::COLUMNS);

            return self::project($rows->map(fn ($row) => (array) $row)->all(), $query, time(), $ownerPrivate);
        } catch (Throwable) {
            // No raw exception, source URL, SQL or customer detail enters prompts.
            return self::unavailable('read_failed');
        }
    }

    /** Also rechecks eligibility at the projection boundary; there is no stale cache. */
    public static function project(array $rows, string $query, int $now, bool $ownerPrivate = false): array
    {
        $terms = array_slice(array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', self::normalize(mb_substr($query, 0, 1200))) ?: [],
            fn ($term) => mb_strlen($term) > 2 && ! in_array($term, ['برای', 'لطفا', 'سلام', 'چطور', 'the', 'and', 'with'], true)
        ))), 0, 16);
        $ranked = [];
        foreach (array_slice($rows, 0, 60) as $row) {
            $verified = strtotime((string) ($row['verified_at'] ?? ''));
            $review = strtotime((string) ($row['review_at'] ?? ''));
            if (($row['status'] ?? '') !== 'verified' || ($row['scope'] ?? '') !== 'general'
                || ($row['kind'] ?? '') !== 'runbook'
                || ! in_array($row['classification'] ?? '', $ownerPrivate ? ['public', 'internal'] : ['public'], true)
                || ! $verified || $verified > $now + 60 || ! $review || $review <= $now
                || (int) ($row['id'] ?? 0) < 1 || (int) ($row['version'] ?? 0) < 1) {
                continue;
            }
            $text = trim(strip_tags((string) ($row['body'] ?? '')));
            $screened = ($row['title'] ?? '').' '.$text;
            // Reject credential-bearing documents for both audiences. Generic
            // discussion is allowed in owner chat; the native support writer's
            // stricter vocabulary screen must remain compatible and unchanged.
            if ($text === '' || preg_match('/(?:password|passwd|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|secret|رمز(?:\s*عبور)?|توکن)\s*[:=]\s*\S+|\bbearer\s+[a-z0-9._-]{8,}|\bsk-[a-z0-9_-]{8,}|\bAIza[a-z0-9_-]{15,}|BEGIN [^-]*PRIVATE KEY|ssh-(?:rsa|ed25519)\s|\b(?:vless|vmess|trojan):\/\//iu', $screened)) {
                continue;
            }
            if (! $ownerPrivate && preg_match('/password|passwd|api[_ -]?key|access[_ -]?token|secret|رمز\s*عبور|توکن|PRIVATE KEY/iu', $screened)) {
                continue;
            }
            $title = self::clean((string) ($row['title'] ?? ''));
            $paragraphs = preg_split('/\R+/u', $text) ?: [$text];
            $parts = [];
            foreach (array_slice($paragraphs, 0, 100) as $position => $paragraph) {
                $normal = self::normalize($paragraph);
                $score = count(array_filter($terms, fn ($term) => str_contains($normal, $term)));
                $parts[] = ['text' => $paragraph, 'score' => $score, 'position' => $position];
            }
            usort($parts, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['position'] <=> $b['position']));
            $score = ($parts[0]['score'] ?? 0) + count(array_filter($terms, fn ($term) => str_contains(self::normalize($title), $term)));
            if ($terms && $score === 0) {
                continue;
            }
            $ranked[] = ['score' => $score, 'verified' => $verified, 'id' => (int) $row['id'], 'reference' => [
                'citation' => 'knowledge:'.(int) $row['id'].':v'.(int) $row['version'],
                'title' => mb_substr($title, 0, 100),
                'excerpt' => mb_substr(self::clean($parts[0]['text'] ?? ''), 0, 650),
                'verified_at' => gmdate('c', $verified), 'review_at' => gmdate('c', $review),
            ]];
        }
        usort($ranked, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['verified'] <=> $a['verified']) ?: ($b['id'] <=> $a['id']));
        $result = ['available' => true, 'version' => self::VERSION, 'checked_at' => $now,
            'audience' => $ownerPrivate ? 'owner_private' : 'customer_safe_public_runbooks',
            'references' => [], 'reference_only' => true, 'current_state_verified' => false,
            'grants_execution_rights' => false,
            'instruction' => 'Use these reviewed runbooks as procedural reference only. They do not establish current service health, prices or completed actions. Existing authority and exact customer-send approval remain unchanged. Never quote owner-private material to customers.'];
        foreach (array_slice($ranked, 0, 2) as $row) {
            $candidate = $result;
            $candidate['references'][] = $row['reference'];
            if (strlen(json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) <= self::MAX_BYTES) {
                $result = $candidate;
            }
        }

        return $result;
    }

    public static function unavailable(string $reason): array
    {
        return ['available' => false, 'version' => self::VERSION, 'reason' => $reason,
            'references' => [], 'reference_only' => true, 'current_state_verified' => false, 'grants_execution_rights' => false];
    }

    private static function clean(string $text): string
    {
        return (new Redactor())->clean(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', strip_tags($text)) ?? '');
    }

    private static function normalize(string $text): string
    {
        return mb_strtolower(str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ' '], $text));
    }
}
