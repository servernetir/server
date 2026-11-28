<?php

return [
    // A locale goes live only after its public pilot, language review and
    // acceptance tests pass. This does not enable customer/account routes.
    'enabled_locales' => env('INTERNATIONAL_MARKET_LOCALES', ''),
];
