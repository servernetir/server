<?php

declare(strict_types=1);

namespace ServerNet\DomainUiRelease20261102;

/** Private, fixed-scope UI release engine. This file must remain outside public_html. */
final class Engine
{
    private const HOME = '/home/servernetcloud';
    private const APP = self::HOME.'/servernet_app';
    private const STAGE = self::HOME.'/domain-ui-release-20261102';
    private const PHP = '/opt/cpanel/ea-php84/root/usr/bin/php';
    private const RELEASE = 'domain-ui-20261102';
    private const VIEWS = [
        'servernet_app/resources/views/pages/home.blade.php',
        'servernet_app/resources/views/pages/domain-search.blade.php',
    ];
    private const ALLOWED = [
        ...self::VIEWS,
        'servernet_app/lang/fa/domain-discovery.php',
        'servernet_app/lang/en/domain-discovery.php',
        'servernet_app/lang/tr/domain-discovery.php',
        'servernet_app/public/assets/js/domain-home-discovery.js',
        'servernet_app/public/assets/css/domain-home-discovery.css',
        'public_html/assets/js/domain-home-discovery.js',
        'public_html/assets/css/domain-home-discovery.css',
    ];
    private const PROTECTED = [
        'servernet_app/app/Http/Controllers/DomainSearchController.php',
        'servernet_app/app/Http/Controllers/DomainCheckController.php',
        'servernet_app/app/Services/Domain/DomainSearch.php',
        'servernet_app/app/Services/Domain/TldPriceBook.php',
        'servernet_app/app/Http/Middleware/PageCache.php',
        'servernet_app/resources/views/layouts/site.blade.php',
        'servernet_app/routes/web.php', 'servernet_app/routes/console.php',
        'public_html/assets/js/site.js', 'public_html/assets/css/site.css',
        'public_html/assets/css/site.min.css',
    ];
    private array $plan;
    private array $locks = [];
    private int $uid;
    private string $payloadHash;
    private object $app;

    /** Authenticated launcher's GET diagnostic: raw hashes only, never file contents. */
    public static function inspect(string $payloadHash): array
    {
        $engine = new self;
        try {
            $engine->load($payloadHash); $out = [];
            foreach (self::ALLOWED as $relative) {
                $file = self::HOME.'/'.$relative; $hash = $engine->hash($file);
                $out[] = ['destination' => $relative, 'sha256' => $hash,
                    'bytes' => $hash === 'absent' ? null : filesize($file),
                    'mode' => $hash === 'absent' ? null : fileperms($file) & 0777,
                    'uid' => $hash === 'absent' ? null : fileowner($file)];
            }
            $protected = [];
            // load() has already confined these entries to the fixed protected allowlist.
            foreach ($engine->plan['protected'] ?? [] as $entry) {
                $file = self::HOME.'/'.$entry['destination']; $hash = $engine->hash($file);
                $protected[] = ['destination' => $entry['destination'], 'sha256' => $hash,
                    'bytes' => $hash === 'absent' ? null : filesize($file),
                    'mode' => $hash === 'absent' ? null : fileperms($file) & 0777,
                    'uid' => $hash === 'absent' ? null : fileowner($file)];
            }
            return ['ok' => true, 'account_uid' => $engine->uid, 'files' => $out, 'protected_files' => $protected];
        } catch (\Throwable) { return ['ok' => false, 'reason' => 'inventory_guard_failed']; }
    }

