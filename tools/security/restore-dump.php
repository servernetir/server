<?php
declare(strict_types=1);

// Run on the source host. Credentials remain in a private, short-lived option
// file and are never printed or passed in a process argument.
$home = realpath($argv[1] ?? '');
$stage = realpath($argv[2] ?? '');
if (!$home || !$stage || !str_starts_with($stage, $home.'/deploy-backups/security-restore-')
    || is_link($stage) || (fileperms($stage) & 0077) !== 0) {
    throw new RuntimeException('Invalid private backup directory');
}
chdir($home.'/servernet_app');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection();
$cfg = $connection->getConfig();
if (!in_array($cfg['driver'], ['mysql', 'mariadb'], true)
    || !preg_match('/\A[A-Za-z0-9_]+\z/D', (string) $cfg['database'])) {
    throw new RuntimeException('Unexpected source database');
}
$tables = $connection->select("SELECT TABLE_NAME AS name,ENGINE AS engine FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
foreach ($tables as $table) {
    if ($table->engine !== 'InnoDB' || !preg_match('/\A[A-Za-z0-9_]+\z/D', $table->name)) {
        throw new RuntimeException('Consistent snapshot requires supported transactional tables');
    }
}
$quote = fn ($v) => '"'.strtr((string) $v, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r']).'"';
$optionPath = $stage.'/dump-client.cnf';
$stream = fopen($optionPath, 'x');
if ($stream === false || !chmod($optionPath, 0600)) {
    throw new RuntimeException('Cannot create private client configuration');
}
try {
    $options = "[client]\nuser=".$quote($cfg['username'])."\npassword=".$quote($cfg['password'] ?? '')."\n";
    if (!empty($cfg['unix_socket'])) {
        $options .= 'socket='.$quote($cfg['unix_socket'])."\nprotocol=SOCKET\n";
    } else {
        $options .= 'host='.$quote($cfg['host'])."\nport=".(int)($cfg['port'] ?? 3306)."\n";
    }
    fwrite($stream, $options);
    fclose($stream);
    $out = fopen($stage.'/database.sql', 'x');
    $err = fopen($stage.'/dump-errors.log', 'x');
    chmod($stage.'/database.sql', 0600);
    chmod($stage.'/dump-errors.log', 0600);
    $command = ['/bin/mariadb-dump', '--defaults-extra-file='.$optionPath,
        '--single-transaction', '--skip-lock-tables', '--quick', '--no-tablespaces',
        '--hex-blob', '--skip-extended-insert', '--no-autocommit', '--routines', '--events', '--triggers',
        '--default-character-set=utf8mb4', $cfg['database']];
    $process = proc_open($command, [['pipe', 'r'], $out, $err], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start database backup'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    fclose($out);
    fclose($err);
    if ($exit !== 0) { throw new RuntimeException('Database backup failed; details retained privately'); }
    file_put_contents($stage.'/database-metadata.json', json_encode([
        'version' => $connection->selectOne('SELECT VERSION() AS v')->v,
        'table_names' => array_map(fn ($t) => $t->name, $tables),
        'bytes' => filesize($stage.'/database.sql'), 'sha256' => hash_file('sha256', $stage.'/database.sql'),
    ], JSON_THROW_ON_ERROR));
    chmod($stage.'/database-metadata.json', 0600);
    echo json_encode(['dump_ok' => true, 'tables' => count($tables), 'bytes' => filesize($stage.'/database.sql')]);
} finally {
    if (is_resource($stream)) { fclose($stream); }
    if (is_file($optionPath)) { unlink($optionPath); }
}
