<?php
declare(strict_types=1);

// One temporary denial rule only. No application bootstrap, cache or provider operations.
const TD_STAGE = '/home/servernetcloud/servernet_app/storage/app/private/arvan-delivery-20261103-v3q4m7dz';
const TD_SOURCE = '/home/servernetcloud/public_html/.htaccess';
const TD_PRIMARY = '/home/servernetcloud/public_html/_arvan_delivery_20261103_v3q4m7dz';
const TD_ROOT = '/home/servernetcloud/servernet_app';
const TD_PHP = '/opt/cpanel/ea-php84/root/usr/bin/php';
const TD_LIBRARY_SHA = '02c952a7ba3ac370e88081898c43be2b2b91ff200e894ce927c5e8bc7da8c93e';
const TD_MARKER = 'ServerNet private Arvan release deny 20261103 v3q4m7dz';

function tdFail(string $code): never { throw new RuntimeException($code); }
function tdExists(string $path): bool { clearstatcache(true, $path); return file_exists($path) || is_link($path); }
function tdLoad(int $owner): void {
    $lib = TD_STAGE.'/deploy.php';
    clearstatcache(true, $lib); $s = @lstat($lib);
    if (!$s || is_link($lib) || realpath($lib) !== $lib || ($s['mode'] & 0170000) !== 0100000
        || $s['uid'] !== $owner || ($s['mode'] & 0777) !== 0600 || $s['nlink'] !== 1
        || hash_file('sha256', $lib) !== TD_LIBRARY_SHA) { tdFail('reviewed_library_required'); }
    require_once $lib;
    $r = tdRead($lib, $owner, true);
    if ($r['sha256'] !== TD_LIBRARY_SHA || $r['dev'] !== $s['dev'] || $r['ino'] !== $s['ino']) { tdFail('reviewed_library_changed'); }
}
function tdRead(string $path, int $owner, bool $private = false): array {
    clearstatcache(true, $path); $s = @lstat($path);
    if (!$s || ($s['mode'] & 0170000) !== 0100000 || $s['nlink'] !== 1 || $s['size'] > 524288
        || ($s['mode'] & 0022) || ($s['mode'] & 07000) || ($private && ($s['mode'] & 0777) !== 0600)) { tdFail('file_type_mode_or_size_invalid'); }
    $r = checkedRead($path, $owner);
    clearstatcache(true, $path); $after = @lstat($path);
    if (!$after) { tdFail('file_identity_changed'); }
    foreach (['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $key) {
        if ($after[$key] !== $s[$key]) { tdFail('file_metadata_changed_during_read'); }
    }
    foreach (['dev','ino','uid','gid'] as $key) {
        if ($r[$key] !== $s[$key]) { tdFail('file_identity_changed'); }
    }
    if ($r['mode'] !== ($s['mode'] & 0777) || strlen($r['bytes']) !== $s['size']) { tdFail('file_metadata_changed_during_read'); }
    return $r;
}
function tdSame(array $a, array $b): void {
    foreach (['sha256','mode','uid','gid','dev','ino'] as $k) {
        if (($a[$k] ?? null) !== ($b[$k] ?? null)) { tdFail('source_compare_and_swap_changed'); }
    }
}
function tdBlock(string $nl): string {
    return '    # BEGIN '.TD_MARKER.$nl
        .'    RewriteRule ^_arvan_delivery_20261103_v3q4m7dz(?:/|$) - [F,L]'.$nl
        .'    # END '.TD_MARKER.$nl;
}
function tdInsert(string $bytes): array {
    if (str_contains($bytes, TD_MARKER) || str_contains($bytes, "\0") || str_starts_with($bytes, "\xEF\xBB\xBF")) { tdFail('existing_marker_or_invalid_source'); }
    $nl = str_contains($bytes, "\r\n") ? "\r\n" : "\n";
    if (str_contains(str_replace("\r\n", '', $bytes), "\r")
        || ($nl === "\r\n" && str_contains(str_replace("\r\n", '', $bytes), "\n"))) { tdFail('mixed_newlines'); }
    $lines = explode($nl, $bytes); $engines = []; $allEngines = 0;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*RewriteEngine\b/i', $line)) { $allEngines++; }
        if (preg_match('/^\s*RewriteEngine\s+On\s*$/iD', $line)) { $engines[] = $i; }
    }
    if ($allEngines !== 1 || count($engines) !== 1 || $engines[0] === count($lines)-1) { tdFail('unique_terminated_engine_required'); }
    $at = $engines[0]; $stack = [];
    foreach (array_slice($lines, 0, $at + 1) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) { continue; }
        if (preg_match('/^(?:RewriteCond|RewriteRule|RewriteOptions|RewriteMap|Redirect\w*|ProxyPass\w*|SetHandler)\b/i', $line)) { tdFail('earlier_routing_or_condition_requires_review'); }
        if (str_starts_with($line, '<')) {
            if (preg_match('/^<IfModule\s+([A-Za-z0-9_.-]+)>$/iD', $line, $m)) { $stack[] = strtolower($m[1]); }
            elseif (preg_match('/^<\/IfModule>$/iD', $line)) { if ($stack === []) { tdFail('context_invalid'); } array_pop($stack); }
            else { tdFail('unsupported_earlier_context'); }
        }
    }
    // No conditional wrapper may make the new denial conditional on an unrelated module.
    if ($stack !== [] && $stack !== ['mod_rewrite.c'] && $stack !== ['rewrite_module']) { tdFail('engine_context_requires_review'); }
    $block = tdBlock($nl);
    array_splice($lines, $at + 1, 0, explode($nl, rtrim($block, "\r\n")));
    return ['bytes' => implode($nl, $lines), 'block' => $block, 'newline' => $nl === "\r\n" ? 'CRLF' : 'LF'];
}
function tdJson(array $value): string { return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"; }
function tdPrivateJson(string $name, int $owner): array {
    $r = tdRead(TD_STAGE.'/'.$name, $owner, true);
    $v = json_decode($r['bytes'], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($v)) { tdFail('private_json_invalid'); }
    return [$v, $r];
}
function tdPlan(int $owner, ?string $expected = null): array {
    [$p, $r] = tdPrivateJson('temp-deny-plan.json', $owner);
    if (($expected !== null && $r['sha256'] !== $expected) || ($p['schema'] ?? null) !== 1
        || ($p['source'] ?? null) !== TD_SOURCE || ($p['primary'] ?? null) !== TD_PRIMARY
        || ($p['library_sha256'] ?? null) !== TD_LIBRARY_SHA || ($p['helper_sha256'] ?? null) !== hash_file('sha256', __FILE__)) { tdFail('reviewed_plan_required'); }
    $before = tdRead(TD_STAGE.'/temp-deny-before.txt', $owner, true);
    $candidate = tdRead(TD_STAGE.'/temp-deny-candidate.txt', $owner, true);
    $built = tdInsert($before['bytes']);
    if ($before['sha256'] !== ($p['before']['sha256'] ?? null) || $candidate['bytes'] !== $built['bytes']
        || $candidate['sha256'] !== ($p['after'] ?? null) || ($p['block'] ?? null) !== $built['block']
        || ($p['before']['uid'] ?? null) !== $owner) { tdFail('plan_snapshot_or_scope_changed'); }
    return [$p, $r['sha256'], $candidate];
}
function tdPublish(string $name, array $value, int $owner, $lock): void {
    checkReleaseLock($lock, $owner);
    $tmp = TD_STAGE.'/temp-deny-json-'.bin2hex(random_bytes(12)).'.tmp';
    $bytes = tdJson($value);
    writeNew($tmp, $bytes);
    $r = tdRead($tmp, $owner, true);
    if ($r['sha256'] !== sha($bytes)) { tdFail('receipt_candidate_changed'); }
    checkReleaseLock($lock, $owner);
    if (!@link($tmp, TD_STAGE.'/'.$name)) { tdFail('receipt_no_overwrite_publication_failed'); }
    checkReleaseLock($lock, $owner);
    if (!unlink($tmp)) { tdFail('receipt_temp_cleanup_failed'); }
    $saved = tdRead(TD_STAGE.'/'.$name, $owner, true);
    if ($saved['sha256'] !== $r['sha256'] || $saved['dev'] !== $r['dev'] || $saved['ino'] !== $r['ino']) { tdFail('receipt_publication_changed'); }
    checkReleaseLock($lock, $owner);
}
function tdNoPrimary(): void {
    if (tdExists(TD_PRIMARY) || realpath(dirname(TD_PRIMARY)) !== dirname(TD_PRIMARY)) { tdFail('primary_directory_must_be_absent'); }
}
function tdReplace(string $bytes, array $expected, int $owner, $lock, bool $cleanup): array {
    checkReleaseLock($lock, $owner);
    $tmp = TD_STAGE.'/temp-deny-rename-'.bin2hex(random_bytes(12));
    writeNew($tmp, $bytes, $expected['mode']);
    if (filegroup($tmp) !== $expected['gid'] && !chgrp($tmp, $expected['gid'])) { tdFail('group_preservation_failed'); }
    $h = fopen($tmp, 'rb');
    if (!$h || !fsync($h)) { if (is_resource($h)) { fclose($h); } tdFail('prepared_file_sync_failed'); }
    fclose($h);
    $ready = tdRead($tmp, $owner);
    if ($ready['sha256'] !== sha($bytes) || $ready['mode'] !== $expected['mode'] || $ready['gid'] !== $expected['gid']
        || $ready['dev'] !== $expected['dev']) { tdFail('prepared_rename_invalid'); }
    tdSame(tdRead(TD_SOURCE, $owner), $expected);
    if ($cleanup) { tdNoPrimary(); }
    checkReleaseLock($lock, $owner);
    // No source rollback: after this boundary, leave protection for explicit reconciliation.
    if (!rename($tmp, TD_SOURCE)) { tdFail('atomic_rename_failed'); }
    checkReleaseLock($lock, $owner);
    $now = tdRead(TD_SOURCE, $owner);
    if ($now['sha256'] !== sha($bytes) || $now['dev'] !== $ready['dev'] || $now['ino'] !== $ready['ino']
        || $now['mode'] !== $expected['mode'] || $now['uid'] !== $owner || $now['gid'] !== $expected['gid']) { tdFail('installed_state_changed'); }
    if ($cleanup) { tdNoPrimary(); }
    return $now;
}
function tdOwnership(array $p, string $planHash, int $owner): void {
    [$attempt] = tdPrivateJson('temp-deny-attempt.json', $owner);
    if (($attempt['schema'] ?? null) !== 1 || ($attempt['plan_sha256'] ?? null) !== $planHash
        || ($attempt['before'] ?? null) !== $p['before'] || ($attempt['after'] ?? null) !== $p['after']) { tdFail('deny_ownership_not_proven'); }
}
function tdMain(array $args): int {
    umask(0077);
    if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) { tdFail('native_account_identity_required'); }
    $owner = posix_geteuid();
    if ((posix_getpwuid($owner)['name'] ?? null) !== 'servernetcloud') { tdFail('native_account_identity_required'); }
    if (PHP_SAPI !== 'cli' || version_compare(PHP_VERSION, '8.4.0', '<') || realpath(PHP_BINARY) !== realpath(TD_PHP)
        || realpath(getcwd()) !== TD_ROOT || __DIR__ !== TD_STAGE || realpath(TD_STAGE) !== TD_STAGE
        || is_link(TD_STAGE) || (fileperms(TD_STAGE) & 0777) !== 0700 || fileowner(TD_STAGE) !== $owner
        || realpath(dirname(TD_SOURCE)) !== dirname(TD_SOURCE) || fileowner(dirname(TD_SOURCE)) !== $owner) { tdFail('fixed_native_runtime_and_private_stage_required'); }
    tdLoad($owner);
    $self = tdRead(__FILE__, $owner, true);
    $mode = $args[1] ?? '--inspect';
    if (!in_array($mode, ['--inspect','--plan','--apply','--cleanup'], true) || count($args) !== ($mode === '--inspect' ? 2 : 3)
        || ($mode !== '--inspect' && !preg_match('/\A[a-f0-9]{64}\z/D', $args[2]))) { tdFail('explicit_mode_and_reviewed_hash_required'); }
    $live = tdRead(TD_SOURCE, $owner);
    if ($mode === '--inspect') {
        $out = ['status'=>'read_only','source'=>TD_SOURCE,'sha256'=>$live['sha256'],'mode'=>decoct($live['mode']),
            'uid'=>$live['uid'],'gid'=>$live['gid'],'dev'=>$live['dev'],'ino'=>$live['ino'],
            'marker_count'=>substr_count($live['bytes'], TD_MARKER),'primary_directory_present'=>tdExists(TD_PRIMARY),
            'attempt_present'=>tdExists(TD_STAGE.'/temp-deny-attempt.json'),'receipt_present'=>tdExists(TD_STAGE.'/temp-deny-receipt.json')];
        try { $out['proposed_sha256'] = sha(tdInsert($live['bytes'])['bytes']); } catch (Throwable $e) { $out['insertion_refusal'] = $e->getMessage(); }
        echo tdJson($out); return 0;
    }
    $lockPath = TD_ROOT.'/storage/framework/arvan-delivery-release.lock';
    if (is_link($lockPath) || (tdExists($lockPath) && (!is_file($lockPath) || fileowner($lockPath) !== $owner || (fileperms($lockPath)&0777) !== 0600))) { tdFail('unsafe_release_lock'); }
    $lock = fopen($lockPath, 'c');
    if (!$lock) { tdFail('release_lock_unavailable'); }
    try {
        checkReleaseLock($lock, $owner);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { tdFail('release_busy'); }
        checkReleaseLock($lock, $owner);
        tdSame(tdRead(__FILE__, $owner, true), $self);
        $live = tdRead(TD_SOURCE, $owner);
        if ($mode === '--plan') {
            if ($live['sha256'] !== $args[2]) { tdFail('reviewed_before_hash_required'); }
            if (tdExists(TD_STAGE.'/temp-deny-attempt.json') || tdExists(TD_STAGE.'/temp-deny-receipt.json')) { tdFail('historical_attempt_requires_reconciliation'); }
            if (tdExists(TD_STAGE.'/temp-deny-plan.json')) {
                [$p,$hash] = tdPlan($owner); tdSame($live,$p['before']);
                echo tdJson(['status'=>'existing_plan_reverified','plan_sha256'=>$hash,'changed'=>false]); return 0;
            }
            $built = tdInsert($live['bytes']);
            $meta = $live; unset($meta['bytes']);
            $p = ['schema'=>1,'source'=>TD_SOURCE,'primary'=>TD_PRIMARY,'library_sha256'=>TD_LIBRARY_SHA,
                'helper_sha256'=>$self['sha256'],'before'=>$meta,'after'=>sha($built['bytes']),'block'=>$built['block'],'newline'=>$built['newline']];
            checkReleaseLock($lock, $owner);
            writeNew(TD_STAGE.'/temp-deny-before.txt', $live['bytes']);
            writeNew(TD_STAGE.'/temp-deny-candidate.txt', $built['bytes']);
            tdSame(tdRead(TD_SOURCE, $owner), $live);
            checkReleaseLock($lock, $owner);
            writeNew(TD_STAGE.'/temp-deny-plan.json', tdJson($p));
            [$verified,$hash] = tdPlan($owner); tdSame(tdRead(TD_SOURCE,$owner),$verified['before']);
            checkReleaseLock($lock,$owner);
            echo tdJson(['status'=>'prepared_not_installed','plan_sha256'=>$hash,'before'=>$p['before'],'after'=>$p['after'],'changed'=>false]); return 0;
        }
        [$p,$planHash,$candidate] = tdPlan($owner, $mode === '--apply' ? $args[2] : null);
        if ($mode === '--apply') {
            if (tdExists(TD_STAGE.'/temp-deny-attempt.json') || tdExists(TD_STAGE.'/temp-deny-receipt.json')) { tdFail('historical_attempt_no_automatic_retry'); }
            tdSame($live,$p['before']);
            $attempt = ['schema'=>1,'plan_sha256'=>$planHash,'before'=>$p['before'],'after'=>$p['after']];
            tdPublish('temp-deny-attempt.json',$attempt,$owner,$lock);
            tdSame(tdRead(TD_SOURCE,$owner),$live); checkReleaseLock($lock,$owner);
            try {
                $installed = tdReplace($candidate['bytes'],$live,$owner,$lock,false);
                unset($installed['bytes']);
                tdPublish('temp-deny-receipt.json',['schema'=>1,'status'=>'deny_installed','plan_sha256'=>$planHash,'installed'=>$installed],$owner,$lock);
                tdSame(tdRead(TD_SOURCE,$owner),$installed); checkReleaseLock($lock,$owner);
                echo tdJson(['status'=>'deny_installed','plan_sha256'=>$planHash,'source_sha256'=>$installed['sha256'],'changed'=>true]); return 0;
            } catch (Throwable $e) {
                echo tdJson(['status'=>'unknown_requires_reconciliation','error_code'=>$e->getMessage(),'automatic_rollback'=>false,'automatic_retry'=>false]); return 1;
            }
        }
        tdOwnership($p,$planHash,$owner);
        if ($live['sha256'] !== $args[2] || $live['mode'] !== $p['before']['mode'] || $live['uid'] !== $p['before']['uid'] || $live['gid'] !== $p['before']['gid']) { tdFail('reviewed_cleanup_state_required'); }
        if (substr_count($live['bytes'],TD_MARKER) !== 2 || substr_count($live['bytes'],$p['block']) !== 1) { tdFail('exact_owned_block_required'); }
        tdNoPrimary();
        if (tdExists(TD_STAGE.'/temp-deny-cleanup-attempt.json') || tdExists(TD_STAGE.'/temp-deny-cleanup-receipt.json')) { tdFail('historical_cleanup_no_automatic_retry'); }
        $without = str_replace($p['block'],'',$live['bytes']);
        if (str_contains($without,TD_MARKER)) { tdFail('ambiguous_marker'); }
        tdPublish('temp-deny-cleanup-attempt.json',['schema'=>1,'plan_sha256'=>$planHash,'before'=>$live['sha256'],'after'=>sha($without)],$owner,$lock);
        try {
            $clean = tdReplace($without,$live,$owner,$lock,true); unset($clean['bytes']);
            tdPublish('temp-deny-cleanup-receipt.json',['schema'=>1,'status'=>'owned_block_removed','plan_sha256'=>$planHash,'installed'=>$clean],$owner,$lock);
            tdSame(tdRead(TD_SOURCE,$owner),$clean); tdNoPrimary(); checkReleaseLock($lock,$owner);
            echo tdJson(['status'=>'owned_block_removed','source_sha256'=>$clean['sha256'],'outside_changes_preserved'=>true,'changed'=>true]); return 0;
        } catch (Throwable $e) {
            echo tdJson(['status'=>'unknown_requires_reconciliation','error_code'=>$e->getMessage(),'automatic_rollback'=>false,'automatic_retry'=>false]); return 1;
        }
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(tdMain($argv)); }
    catch (Throwable $e) { echo tdJson(['status'=>'refused','error_code'=>$e->getMessage(),'changed'=>false]); exit(1); }
}
