<?php

return [
    // Deploy schema/adapters first. This switch does not authorize any paid request.
    'enabled' => env('DOMAIN_MULTI_PROVIDER_ENABLED', false),
    // Independently accepted contact path; does not authorize procurement.
    'contact_provisioning_enabled' => env('DOMAIN_SUPPLIER_CONTACT_PROVISIONING_ENABLED', false),
    // Explicit destination for a customer-requested incoming transfer; never infer it from cheapest create.
    'transfer_account_id' => (int) env('DOMAIN_SUPPLIER_TRANSFER_ACCOUNT_ID', 0),
    'health_max_age_seconds' => 300,
    'quote_ttl_seconds' => 900,
    'fx_max_age_seconds' => 172800,
    // Pilot remains the default. Production is a separate, bounded release decision.
    'authorization_mode' => env('DOMAIN_SUPPLIER_AUTHORIZATION_MODE', 'pilot'),
    'production_account_ids' => array_values(array_filter(array_map('intval', explode(',',
        (string) env('DOMAIN_SUPPLIER_PRODUCTION_ACCOUNT_IDS', ''))), fn ($id) => $id > 0)),
    // JSON mapping of ISO currency to an integer landed-cost ceiling per operation.
    'production_caps_minor' => json_decode((string) env('DOMAIN_SUPPLIER_PRODUCTION_CAPS_MINOR', '{}'), true) ?: [],
    'approver_user_ids' => array_values(array_filter(array_map('intval', explode(',',
        (string) env('DOMAIN_SUPPLIER_APPROVER_USER_IDS', ''))), fn ($id) => $id > 0)),
];
