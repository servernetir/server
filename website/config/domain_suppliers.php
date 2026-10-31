<?php

return [
    // Deploy schema/adapters first. This switch does not authorize any paid request.
    'enabled' => env('DOMAIN_MULTI_PROVIDER_ENABLED', false),
    // Explicit destination for a customer-requested incoming transfer; never infer it from cheapest create.
    'transfer_account_id' => (int) env('DOMAIN_SUPPLIER_TRANSFER_ACCOUNT_ID', 0),
    'health_max_age_seconds' => 300,
    'quote_ttl_seconds' => 900,
    'fx_max_age_seconds' => 172800,
    // Each production financial operation still needs an exact owner approval.
    'require_operation_approval' => true,
    'approver_user_ids' => array_values(array_filter(array_map('intval', explode(',',
        (string) env('DOMAIN_SUPPLIER_APPROVER_USER_IDS', ''))), fn ($id) => $id > 0)),
];
