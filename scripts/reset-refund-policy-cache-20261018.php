<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
Dotenv\Dotenv::createImmutable('/home/servernetcloud/servernet_app')->safeLoad();
$token = (string) ($_ENV['DEPLOY_TOKEN'] ?? '');
if ($token === '') throw new RuntimeException('Reset credential unavailable');
$curl = curl_init('https://servernet.cloud/system/opcache');
curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 45,
    CURLOPT_POSTFIELDS => json_encode(['token' => $token])]);
$body = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$errno = curl_errno($curl);
$json = json_decode($body ?: '', true);
$result = ['status' => $status, 'curl_errno' => $errno,
    'opcache_reset' => $json['opcache_reset'] ?? null, 'cleared' => $json['cleared'] ?? []];
echo json_encode($result).PHP_EOL;
if ($status === 200 && ($json['opcache_reset'] ?? false) === true) {
    file_put_contents(__DIR__.'/CACHE-RESET.json', json_encode($result));
    chmod(__DIR__.'/CACHE-RESET.json', 0600);
}
