<?php

// Managed-service estimates, USD cents; not a billing tariff or AWS invoice.
return [
    'plans' => [[
    'volume' => 10000,
    'usd_cents' => 490,
], [
    'volume' => 50000,
    'usd_cents' => 1490,
], [
    'volume' => 100000,
    'usd_cents' => 2490,
]],
    'extra_thousand_usd_cents' => 25,
    'max_volume' => 1000000,
];
