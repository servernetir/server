<?php
declare(strict_types=1);

// Full apply flows on six synthetic sources in fresh temporary directories only.
require __DIR__.'/deploy.php';
function receiptCheck(bool $value, string $label): void { if (!$value) { throw new RuntimeException('test_failed:'.$label); } }
function receiptInject(string &$source, string $needle, string $injection): void {
    receiptCheck(substr_count($source, $needle) === 1, 'unique_fault_anchor');
    $source = str_replace($needle, $injection.$needle, $source);
}
function receiptCall(string $app, string $dir, array $args): array {
    $p = proc_open([PHP_BINARY, $dir.'/deploy.php', ...$args], [0 => ['file','/dev/null','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $app);
    if (!is_resource($p)) { throw new RuntimeException('test_process_failed'); }
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($p);
    receiptCheck($err === '', 'no_unexpected_stderr');
    return [$exit, json_decode($out, true, 128, JSON_THROW_ON_ERROR)];
}
function receiptRemove(string $root): void {
    foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isDir() && !$file->isLink()) { receiptRemove($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($root);
}
$cases = ['write', 'flush', 'hash', 'publication', 'post_publication_verify', 'temp_unlink',
    'foreign_before_publication', 'foreign_edit_after_publication', 'foreign_replace_after_publication',
    'source_after_publication', 'prepared_rollback_no_new_writes', 'prepared_rollback_tamper',
    'source_mode_drift', 'preexisting_broken_receipt', 'lock_before_first_rename', 'lock_after_partial_writes', 'lock_after_publication'];
foreach ($cases as $case) {
    $root = sys_get_temp_dir().'/arvan-web-receipt-'.bin2hex(random_bytes(10)); mkdir($root, 0700);
    try {
        $app = $root.'/app'; $dir = $app.'/storage/app/private/arvan-delivery-syntheticv3';
        mkdir($dir, 0700, true); mkdir($app.'/storage/framework', 0700, true);
        $package = ['schema' => 1, 'base_commit' => 'synthetic', 'candidate_commit' => 'synthetic', 'files' => []];
        $before = []; $after = [];
        foreach (RELEASE_PATHS as $n => $path) {
            $before[$path] = "<?php\n// synthetic before $n\nreturn 1;\n";
            $after[$path] = "<?php\n// synthetic after $n\nreturn 2;\n";
            mkdir(dirname($app.'/'.$path), 0700, true); file_put_contents($app.'/'.$path, $before[$path]); chmod($app.'/'.$path, 0644);
            $package['files'][] = ['path' => $path, 'base_sha256' => sha($before[$path]), 'hunks' => [['id' => 1, 'old' => $before[$path], 'new' => $after[$path]]]];
        }
        $bytes = json_encode($package, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        file_put_contents($dir.'/patchdata.json', $bytes); chmod($dir.'/patchdata.json', 0600);
        $helper = (string) file_get_contents(__DIR__.'/deploy.php');
        $helper = str_replace("const RELEASE_ROOT = '".RELEASE_ROOT."';", 'const RELEASE_ROOT = '.var_export($app, true).';', $helper);
        $helper = str_replace("const RELEASE_PHP = '".RELEASE_PHP."';", 'const RELEASE_PHP = '.var_export(PHP_BINARY, true).';', $helper);
        $helper = str_replace("const PATCHDATA_SHA256 = '".PATCHDATA_SHA256."';", "const PATCHDATA_SHA256 = '".sha($bytes)."';", $helper);
        $helper = str_replace("version_compare(PHP_VERSION, '8.4.0', '<')", 'false /* ISOLATED TEST COPY ONLY */', $helper);
        file_put_contents($dir.'/deploy.php', $helper); chmod($dir.'/deploy.php', 0600);
        [$exit, $plan] = receiptCall($app, $dir, ['--plan']);
        receiptCheck($exit === 0 && $plan['status'] === 'prepared_not_installed', $case.'_plan');
        $planHash = hash_file('sha256', $dir.'/plan.json');
        $successGuard = "if ((\$receipt['status'] ?? '') === 'source_installed_activation_pending') ";
        $receiptNameGuard = "if (str_starts_with(basename(\$path), 'receipt-')) ";
        $pubAnchor = '    // Atomic no-overwrite publication; never replace a concurrent receipt.';
        $verifyAnchor = '    // Verification remains inside the source rollback scope.';
        if ($case === 'write') {
            receiptInject($helper, '    writeNew($tmp, $bytes);', '    '.$successGuard."{ refuse('synthetic_receipt_write_failure'); }\n");
        } elseif ($case === 'flush') {
            receiptInject($helper, '        if (!chmod($path, $mode) || !fflush($h) || !fsync($h))', '        '.$receiptNameGuard."{ refuse('synthetic_receipt_flush_failure'); }\n");
        } elseif ($case === 'hash') {
            receiptInject($helper, "    if (hash_file('sha256', \$path) !== sha(\$bytes))", '    '.$receiptNameGuard."{ file_put_contents(\$path, 'corrupted receipt candidate'); }\n");
        } elseif ($case === 'publication') {
            receiptInject($helper, $pubAnchor, '    '.$successGuard."{ refuse('synthetic_receipt_publication_failure'); }\n");
        } elseif ($case === 'post_publication_verify') {
            receiptInject($helper, $verifyAnchor, '    '.$successGuard."{ refuse('synthetic_receipt_verify_failure'); }\n");
        } elseif ($case === 'temp_unlink') {
            receiptInject($helper, "    if (!unlink(\$tmp)) { refuse('receipt_temp_unlink_failed'); }", '    '.$successGuard."{ refuse('synthetic_receipt_temp_unlink_failure'); }\n");
        } elseif ($case === 'foreign_before_publication') {
            receiptInject($helper, $pubAnchor, '    '.$successGuard."{ file_put_contents(\$path, '{\"foreign\":true}'); chmod(\$path, 0600); }\n");
        } elseif (in_array($case, ['foreign_edit_after_publication', 'foreign_replace_after_publication'], true)) {
            $unlink = $case === 'foreign_replace_after_publication' ? 'unlink($path); ' : '';
            receiptInject($helper, $verifyAnchor, '    '.$successGuard."{ ".$unlink."file_put_contents(\$path, '{\"foreign\":true}'); chmod(\$path, 0600); refuse('synthetic_receipt_verify_failure'); }\n");
        } elseif ($case === 'source_after_publication') {
            receiptInject($helper, '            // A published success must still describe the exact live sources.', "            file_put_contents(RELEASE_ROOT.'/lang/en/ui.php', \"\\n// concurrent source edit\\n\", FILE_APPEND);\n");
        } elseif ($case === 'prepared_rollback_no_new_writes') {
            receiptInject($helper, '    $dir = dirname($path);', "    if (file_exists(__DIR__.'/deny-new-writes')) { refuse('synthetic_storage_failure'); }\n");
            receiptInject($helper, '    writeNew($tmp, $bytes);', '    '.$successGuard."{ file_put_contents(__DIR__.'/deny-new-writes', '1'); refuse('synthetic_storage_failure'); }\n");
        } elseif ($case === 'prepared_rollback_tamper') {
            receiptInject($helper, '            publishReceipt($receipt, $owner, $publication, $lock);', "            file_put_contents(\$rollbackPrepared['lang/en/ui.php'], 'foreign rollback candidate edit'); refuse('synthetic_receipt_failure');\n");
        } elseif ($case === 'source_mode_drift') {
            receiptInject($helper, '            publishReceipt($receipt, $owner, $publication, $lock);', "            chmod(RELEASE_ROOT.'/app/Models/CloudInstance.php', 0600); refuse('synthetic_receipt_failure');\n");
        } elseif ($case === 'lock_after_publication') {
            receiptInject($helper, '            // A published success must still describe the exact live sources.', "            unlink(RELEASE_ROOT.'/storage/framework/arvan-delivery-release.lock'); touch(RELEASE_ROOT.'/storage/framework/arvan-delivery-release.lock'); chmod(RELEASE_ROOT.'/storage/framework/arvan-delivery-release.lock', 0600);\n");
        } elseif (in_array($case, ['lock_before_first_rename', 'lock_after_partial_writes'], true)) {
            $target = $case === 'lock_before_first_rename' ? 'app/Services/Cloud/ArvanConsoleBroker.php' : 'lang/fa/ui.php';
            $fault = "    if (\$expected === \$file['before'] && \$file['path'] === '".$target."') { unlink(RELEASE_ROOT.'/storage/framework/arvan-delivery-release.lock'); touch(RELEASE_ROOT.'/storage/framework/arvan-delivery-release.lock'); chmod(RELEASE_ROOT.'/storage/framework/arvan-delivery-release.lock', 0600); }\n";
            receiptInject($helper, "    \$dst = RELEASE_ROOT.'/'.\$file['path'];", $fault);
        } else {
            symlink($dir.'/nonexistent', $dir.'/receipt.json');
        }
        file_put_contents($dir.'/deploy.php', $helper);
        [$exit, $result] = receiptCall($app, $dir, ['--apply', $planHash]);
        receiptCheck($exit === 1, $case.'_fails_closed');
        if ($case === 'preexisting_broken_receipt') {
            receiptCheck(($result['status'] ?? '') === 'refused' && is_link($dir.'/receipt.json'), 'broken_receipt_preserved');
            foreach ($before as $path => $old) { receiptCheck(file_get_contents($app.'/'.$path) === $old, 'broken_receipt_zero_mutations'); }
            continue;
        }
        if (str_starts_with($case, 'lock_')) {
            receiptCheck($result['error_code'] === 'release_lock_identity_changed', $case.'_lock_change_refused');
            $written = $case === 'lock_before_first_rename' ? 0 : ($case === 'lock_after_partial_writes' ? 2 : 6);
            receiptCheck(count($result['attempted_writes']) === $written && count($result['installed']) === $written, $case.'_exact_owned_writes');
            receiptCheck($result['status'] === 'failed_release_lock_reconciliation_requires_operator' && !$result['receipt_withdrawn'] && $result['rolled_back'] === [], $case.'_safe_stop');
            if ($case === 'lock_after_publication') {
                $saved = json_decode((string) file_get_contents($dir.'/receipt.json'), true);
                receiptCheck($saved['status'] === 'source_installed_activation_pending', 'own_success_receipt_preserved_under_foreign_mutex');
            } else { receiptCheck(!file_exists($dir.'/receipt.json'), 'no_failure_receipt_published_under_foreign_mutex'); }
            foreach (RELEASE_PATHS as $n => $path) { receiptCheck(file_get_contents($app.'/'.$path) === ($n < $written ? $after[$path] : $before[$path]), $case.'_no_mutations_under_replaced_mutex'); }
            continue;
        }
        receiptCheck(count($result['attempted_writes']) === 6 && count($result['installed']) === 6, $case.'_fault_after_all_renames');
        if (str_starts_with($case, 'foreign_')) {
            receiptCheck($result['status'] === 'failed_receipt_reconciliation_requires_operator' && !$result['receipt_withdrawn'], $case.'_foreign_receipt_requires_operator');
            receiptCheck(json_decode((string) file_get_contents($dir.'/receipt.json'), true) === ['foreign' => true], $case.'_foreign_receipt_preserved');
            foreach ($after as $path => $new) { receiptCheck(file_get_contents($app.'/'.$path) === $new, $case.'_source_state_preserved_for_reconciliation'); }
        } elseif (in_array($case, ['source_after_publication', 'prepared_rollback_tamper', 'source_mode_drift'], true)) {
            receiptCheck($result['status'] === 'failed_rollback_requires_operator' && count($result['rollback_errors']) === 1 && count($result['rolled_back']) === 5, $case.'_continue_other_rollbacks');
            $preserved = $case === 'source_mode_drift' ? 'app/Models/CloudInstance.php' : 'lang/en/ui.php';
            foreach ($before as $path => $old) {
                $expected = $path === $preserved ? $after[$path].($case === 'source_after_publication' ? "\n// concurrent source edit\n" : '') : $old;
                receiptCheck(file_get_contents($app.'/'.$path) === $expected, $case.'_foreign_source_or_prepared_edit_preserved');
            }
            if ($case === 'source_mode_drift') { clearstatcache(true, $app.'/'.$preserved); receiptCheck((fileperms($app.'/'.$preserved) & 0777) === 0600, 'foreign_mode_preserved'); }
        } else {
            receiptCheck($result['status'] === 'failed_source_rollback_complete' && count($result['rolled_back']) === 6 && $result['receipt_withdrawn'], $case.'_all_sources_rolled_back');
            foreach ($before as $path => $old) { receiptCheck(file_get_contents($app.'/'.$path) === $old, $case.'_before_restored'); }
        }
        if (is_file($dir.'/receipt.json')) {
            $saved = json_decode((string) file_get_contents($dir.'/receipt.json'), true, 128, JSON_THROW_ON_ERROR);
            receiptCheck(($saved['status'] ?? '') !== 'source_installed_activation_pending', $case.'_no_false_success_receipt');
        }
    } finally { receiptRemove($root); }
}
echo json_encode(['receipt_failure_flow_cases_passed' => count($cases), 'php' => PHP_VERSION, 'synthetic_only' => true], JSON_THROW_ON_ERROR), "\n";
