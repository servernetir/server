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
$cycle = $temporary.'/GrowthReadCycle.php';
$caller = $temporary.'/owner-growth-caller.php';
file_put_contents($client, "<?php\nfinal class SyntheticGrowthClient {}\n");
file_put_contents($cycle, "<?php\nfinal class SyntheticGrowthCycle {}\n");
file_put_contents($caller, "<?php\n// Synthetic owner caller.\n");
chmod($client, 0644);
chmod($cycle, 0644);
chmod($caller, 0644);

$base = [
    'client' => realpath($client),
    'expected-client-sha' => hash_file('sha256', $client),
    'cycle' => realpath($cycle),
    'expected-cycle-sha' => hash_file('sha256', $cycle),
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
$assert($ready['checks']['cycle_expected_sha256'] === true);
$assert($ready['checks']['caller_expected_sha256'] === true);
$assert($ready['checks']['client_and_caller_are_distinct'] === true);
$assert($ready['checks']['runtime_files_are_distinct'] === true);
$assert($ready['observed']['client']['mode'] === '0644');
$assert($ready['observed']['cycle']['path'] === realpath($cycle));
$assert($ready['observed']['caller']['path'] === realpath($caller));

$wrongHash = $base;
$wrongHash['expected-client-sha'] = str_repeat('0', 64);
$assert(HermesGrowthReadPreflight::inspect($wrongHash)['status'] === 'not_ready');
$assert(HermesGrowthReadPreflight::inspect($wrongHash)['checks']['client_expected_sha256'] === false);

$wrongCycleHash = $base;
$wrongCycleHash['expected-cycle-sha'] = str_repeat('0', 64);
$assert(HermesGrowthReadPreflight::inspect($wrongCycleHash)['status'] === 'not_ready');
$assert(HermesGrowthReadPreflight::inspect($wrongCycleHash)['checks']['cycle_expected_sha256'] === false);

$wrongOwner = $base;
$wrongOwner['expected-uid'] = (string) (fileowner($client) + 1);
$assert(HermesGrowthReadPreflight::inspect($wrongOwner)['status'] === 'not_ready');
$assert(HermesGrowthReadPreflight::inspect($wrongOwner)['checks']['caller_expected_owner'] === false);
$assert(HermesGrowthReadPreflight::inspect($wrongOwner)['checks']['cycle_expected_owner'] === false);

$staleClock = $base;
$staleClock['peer-epoch'] = (string) (time() - 61);
$assert(HermesGrowthReadPreflight::inspect($staleClock)['status'] === 'not_ready');
$assert(HermesGrowthReadPreflight::inspect($staleClock)['checks']['clock_within_limit'] === false);

chmod($cycle, 0664);
$unsafeCycle = HermesGrowthReadPreflight::inspect($base);
$assert($unsafeCycle['status'] === 'not_ready');
$assert($unsafeCycle['checks']['cycle_not_group_or_world_writable'] === false);
chmod($cycle, 0644);

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
$assert(HermesGrowthReadPreflight::inspect($sameFile)['checks']['runtime_files_are_distinct'] === false);

$sameCycle = $base;
$sameCycle['cycle'] = $base['client'];
$sameCycle['expected-cycle-sha'] = $base['expected-client-sha'];
$assert(HermesGrowthReadPreflight::inspect($sameCycle)['status'] === 'not_ready');
$assert(HermesGrowthReadPreflight::inspect($sameCycle)['checks']['runtime_files_are_distinct'] === false);

$invalidSha = $base;
$invalidSha['expected-cycle-sha'] = 'not-a-sha';
try {
    HermesGrowthReadPreflight::inspect($invalidSha);
    throw new RuntimeException('missing_invalid_sha_exception');
} catch (InvalidArgumentException $exception) {
    $assert($exception->getMessage() === 'invalid_sha256');
}

$missingCycle = $base;
unset($missingCycle['cycle']);
try {
    HermesGrowthReadPreflight::inspect($missingCycle);
    throw new RuntimeException('missing_cycle_exception');
} catch (InvalidArgumentException $exception) {
    $assert($exception->getMessage() === 'missing_cycle');
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
unlink($cycle);
unlink($caller);
rmdir($temporary);

echo "Hermes Growth preflight: {$assertions} assertions passed.\n";
