<?php
/** Private CLI adapter. Reuses the current verified owner and existing Bale sender. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const HA_HOME = '/home/servernetcloud';
const HA_RELEASE = HA_HOME.'/.hermes-assistant-20261102';
try {
    $raw = stream_get_contents(STDIN, 8193);
    if (strlen($raw) > 8192) { throw new RuntimeException('event_size'); }
    $event = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    $path = HA_RELEASE.'/relay-notifier.json';
    if (is_link($path) || (fileperms($path) & 0077) !== 0) { throw new RuntimeException('private_config'); }
    $config = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    require HA_HOME.'/servernet_app/vendor/autoload.php';
    $app = require HA_HOME.'/servernet_app/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $owner = app(App\Services\Hermes\HermesOperations::class)->owner();
    if (! $owner || ! hash_equals($config['owner_binding'] ?? '', hash('sha256', $owner['user_id'].':'.$owner['chat_id']))) {
        throw new RuntimeException('owner_changed');
    }
    $text = App\Services\Hermes\HermesRelayMonitor::text($event, time());
    $sent = app(App\Services\Bale\BaleSender::class)->send($owner['chat_id'], $text);
    echo json_encode(['sent' => $sent === true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit($sent === true ? 0 : 1);
} catch (Throwable) {
    echo '{"sent":false}'.PHP_EOL;
    exit(1);
}
