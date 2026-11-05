<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/** Request-local navigation context for the parallel customer console. */
final class ConsoleNext
{
    public const ATTRIBUTE = 'console_next';

    /** Private operational state; it never contains account data or credentials. */
    public static function activationPath(): string
    {
        return storage_path('app/console-next.state');
    }

    /** Shared with the guarded installer; this stable lock file is never removed. */
    public static function releaseLockPath(): string
    {
        return storage_path('app/.console-next-release.lock');
    }

    public static function enabled(): bool
    {
        $path = self::activationPath();
        if (! file_exists($path) && ! is_link($path)) {
            return filter_var(config('console-next.enabled', false), FILTER_VALIDATE_BOOL);
        }

        // Unknown, unreadable or non-regular state fails closed, including symlinks.
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            return false;
        }

        return trim((string) @file_get_contents($path)) === 'enabled';
    }

    /** Atomic, private activation/rollback switch; no database, job or cache work. */
    public static function setEnabled(bool $enabled): void
    {
        $path = self::activationPath();
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the private console activation directory.');
        }

        $lock = self::acquireReleaseLock();
        $temporary = null;
        try {
            clearstatcache(true, $path);
            if (is_link($path) || (file_exists($path) && ! is_file($path))) {
                throw new RuntimeException('The private console activation state must be a regular file.');
            }

            $temporary = tempnam($directory, '.console-next-');
            if ($temporary === false) {
                throw new RuntimeException('Cannot prepare the private console activation state.');
            }

            $state = $enabled ? "enabled\n" : "disabled\n";
            if (file_put_contents($temporary, $state, LOCK_EX) !== strlen($state)
                || ! chmod($temporary, 0600)
                || ! @rename($temporary, $path)) {
                throw new RuntimeException('Cannot atomically update the private console activation state.');
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    private static function acquireReleaseLock()
    {
        $path = self::releaseLockPath();
        $expectedIdentity = null;
        // Exclusive creation cannot follow an existing symlink. Set permissions at
        // creation, rather than chmod a pathname that might have been exchanged.
        $previousMask = umask(0077);
        try {
            $lock = @fopen($path, 'x+b');
        } finally {
            umask($previousMask);
        }

        if ($lock === false) {
            clearstatcache(true, $path);
            $before = @lstat($path);
            if ($before === false || ($before['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('The console release lock must be a private regular file.');
            }
            $expectedIdentity = $before;
            // Opening an existing regular file never truncates or creates a target.
            $lock = @fopen($path, 'r+b');
            if ($lock === false) {
                throw new RuntimeException('Cannot open the private console release lock.');
            }
        }

        try {
            self::assertReleaseLock($lock, $path, $expectedIdentity);
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('A console release is in progress; activation state was not changed.');
            }
            self::assertReleaseLock($lock, $path, $expectedIdentity);

            return $lock;
        } catch (\Throwable $exception) {
            fclose($lock);
            throw $exception;
        }
    }

    /** @param resource $lock */
    private static function assertReleaseLock($lock, string $path, ?array $expectedIdentity): void
    {
        $opened = fstat($lock);
        clearstatcache(true, $path);
        $current = @lstat($path);
        if ($opened === false || $current === false
            || ($current['mode'] & 0170000) !== 0100000
            || ($opened['mode'] & 0777) !== 0600
            || $opened['nlink'] !== 1
            || (function_exists('posix_geteuid') && $opened['uid'] !== posix_geteuid())
            || ($expectedIdentity !== null && ($opened['dev'] !== $expectedIdentity['dev'] || $opened['ino'] !== $expectedIdentity['ino']))
            || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
            throw new RuntimeException('The console release lock must remain the same private regular file (0600).');
        }
    }

    public static function active(?Request $request = null): bool
    {
        $request ??= app()->bound('request') ? request() : null;

        return $request instanceof Request
            && $request->attributes->get(self::ATTRIBUTE) === true;
    }

    /** Accepts the complete route name, including its optional locale prefix. */
    public static function routeName(string $name, ?Request $request = null): string
    {
        if (! self::active($request)
            || ! preg_match('/^((?:en|tr)\.)?account\.(?!next(?:\.|$))(.+)$/D', $name, $parts)) {
            return $name;
        }

        $candidate = ($parts[1] ?? '').'account.next.'.$parts[2];

        return Route::has($candidate) ? $candidate : $name;
    }

    /**
     * Keep internal controller redirects in this interface. Payment gateways,
     * authentication, admin URLs and every other origin keep their own contract.
     */
    public static function candidateUrl(string $url, ?Request $request = null): string
    {
        $request ??= app()->bound('request') ? request() : null;
        if (! $request instanceof Request || ! self::active($request)) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return $url;
        }

        $path = $parts['path'] ?? '';
        if (! str_starts_with($path, '/') || ! self::sameOrigin($parts, $request)) {
            return $url;
        }

        $path = preg_replace('~^/((?:en|tr)/)?account(?!/next(?:/|$))(?=/|$)~D', '/$1account/next', $path);
        if ($path === ($parts['path'] ?? '')) {
            return $url;
        }

        return self::withPath($parts, $path);
    }

    /** Explicit return link; it never sets a cookie or changes the default UI. */
    public static function legacyUrl(?Request $request = null): string
    {
        $request ??= request();
        $path = preg_replace('~^/((?:en|tr)/)?account/next(?=/|$)~D', '/$1account', '/'.$request->path());
        $query = $request->getQueryString();

        return $request->getSchemeAndHttpHost().$path.($query !== null ? '?'.$query : '');
    }

    private static function sameOrigin(array $parts, Request $request): bool
    {
        if (isset($parts['host'])) {
            // Scheme-relative URLs are left untouched, as are credentials in URLs.
            $scheme = strtolower($parts['scheme'] ?? '');
            if (! in_array($scheme, ['http', 'https'], true)
                || strtolower($parts['host']) !== strtolower($request->getHost())
                || $scheme !== strtolower($request->getScheme())) {
                return false;
            }

            return ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) === $request->getPort();
        }

        return ! isset($parts['scheme']) && ! str_starts_with($parts['path'] ?? '', '//');
    }

    private static function withPath(array $parts, string $path): string
    {
        $origin = isset($parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : '';

        return $origin.$path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
