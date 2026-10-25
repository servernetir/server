<?php

namespace Tests\Feature;

use Tests\TestCase;

class OvhLeaseConcurrencyTest extends TestCase
{
    public function test_two_independent_workers_cannot_claim_the_same_order_lease(): void
    {
        if (! function_exists('proc_open')) { $this->markTestSkipped('proc_open unavailable'); }
        $dir = sys_get_temp_dir().'/snet-ovh-lease-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $db = $dir.'/lease.sqlite';
        $pdo = new \PDO('sqlite:'.$db);
        $pdo->exec('CREATE TABLE ovh_orders (id INTEGER PRIMARY KEY, lease_token TEXT, locked_until TEXT, updated_at TEXT)');
        $pdo->exec('INSERT INTO ovh_orders (id) VALUES (1)');
        $args = [PHP_BINARY];
        if (php_ini_loaded_file()) { array_push($args, '-c', php_ini_loaded_file()); }
        else {
            array_push($args, '-n', '-d', 'extension_dir='.ini_get('extension_dir'));
            foreach (['pdo_sqlite', 'mbstring', 'openssl', 'fileinfo', 'curl', 'intl', 'sodium', 'zip', 'gd'] as $ext) {
                $file = rtrim(ini_get('extension_dir'), '/\\').'/'.(PHP_OS_FAMILY === 'Windows' ? 'php_'.$ext.'.dll' : $ext.'.so');
                if (is_file($file)) { array_push($args, '-d', 'extension='.$ext); }
            }
        }
        $processes = []; $allPipes = [];
        try {
            foreach ([1, 2] as $slot) {
                $command = [...$args, base_path('tests/Support/ovh-lease-worker.php'), $db, $dir, (string) $slot];
                $processes[$slot] = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($processes[$slot]); fclose($pipes[0]); $allPipes[$slot] = $pipes;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($dir.'/ready-1') || ! is_file($dir.'/ready-2')) && microtime(true) < $deadline) { usleep(1000); }
            $this->assertFileExists($dir.'/ready-1'); $this->assertFileExists($dir.'/ready-2');
            file_put_contents($dir.'/go', 'go');
            $results = [];
            foreach ($processes as $slot => $process) {
                $out = stream_get_contents($allPipes[$slot][1]); $err = stream_get_contents($allPipes[$slot][2]);
                fclose($allPipes[$slot][1]); fclose($allPipes[$slot][2]);
                $this->assertSame(0, proc_close($process), $err); unset($processes[$slot]);
                $results[] = trim($out);
            }
            sort($results); $this->assertSame(['busy', 'won'], $results);
            $this->assertNotEmpty($pdo->query('SELECT lease_token FROM ovh_orders WHERE id=1')->fetchColumn());
        } finally {
            foreach ($processes as $process) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
            $pdo = null;
            foreach (['ready-1', 'ready-2', 'go', 'lease.sqlite', 'lease.sqlite-shm', 'lease.sqlite-wal', 'lease.sqlite-journal'] as $name) { if (is_file($dir.'/'.$name)) { unlink($dir.'/'.$name); } }
            rmdir($dir);
        }
    }
}
