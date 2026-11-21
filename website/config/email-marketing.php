<?php

return [
    // Enrollment and administrative staging are disabled until the additive
    // migration and consent workflow are independently accepted. Withdrawal
    // must remain available even if enrollment is turned off later.
    'enabled' => env('EMAIL_MARKETING_PREFERENCES_ENABLED', false),
    // Independent evidence review only, not an enrollment or sending feature.
    'evidence_review_enabled' => env('EMAIL_MARKETING_EVIDENCE_REVIEW_ENABLED', false),
];
