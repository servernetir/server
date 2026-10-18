<?php
declare(strict_types=1);

// Private CLI activation of this installed release through the existing route.
// The existing route clears config, route, view AND application cache. It is
// deliberately called once after all file hashes pass, never via a public helper.
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')
    || __DIR__ !== '/home/servernetcloud/cloud-store-experience-20261018-v4'
    || is_link(__DIR__) || (fileperms(__DIR__) & 0777) !== 0700
    || fileowner(__DIR__) !== posix_geteuid() || file_exists(__DIR__.'/ACTIVATED.json')
    || !is_file(__DIR__.'/APPLIED.json')) {
    throw new RuntimeException('Wrong private installed release context or already activated');
}
umask(0077);
$home = dirname(__DIR__);
$owner = json_decode(file_get_contents($home.'/.cloud-store-experience-deploy.lock/owner.json'), true, 32, JSON_THROW_ON_ERROR);
if (($owner['bundle'] ?? null) !== __DIR__) throw new RuntimeException('Release lock missing');
$manifest = json_decode(file_get_contents(__DIR__.'/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
if (count($manifest) !== 53) throw new RuntimeException('Wrong manifest');
foreach ($manifest as $entry) {
    $path = $home.'/'.$entry['path'];
    if (is_link($path) || hash_file('sha256', $path) !== $entry['after']) {
        throw new RuntimeException('Installed manifest drift');
    }
}
require $home.'/servernet_app/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($home.'/servernet_app')->safeLoad();
$token = (string) ($_ENV['DEPLOY_TOKEN'] ?? '');
if ($token === '') throw new RuntimeException('Activation credential unavailable');
$curl = curl_init('https://servernet.cloud/system/opcache');
curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 45,
    CURLOPT_FOLLOWLOCATION => false, CURLOPT_POSTFIELDS => json_encode(['token' => $token], JSON_THROW_ON_ERROR)]);
$body = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$errno = curl_errno($curl);
curl_close($curl);
$json = json_decode($body ?: '', true);
$result = ['utc' => gmdate('c'), 'status' => $status, 'curl_errno' => $errno,
    'opcache_reset' => $json['opcache_reset'] ?? null, 'cleared' => $json['cleared'] ?? [],
    'manifest_sha256' => hash_file('sha256', __DIR__.'/manifest.json')];
echo json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;
if ($status !== 200 || $errno !== 0 || ($json['opcache_reset'] ?? false) !== true
    || array_diff(['config:clear', 'route:clear', 'view:clear', 'cache:clear'], $result['cleared']) !== []) {
    throw new RuntimeException('Activation did not confirm all required results; inspect before retry');
}
$receipt = fopen(__DIR__.'/ACTIVATED.json', 'x');
if ($receipt === false) throw new RuntimeException('Activation receipt exists');
fwrite($receipt, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fclose($receipt);
chmod(__DIR__.'/ACTIVATED.json', 0600);
