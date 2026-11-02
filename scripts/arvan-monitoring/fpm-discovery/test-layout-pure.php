<?php
declare(strict_types=1);
$source = (string) file_get_contents(__DIR__.'/inspect-fpm-layout.php');
$start = strpos($source, 'function layoutMetadata');
eval(substr($source, $start, strpos($source, "$"."result = ['utc'", $start) - $start));
$root = sys_get_temp_dir().'/fpm-layout-test-'.bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root.'/pool', 0700);
$allowed = ['user', 'listen', 'php_admin_value[doc_root]']; $assertions = 0;
function verify(bool $value): void { global $assertions; $assertions++; if (!$value) { throw new RuntimeException('synthetic_layout_failure'); } }
try {
    $file = $root.'/pool/servernet_cloud.conf';
    $bytes = "[servernet_cloud]\nuser = servernetcloud\nlisten = $root/usr/var/run/php-fpm/observed.sock\nsecret = never-emitted\n";
    file_put_contents($file, $bytes);
    $pool = layoutPoolSettings($file, $root.'/pool', $root, $allowed);
    verify($pool['status'] === 'confirmed_account_pool');
    verify(!isset($pool['selected']['secret']));
    verify($pool['observed_listen_socket']['status'] === 'unavailable');
    $link = $root.'/pool/console_servernet_cloud.conf'; symlink($file, $link);
    verify(layoutMetadata($link)['type'] === 'symlink');
    verify(layoutPoolSettings($link, $root.'/pool', $root, $allowed)['status'] === 'confirmed_account_pool');
    file_put_contents($root.'/outside', $bytes); $outside = $root.'/pool/servernet_outside.conf'; symlink($root.'/outside', $outside);
    verify(layoutPoolSettings($outside, $root.'/pool', $root, $allowed)['status'] === 'not_readable_regular_pool_in_observed_directory');
    file_put_contents($file, "user=otheraccount\nlisten=/must/not/emit.sock\n");
    $row = layoutPoolSettings($file, $root.'/pool', $root, $allowed);
    verify($row === ['status' => 'account_pool_not_confirmed']);
    file_put_contents($file, "user=servernetcloud\nuser=otheraccount\n");
    verify(layoutPoolSettings($file, $root.'/pool', $root, $allowed)['status'] === 'account_pool_not_confirmed');
    file_put_contents($file, str_repeat('X', 1024 * 1024 + 1)); clearstatcache(true, $file);
    verify(layoutPoolSettings($file, $root.'/pool', $root, $allowed)['status'] === 'unavailable_or_oversize');
    echo json_encode(['status' => 'ok', 'assertions' => $assertions, 'synthetic_only' => true]), "\n";
} finally {
    foreach (glob($root.'/pool/*') ?: [] as $path) { unlink($path); }
    if (file_exists($root.'/outside')) { unlink($root.'/outside'); }
    rmdir($root.'/pool'); rmdir($root);
}
