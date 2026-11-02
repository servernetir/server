<?php
declare(strict_types=1);

// No production paths are accessed. Full-flow tests rewrite only private test copies.
require __DIR__.'/deploy.php';
$package = readJson(__DIR__.'/patchdata.json');
$checks = 0;
function checkReleaseTest(bool $ok, string $name): void {
    if (!$ok) { throw new RuntimeException('test_failed:'.$name); }
}
foreach ($package['files'] as $file) {
    $base = implode("\n// independent hunk separator\n", array_column($file['hunks'], 'old'));
    $expected = implode("\n// independent hunk separator\n", array_column($file['hunks'], 'new'));
    $first = patchSource($base, $file['hunks']);
    checkReleaseTest($first['bytes'] === $expected, 'exact_patch'); $checks++;
    $again = patchSource($first['bytes'], $file['hunks']);
    checkReleaseTest($again['bytes'] === $expected && count(array_filter($again['hunks'], fn ($v) => $v === 'already_applied')) === count($file['hunks']), 'already_applied'); $checks++;
    checkReleaseTest(patchSource("// unrelated edit\n".$base, $file['hunks'])['bytes'] === "// unrelated edit\n".$expected, 'unrelated_edit_preserved'); $checks++;
    checkReleaseTest(patchSource(str_replace("\n", "\r\n", $base), $file['hunks'])['bytes'] === str_replace("\n", "\r\n", $expected), 'crlf_preserved'); $checks++;
    try { patchSource($base.$file['hunks'][0]['old'], $file['hunks']); throw new RuntimeException('ambiguity_accepted'); }
    catch (ReleaseRefusal) { $checks++; }
}
checkReleaseTest(array_column($package['files'], 'path') === RELEASE_PATHS, 'six_fixed_paths');
checkReleaseTest(hash_file('sha256', __DIR__.'/patchdata.json') === PATCHDATA_SHA256, 'package_integrity');

