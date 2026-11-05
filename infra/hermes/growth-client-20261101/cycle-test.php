<?php

require __DIR__.'/owner-growth-caller.php';

use App\Services\Hermes\HermesGrowthReadClient;

$assertions = 0;
$assert = static function (bool $condition, string $message = 'assertion_failed') use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$expect = static function (callable $callable, string $expected) use ($assert): void {
    try {
        $callable();
    } catch (Throwable $exception) {
        $assert($exception->getMessage() === $expected, 'unexpected_'.$exception->getMessage());

        return;
    }

    throw new RuntimeException('missing_'.$expected);
};

$root = sys_get_temp_dir().'/hermes-growth-caller-'.bin2hex(random_bytes(8));
$receipts = $root.'/receipts';
if (! mkdir($receipts, 0700, true) && ! is_dir($receipts)) {
    throw new RuntimeException('temp_directory_failed');
}
chmod($root, 0700);
chmod($receipts, 0700);

$keyFile = $root.'/growth.key';
$secret = str_repeat('s', 32);
file_put_contents($keyFile, $secret."\n");
chmod($keyFile, 0600);
$uid = (string) fileowner($keyFile);
$clock = static fn (): int => 1793870000;

$calls = 0;
$factory = static function (string $key) use ($assert, $secret, &$calls): HermesGrowthReadClient {
    $assert($key === $secret, 'key_changed');

    return new HermesGrowthReadClient(
        $key,
        static function (string $url) use (&$calls): array {
            $calls++;
            $action = str_ends_with($url, '/curriculum') ? 'curriculum' : 'oversight';

            return [
                'status' => 200,
                'body' => json_encode([
                    'data' => ['action' => $action, 'private_value' => 'not-for-receipt'],
                    'executed_actions' => [],
                ], JSON_THROW_ON_ERROR),
            ];
        },
        static fn (): int => 1793870000,
        static fn (): string => 'abcdefghijklmnopqrstuvwx',
    );
};

$base = [
    'key-file' => realpath($keyFile),
    'receipt-dir' => realpath($receipts),
    'expected-uid' => $uid,
    'run-id' => 'hg-20261105T095500Z-test',
];

$receipt = HermesGrowthOwnerCaller::execute($base, $factory, $clock);
$assert($receipt['status'] === 'accepted');
$assert($receipt['retry_allowed'] === false);
$assert(count($receipt['completed_actions']) === 2);
$assert($receipt['completed_actions'][0]['action'] === 'curriculum');
$assert($receipt['completed_actions'][1]['action'] === 'oversight');
$assert($calls === 2);

$receiptPath = $receipts.'/'.$base['run-id'].'.json';
$assert(is_file($receiptPath));
clearstatcache(true, $receiptPath);
$assert((fileperms($receiptPath) & 0777) === 0600);
$stored = file_get_contents($receiptPath);
$assert(is_string($stored));
$assert(! str_contains($stored, $secret));
$assert(! str_contains($stored, 'not-for-receipt'));
$decoded = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
$assert($decoded['schema'] === 'servernet-growth-read-receipt-v1');
$assert(strlen($decoded['completed_actions'][0]['data_sha256']) === 64);

$callsBeforeDuplicate = $calls;
$expect(
    static fn () => HermesGrowthOwnerCaller::execute($base, $factory, $clock),
    'growth_receipt_exists',
);
$assert($calls === $callsBeforeDuplicate, 'duplicate_cycle_was_sent');

$failureCalls = 0;
$failureFactory = static function (string $key) use (&$failureCalls): HermesGrowthReadClient {
    return new HermesGrowthReadClient(
        $key,
        static function () use (&$failureCalls): array {
            $failureCalls++;

            return ['status' => 503, 'body' => '{}'];
        },
        static fn (): int => 1793870000,
        static fn (): string => 'abcdefghijklmnopqrstuvwx',
    );
};
$unknownOptions = $base;
$unknownOptions['run-id'] = 'hg-20261105T095501Z-fail';
$unknown = HermesGrowthOwnerCaller::execute($unknownOptions, $failureFactory, $clock);
$assert($unknown['status'] === 'unknown');
$assert($unknown['failed_action'] === 'curriculum');
$assert($unknown['error'] === 'growth_http_503');
$assert($unknown['retry_allowed'] === false);
$assert($unknown['completed_actions'] === []);
$assert($failureCalls === 1, 'unknown_result_was_retried');
$assert(is_file($receipts.'/'.$unknownOptions['run-id'].'.json'));

$leakFactory = static fn (string $key): HermesGrowthReadClient => new HermesGrowthReadClient(
    $key,
    static fn (): array => throw new RuntimeException('/private/path/token'),
    static fn (): int => 1793870000,
    static fn (): string => 'abcdefghijklmnopqrstuvwx',
);
$leakOptions = $base;
$leakOptions['run-id'] = 'hg-20261105T095502Z-safe';
$safe = HermesGrowthOwnerCaller::execute($leakOptions, $leakFactory, $clock);
$assert($safe['error'] === 'growth_cycle_failed');
$assert(! str_contains(json_encode($safe, JSON_THROW_ON_ERROR), '/private/path/token'));

chmod($keyFile, 0644);
$unsafeKey = $base;
$unsafeKey['run-id'] = 'hg-20261105T095503Z-mode';
$expect(
    static fn () => HermesGrowthOwnerCaller::execute($unsafeKey, $factory, $clock),
    'growth_key_file_unsafe',
);
chmod($keyFile, 0600);

chmod($receipts, 0750);
$unsafeDirectory = $base;
$unsafeDirectory['run-id'] = 'hg-20261105T095504Z-dirx';
$expect(
    static fn () => HermesGrowthOwnerCaller::execute($unsafeDirectory, $factory, $clock),
    'growth_receipt_directory_unsafe',
);
chmod($receipts, 0700);

foreach (glob($receipts.'/*.json') ?: [] as $path) {
    unlink($path);
}
unlink($keyFile);
rmdir($receipts);
rmdir($root);

echo "Hermes Growth owner caller: {$assertions} assertions passed.\n";
