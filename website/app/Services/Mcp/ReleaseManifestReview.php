<?php

namespace App\Services\Mcp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Advisory review of an immutable public-web release manifest. Never deploys. */
final class ReleaseManifestReview
{
    private const REPOSITORY = 'servernetir/server';

    private const MANIFEST_LIMIT = 32768;

    private const FILE_LIMIT = 2097152;

    /** Explicit public-only PHP admission lists, not a general app-file reader. */
    private const PHP_PATHS = [
        'app/Console/Commands/' => ['CheckContentLinks', 'CheckSiteLinks', 'ReleaseGate'],
        'app/Http/Controllers/' => [
            'AiStorefrontController', 'BlogController', 'CareersController', 'CatalogController',
            'CloudCatalogController', 'CountryVpsController', 'DocsController', 'GpuController',
            'HourlyVpsController', 'IdentityStorefrontController', 'InternationalMarketController',
            'LookupController', 'ManagedN8nController', 'PartsShopController', 'ServerShopController',
            'SiteController', 'SolutionController', 'StorageCatalogController', 'ToolController',
            'UrmiaController', 'WebToolsController',
        ],
        'app/Http/Middleware/' => ['ConsoleHost', 'LegacyDomain', 'PageCache', 'SetLocale', 'TrackNotFound'],
        'app/Support/' => [
            'Countries', 'HomeServiceDirectory', 'HtmlSeoInspector', 'InternationalMarket',
            'RobotsPolicy', 'SitemapUrlPolicy',
        ],
        'config/' => [
            'blog', 'catalog', 'company', 'datacenter_content', 'docs', 'growth', 'hardware_topics',
            'international-market', 'pagecache', 'pages', 'parts_content', 'seo', 'solutions',
            'urmia', 'urmia_i18n', 'webdesign',
        ],
        'config/catalog/' => ['cloud', 'dedicated', 'domain', 'services', 'vps'],
    ];

