<?php

return [
    'enabled' => (bool) env('IDENTITY_API_ENABLED', false),
    'resale_evidence' => env('IDENTITY_RESALE_EVIDENCE'),
    'egress_verified' => (bool) env('IDENTITY_EGRESS_VERIFIED', false),
    'token' => env('IDENTITY_ZOHAL_TOKEN', env('ZOHAL_TOKEN')),
    'base_url' => env('ZOHAL_BASE_URL', 'https://service.zohal.io'),
    'markup_bps' => 500,
    'terms_version' => '2026-10-17',
    'decision_minutes' => 15,
    'result_retention_hours' => 24,
    'max_payload_bytes' => 16384,
    'provider_concurrency' => 4,
    'provider_daily_cap_irt' => 0, // Explicit operator funding limit required.
    'upstream_balance_irt' => null,
    'upstream_balance_verified_at' => null,
    'balance_max_age_minutes' => 60,
    'internal_kyc_reserve_irt' => 0,
    'auto_topup_enabled' => false, // No documented contractual payment API.
];
