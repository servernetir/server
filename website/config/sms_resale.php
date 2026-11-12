<?php

return [
    // No shared OTP credentials, automatic money transfer, SSO or live sales by default.
    'panel_url' => 'https://sms.servernet.cloud',
    'minimum_topup_irr' => 1000000,
    'plans' => [
        'start' => ['first_irt' => 0, 'renew_irt' => 0, 'rate_irr' => 2290, 'margin_bps' => 2000],
        'growth' => ['first_irt' => 249000, 'renew_irt' => 129000, 'rate_irr' => 2090, 'margin_bps' => 1500],
        'pro' => ['first_irt' => 599000, 'renew_irt' => 299000, 'rate_irr' => 1990, 'margin_bps' => 1200],
    ],
];
