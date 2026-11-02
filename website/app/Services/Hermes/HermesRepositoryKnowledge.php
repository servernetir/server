<?php

namespace App\Services\Hermes;

/** Public develop index summaries, retained as owner references rather than deployment evidence. */
final class HermesRepositoryKnowledge
{
    public const BRANCH_URL = 'https://api.github.com/repos/servernetir/server/branches/develop';
    public const RAW_PREFIX = 'https://raw.githubusercontent.com/servernetir/server/';
    public const INDEX = 'docs/project/README.md';
    public const MAX_BYTES = 262144;
    public const MAX_DOCUMENTS = 12;

    public static function due(array $knowledge, int $now): bool
    {
        $at = $knowledge['repository_source']['checked_at'] ?? null;
        return ! is_int($at) || $at <= 0 || $at > $now || intdiv($now, 3600) > intdiv($at, 3600);
    }

    public static function commit(string $body): string
    {
        if (strlen($body) > 65536) { throw new \RuntimeException('Repository branch response too large'); }
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $sha = $data['commit']['sha'] ?? null;
        if (! is_string($sha) || ! preg_match('/^[a-f0-9]{40}$/D', $sha)) { throw new \RuntimeException('Invalid repository commit'); }
        return $sha;
    }

    public static function parse(string $body, string $sha, int $now): array
    {
        if (! preg_match('/^[a-f0-9]{40}$/D', $sha) || strlen($body) > self::MAX_BYTES
            || ! mb_check_encoding($body, 'UTF-8')) { throw new \RuntimeException('Invalid repository index'); }
        $documents = []; $undated = 0;
        foreach (preg_split('/\R\s*\R/u', trim($body)) ?: [] as $paragraph) {
            if (! preg_match('/\[([^\]\r\n]{1,200})\]\(([^)\s]{1,220}\.md)\)/u', $paragraph, $link)) { continue; }
            $path = self::reportPath($link[2]);
            if ($path === null) { continue; }
            // Dates come from the linked report name, never the download time.
            if (! preg_match('/(20[0-9]{2})-([0-9]{2})-([0-9]{2})/', $path, $date)
                || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])) { $undated++; continue; }
            $sourceDate = $date[0];
            if ($sourceDate > gmdate('Y-m-d', $now)) { $undated++; continue; }
            $plain = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $paragraph);
            $plain = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', strip_tags((string) $plain)) ?? '');
            if ($plain === '' || HermesConversationMemory::sensitive($plain)) { continue; }
            $id = 'repo-index-'.substr(hash('sha256', $path), 0, 32);
            $documents[$id] = ['id' => $id, 'title' => mb_substr(trim($link[1]), 0, 160),
                'text' => mb_substr('Repository index summary only; Git reports do not verify current production. Linked report: '.$path.'. '.$plain, 0, 1800),
                'topics' => ['project:servernet', 'develop', 'انتشار', 'تغییرات'],
                'source_type' => 'repository_document', 'source_ref' => 'https://github.com/servernetir/server/blob/'.$sha.'/'.self::INDEX,
                'source_date' => $sourceDate, 'status' => 'documented'];
            if (count($documents) >= self::MAX_DOCUMENTS) { break; }
        }
        if ($documents === []) { throw new \RuntimeException('No dated repository summaries'); }
        return ['commit' => $sha, 'documents' => array_values($documents), 'undated_entries_skipped' => $undated];
    }

    /** Rotate only this source's managed records, atomically; retain all last-good data on failure. */
    public static function record(array &$knowledge, ?array $parsed, int $now, ?string $failure = null): void
    {
        $previous = (array) ($knowledge['repository_source'] ?? []);
        if ($parsed !== null) {
            try {
                if (! is_string($parsed['commit'] ?? null) || ! preg_match('/^[a-f0-9]{40}$/D', $parsed['commit'])
                    || ! is_array($parsed['documents'] ?? null) || $parsed['documents'] === []
                    || count($parsed['documents']) > self::MAX_DOCUMENTS) { throw new \RuntimeException('Invalid repository batch'); }
                $next = $knowledge;
                $next['reference_documents'] = array_filter((array) ($next['reference_documents'] ?? []), static function ($row): bool {
                    return ! (is_array($row) && is_string($row['id'] ?? null)
                        && preg_match('/^repo-index-[a-f0-9]{32}$/D', $row['id'])
                        && ($row['source_type'] ?? '') === 'repository_document' && ($row['audience'] ?? '') === 'owner_private'
                        && is_string($row['source_ref'] ?? null)
                        && preg_match('~^https://github\.com/servernetir/server/blob/[a-f0-9]{40}/docs/project/README\.md$~D', $row['source_ref']));
                });
                HermesKnowledge::importReferences($next, $parsed['documents'], $now);
                $next['repository_source'] = ['status' => 'ok', 'checked_at' => $now, 'last_success_at' => $now,
                    'commit' => $parsed['commit'], 'document_count' => count($parsed['documents']),
                    'undated_entries_skipped' => max(0, (int) ($parsed['undated_entries_skipped'] ?? 0)),
                    'source' => 'public_develop_project_index', 'current_production_verified' => false];
                $knowledge = $next;
                return;
            } catch (\Throwable) {
                $failure = 'reference_import_unavailable';
            }
        }
        $knowledge['repository_source'] = array_intersect_key($previous, array_flip(['last_success_at', 'commit', 'document_count', 'undated_entries_skipped']))
            + ['status' => 'degraded', 'checked_at' => $now,
                'failure' => in_array($failure, ['connection_error', 'http_error', 'invalid_source', 'reference_import_unavailable'], true) ? $failure : 'invalid_source',
                'source' => 'public_develop_project_index', 'current_production_verified' => false];
    }

    public static function context(array $knowledge, int $now): array
    {
        $row = (array) ($knowledge['repository_source'] ?? []);
        $success = $row['last_success_at'] ?? null;
        return array_intersect_key($row, array_flip(['checked_at', 'last_success_at', 'commit', 'document_count', 'failure', 'undated_entries_skipped']))
            + ['status' => in_array($row['status'] ?? '', ['ok', 'degraded'], true) ? $row['status'] : 'not_checked',
                'stale' => ! is_int($success) || $success <= 0 || $success > $now || $now - $success > 7200,
                'source' => 'public_develop_project_index', 'current_production_verified' => false];
    }

    private static function reportPath(string $link): ?string
    {
        // Resolve only repository-relative Markdown paths, never external links or arbitrary downloads.
        if (! preg_match('~^(?:\.\./)*[A-Za-z0-9_./-]+\.md$~D', $link)) { return null; }
        $parts = ['docs', 'project'];
        foreach (explode('/', $link) as $part) {
            if ($part === '..') { if ($parts === []) { return null; } array_pop($parts); }
            elseif ($part !== '.' && $part !== '') { $parts[] = $part; }
        }
        return implode('/', $parts);
    }
}
