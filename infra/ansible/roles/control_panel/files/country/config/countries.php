<?php
// Sellable exit countries. The base list is the free-tier live pool (DE/NL/FI).
// Dedicated (guaranteed) exits attached with `servernet-exit-set` are written to
// config/countries_dedicated.php and MERGED in here, so they appear in the
// sale-time dropdown automatically. List ONLY countries with a working exit.
$catalog = [
    'de' => ['label' => 'Germany',     'flag' => 'DE'],
    'nl' => ['label' => 'Netherlands', 'flag' => 'NL'],
    'fi' => ['label' => 'Finland',     'flag' => 'FI'],
];

// Optional overlay managed by servernet-exit-set (dedicated own-server exits).
$overlay = @include __DIR__ . '/countries_dedicated.php';
if (is_array($overlay)) {
    $catalog = array_merge($catalog, $overlay);
}

return ['catalog' => $catalog];
