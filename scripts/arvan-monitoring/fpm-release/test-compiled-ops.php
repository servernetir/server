<?php
declare(strict_types=1);

// Synthetic filesystem checks only. No FPM, Composer, application or production paths.
require dirname(__DIR__).'/arvan-release-candidate/v2/deploy.php';
$source = (string) file_get_contents(__DIR__.'/fpm-activation.php');
$prelude = substr($source, strlen("<?php\n"), strpos($source, '// Guarded FPM entry point') - strlen("<?php\n"));
eval($prelude);
$root = sys_get_temp_dir().'/arvan-fpm-unit-'.bin2hex(random_bytes(10));
mkdir($root, 0700); mkdir($root.'/private', 0700);
$owner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
$assertions = 0;
function check(bool $value, string $label): void { global $assertions; $assertions++; if (!$value) { throw new RuntimeException($label); } }
function refuses(callable $fn, string $reason): void {
    try { $fn(); } catch (RuntimeException $e) { check($e->getMessage() === $reason, 'wrong_refusal_'.$reason); return; }
    throw new RuntimeException('expected_refusal_'.$reason);
}
function fixture(string $name): array {
    global $root, $owner;
    $path = $root.'/'.$name.'.php'; writeNew($path, '<?php // old '.$name."\n", 0640);
    return ['path' => $path] + array_intersect_key(checkedRead($path, $owner), array_flip(['sha256', 'mode', 'uid', 'gid']));
}
function install(array $row, string $bytes): array {
    global $root, $owner;
    $backup = $root.'/private/before-'.bin2hex(random_bytes(6)).'.php';
    writeNew($backup, checkedRead($row['path'], $owner)['bytes']);
    $owned = $row + ['backup' => $backup, 'after' => sha($bytes)];
    afReplaceCompiled($row, $owner, $row['sha256'], $bytes, $root.'/private');
    return $owned;
}
try {
    $a = fixture('normal'); $aOwned = install($a, '<?php // new normal');
    check(afSameCompiled($a, $owner, $aOwned['after'])['mode'] === 0640, 'mode_preserved');
    check(afRollbackCompiled([$aOwned], $owner, $root.'/private', static fn () => null) === [], 'normal_rollback');
    check(hash_file('sha256', $a['path']) === $a['sha256'], 'old_restored');

    // Same bytes with a concurrent metadata change must never be replaced.
    $b = fixture('mode'); chmod($b['path'], 0600);
    refuses(fn () => afReplaceCompiled($b, $owner, $b['sha256'], 'replacement', $root.'/private'), 'compiled_view_changed');
    check(hash_file('sha256', $b['path']) === $b['sha256'] && (fileperms($b['path']) & 0777) === 0600, 'chmod_preserved');

    // Failure on an untouched later row must still restore the earlier owned write.
    $c = fixture('earlier'); $d = fixture('untouched'); $cOwned = install($c, 'new earlier');
    file_put_contents($d['path'], 'concurrent later');
    refuses(fn () => afReplaceCompiled($d, $owner, $d['sha256'], 'replacement', $root.'/private'), 'compiled_view_changed');
    check(afRollbackCompiled([$cOwned], $owner, $root.'/private', static fn () => null) === [], 'earlier_restored_after_later_drift');
    check(hash_file('sha256', $c['path']) === $c['sha256'], 'earlier_old');
    check(file_get_contents($d['path']) === 'concurrent later', 'untouched_drift_preserved');

    // Rollback must continue past an owned path changed by another writer.
    $e = fixture('restore'); $f = fixture('drift');
    $eOwned = install($e, 'new restore'); $fOwned = install($f, 'new drift');
    file_put_contents($f['path'], 'concurrent own drift');
    $errors = afRollbackCompiled([$eOwned, $fOwned], $owner, $root.'/private', static fn () => null);
    check($errors === ['drift.php'], 'drift_reported');
    check(hash_file('sha256', $e['path']) === $e['sha256'], 'rollback_continues');
    check(file_get_contents($f['path']) === 'concurrent own drift', 'owned_drift_preserved');

    $g = fixture('backup'); $gOwned = install($g, 'new backup'); file_put_contents($gOwned['backup'], 'corrupt');
    check(afRollbackCompiled([$gOwned], $owner, $root.'/private', static fn () => null) === ['backup.php'], 'bad_backup_refused');
    check(file_get_contents($g['path']) === 'new backup', 'bad_backup_not_installed');

    // Random private names make an interrupted no-receipt retry non-colliding.
    $h = fixture('resume'); install($h, 'new resume');
    $current = ['path' => $h['path']] + array_intersect_key(checkedRead($h['path'], $owner), array_flip(['sha256', 'mode', 'uid', 'gid']));
    install($current, 'new resume'); check(file_get_contents($h['path']) === 'new resume', 'resume_after_prior_private_backups');

    $i = fixture('receipt'); $receipt = ['compiled_verified' => [$i]];
    afVerifyCompiledReceipt($receipt, [$i], $owner); check(true, 'receipt_verified');
    file_put_contents($i['path'], 'old or concurrent view');
    refuses(fn () => afVerifyCompiledReceipt($receipt, [$i], $owner), 'compiled_view_changed');
    refuses(fn () => afVerifyCompiledReceipt($receipt, [], $owner), 'historical_compiled_inventory_changed');
    $j = fixture('receiptmode'); chmod($j['path'], 0600);
    refuses(fn () => afVerifyCompiledReceipt(['compiled_verified' => [$j]], [$j], $owner), 'compiled_view_changed');
    echo json_encode(['status' => 'ok', 'assertions' => $assertions, 'synthetic_only' => true]), "\n";
} finally {
    foreach (glob($root.'/private/*') ?: [] as $path) { unlink($path); }
    foreach (glob($root.'/*.php') ?: [] as $path) { unlink($path); }
    rmdir($root.'/private'); rmdir($root);
}
