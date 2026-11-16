<?php

return [
    // Official editorial feeds only; content cannot introduce arbitrary fetch targets.
    'feeds' => [
        'https://developer.nvidia.com/blog/feed/',
        'https://blog.cloudflare.com/rss/',
        'https://kubernetes.io/feed.xml',
        'https://pytorch.org/feed.xml',
    ],
    'product_paths' => ['/gpu', '/ai', '/vps/hourly', '/hosting/linux'],
    // Prefer the lower-latency model for the three-language bundle; the existing pool remains fallback.
    'preferred_models' => ['di-googlegemini-25-flash-cd62f9d5ea'],
    'max_output_tokens' => 9000,
];
