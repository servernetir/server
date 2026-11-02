<?php
declare(strict_types=1);
// Generated with an immutable release path and private config during installation.
// Public endpoint only exchanges signed data; it never accepts executable code.
ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(404); echo '{"ok":false}'; exit; }
if (($_SERVER['QUERY_STRING'] ?? '') !== '') { http_response_code(404); echo '{"ok":false}'; exit; }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) { http_response_code(413); echo '{"ok":false}'; exit; }
$body = file_get_contents('php://input', false, null, 0, 65537);
if (!is_string($body) || strlen($body) > 65536) { http_response_code(413); echo '{"ok":false}'; exit; }
$headers = [];
foreach (['Agent', 'Timestamp', 'Nonce', 'Signature'] as $name) {
    $headers['X-Relay-'.$name] = $_SERVER['HTTP_X_RELAY_'.strtoupper($name)] ?? '';
}
if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}\z/D', $headers['X-Relay-Agent'])
    || !preg_match('/\A[0-9]{1,12}\z/D', $headers['X-Relay-Timestamp'])
    || !preg_match('/\A[0-9a-f]{32}\z/D', $headers['X-Relay-Nonce'])
    || !preg_match('/\A[0-9a-f]{64}\z/D', $headers['X-Relay-Signature'])) {
    http_response_code(403); echo '{"ok":false}'; exit;
}
// Authenticate cheaply in PHP before allowing a request to spawn Python. The
// private gateway still independently verifies the MAC and durable replay log.
function readRelayPrivate(string $path, int $limit): string {
    $s = @lstat($path);
    if (!$s || ($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 0077) !== 0
        || $s['uid'] !== posix_geteuid() || $s['size'] > $limit) { throw new RuntimeException(); }
    $bytes = file_get_contents($path);
    if (!is_string($bytes) || strlen($bytes) > $limit) { throw new RuntimeException(); }
    return $bytes;
}
try {
    $config = json_decode(readRelayPrivate('@CONFIG@', 131072), true, 32, JSON_THROW_ON_ERROR);
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== ($config['agent_path'] ?? '/relay-recovery-agent.php')) { throw new RuntimeException(); }
    $binding = $config['agents'][$headers['X-Relay-Agent']] ?? null;
    if (!is_array($binding) || ($binding['enabled'] ?? false) !== true) { throw new RuntimeException(); }
    $key = readRelayPrivate($binding['key_file'], 256);
    if (strlen($key) < 32 || abs(time() - (int)$headers['X-Relay-Timestamp']) > 90) { throw new RuntimeException(); }
    $canonical = "SN-RELAY/1\n".$headers['X-Relay-Agent']."\nPOST\n".$path."\n".
        $headers['X-Relay-Timestamp']."\n".$headers['X-Relay-Nonce']."\n".hash('sha256', $body);
    if (!hash_equals(hash_hmac('sha256', $canonical, $key), $headers['X-Relay-Signature'])) { throw new RuntimeException(); }
} catch (Throwable $error) { http_response_code(403); echo '{"ok":false}'; exit; }
try {
    $state = $config['state_dir']; $s = lstat($state);
    if (!$s || ($s['mode'] & 0170000) !== 0040000 || ($s['mode'] & 0077) !== 0
        || $s['uid'] !== posix_geteuid()) { throw new RuntimeException(); }
    $admissionPath = $state.'/http-admission.lock';
    if (is_link($admissionPath)) { throw new RuntimeException(); }
    $admission = fopen($admissionPath, 'c+');
    if (!$admission) { throw new RuntimeException(); }
    chmod($admissionPath, 0600);
    // Keep the lock through the child lifetime; replay cannot fork a second
    // child concurrently. A five-second floor also bounds authenticated replay.
    if (!flock($admission, LOCK_EX | LOCK_NB)) { throw new RuntimeException(); }
    $last = (int)stream_get_contents($admission, 32);
    if (time() - $last < 5) { throw new RuntimeException(); }
    rewind($admission); ftruncate($admission, 0); fwrite($admission, (string)time()); fflush($admission);
} catch (Throwable $error) { http_response_code(503); echo '{"ok":false}'; exit; }
$envelope = json_encode(['method' => 'POST', 'path' => $path,
    'headers' => $headers, 'body' => $body], JSON_THROW_ON_ERROR);
$process = proc_open(['@PYTHON@', '-m', 'relay_recovery.gateway', '--config', '@CONFIG@'],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, '@RELEASE@');
if (!is_resource($process)) { http_response_code(503); echo '{"ok":false}'; exit; }
stream_set_blocking($pipes[0], false); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
$output = ''; $offset = 0; $inputOpen = true; $deadline = microtime(true) + 8.0; $failed = false;
while (!feof($pipes[1])) {
    if (microtime(true) > $deadline) { $failed = true; break; }
    $read = [$pipes[1], $pipes[2]]; $write = $inputOpen ? [$pipes[0]] : []; $except = null;
    if (stream_select($read, $write, $except, 0, 100000) === false) { $failed = true; break; }
    if ($inputOpen && $write !== []) {
        $written = @fwrite($pipes[0], substr($envelope, $offset, 8192));
        if ($written === false) { $failed = true; break; }
        $offset += $written;
        if ($offset >= strlen($envelope)) { fclose($pipes[0]); $inputOpen = false; }
    }
    foreach ($read as $stream) {
        $chunk = fread($stream, 8192);
        if ($stream === $pipes[1]) { $output .= $chunk; }
    }
    if (strlen($output) > 200000) { $failed = true; break; }
}
if ($inputOpen) { fclose($pipes[0]); }
if ($failed) {
    proc_terminate($process); usleep(100000);
    if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
}
fclose($pipes[1]); fclose($pipes[2]);
$code = proc_close($process);
$reply = !$failed && $code === 0 ? json_decode($output, true) : null;
if (!is_array($reply) || !in_array($reply['status'] ?? 0, [200,403,404,503], true)
    || !is_string($reply['body'] ?? null)) { http_response_code(503); echo '{"ok":false}'; exit; }
http_response_code($reply['status']);
foreach (($reply['headers'] ?? []) as $name => $value) {
    if (in_array($name, ['X-Relay-Agent','X-Relay-Timestamp','X-Relay-Nonce','X-Relay-Signature'], true)
        && is_string($value) && !str_contains($value,"\r") && !str_contains($value,"\n")) { header($name.': '.$value); }
}
echo $reply['body'];
