<?php

return [
    // Must match an allowed IP on the dedicated Gateway key, if that key has CIDR restrictions.
    'gateway_source_ip' => env('GROWTH_GATEWAY_SOURCE_IP'),
    'media_hosts' => ['api.deepinfra.com', 'inference.deepinfra.com', 'storage.googleapis.com', 'generativelanguage.googleapis.com'],
    'max_asset_bytes' => 25_000_000,
    'research_sources' => [
        'Google AI releases' => 'https://ai.google.dev/gemini-api/docs/changelog',
        'Cloudflare updates' => 'https://blog.cloudflare.com/',
        'ServerNet products' => 'https://servernet.cloud/',
    ],
];
