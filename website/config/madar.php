<?php

return [
    'terms_version' => '2026-10-31-v2',
    'max_open_per_customer' => 5,
    'weekly_claim_cap' => 3,
    'repeat_days' => 30,
    'review_days' => 7,
    'public_base_url' => 'https://servernet.cloud',
    'campaigns' => [
        'gpu-video' => ['icon' => 'video', 'tag' => 'creative', 'public_content' => true, 'reward_irt' => 500_000, 'target_path' => '/gpu', 'distribution_required' => false],
        'gpu-code' => ['icon' => 'code', 'tag' => 'developer', 'public_content' => true, 'reward_irt' => 400_000, 'target_path' => '/gpu', 'distribution_required' => true],
        'gpu-guide' => ['icon' => 'book', 'tag' => 'education', 'public_content' => true, 'reward_irt' => 300_000, 'target_path' => '/gpu', 'distribution_required' => false],
        'referral' => ['icon' => 'users', 'tag' => 'partner', 'public_content' => false, 'reward_irt' => 200_000, 'target_path' => '/gpu', 'distribution_required' => false],
        'migration-story' => ['icon' => 'cloud', 'tag' => 'experience', 'public_content' => true, 'reward_irt' => 250_000, 'target_path' => '/cloud', 'distribution_required' => false],
        'gpu-template' => ['icon' => 'code', 'tag' => 'developer', 'public_content' => true, 'reward_irt' => 800_000, 'target_path' => '/gpu', 'distribution_required' => true],
        'gpu-benchmark' => ['icon' => 'zap', 'tag' => 'education', 'public_content' => true, 'reward_irt' => 450_000, 'target_path' => '/gpu', 'distribution_required' => false],
        'gpu-workshop' => ['icon' => 'users', 'tag' => 'partner', 'public_content' => true, 'reward_irt' => 750_000, 'target_path' => '/gpu', 'distribution_required' => true],
    ],
];
