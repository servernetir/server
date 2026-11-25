<?php

return [
    // Small private local receipts are independent of the cache/database.
    'receipt_path' => null, // Resolve storage_path at use time, including isolated test storage.

    'disk' => [
        'warn_percent' => 85,
        'critical_percent' => 95,
        'warn_free_gib' => 8,
        'critical_free_gib' => 2,
    ],

    // Existing cadence + operational slack, in minutes. No new cron.
    'jobs' => [
        'site_gate' => ['warn_age' => 26 * 60, 'fail_age' => 36 * 60, 'run_limit' => 120],
        'fx_refresh' => ['warn_age' => 8 * 60, 'fail_age' => 12 * 60, 'run_limit' => 60],
        'domain_catalogue' => ['warn_age' => 8 * 60, 'fail_age' => 18 * 60, 'run_limit' => 45],
        'file_cache_prune' => ['warn_age' => 90, 'fail_age' => 180, 'run_limit' => 60],
    ],
];