function removeReleaseTestTree(string $root): void {
    foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isDir() && !$file->isLink()) { removeReleaseTestTree($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($root);
}
function releaseTestCall(string $app, string $dir, array $args): array {
    $p = proc_open([PHP_BINARY, $dir.'/deploy.php', ...$args],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app);
    if (!is_resource($p)) { throw new RuntimeException('test_process_failed'); }
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($p);
    $decoded = json_decode($stdout, true, 128, JSON_THROW_ON_ERROR);
    return [$exit, $decoded];
}
$flowCases = ['success_receipt_noop', 'preflight_drift', 'install_failure', 'receipt_later_drift', 'untouched_later_drift', 'owned_drift_continues_rollback'];
foreach ($flowCases as $case) {
    $testRoot = sys_get_temp_dir().'/servernet-release-test-'.bin2hex(random_bytes(8));
    if (!mkdir($testRoot, 0700)) { throw new RuntimeException('test_directory_failed'); }
    try {
        $app = $testRoot.'/app'; $dir = $app.'/storage/app/private/arvan-delivery-synthetic123';
        mkdir($dir, 0700, true); mkdir($app.'/storage/framework', 0700, true);
        $fixture = ['schema' => 1, 'base_commit' => 'synthetic-only', 'candidate_commit' => 'synthetic-only', 'files' => []];
        $before = [];
        foreach (RELEASE_PATHS as $n => $path) {
            $old = "<?php\n// fixture ".$n." before\nreturn 1;\n";
            $new = "<?php\n// fixture ".$n." after\nreturn 2;\n";
            mkdir(dirname($app.'/'.$path), 0700, true);
            file_put_contents($app.'/'.$path, $old); chmod($app.'/'.$path, 0644); $before[$path] = $old;
            $fixture['files'][] = ['path' => $path, 'base_sha256' => sha($old), 'candidate_sha256' => sha($new), 'hunks' => [['id' => 1, 'old' => $old, 'new' => $new]]];
        }
        $fixtureBytes = json_encode($fixture, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        file_put_contents($dir.'/patchdata.json', $fixtureBytes); chmod($dir.'/patchdata.json', 0600);
        $helper = (string) file_get_contents(__DIR__.'/deploy.php');
        $helper = str_replace("const RELEASE_ROOT = '".RELEASE_ROOT."';", 'const RELEASE_ROOT = '.var_export($app, true).';', $helper);
        $helper = str_replace("const RELEASE_PHP = '".RELEASE_PHP."';", 'const RELEASE_PHP = '.var_export(PHP_BINARY, true).';', $helper);
        $helper = str_replace("const PATCHDATA_SHA256 = '".PATCHDATA_SHA256."';", "const PATCHDATA_SHA256 = '".sha($fixtureBytes)."';", $helper);
        // PHP8.3 can execute these isolated control-flow tests; production helper stays PHP8.4-only.
        $helper = str_replace("version_compare(PHP_VERSION, '8.4.0', '<')", 'false /* TEST COPY ONLY */', $helper);
        if ($case === 'install_failure') {
            $needle = "\$dst = RELEASE_ROOT.'/'.\$file['path'];";
            $helper = str_replace($needle, $needle."\n    if (\$file['path'] === 'lang/en/ui.php' && \$expected === \$file['before']) { refuse('synthetic_install_failure'); }", $helper, $count);
            checkReleaseTest($count === 1, 'fault_injection_unique');
        }
        if (in_array($case, ['untouched_later_drift', 'owned_drift_continues_rollback'], true)) {
            $needle = "if (\$file['before'] === \$file['after']) { continue; }";
            $driftPath = $case === 'untouched_later_drift' ? 'lang/tr/ui.php' : 'app/Models/CloudInstance.php';
            $hook = "if (\$file['path'] === 'lang/fa/ui.php') { file_put_contents(RELEASE_ROOT.'/".$driftPath."', \"\\n// unrelated concurrent edit\\n\", FILE_APPEND); refuse('synthetic_concurrent_drift'); }\n                ";
            $helper = str_replace($needle, $hook.$needle, $helper, $count);
            checkReleaseTest($count === 1, 'drift_injection_unique');
        }
        file_put_contents($dir.'/deploy.php', $helper); chmod($dir.'/deploy.php', 0600);
        [$exit, $inspection] = releaseTestCall($app, $dir, ['--inspect']);
        checkReleaseTest($exit === 0 && count($inspection['files']) === 6, 'initial_inspection');
        [$exit, $result] = releaseTestCall($app, $dir, ['--plan']);
        checkReleaseTest($exit === 0 && $result['status'] === 'prepared_not_installed', 'plan');
        $planHash = hash_file('sha256', $dir.'/plan.json');
        if ($case === 'preflight_drift') { file_put_contents($app.'/lang/tr/ui.php', "\n// preserved edit\n", FILE_APPEND); }
        [$exit, $result] = releaseTestCall($app, $dir, ['--apply', $planHash]);
        if (in_array($case, ['success_receipt_noop', 'receipt_later_drift'], true)) {
            checkReleaseTest($exit === 0 && $result['status'] === 'source_installed_activation_pending', 'apply_success');
            $receipt = file_get_contents($dir.'/receipt.json');
            if ($case === 'receipt_later_drift') { file_put_contents($app.'/lang/tr/ui.php', "\n// preserved later edit\n", FILE_APPEND); }
            $snapshot = [];
            foreach (RELEASE_PATHS as $path) { $snapshot[$path] = file_get_contents($app.'/'.$path); }
            [$retryExit, $retry] = releaseTestCall($app, $dir, ['--apply', $planHash]);
            if ($case === 'receipt_later_drift') {
                checkReleaseTest($retryExit === 1 && $retry['error_code'] === 'historical_receipt_live_state_changed', 'later_drift_refused');
            } else {
                checkReleaseTest($retryExit === 0 && $retry['status'] === 'receipt_already_exists' && $retry['live_after_hashes_reverified'], 'retry_reverified');
            }
            checkReleaseTest(file_get_contents($dir.'/receipt.json') === $receipt, 'historical_receipt_immutable');
            foreach ($snapshot as $path => $bytes) { checkReleaseTest(file_get_contents($app.'/'.$path) === $bytes, 'retry_no_mutation'); }
            [$inspectExit, $inspection] = releaseTestCall($app, $dir, ['--inspect']);
            checkReleaseTest($inspectExit === 0 && count($inspection['files']) === 6 && $inspection['files']['lang/tr/ui.php']['sha256'] === sha($snapshot['lang/tr/ui.php']), 'receipt_inspection_still_live');
        } elseif ($case === 'preflight_drift') {
            checkReleaseTest($exit === 1 && $result['attempted_writes'] === [] && $result['installed'] === [], 'preflight_zero_writes');
            checkReleaseTest(file_get_contents($app.'/lang/tr/ui.php') === $before['lang/tr/ui.php']."\n// preserved edit\n", 'preflight_drift_preserved');
        } elseif ($case === 'install_failure') {
            checkReleaseTest($exit === 1 && $result['status'] === 'failed_source_rollback_complete' && count($result['rolled_back']) === 3, 'reverse_rollback');
            foreach ($before as $path => $bytes) { checkReleaseTest(file_get_contents($app.'/'.$path) === $bytes, 'rollback_restores_before'); }
        } elseif ($case === 'untouched_later_drift') {
            checkReleaseTest($exit === 1 && $result['status'] === 'failed_source_rollback_complete' && count($result['rolled_back']) === 2, 'untouched_drift_does_not_block_rollback');
            foreach ($before as $path => $bytes) { checkReleaseTest(file_get_contents($app.'/'.$path) === $bytes.($path === 'lang/tr/ui.php' ? "\n// unrelated concurrent edit\n" : ''), 'untouched_edit_preserved'); }
        } else {
            checkReleaseTest($exit === 1 && $result['status'] === 'failed_rollback_requires_operator' && count($result['rollback_errors']) === 1 && count($result['rolled_back']) === 1, 'rollback_continues_after_owned_drift');
            checkReleaseTest(file_get_contents($app.'/app/Services/Cloud/ArvanConsoleBroker.php') === $before['app/Services/Cloud/ArvanConsoleBroker.php'], 'earlier_owned_file_restored');
            checkReleaseTest(str_ends_with(file_get_contents($app.'/app/Models/CloudInstance.php'), "\n// unrelated concurrent edit\n"), 'owned_concurrent_edit_preserved');
        }
    } finally { removeReleaseTestTree($testRoot); }
}
echo json_encode(['pure_checks_passed' => $checks, 'isolated_flow_cases_passed' => count($flowCases), 'php' => PHP_VERSION], JSON_THROW_ON_ERROR), PHP_EOL;
