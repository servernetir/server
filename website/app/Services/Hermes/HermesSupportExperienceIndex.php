<?php

namespace App\Services\Hermes;

use Illuminate\Support\Facades\Crypt;

/** Private immutable pages; operations state contains only an authenticated reference. */
final class HermesSupportExperienceIndex
{
    private const PAGE_CASES = 256;
    private const PAGE_BYTES = 524288;
    private const FILE_BYTES = 2097152;
    private const MANIFEST_BYTES = 8388608;
    private const CASE_KEYS = ['ticket_id', 'customer_id', 'department', 'reply_message_id', 'reply_at',
        'source_digest', 'topics', 'staff_reported_tags', 'outcome', 'reference_only', 'closing_acknowledgement'];
    private const SUMMARY_KEYS = ['version', 'observed_at', 'source', 'source_scope', 'source_ticket_count',
        'source_message_count', 'bounded_inventory_complete', 'topic_counts', 'department_counts',
        'confirmed_case_count', 'grants_permissions', 'snapshot_max_ticket_id', 'snapshot_max_message_id', 'compiler_version'];

    public function __construct(private ?string $directory = null) {}

    /** Producer appends bounded compiled batches and returns aggregate metadata. */
    public function publish(array $owner, callable $produce, array $previous = []): array
    {
        $ownerHash = self::ownerHash($owner);
        $directory = $this->ownerDirectory($owner, true);
        $generation = bin2hex(random_bytes(16));
        $staging = $directory.'/.building-'.$generation;
        if (! mkdir($staging, 0700)) { throw new \RuntimeException('Experience staging unavailable'); }
        $pending = []; $pendingBytes = 2; $pages = []; $count = 0;
        $contentHash = hash_init('sha256');
        $flush = function () use (&$pending, &$pendingBytes, &$pages, &$count, $staging, $generation, $ownerHash): void {
            if ($pending === []) { return; }
            $number = count($pages);
            $encoded = Crypt::encryptString(json_encode(['owner_hash' => $ownerHash, 'generation' => $generation,
                'page' => $number, 'cases' => $pending], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if (strlen($encoded) > self::FILE_BYTES) { throw new \LengthException('Experience page limit'); }
            $this->write($staging.'/'.self::pageName($number), $encoded);
            $pages[] = ['sha256' => hash('sha256', $encoded), 'bytes' => strlen($encoded), 'count' => count($pending)];
            $count += count($pending);
            $pending = []; $pendingBytes = 2;
        };
        $append = function (iterable $cases) use (&$pending, &$pendingBytes, $flush, $contentHash): void {
            foreach ($cases as $case) {
                $case = self::safeCase($case);
                $json = json_encode($case, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                hash_update($contentHash, $json."\n");
                $bytes = strlen($json) + 1;
                if ($bytes > self::PAGE_BYTES) { throw new \LengthException('Experience case limit'); }
                if (count($pending) >= self::PAGE_CASES || $pendingBytes + $bytes > self::PAGE_BYTES) { $flush(); }
                $pending[] = $case; $pendingBytes += $bytes;
            }
        };
        // An interrupted build has no published reference and cannot replace the
        // previous generation. Its private encrypted staging files are inert.
        $summary = $produce($append);
        if (! is_array($summary)) { throw new \RuntimeException('Experience summary unavailable'); }
        $flush();
        $summary = array_intersect_key($summary, array_flip(self::SUMMARY_KEYS));
        $summary['case_count'] = $count;
        $summary['grants_permissions'] = false;
        $stableSummary = $summary; unset($stableSummary['observed_at']);
        hash_update($contentHash, json_encode($stableSummary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $summary['content_sha256'] = hash_final($contentHash);
        $summary['checked_at'] = $summary['observed_at'] ?? time();
        if (($previous['content_sha256'] ?? '') === $summary['content_sha256'] && is_array($previous['index_ref'] ?? null)) {
            try {
                $existing = $this->summary($owner, $previous['index_ref']);
                if (($existing['content_sha256'] ?? '') === $summary['content_sha256']) {
                    $this->verify($owner, $previous['index_ref']);
                    $this->removeGeneration($staging);
                    return array_replace($existing, ['checked_at' => $summary['checked_at']]);
                }
            } catch (\Throwable) { /* Missing/corrupt prior manifest is replaced by this complete build. */ }
        }
        if (strlen(json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 65536) {
            throw new \LengthException('Experience summary limit');
        }
        $manifest = ['format' => 'encrypted_pages_v1', 'owner_hash' => $ownerHash, 'generation' => $generation,
            'summary' => $summary, 'pages' => $pages, 'case_count' => $count];
        $encoded = Crypt::encryptString(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if (strlen($encoded) > self::MANIFEST_BYTES) { throw new \LengthException('Experience manifest limit'); }
        $this->write($staging.'/manifest.enc', $encoded);
        if (! rename($staging, $directory.'/'.$generation)) { throw new \RuntimeException('Experience publish unavailable'); }
        return $summary + ['index_ref' => ['format' => 'encrypted_pages_v1', 'owner_hash' => $ownerHash,
            'generation' => $generation, 'manifest_sha256' => hash('sha256', $encoded)]];
    }

    /** Safe summary only; no case list is ever copied into operations state. */
    public function summary(array $owner, array $reference): array
    {
        $manifest = $this->manifest($owner, $reference);
        return $manifest['summary'] + ['index_ref' => $reference];
    }

    /** Fully consume this iterator before using its result; corruption throws. */
    public function cases(array $owner, array $reference): \Generator
    {
        $lock = $this->lock($owner, '.read.lock', LOCK_SH);
        try {
            $manifest = $this->manifest($owner, $reference);
            $directory = $this->ownerDirectory($owner, false).'/'.$reference['generation'];
            $count = 0;
            foreach ($manifest['pages'] as $number => $expected) {
                $encoded = $this->read($directory.'/'.self::pageName($number), self::FILE_BYTES);
                if (strlen($encoded) !== $expected['bytes'] || ! hash_equals($expected['sha256'], hash('sha256', $encoded))) {
                    throw new \RuntimeException('Experience page integrity failure');
                }
                $page = json_decode(Crypt::decryptString($encoded), true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($page) || ($page['owner_hash'] ?? '') !== $reference['owner_hash']
                    || ($page['generation'] ?? '') !== $reference['generation'] || ($page['page'] ?? null) !== $number
                    || ! is_array($page['cases'] ?? null) || ! array_is_list($page['cases'])
                    || count($page['cases']) !== $expected['count']) {
                    throw new \RuntimeException('Experience page binding failure');
                }
                foreach ($page['cases'] as $case) { ++$count; yield self::safeCase($case); }
            }
            if ($count !== $manifest['case_count']) { throw new \RuntimeException('Experience coverage mismatch'); }
    
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Serialize source snapshots, pointer commit and retention for this owner. */
    public function synchronized(array $owner, callable $callback): mixed
    {
        $lock = $this->lock($owner, '.writer.lock', LOCK_EX);
        try { return $callback(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /**
     * After the pointer commit, retain current + previous and a five-minute race
     * grace. Active readers hold a shared lock; GC skips instead of disrupting them.
     */
    public function collect(array $owner, array $references, ?int $now = null): int
    {
        $now ??= time(); $directory = $this->ownerDirectory($owner, true); $keep = [];
        foreach ($references as $reference) {
            if (is_array($reference) && ($reference['owner_hash'] ?? '') === self::ownerHash($owner)
                && preg_match('/^[a-f0-9]{32}$/D', (string) ($reference['generation'] ?? ''))) {
                $keep[$reference['generation']] = true;
            }
        }
        $lock = $this->lock($owner, '.read.lock', LOCK_EX | LOCK_NB, false);
        if ($lock === null) { return 0; }
        try {
            $removed = 0;
            foreach (scandir($directory) ?: [] as $name) {
                if (! preg_match('/^(?:[a-f0-9]{32}|\.building-[a-f0-9]{32})$/D', $name)
                    || isset($keep[$name])) { continue; }
                $path = $directory.'/'.$name;
                if (is_link($path) || ! is_dir($path) || (int) filemtime($path) > $now - 300) { continue; }
                $removed += $this->removeGeneration($path) ? 1 : 0;
            }
            return $removed;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Readback acceptance without exposing case metadata or contacting a model. */
    public function verify(array $owner, array $reference): array
    {
        $summary = $this->summary($owner, $reference); $count = 0;
        foreach ($this->cases($owner, $reference) as $case) { ++$count; }
        if ($count !== $summary['case_count']) { throw new \RuntimeException('Experience count mismatch'); }
        return ['verified' => true, 'case_count' => $count, 'generation' => $reference['generation'],
            'manifest_sha256' => $reference['manifest_sha256']];
    }

    private function manifest(array $owner, array $reference): array
    {
        $ownerHash = self::ownerHash($owner);
        if (($reference['format'] ?? '') !== 'encrypted_pages_v1'
            || ! hash_equals($ownerHash, (string) ($reference['owner_hash'] ?? ''))
            || ! preg_match('/^[a-f0-9]{32}$/D', (string) ($reference['generation'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($reference['manifest_sha256'] ?? ''))) {
            throw new \RuntimeException('Experience reference unavailable');
        }
        $directory = $this->ownerDirectory($owner, false).'/'.$reference['generation'];
        if (is_link($directory) || ! is_dir($directory)) { throw new \RuntimeException('Experience generation unavailable'); }
        $encoded = $this->read($directory.'/manifest.enc', self::MANIFEST_BYTES);
        if (! hash_equals($reference['manifest_sha256'], hash('sha256', $encoded))) {
            throw new \RuntimeException('Experience manifest integrity failure');
        }
        $manifest = json_decode(Crypt::decryptString($encoded), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['format'] ?? '') !== 'encrypted_pages_v1'
            || ($manifest['owner_hash'] ?? '') !== $ownerHash || ($manifest['generation'] ?? '') !== $reference['generation']
            || ! is_array($manifest['pages'] ?? null) || ! array_is_list($manifest['pages'])
            || ! is_array($manifest['summary'] ?? null) || ! is_int($manifest['case_count'] ?? null)
            || ($manifest['summary']['case_count'] ?? null) !== $manifest['case_count']) {
            throw new \RuntimeException('Experience manifest binding failure');
        }
        $count = 0;
        foreach ($manifest['pages'] as $page) {
            if (! is_array($page) || ! is_int($page['count'] ?? null) || $page['count'] < 1 || $page['count'] > self::PAGE_CASES
                || ! is_int($page['bytes'] ?? null) || $page['bytes'] < 1 || $page['bytes'] > self::FILE_BYTES
                || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($page['sha256'] ?? ''))) {
                throw new \RuntimeException('Experience manifest page unavailable');
            }
            $count += $page['count'];
        }
        if ($count !== $manifest['case_count']) { throw new \RuntimeException('Experience manifest coverage mismatch'); }
        return $manifest;
    }

    private static function safeCase(mixed $case): array
    {
        if (! is_array($case)) { throw new \RuntimeException('Experience case unavailable'); }
        $case = array_intersect_key($case, array_flip(self::CASE_KEYS));
        foreach (['ticket_id', 'customer_id', 'reply_message_id'] as $key) {
            if (! is_int($case[$key] ?? null) || $case[$key] < 1) { throw new \RuntimeException('Experience case identity unavailable'); }
        }
        foreach (['topics', 'staff_reported_tags'] as $key) {
            if (! is_array($case[$key] ?? null) || ! array_is_list($case[$key]) || count($case[$key]) > 32) {
                throw new \RuntimeException('Experience case tags unavailable');
            }
            foreach ($case[$key] as $value) {
                if (! is_string($value) || ! preg_match('/^[a-z][a-z0-9_]{0,119}$/D', $value)) {
                    throw new \RuntimeException('Experience case tag unavailable');
                }
            }
        }
        if (! is_string($case['department'] ?? null) || strlen($case['department']) > 200
            || ! is_string($case['reply_at'] ?? null) || strlen($case['reply_at']) > 100
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($case['source_digest'] ?? ''))
            || ! in_array($case['outcome'] ?? '', ['awaiting_customer_confirmation', 'customer_reports_continuing',
                'customer_confirmed_report', 'no_explicit_outcome', 'staff_closed_unconfirmed'], true)
            || ($case['reference_only'] ?? null) !== true) {
            throw new \RuntimeException('Experience case metadata unavailable');
        }
        $case['closing_acknowledgement'] = ($case['closing_acknowledgement'] ?? false) === true;
        return $case;
    }

    private static function ownerHash(array $owner): string
    {
        if (! ctype_digit((string) ($owner['user_id'] ?? '')) || ! ctype_digit((string) ($owner['chat_id'] ?? ''))) {
            throw new \InvalidArgumentException('Experience owner unavailable');
        }
        return hash('sha256', $owner['user_id'].':'.$owner['chat_id']);
    }

    private function ownerDirectory(array $owner, bool $create): string
    {
        $root = $this->directory ?? storage_path('app/private/hermes-support-experience');
        $directory = $root.'/'.self::ownerHash($owner);
        foreach ([$root, $directory] as $path) {
            if (is_link($path)) { throw new \RuntimeException('Experience path unavailable'); }
            if (! is_dir($path) && (! $create || (! @mkdir($path, 0700, true) && ! is_dir($path)))) {
                throw new \RuntimeException('Experience directory unavailable');
            }
            if ($create) { chmod($path, 0700); }
        }
        return $directory;
    }

    private function lock(array $owner, string $name, int $mode, bool $required = true)
    {
        $path = $this->ownerDirectory($owner, true).'/'.$name;
        if (is_link($path)) { throw new \RuntimeException('Experience lock unavailable'); }
        $lock = fopen($path, 'c+b');
        if (! $lock) { throw new \RuntimeException('Experience lock unavailable'); }
        chmod($path, 0600);
        if (! flock($lock, $mode)) {
            fclose($lock);
            if (! $required) { return null; }
            throw new \RuntimeException('Experience lock unavailable');
        }
        return $lock;
    }

    /** Only known files in a validated owner generation may be removed. */
    private function removeGeneration(string $path): bool
    {
        if (is_link($path) || ! is_dir($path)
            || ! preg_match('/^(?:[a-f0-9]{32}|\.building-[a-f0-9]{32})$/D', basename($path))) { return false; }
        $files = [];
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') { continue; }
            $file = $path.'/'.$name;
            if (! preg_match('/^(?:manifest\.enc|page-[0-9]{8}\.enc)$/D', $name)
                || is_link($file) || ! is_file($file)) { return false; }
            $files[] = $file;
        }
        foreach ($files as $file) { if (! unlink($file)) { return false; } }
        return rmdir($path);
    }

    private function write(string $path, string $encoded): void
    {
        $file = fopen($path, 'xb');
        if (! $file) { throw new \RuntimeException('Experience write unavailable'); }
        chmod($path, 0600);
        try {
            $offset = 0;
            while ($offset < strlen($encoded)) {
                $bytes = fwrite($file, substr($encoded, $offset));
                if (! is_int($bytes) || $bytes < 1) { throw new \RuntimeException('Experience write failed'); }
                $offset += $bytes;
            }
            if (! fflush($file)) { throw new \RuntimeException('Experience flush failed'); }
        } finally { fclose($file); }
    }

    private function read(string $path, int $limit): string
    {
        if (is_link($path) || ! is_file($path) || filesize($path) > $limit) {
            throw new \RuntimeException('Experience file unavailable');
        }
        $encoded = file_get_contents($path);
        if (! is_string($encoded) || $encoded === '' || strlen($encoded) > $limit) {
            throw new \RuntimeException('Experience read failed');
        }
        return $encoded;
    }

    private static function pageName(int $number): string { return sprintf('page-%08d.enc', $number); }
}
