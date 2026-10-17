<?php

// Run from the app root, with an existing private release directory as argv[1].
// Credentials stay in a short-lived 0600 defaults file, never command arguments/output.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$destination = realpath($argv[1] ?? '');
if (!$destination || !str_starts_with($destination, '/home/servernetcloud/identity-api-')
    || (fileperms($destination) & 0077) !== 0) {
    throw new RuntimeException('Private release destination required');
}
$cfg = Illuminate\Support\Facades\DB::connection()->getConfig();
if (!in_array($cfg['driver'], ['mysql', 'mariadb'], true)) {
    throw new RuntimeException('Unexpected database driver');
}
umask(0077);
$output = $destination.'/database-before.sql.gz';
if (file_exists($output)) {
    throw new RuntimeException('Backup exists; inspect before retrying');
}
$defaults = tempnam($destination, 'mysql-private-');
chmod($defaults, 0600);
$quote = fn ($v) => '"'.str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], (string) $v).'"';
$ini = "[client]\n";
foreach (['host', 'port', 'username', 'password', 'unix_socket'] as $key) {
    if (filled($cfg[$key] ?? null)) {
        $option = ['username' => 'user', 'unix_socket' => 'socket'][$key] ?? $key;
        $ini .= $option.'='.$quote($cfg[$key])."\n";
    }
}
file_put_contents($defaults, $ini);
try {
    $command = ['/bin/mysqldump', '--defaults-extra-file='.$defaults, '--single-transaction',
        '--skip-lock-tables', '--no-tablespaces', '--hex-blob', '--default-character-set=utf8mb4', $cfg['database']];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'],
        2 => ['file', $destination.'/backup-errors.private.log', 'a']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Backup could not start');
    }
    $gz = gzopen($output, 'wb9');
    if (!$gz) {
        throw new RuntimeException('Backup output could not open');
    }
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1048576);
        if ($chunk === false || gzwrite($gz, $chunk) !== strlen($chunk)) {
            throw new RuntimeException('Backup write failed');
        }
    }
    fclose($pipes[1]);
    gzclose($gz);
    if (proc_close($process) !== 0 || filesize($output) < 100) {
        throw new RuntimeException('Backup failed; inspect private log before changes');
    }
    chmod($output, 0600);
    echo json_encode(['backup_sha256' => hash_file('sha256', $output), 'bytes' => filesize($output)]).PHP_EOL;
} finally {
    unlink($defaults);
}
