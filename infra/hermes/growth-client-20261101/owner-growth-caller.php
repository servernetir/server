<?php

require __DIR__.'/HermesGrowthReadClient.php';
require __DIR__.'/GrowthReadCycle.php';

use App\Services\Hermes\GrowthReadCycle;
use App\Services\Hermes\HermesGrowthReadClient;

/**
 * Owner-operated CLI caller for one receipt-bound read-only Growth HMAC cycle.
 *
 * It consumes an existing key file; it never creates, rotates or prints a key.
 */
final class HermesGrowthOwnerCaller
{
    /** @return array<string, mixed> */
    public static function execute(
        array $options,
        ?callable $clientFactory = null,
        ?callable $clock = null,
    ): array {
        $validated = self::validateOptions($options);
        $receiptPath = $validated['receipt_dir'].'/'.$validated['run_id'].'.json';

        if (file_exists($receiptPath) || is_link($receiptPath)) {
            throw new RuntimeException('growth_receipt_exists');
        }

        $key = self::readKey($validated['key_file'], $validated['expected_uid']);
        $factory = $clientFactory
            ? Closure::fromCallable($clientFactory)
            : static fn (string $secret): HermesGrowthReadClient => new HermesGrowthReadClient($secret);
        $client = $factory($key);
        if (! $client instanceof HermesGrowthReadClient) {
            throw new RuntimeException('growth_client_invalid');
        }

        $receipt = (new GrowthReadCycle($client, $clock))->run($validated['run_id']);
        self::writeReceipt($receiptPath, $receipt);

        return $receipt;
    }

    /** @return array{key_file: string, receipt_dir: string, expected_uid: int, run_id: string} */
    private static function validateOptions(array $options): array
    {
        foreach (['key-file', 'receipt-dir', 'expected-uid', 'run-id'] as $name) {
            if (! isset($options[$name]) || ! is_string($options[$name]) || $options[$name] === '') {
                throw new InvalidArgumentException('missing_'.$name);
            }
        }

        if (preg_match('/^\d+$/D', $options['expected-uid']) !== 1) {
            throw new InvalidArgumentException('growth_expected_uid_invalid');
        }
        if (preg_match('/^hg-[0-9]{8}T[0-9]{6}Z-[a-zA-Z0-9_-]{4,40}$/D', $options['run-id']) !== 1) {
            throw new InvalidArgumentException('growth_run_id_invalid');
        }

        $keyFile = self::canonicalFile($options['key-file']);
        $receiptDir = self::canonicalDirectory($options['receipt-dir']);
        $expectedUid = (int) $options['expected-uid'];

        $dirPerms = fileperms($receiptDir);
        $dirOwner = fileowner($receiptDir);
        if ($dirPerms === false || $dirOwner === false
            || (int) $dirOwner !== $expectedUid
            || (($dirPerms & 0777) !== 0700)
            || ! is_writable($receiptDir)) {
            throw new RuntimeException('growth_receipt_directory_unsafe');
        }

        return [
            'key_file' => $keyFile,
            'receipt_dir' => $receiptDir,
            'expected_uid' => $expectedUid,
            'run_id' => $options['run-id'],
        ];
    }

    private static function canonicalFile(string $path): string
    {
        $real = realpath($path);
        if ($real === false || $path !== $real || is_link($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('growth_key_file_unavailable');
        }

        return $real;
    }

    private static function canonicalDirectory(string $path): string
    {
        $real = realpath($path);
        if ($real === false || $path !== $real || is_link($path) || ! is_dir($path)) {
            throw new RuntimeException('growth_receipt_directory_unavailable');
        }

        return $real;
    }

    private static function readKey(string $path, int $expectedUid): string
    {
        $perms = fileperms($path);
        $owner = fileowner($path);
        $size = filesize($path);
        if ($perms === false || $owner === false || $size === false
            || (int) $owner !== $expectedUid
            || (($perms & 0077) !== 0)
            || (($perms & 0400) === 0)
            || $size < 32
            || $size > 4096) {
            throw new RuntimeException('growth_key_file_unsafe');
        }

        $key = file_get_contents($path);
        if (! is_string($key)) {
            throw new RuntimeException('growth_key_read_failed');
        }
        $key = rtrim($key, "\r\n");
        if (strlen($key) < 32 || strlen($key) > 4096 || str_contains($key, "\0")) {
            throw new RuntimeException('growth_key_invalid');
        }

        return $key;
    }

    /** @param array<string, mixed> $receipt */
    private static function writeReceipt(string $path, array $receipt): void
    {
        $payload = json_encode(
            $receipt,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ).PHP_EOL;

        $previousUmask = umask(0077);
        try {
            $handle = fopen($path, 'x');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new RuntimeException('growth_receipt_create_failed');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('growth_receipt_lock_failed');
            }

            $written = 0;
            $length = strlen($payload);
            while ($written < $length) {
                $chunk = fwrite($handle, substr($payload, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new RuntimeException('growth_receipt_write_failed');
                }
                $written += $chunk;
            }

            if (! fflush($handle) || ! chmod($path, 0600)) {
                throw new RuntimeException('growth_receipt_flush_failed');
            }
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('growth_receipt_sync_failed');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function safeError(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return preg_match('/^(?:growth_|missing-)[a-z0-9_-]+$/D', $message) === 1
            ? $message
            : 'growth_caller_failed';
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = getopt('', ['key-file:', 'receipt-dir:', 'expected-uid:', 'run-id:']);
        if (! is_array($options)) {
            throw new InvalidArgumentException('growth_options_invalid');
        }

        $receipt = HermesGrowthOwnerCaller::execute($options);
        echo json_encode([
            'run_id' => $receipt['run_id'],
            'status' => $receipt['status'],
            'receipt_written' => true,
            'retry_allowed' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        exit($receipt['status'] === 'accepted' ? 0 : 2);
    } catch (Throwable $exception) {
        fwrite(STDERR, json_encode([
            'status' => 'invalid',
            'error' => HermesGrowthOwnerCaller::safeError($exception),
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(64);
    }
}
