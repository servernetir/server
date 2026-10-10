<?php

return [
    // Empty means unknown, never assume an account is VAT exempt.
    'supplier_vat_pct' => env('OPENPROVIDER_VAT_PCT'),
    'supplier_fee_pct' => env('OPENPROVIDER_FEE_PCT', 0),
];
