<?php

return [
    // Deployment gate: never enabled by a migration or catalogue refresh.
    'purchase_enabled' => (bool) env('OVH_PURCHASE_ENABLED', false),
    'max_attempts' => 12,
    'max_age_hours' => 24,
    'lease_seconds' => 900,
];
