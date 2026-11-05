<?php

require __DIR__.'/preflight.php';

$assertions = 0;
$assert = static function (bool $condition, string $message = 'assertion_failed') use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$temporary = sys_get_temp_dir().'/hermes-growth-preflight-'.bin2hex(random_bytes(8));
if (! mkdir($temporary, 0700) && ! is_dir($temporary)) {
    throw new RuntimeException('temp_directory_failed');
}

$client = $temporary.'/HermesGrowthReadClient.php';
$caller = $temporary.'/owner-growth-caller.php';
file_put_contents($client, "<?php\nfinal class SyntheticGrowthClient {}\n");
file_put_contents($caller, "<?php\n// Synthetic owner caller.\n");
chmod($client, 0644);
chmod($caller, 0644);

$base = [
    'client' => realpath($client),
    'expected-client-sha' => hash_file('sha256', $client),
    'caller' => realpath($caller),
    'expected-caller-sha' => hash_file('sha256', $caller),
    'expected-uid' => (string) fileowner($client),
    'peer-epoch' => (string) time(),
    'max-skew' => '60',
];

$ready = HermesGrowthReadPreflight::inspect($base);
$assert($ready['status'] === 'ready');
$assert($ready['checks']['php_84_or_newer'] === true);
$assert($ready['checks']['curl_loaded'] === true);
$assert($ready['checks']['crypto_available'] === true);
$assert($ready['checks']['clock_within_limit'] === true);
$assert($ready['checks']['client_expected_sha256'] === true);
$assert($ready['checks']['caller_expected_sha256'] === true);
$assert($ready['checks']['client_and_caller_are_distinct'] === true);
$assert($ready['observed']['client']['mode'] === '0644');
$assert($ready['observed']['caller']['path'] === realpath($caller));

$wrongHash = $base;
$wrongHash['expected-client-sha'] = str_repeat('0', 64);
$assert(HermesGrowthReadPreflight::inspect($wrongHash)['status'] === 'not_ready');
$assert(HermesGrowthReadPreflight::inspect($wrongHash)['checks']['client_expected_sha256'] === false);

$wrongOwner = $base;
$wrongOwner['expected-uid'] = (string) (fileowner($client) + 1);
$assert(HermesGrowthReadPreflight::inspect($wrongOwner)['status'] === 'not_ready');
$assert(HermesGrowthReadPreflight::inspect($wrongOwner)['checks']['caller_expected_owner'] === false);

$staleClock = $base;
$staleClock['peer-epoch'] = (string) (time() - 61);
$assert(HermesGrowthReadPreflight::inspect($staleClock)['status'] === 'not_ready');
$assert(HermesGrowthReadPreflight::inspect($staleClock)['checks']['clock_within_limit'] === false);

chmod($caller, 0664);
$unsafe = HermesGrowthReadPreflight::inspect($base);
$assert($unsafe['status'] === 'not_ready');
$assert($unsafe['checks']['caller_not_group_or_world_writable'] === false);
chmod($caller, 0644);

$sameFile = $base;
$sameFile['caller'] = $base['client'];
$sameFile['expected-caller-sha'] = $base['expected-client-sha'];
$assert(HermesGrowthReadPreflight::inspect($sameFile)['status'] === 'not_ready');
$assert(HermesGrowthReadPreflight::inspect($sameFile)['checks']['client_and_caller_are_distinct'] === false);

$invalidSha = $base;
$invalidSha['expected-client-sha'] = 'not-a-sha';
try {
    HermesGrowthReadPreflight::inspect($invalidSha);
    throw new RuntimeException('missing_invalid_sha_exception');
} catch (InvalidArgumentException $exception) {
    $assert($exception->getMessage() === 'invalid_sha256');
}

$relativePath = $base;
$relativePath['client'] = basename($client);
try {
    HermesGrowthReadPreflight::inspect($relativePath);
    throw new RuntimeException('missing_unavailable_file_exception');
} catch (RuntimeException $exception) {
    $assert($exception->getMessage() === 'growth_preflight_file_unavailable');
}

unlink($client);
unlink($caller);
rmdir($temporary);

echo "Hermes Growth preflight: {$assertions} assertions passed.\n";