    public static function run(object $app, string $phase, string $payloadHash): array
    {
        $engine = new self;
        try {
            if (! in_array($phase, ['preview', 'apply', 'verify', 'rollback'], true)) throw new \RuntimeException('invalid_phase');
            $engine->app = $app;
            $engine->load($payloadHash);
            $engine->lock();
            $result = match ($phase) {
                'preview' => $engine->preview($app),
                'apply' => $engine->apply($app),
                'verify' => $engine->verify(),
                'rollback' => $engine->rollback(),
                default => throw new \RuntimeException('invalid_phase'),
            };
            return ['release' => self::RELEASE, 'phase' => $phase, 'ok' => true] + $result;
        } catch (\Throwable $e) {
            return ['release' => self::RELEASE, 'phase' => $phase, 'ok' => false,
                'reason' => $e instanceof \RuntimeException && preg_match('/^[a-z_]+$/D', $e->getMessage())
                    ? $e->getMessage() : 'inspect_private_receipt'];
        } finally {
            foreach (array_reverse($engine->locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private function load(string $hash): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new \RuntimeException('payload_pin_missing');
        if (is_link(self::HOME) || realpath(self::HOME) !== self::HOME || is_link(self::STAGE)
            || realpath(self::STAGE) !== self::STAGE) throw new \RuntimeException('invalid_stage');
        $file = self::STAGE.'/payload.json';
        if (is_link($file) || ! is_file($file) || filesize($file) > 2000000
            || hash_file('sha256', $file) !== $hash) throw new \RuntimeException('payload_hash_mismatch');
        $plan = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (($plan['release'] ?? null) !== self::RELEASE || ! is_int($plan['account_uid'] ?? null)
            || ! preg_match('/^[a-f0-9]{40}$/D', (string) ($plan['source_sha'] ?? ''))
            || ! is_array($plan['entries'] ?? null) || count($plan['entries']) !== count(self::ALLOWED)) {
            throw new \RuntimeException('invalid_payload');
        }
        $this->uid = $plan['account_uid'];
        if ($this->uid < 1 || fileowner(self::HOME) !== $this->uid || fileowner(self::STAGE) !== $this->uid
            || fileowner($file) !== $this->uid || (fileperms(self::STAGE) & 0077) !== 0
            || (fileperms($file) & 0022) !== 0 || (function_exists('posix_geteuid') && posix_geteuid() !== $this->uid)) {
            throw new \RuntimeException('account_identity_mismatch');
        }
        $seen = [];
        foreach ($plan['entries'] as $entry) {
            $path = $entry['destination'] ?? '';
            if (! in_array($path, self::ALLOWED, true) || isset($seen[$path]) || ! is_string($entry['content'] ?? null)
                || ! preg_match('/^(?:[a-f0-9]{64}|absent)$/D', (string) ($entry['before_sha256'] ?? ''))
                || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($entry['after_sha256'] ?? ''))
                || hash('sha256', $entry['content']) !== $entry['after_sha256']
                || ! in_array($entry['mode'] ?? null, [0600, 0644], true)
                || (! is_int($entry['before_mode'] ?? null) && ($entry['before_mode'] ?? null) !== null)) {
                throw new \RuntimeException('invalid_entry');
            }
            if ($entry['before_sha256'] === 'absent') {
                if (($entry['before_mode'] ?? null) !== null || $entry['mode'] !== 0644) throw new \RuntimeException('invalid_new_file_mode');
            } elseif (! is_int($entry['before_mode'] ?? null) || $entry['mode'] !== $entry['before_mode']
                || (! in_array($path, self::VIEWS, true) && $entry['mode'] !== 0644)) throw new \RuntimeException('existing_mode_not_preserved');
            $seen[$path] = true;
        }
        foreach ($plan['protected'] ?? [] as $entry) {
            if (! in_array($entry['destination'] ?? null, self::PROTECTED, true)
                || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($entry['sha256'] ?? ''))) throw new \RuntimeException('invalid_protected_entry');
        }
        $this->plan = $plan; $this->payloadHash = $hash;
        $this->safe(self::APP, false);
        $this->safe(self::STAGE.'/engine.php', false);
        $this->safe($file, false);
    }

    private function lock(): void
    {
        foreach (['.servernet-web-release.lock', 'servernet-release.lock', '.codex-web-deploy.lock'] as $name) {
            $path = self::HOME.'/'.$name;
            $this->safe($path, true);
            $handle = fopen($path, 'c+');
            if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) throw new \RuntimeException('release_busy');
            $this->locks[] = $handle;
            $stat = fstat($handle);
            if (($stat['uid'] ?? null) !== $this->uid || (($stat['mode'] ?? 0) & 0022) !== 0) throw new \RuntimeException('unsafe_lock');
        }
    }

    private function safe(string $path, bool $absent): void
    {
        if (! str_starts_with($path, self::HOME.'/') || str_contains($path, '/..') || str_contains($path, "\0")) throw new \RuntimeException('unsafe_path');
        $parts = explode('/', substr($path, strlen(self::HOME) + 1)); $cursor = self::HOME;
        foreach ($parts as $i => $part) {
            if ($part === '' || $part === '.') throw new \RuntimeException('unsafe_path');
            $cursor .= '/'.$part;
            if (is_link($cursor)) throw new \RuntimeException('symlink_rejected');
            if (! file_exists($cursor)) {
                if ($absent && $i === count($parts) - 1) return;
                throw new \RuntimeException('missing_parent_or_target');
            }
            if (fileowner($cursor) !== $this->uid || (fileperms($cursor) & 0002) !== 0) throw new \RuntimeException('unsafe_owner_or_mode');
            if ($i < count($parts) - 1 && ! is_dir($cursor)) throw new \RuntimeException('invalid_parent');
        }
    }

