<?php

/**
 * No-secret readiness check for the read-only Hermes/Araz Growth HMAC client.
 *
 * This script never reads environment variables or a shared key and never
 * performs a network request. It validates only the local runtime, the exact
 * client/caller files and an operator-supplied peer epoch.
 */
final class HermesGrowthReadPreflight
{
    private const MIN_PHP_VERSION_ID = 80400;

    /** @return array{status: string, checks: array<string, bool>, observed: array<string, mixed>} */
    public static function inspect(array $options): array
    {
        $validated = self::validateOptions($options);
        $client = self::inspectFile($validated['client']);
        $caller = self::inspectFile($validated['caller']);
        $clockSkew = abs(time() - $validated['peer_epoch']);

        $checks = [
            'php_84_or_newer' => PHP_VERSION_ID >= self::MIN_PHP_VERSION_ID,
            'curl_loaded' => extension_loaded('curl'),
            'crypto_available' => function_exists('hash_hmac') && function_exists('random_bytes'),
            'clock_within_limit' => $clockSkew <= $validated['max_skew'],
            'client_canonical_regular_file' => $client['canonical_regular_file'],
            'client_not_group_or_world_writable' => $client['safe_permissions'],
            'client_expected_owner' => $client['uid'] === $validated['expected_uid'],
            'client_expected_sha256' => hash_equals($validated['expected_client_sha'], $client['sha256']),
            'caller_canonical_regular_file' => $caller['canonical_regular_file'],
            'caller_not_group_or_world_writable' => $caller['safe_permissions'],
            'caller_expected_owner' => $caller['uid'] === $validated['expected_uid'],
            'caller_expected_sha256' => hash_equals($validated['expected_caller_sha'], $caller['sha256']),
            'client_and_caller_are_distinct' => $client['realpath'] !== $caller['realpath'],
        ];

        return [
            'status' => in_array(false, $checks, true) ? 'not_ready' : 'ready',
            'checks' => $checks,
            'observed' => [
                'php_version' => PHP_VERSION,
                'clock_skew_seconds' => $clockSkew,
                'max_skew_seconds' => $validated['max_skew'],
                'expected_uid' => $validated['expected_uid'],
                'client' => self::publicFileFacts($client),
                'caller' => self::publicFileFacts($caller),
            ],
        ];
    }

    /** @return array{client: string, expected_client_sha: string, caller: string, expected_caller_sha: string, expected_uid: int, peer_epoch: int, max_skew: int} */
    private static function validateOptions(array $options): array
    {
        $required = [
            'client',
            'expected-client-sha',
            'caller',
            'expected-caller-sha',
            'expected-uid',
            'peer-epoch',
        ];
        foreach ($required as $name) {
            if (! isset($options[$name]) || ! is_string($options[$name]) || $options[$name] === '') {
                throw new InvalidArgumentException('missing_'.$name);
            }
        }

        $expectedClientSha = strtolower($options['expected-client-sha']);
        $expectedCallerSha = strtolower($options['expected-caller-sha']);
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedClientSha) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $expectedCallerSha) !== 1) {
            throw new InvalidArgumentException('invalid_sha256');
        }
        if (preg_match('/^\d+$/D', $options['expected-uid']) !== 1
            || preg_match('/^\d{10}$/D', $options['peer-epoch']) !== 1) {
            throw new InvalidArgumentException('invalid_numeric_option');
        }

        $maxSkewRaw = $options['max-skew'] ?? '60';
        if (! is_string($maxSkewRaw) || preg_match('/^\d{1,3}$/D', $maxSkewRaw) !== 1) {
            throw new InvalidArgumentException('invalid_max_skew');
        }
        $maxSkew = (int) $maxSkewRaw;
        if ($maxSkew < 1 || $maxSkew > 300) {
            throw new InvalidArgumentException('invalid_max_skew');
        }

        return [
            'client' => $options['client'],
            'expected_client_sha' => $expectedClientSha,
            'caller' => $options['caller'],
            'expected_caller_sha' => $expectedCallerSha,
            'expected_uid' => (int) $options['expected-uid'],
            'peer_epoch' => (int) $options['peer-epoch'],
            'max_skew' => $maxSkew,
        ];
    }

    /** @return array{provided: string, realpath: string, canonical_regular_file: bool, safe_permissions: bool, mode: string, uid: int, sha256: string} */
    private static function inspectFile(string $path): array
    {
        $realpath = realpath($path);
        if ($realpath === false || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('growth_preflight_file_unavailable');
        }
        $perms = fileperms($path);
        $uid = fileowner($path);
        $sha = hash_file('sha256', $path);
        if ($perms === false || $uid === false || $sha === false) {
            throw new RuntimeException('growth_preflight_file_metadata_unavailable');
        }

        $mode = $perms & 0777;

        return [
            'provided' => $path,
            'realpath' => $realpath,
            'canonical_regular_file' => ! is_link($path) && $path === $realpath,
            'safe_permissions' => ($mode & 0022) === 0,
            'mode' => sprintf('%04o', $mode),
            'uid' => (int) $uid,
            'sha256' => strtolower($sha),
        ];
    }

    /** @return array{path: string, mode: string, uid: int, sha256: string} */
    private static function publicFileFacts(array $facts): array
    {
        return [
            'path' => $facts['realpath'],
            'mode' => $facts['mode'],
            'uid' => $facts['uid'],
            'sha256' => $facts['sha256'],
        ];
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = getopt('', [
            'client:',
            'expected-client-sha:',
            'caller:',
            'expected-caller-sha:',
            'expected-uid:',
            'peer-epoch:',
            'max-skew::',
        ]);
        if (! is_array($options)) {
            throw new InvalidArgumentException('invalid_options');
        }
        $result = HermesGrowthReadPreflight::inspect($options);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        exit($result['status'] === 'ready' ? 0 : 2);
    } catch (Throwable $exception) {
        fwrite(STDERR, json_encode([
            'status' => 'invalid',
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(64);
    }
}
