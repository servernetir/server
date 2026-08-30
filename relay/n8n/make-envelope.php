<?php
/*
| ساختِ پاکتِ واقعی — کپیِ دقیقِ BaleRelaySender::encode()، با زمانِ الان.
| خروجی همان چیزی است که رباتِ فرستنده در گروهِ بله می‌نویسد.
*/
$secret = $argv[1] ?? 'test-shared-secret';

$payload = [
    'version'    => 1,
    'template'   => 'otp',
    'mobile'     => '+989142223343',
    'params'     => ['code' => '483920'],
    'request_id' => '9f1c2b7a-0000-4aaa-bbbb-cccccccccccc',
    'issued_at'  => time(),
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$b64  = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
$sig  = hash_hmac('sha256', $b64, $secret);

echo 'SMS_RELAY_V1:'.$b64.'.'.$sig;
