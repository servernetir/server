<?php

return [
    'enabled' => env('ENGINE_ENABLED', false),
    'console' => [
        'gateway_url' => env('ENGINE_CONSOLE_GATEWAY_URL'),
        'gateway_secret' => env('ENGINE_CONSOLE_GATEWAY_SECRET'),
        'ttl_seconds' => 90,
    ],
    'traffic' => [
        'max_interval_seconds' => 120,
        'max_clock_skew_seconds' => 30,
        'alert_thresholds' => [80, 90, 100],
    ],
];
