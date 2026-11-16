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
    'max_output_tokens' => 12000,
];
