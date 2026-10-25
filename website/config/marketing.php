<?php

return [
    // Approval of a campaign alone never bypasses this emergency transport switch.
    'sms_enabled' => false,
    'sms_country_reviewed' => false,
    'sms_daily_limit' => 100,
    'sms_daily_budget' => 0,
    'sms_per_customer_7d' => 2,
    'sms_gap_hours' => 24,
    'sms_hours' => [9, 20],
    'sms_segment_cost_irt' => null,
    // Only synchronous transports with known acceptance semantics may be used.
    'sms_drivers' => ['ippanel', 'kavenegar', 'sns'],
    'scheduler_batch' => 200,
    'reservation_minutes' => 30,
    'supported_operations' => ['purchase', 'renewal'],
];
