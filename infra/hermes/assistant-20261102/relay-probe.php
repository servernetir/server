<?php
/** Fixed unauthenticated access-path probe from WHM. No recovery or API credentials. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$available = false; $body = ''; $curl = null; $at = time();
try {
    $input = json_decode(stream_get_contents(STDIN, 1024), true, 4, JSON_THROW_ON_ERROR);
    if (! is_int($input['at'] ?? null) || abs(time() - $input['at']) > 30) { throw new RuntimeException('probe_timestamp'); }
    $at = $input['at'];
    $curl = curl_init('https://admin.servernet.cloud/');
    curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 7,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'ServerNet-Hermes-RelayMonitor/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html', 'Cache-Control: no-cache'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 131072) { return 0; }
            $body .= $chunk; return strlen($chunk);
        }]);
    $ok = curl_exec($curl);
    $available = $ok === true && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200
        && preg_match('~<title>[^<]{0,160}Proxmox[^<]{0,160}</title>~i', $body) === 1;
} catch (Throwable) { /* Failures are path evidence only, never whole-relay state. */ }
finally { if ($curl !== null) { curl_close($curl); } }
echo json_encode(['available' => $available, 'at' => $at], JSON_THROW_ON_ERROR).PHP_EOL;