    private function hash(string $path): string
    {
        $this->safe($path, true); clearstatcache(true, $path);
        return is_file($path) ? (string) hash_file('sha256', $path) : 'absent';
    }

    private function directory(string $path): void
    {
        if (! str_starts_with($path, self::STAGE.'/')) throw new \RuntimeException('private_directory_only');
        $parent = dirname($path);
        if (! is_dir($parent)) $this->directory($parent);
        if (! file_exists($path) && ! mkdir($path, 0700)) throw new \RuntimeException('private_directory_failed');
        $this->safe($path, false);
        if (! is_dir($path) || (fileperms($path) & 0077) !== 0) throw new \RuntimeException('private_directory_mode');
    }

    private function atomic(string $path, string $bytes, int $mode, ?string $expected = null, ?int $expectedMode = null): void
    {
        $this->safe($path, true);
        $tmp = dirname($path).'/.domain-ui-'.bin2hex(random_bytes(12));
        $handle = fopen($tmp, 'x+b');
        if ($handle === false) throw new \RuntimeException('stage_write_failed');
        try {
            if (! chmod($tmp, $mode) || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)
                || (function_exists('fsync') && ! fsync($handle))) throw new \RuntimeException('stage_write_failed');
            $stat = fstat($handle);
            if (($stat['uid'] ?? null) !== $this->uid) throw new \RuntimeException('stage_owner_mismatch');
        } catch (\Throwable $error) {
            fclose($handle); if (is_file($tmp)) unlink($tmp); throw $error;
        }
        fclose($handle);
        try {
            if ($expected !== null && $this->hash($path) !== $expected) throw new \RuntimeException('concurrent_file_drift');
            if ($expected !== null && $expected !== 'absent' && $expectedMode !== null
                && (fileperms($path) & 0777) !== $expectedMode) throw new \RuntimeException('concurrent_mode_drift');
            if (! rename($tmp, $path)) throw new \RuntimeException('atomic_replace_failed');
        } finally { if (is_file($tmp)) unlink($tmp); }
        if ($this->hash($path) !== hash('sha256', $bytes)) throw new \RuntimeException('written_hash_mismatch');
    }

    private function state(): ?array
    {
        $file = self::STAGE.'/state.json';
        if ($this->hash($file) === 'absent') return null;
        $state = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (($state['payload_sha256'] ?? null) !== $this->payloadHash || ! is_array($state['compiled'] ?? null)
            || count($state['compiled']) !== count(self::VIEWS)) throw new \RuntimeException('state_payload_mismatch');
        $seen = []; $compiler = $this->app->make('blade.compiler');
        foreach ($state['compiled'] as $entry) {
            $i = $entry['index'] ?? null; $relative = is_int($i) ? ($this->plan['entries'][$i]['destination'] ?? null) : null;
            if (! in_array($relative, self::VIEWS, true) || isset($seen[$relative])
                || ($entry['path'] ?? null) !== $compiler->getCompiledPath(self::HOME.'/'.$relative)
                || ! str_starts_with($entry['path'], self::APP.'/storage/framework/views/')
                || ! preg_match('/^(?:[a-f0-9]{64}|absent)$/D', (string) ($entry['before_sha256'] ?? ''))
                || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($entry['after_sha256'] ?? ''))
                || ! $this->compiledMode($entry['before_sha256'], $entry['before_mode'] ?? null, $entry['mode'] ?? null)) {
                throw new \RuntimeException('invalid_compiled_state');
            }
            $seen[$relative] = true;
        }
        return $state;
    }

    private function compiledMode(string $before, ?int $beforeMode, mixed $mode): bool
    {
        return $before === 'absent' ? $beforeMode === null && $mode === 0644
            : in_array($beforeMode, [0600, 0644, 0755], true) && $mode === $beforeMode;
    }

    private function save(array $state): void
    {
        $state['payload_sha256'] = $this->payloadHash; $state['updated_at'] = gmdate('c');
        $this->atomic(self::STAGE.'/state.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0600);
    }

    private function assertProtected(): void
    {
        foreach ($this->plan['protected'] ?? [] as $entry) {
            if ($this->hash(self::HOME.'/'.$entry['destination']) !== $entry['sha256']) throw new \RuntimeException('protected_file_drift');
        }
    }

    private function checkEntries(string $which): void
    {
        foreach ($this->plan['entries'] as $entry) {
            $file = self::HOME.'/'.$entry['destination'];
            if ($this->hash($file) !== $entry[$which.'_sha256']) throw new \RuntimeException('destination_hash_drift');
            if ($which === 'before' && $entry['before_sha256'] !== 'absent'
                && (fileperms($file) & 0777) !== $entry['before_mode']) throw new \RuntimeException('destination_mode_drift');
            if ($which === 'after' && (fileperms($file) & 0777) !== $entry['mode']) throw new \RuntimeException('destination_mode_drift');
        }
    }

    private function lint(string $file): void
    {
        if (! is_executable(self::PHP) || ! function_exists('proc_open')) throw new \RuntimeException('private_lint_unavailable');
        $process = proc_open([self::PHP, '-l', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::STAGE);
        if (! is_resource($process)) throw new \RuntimeException('private_lint_failed');
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            $this->atomic(self::STAGE.'/lint-error.txt', $output, 0600);
            throw new \RuntimeException('private_lint_failed');
        }
    }

    private function preview(object $app): array
    {
        $old = $this->state();
        if ($old !== null) {
            if (($old['status'] ?? null) !== 'previewed') throw new \RuntimeException('duplicate_preview_rejected');
            $this->assertProtected(); $this->checkEntries('before');
            return ['state' => 'previewed', 'files' => 9, 'duplicate' => true];
        }
        $this->assertProtected(); $this->checkEntries('before');
        $this->directory(self::STAGE.'/candidates'); $this->directory(self::STAGE.'/before');
        $compiled = []; $compiler = $app->make('blade.compiler');
        foreach ($this->plan['entries'] as $i => $entry) {
            $live = self::HOME.'/'.$entry['destination']; $candidate = self::STAGE.'/candidates/file-'.$i;
            $this->atomic($candidate, $entry['content'], 0600);
            if ($entry['before_sha256'] !== 'absent') $this->atomic(self::STAGE.'/before/file-'.$i, (string) file_get_contents($live), 0600);
            if (in_array($entry['destination'], self::VIEWS, true)) {
                $path = $compiler->getCompiledPath($live);
                if (! str_starts_with($path, self::APP.'/storage/framework/views/') || ! preg_match('/^[a-f0-9]+\.php$/D', basename($path))) throw new \RuntimeException('unexpected_compiled_path');
                $this->safe($path, true); $before = $this->hash($path);
                $beforeMode = $before === 'absent' ? null : fileperms($path) & 0777;
                $mode = $beforeMode ?? 0644;
                if (! $this->compiledMode($before, $beforeMode, $mode)) throw new \RuntimeException('unsupported_compiled_mode');
                if ($before !== 'absent') $this->atomic(self::STAGE.'/before/compiled-'.$i, (string) file_get_contents($path), 0600);
                $compiler->setPath($live);
                $php = $compiler->compileString($entry['content'])."\n<?php /**PATH {$live} ENDPATH**/ ?>";
                $compiledCandidate = self::STAGE.'/candidates/compiled-'.$i.'.php';
                $this->atomic($compiledCandidate, $php, 0600); $this->lint($compiledCandidate);
                $compiled[] = ['path' => $path, 'before_sha256' => $before, 'after_sha256' => hash('sha256', $php),
                    'before_mode' => $beforeMode, 'mode' => $mode, 'index' => $i];
            } elseif (str_ends_with($entry['destination'], '.php')) $this->lint($candidate);
        }
        $this->assertProtected(); $this->checkEntries('before');
        $this->save(['status' => 'previewed', 'source_sha' => $this->plan['source_sha'], 'compiled' => $compiled]);
        return ['state' => 'previewed', 'files' => 9, 'private_lint' => 5, 'compiled_views' => count($compiled)];
    }

    private function opcache(array $paths): void
    {
        if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN) && ! function_exists('opcache_invalidate')) throw new \RuntimeException('targeted_opcache_unavailable');
        foreach ($paths as $path) {
            if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($path)
                && ! opcache_invalidate($path, true)) throw new \RuntimeException('targeted_opcache_failed');
        }
    }

    private function phpPaths(array $state): array
    {
        $paths = array_column($state['compiled'], 'path');
        foreach ($this->plan['entries'] as $entry) {
            if (str_starts_with($entry['destination'], 'servernet_app/lang/')) $paths[] = self::HOME.'/'.$entry['destination'];
        }
        return $paths;
    }

    private function pages(): int
    {
        $store = \Illuminate\Support\Facades\Cache::store(); $generation = (int) $store->get('page:gen', 0); $count = 0;
        foreach (['', '/index.php'] as $base) {
            foreach (['/', 'domains', 'en', 'en/domains', 'tr', 'tr/domains'] as $path) {
                $store->forget('page:g'.$generation.':'.sha1('servernet.cloud|'.$base.'|'.$path)); $count++;
            }
        }
        return $count;
    }

    private function checkCompiled(array $state, string $which): void
    {
        foreach ($state['compiled'] as $entry) {
            if ($this->hash($entry['path']) !== $entry[$which.'_sha256']) throw new \RuntimeException('compiled_view_drift');
            if ($entry[$which.'_sha256'] !== 'absent' && (fileperms($entry['path']) & 0777)
                !== ($which === 'before' ? $entry['before_mode'] : $entry['mode'])) throw new \RuntimeException('compiled_mode_drift');
        }
    }

    private function apply(object $app): array
    {
        $state = $this->state();
        if (($state['status'] ?? null) !== 'previewed') throw new \RuntimeException('duplicate_or_unprepared_apply');
        $this->assertProtected(); $this->checkEntries('before'); $this->checkCompiled($state, 'before');
        $this->opcache($this->phpPaths($state));
        foreach ($this->plan['entries'] as $i => $entry) {
            if ($this->hash(self::STAGE.'/candidates/file-'.$i) !== $entry['after_sha256']) throw new \RuntimeException('candidate_hash_drift');
        }
        foreach ($state['compiled'] as $entry) {
            if ($this->hash(self::STAGE.'/candidates/compiled-'.$entry['index'].'.php') !== $entry['after_sha256']) throw new \RuntimeException('candidate_hash_drift');
        }
        $state['status'] = 'applying'; $this->save($state);
        try {
            $order = array_keys($this->plan['entries']);
            usort($order, fn ($a, $b) => (int) in_array($this->plan['entries'][$a]['destination'], self::VIEWS, true)
                <=> (int) in_array($this->plan['entries'][$b]['destination'], self::VIEWS, true));
            foreach ($order as $i) {
                $entry = $this->plan['entries'][$i];
                $this->atomic(self::HOME.'/'.$entry['destination'], (string) file_get_contents(self::STAGE.'/candidates/file-'.$i), $entry['mode'], $entry['before_sha256'], $entry['before_mode']);
            }
            foreach ($state['compiled'] as $entry) {
                $this->atomic($entry['path'], (string) file_get_contents(self::STAGE.'/candidates/compiled-'.$entry['index'].'.php'), $entry['mode'], $entry['before_sha256'], $entry['before_mode']);
                $source = self::HOME.'/'.$this->plan['entries'][$entry['index']]['destination'];
                if (! touch($entry['path'], max(time(), filemtime($source) + 1))) throw new \RuntimeException('compiled_timestamp_failed');
            }
            $this->opcache($this->phpPaths($state)); $this->pages();
            $this->checkEntries('after'); $this->checkCompiled($state, 'after'); $this->assertProtected();
            $state['status'] = 'applied'; $this->save($state);
            return ['state' => 'applied', 'files' => 9, 'compiled_views' => 2, 'page_keys_invalidated' => 12];
        } catch (\Throwable $error) {
            try { $this->restore($state); $state['status'] = 'rolled_back'; $this->save($state); }
            catch (\Throwable) { $state['status'] = 'stopped_manual_review'; $this->save($state); }
            throw new \RuntimeException($state['status'] === 'rolled_back' ? 'apply_failed_rolled_back' : 'apply_failed_manual_review');
        }
    }

    private function verify(): array
    {
        $state = $this->state();
        if (! in_array($state['status'] ?? null, ['applied', 'verified'], true)) throw new \RuntimeException('not_applied');
        $this->assertProtected(); $this->checkEntries('after'); $this->checkCompiled($state, 'after');
        $state['status'] = 'verified'; $this->save($state);
        return ['state' => 'verified', 'files' => 9, 'compiled_views' => 2, 'backend_unchanged' => true,
            'schema_or_configuration_changed' => false, 'supplier_calls' => 0];
    }

    private function restore(array $state): void
    {
        $this->assertProtected(); $observed = []; $compiledObserved = []; $observedModes = []; $compiledModes = [];
        foreach ($this->plan['entries'] as $i => $entry) {
            $observed[$i] = $this->hash(self::HOME.'/'.$entry['destination']);
            if (! in_array($observed[$i], [$entry['before_sha256'], $entry['after_sha256']], true)) throw new \RuntimeException('rollback_newer_file_rejected');
            $observedModes[$i] = $observed[$i] === 'absent' ? null
                : ($observed[$i] === $entry['before_sha256'] ? $entry['before_mode'] : $entry['mode']);
            if ($observedModes[$i] !== null && (fileperms(self::HOME.'/'.$entry['destination']) & 0777) !== $observedModes[$i]) throw new \RuntimeException('rollback_mode_drift');
        }
        foreach ($state['compiled'] as $i => $entry) {
            $compiledObserved[$i] = $this->hash($entry['path']);
            if (! in_array($compiledObserved[$i], [$entry['before_sha256'], $entry['after_sha256']], true)) throw new \RuntimeException('rollback_newer_compiled_rejected');
            $compiledModes[$i] = $compiledObserved[$i] === 'absent' ? null
                : ($compiledObserved[$i] === $entry['before_sha256'] ? $entry['before_mode'] : $entry['mode']);
            if ($compiledModes[$i] !== null && (fileperms($entry['path']) & 0777) !== $compiledModes[$i]) throw new \RuntimeException('rollback_compiled_mode_drift');
        }
        foreach ($this->plan['entries'] as $i => $entry) {
            if ($entry['before_sha256'] !== 'absent' && $this->hash(self::STAGE.'/before/file-'.$i) !== $entry['before_sha256']) throw new \RuntimeException('backup_hash_drift');
        }
        foreach ($state['compiled'] as $entry) {
            if ($entry['before_sha256'] !== 'absent' && $this->hash(self::STAGE.'/before/compiled-'.$entry['index']) !== $entry['before_sha256']) throw new \RuntimeException('backup_hash_drift');
        }
        $this->opcache($this->phpPaths($state));
        // Restore old source and old compiled views together while all new dependencies still exist.
        foreach ($this->plan['entries'] as $i => $entry) {
            if (in_array($entry['destination'], self::VIEWS, true)) {
                $this->restoreOne(self::HOME.'/'.$entry['destination'], self::STAGE.'/before/file-'.$i,
                    $entry['before_sha256'], $entry['before_mode'], $observed[$i], $observedModes[$i]);
            }
        }
        foreach ($state['compiled'] as $i => $entry) {
            $this->restoreOne($entry['path'], self::STAGE.'/before/compiled-'.$entry['index'],
                $entry['before_sha256'], $entry['before_mode'], $compiledObserved[$i], $compiledModes[$i]);
            if ($entry['before_sha256'] !== 'absent') {
                $source = self::HOME.'/'.$this->plan['entries'][$entry['index']]['destination'];
                if (! touch($entry['path'], max(time(), filemtime($source) + 1))) throw new \RuntimeException('compiled_timestamp_failed');
            }
        }
        $this->opcache(array_column($state['compiled'], 'path'));
        $this->pages();
        foreach ($this->plan['entries'] as $i => $entry) {
            if (! in_array($entry['destination'], self::VIEWS, true)) {
                $this->restoreOne(self::HOME.'/'.$entry['destination'], self::STAGE.'/before/file-'.$i,
                    $entry['before_sha256'], $entry['before_mode'], $observed[$i], $observedModes[$i]);
            }
        }
        $this->opcache($this->phpPaths($state)); $this->pages();
        $this->checkEntries('before'); $this->checkCompiled($state, 'before'); $this->assertProtected();
    }

    private function restoreOne(string $path, string $backup, string $before, ?int $mode, string $expected, ?int $expectedMode): void
    {
        if ($this->hash($path) !== $expected) throw new \RuntimeException('rollback_concurrent_drift');
        if ($expected !== 'absent' && $expectedMode !== null && (fileperms($path) & 0777) !== $expectedMode) throw new \RuntimeException('rollback_concurrent_mode_drift');
        if ($before === 'absent') {
            if ($expected !== 'absent' && ! unlink($path)) throw new \RuntimeException('rollback_remove_failed');
        } else {
            $this->atomic($path, (string) file_get_contents($backup), $mode, $expected, $expectedMode);
        }
    }

    private function rollback(): array
    {
        $state = $this->state();
        if (! in_array($state['status'] ?? null, ['applied', 'verified', 'applying'], true)) throw new \RuntimeException('rollback_state_rejected');
        $this->restore($state); $state['status'] = 'rolled_back'; $this->save($state);
        return ['state' => 'rolled_back', 'files' => 9, 'compiled_views' => 2];
    }
}