    public function review(string $release, string $commit, string $manifestSha): array
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $release) !== 1
            || ! $this->hex($commit, 40) || ! $this->hex($manifestSha, 64)) {
            throw new RuntimeException('invalid_release_identity');
        }

        $raw = $this->fetch($release, $commit);
        if (! hash_equals($manifestSha, hash('sha256', $raw))) {
            throw new RuntimeException('manifest_hash_mismatch');
        }

        $manifest = $this->validateManifest($raw, $release);
        $files = [];
        $counts = ['matching' => 0, 'conflict' => 0, 'unsafe' => 0, 'absent' => 0];
        $started = gmdate('c');
        foreach ($manifest['files'] as $row) {
            $status = $this->fileStatus($row);
            $counts[$status]++;
            $files[] = [
                'source' => $row['source'], 'destination' => $row['destination'],
                'operation' => $row['operation'], 'review_class' => $this->reviewClass($row['source']), 'status' => $status,
            ];
        }

        return [
            'repository' => self::REPOSITORY, 'release' => $release,
            'manifest_commit' => $commit, 'manifest_sha256' => $manifestSha,
            'git_source_commit' => $manifest['git_source_commit'],
            'status' => 'review_only', 'deployment_authorized' => false,
            'preflight_unlocked' => true, 'preflight_started_at' => $started, 'preflight_finished_at' => gmdate('c'),
            'files' => $files, 'counts' => $counts,
            'gates' => [
                'manifest_integrity_verified' => true,
                'manifest_schema_validated' => true,
                'git_source_bytes_verified' => false,
                'git_source_commit_on_develop_verified' => false,
                'runtime_php_blade_validated' => false,
                'release_lock_held' => false,
                'private_backup_verified' => false,
                'owner_deployment_approved' => false,
                'operator_coordination_confirmed' => false,
                'executor_provisioned' => false,
                'activation_verified' => false,
            ],
        ];
    }

    private function fetch(string $release, string $commit): string
    {
        // Neither a request-supplied URL/ref nor a redirect can change the origin.
        $url = 'https://raw.githubusercontent.com/'.self::REPOSITORY.'/'.$commit.'/scripts/releases/'.$release.'/manifest.json';
        $deadline = hrtime(true) + 8000000000;
        try {
            $response = Http::acceptJson()->withHeaders(['Accept-Encoding' => 'identity'])
                ->withOptions(['allow_redirects' => false, 'verify' => true, 'stream' => true, 'decode_content' => false, 'read_timeout' => 2])
                ->connectTimeout(3)->timeout(6)->get($url);
            $stream = $response->toPsrResponse()->getBody();
            try {
                $contentLength = $response->header('Content-Length');
                if ($response->status() !== 200 || ($contentLength !== ''
                    && (! ctype_digit($contentLength) || (int) $contentLength > self::MANIFEST_LIMIT))
                    || ($response->header('Content-Encoding') !== ''
                    && strtolower($response->header('Content-Encoding')) !== 'identity')
                    || ($stream->getSize() !== null && $stream->getSize() > self::MANIFEST_LIMIT)) {
                    throw new RuntimeException('manifest_unavailable');
                }
                $raw = '';
                while (! $stream->eof()) {
                    if (hrtime(true) > $deadline || strlen($raw) > self::MANIFEST_LIMIT) {
                        throw new RuntimeException('manifest_unavailable');
                    }
                    $chunk = $stream->read(min(4096, self::MANIFEST_LIMIT + 1 - strlen($raw)));
                    if ($chunk === '' && ! $stream->eof()) {
                        throw new RuntimeException('manifest_unavailable');
                    }
                    $raw .= $chunk;
                }
                if (hrtime(true) > $deadline || strlen($raw) > self::MANIFEST_LIMIT) {
                    throw new RuntimeException('manifest_unavailable');
                }

                return $raw;
            } finally {
                $stream->close();
            }
        } catch (\Throwable) {
            // Do not expose remote exception messages, response bodies, or headers.
            throw new RuntimeException('manifest_unavailable');
        }
    }

    private function validateManifest(string $raw, string $release): array
    {
        try {
            $manifest = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            $canonical = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('invalid_manifest');
        }

        // Compact canonical bytes also reject duplicate JSON keys silently dropped by json_decode.
        if (($raw !== $canonical && $raw !== $canonical."\n") || ! is_array($manifest)
            || ! $this->exactKeys($manifest, ['schema', 'release', 'git_source_commit', 'deployment_authorized', 'files'])
            || $manifest['schema'] !== 'servernet-web-release/v1' || $manifest['release'] !== $release
            || ! $this->hex($manifest['git_source_commit'], 40) || $manifest['deployment_authorized'] !== false
            || ! is_array($manifest['files']) || ! array_is_list($manifest['files'])
            || count($manifest['files']) < 1 || count($manifest['files']) > 32) {
            throw new RuntimeException('invalid_manifest');
        }

        $seen = [];
        foreach ($manifest['files'] as $row) {
            if (! is_array($row) || ! $this->exactKeys($row, ['source', 'destination', 'operation', 'before', 'after'])
                || ! is_string($row['source']) || ! is_string($row['destination'])
                || ! in_array($row['operation'], ['add', 'replace', 'overlay'], true)
                || ! $this->hex($row['after'], 64)
                || ($row['operation'] === 'add' ? $row['before'] !== null : ! $this->hex($row['before'], 64))
                || $row['before'] === $row['after']) {
                throw new RuntimeException('invalid_manifest');
            }
            $destination = $this->destination($row['source']);
            if ($destination === null || $row['destination'] !== $destination || isset($seen[strtolower($destination)])) {
                throw new RuntimeException('invalid_manifest');
            }
            $seen[strtolower($destination)] = true;
        }

        // Reject the complete manifest before touching local file metadata if any row is outside scope.
        return $manifest;
    }

    private function destination(string $source): ?string
    {
        if (strlen($source) > 240 || ! str_starts_with($source, 'website/')) {
            return null;
        }
        $relative = substr($source, 8);
        foreach (explode('/', $relative) as $segment) {
            $name = strtolower(explode('.', $segment, 2)[0]);
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,119}\z/D', $segment) !== 1 || str_contains($segment, '..')
                || strtolower($segment) === 'index.php'
                || preg_match('/(?:\A|[-_])(?:storage|vendor|secret|secrets|database|db|admin|account|auth|oauth|credential|credentials|password|passwd|private|token|tokens|session)(?:\z|[-_])/D', $name) === 1) {
                return null;
            }
        }
        if (preg_match('~\Apublic/assets/(?:[A-Za-z0-9][A-Za-z0-9_-]*/)*[A-Za-z0-9][A-Za-z0-9._-]*\.(?:css|js)\z~D', $relative) === 1) {
            return 'public_html/'.substr($relative, 7);
        }
        if (in_array($relative, ['routes/web.php', 'routes/international-market.php'], true)
            || preg_match('~\Alang/[a-z]{2}(?:-[A-Z]{2})?/[A-Za-z0-9][A-Za-z0-9_-]*\.(?:php|json)\z~D', $relative) === 1
            || preg_match('~\Aresources/views/(?:pages|partials|layouts|components)/(?:[A-Za-z0-9][A-Za-z0-9_-]*/)*[A-Za-z0-9][A-Za-z0-9_-]*\.blade\.php\z~D', $relative) === 1) {
            return 'servernet_app/'.$relative;
        }
        foreach (self::PHP_PATHS as $prefix => $names) {
            foreach ($names as $name) {
                if ($relative === $prefix.$name.'.php') {
                    return 'servernet_app/'.$relative;
                }
            }
        }

        return null;
    }

    private function fileStatus(array $row): string
    {
        try {
            return $this->probeFile($row);
        } catch (\Throwable) {
            // Filesystem details and local exception text must not escape into the tool response.
            return 'unsafe';
        }
    }

    private function reviewClass(string $source): string
    {
        return match (true) {
            str_starts_with($source, 'website/app/') => 'application_php',
            str_starts_with($source, 'website/config/') => 'configuration_php',
            str_starts_with($source, 'website/routes/') => 'routing_php',
            str_starts_with($source, 'website/resources/views/') => 'runtime_template',
            str_starts_with($source, 'website/lang/') => str_ends_with($source, '.php') ? 'translation_php' : 'translation_data',
            str_ends_with($source, '.js') => 'public_script',
            default => 'public_stylesheet',
        };
    }

    private function probeFile(array $row): string
    {
        $public = str_starts_with($row['destination'], 'public_html/');
        $root = $public
            ? config('mcp_admin.release_public_root', '/home/servernetcloud/public_html')
            : config('mcp_admin.release_app_root', '/home/servernetcloud/servernet_app');
        $relative = substr($row['destination'], $public ? 12 : 14);
        $observed = $this->inspect($root, $relative);
        if ($observed['state'] === 'unsafe') {
            return 'unsafe';
        }
        if ($observed['state'] === 'absent') {
            return $row['operation'] === 'add' ? 'absent' : 'conflict';
        }
        if ($row['operation'] === 'add') {
            return 'conflict';
        }

        // Read only the admitted, bounded regular file; compare descriptor identity
        // before reading, then recheck the path and metadata. This is advisory, not a release lock.
        $handle = @fopen($observed['path'], 'rb');
        if (! is_resource($handle)) {
            return 'unsafe';
        }
        try {
            $opened = @fstat($handle);
            $checked = $this->inspect($root, $relative);
            if (! $this->sameStat($observed['stat'], $opened) || $checked['state'] !== 'file'
                || ! $this->sameStat($observed['stat'], $checked['stat'])) {
                return 'unsafe';
            }
            $ctx = hash_init('sha256');
            $read = @hash_update_stream($ctx, $handle, self::FILE_LIMIT + 1);
            $ended = @fstat($handle);
            $checked = $this->inspect($root, $relative);
            if ($read !== $observed['stat']['size'] || $read > self::FILE_LIMIT
                || ! $this->sameStat($observed['stat'], $ended) || $checked['state'] !== 'file'
                || ! $this->sameStat($observed['stat'], $checked['stat'])) {
                return 'unsafe';
            }

            return hash_equals($row['before'], hash_final($ctx)) ? 'matching' : 'conflict';
        } catch (\Throwable) {
            return 'unsafe';
        } finally {
            fclose($handle);
        }
    }

    private function inspect(mixed $root, string $relative): array
    {
        // Production roots are operator configuration, never tool arguments.
        if (! is_string($root) || ! str_starts_with($root, '/') || $root === '/' || str_contains($root, "\0")) {
            return ['state' => 'unsafe'];
        }
        $root = rtrim($root, '/');
        clearstatcache(true, $root);
        if (realpath($root) !== $root || is_link($root) || ! is_dir($root) || ! is_readable($root) || ! is_executable($root)) {
            return ['state' => 'unsafe'];
        }
        $cursor = $root;
        $segments = explode('/', $relative);
        foreach ($segments as $index => $segment) {
            $cursor .= '/'.$segment;
            clearstatcache(true, $cursor);
            $stat = @lstat($cursor);
            if ($stat === false) {
                // An inaccessible or changed ancestor is not evidence of absence.
                $parent = dirname($cursor);
                clearstatcache(true, $parent);

                return ['state' => realpath($parent) === $parent && is_dir($parent) && is_readable($parent)
                    && is_executable($parent) && ! is_link($cursor) ? 'absent' : 'unsafe'];
            }
            $type = $stat['mode'] & 0170000;
            if ($index !== count($segments) - 1) {
                if ($type !== 0040000 || realpath($cursor) !== $cursor || ! is_readable($cursor) || ! is_executable($cursor)) {
                    return ['state' => 'unsafe'];
                }
                continue;
            }
            if ($type !== 0100000 || realpath($cursor) !== $cursor || ! is_readable($cursor)
                || $stat['nlink'] !== 1 || $stat['size'] < 0 || $stat['size'] > self::FILE_LIMIT) {
                return ['state' => 'unsafe'];
            }

            return ['state' => 'file', 'path' => $cursor, 'stat' => $stat];
        }

        return ['state' => 'unsafe'];
    }

    private function sameStat(array $a, mixed $b): bool
    {
        if (! is_array($b)) {
            return false;
        }
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function exactKeys(array $value, array $expected): bool
    {
        return count($value) === count($expected) && array_diff(array_keys($value), $expected) === [];
    }

    private function hex(mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length && preg_match('/\A[a-f0-9]+\z/D', $value) === 1;
    }
}
