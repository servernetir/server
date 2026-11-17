<?php

return [
    'enabled' => (bool) env('SERVERNET_STUDIO_ENABLED', false),
    'client_id' => 'servernet-studio',
    'client_secret' => env('SERVERNET_STUDIO_CLIENT_SECRET'),
    'redirect_uri' => env('SERVERNET_STUDIO_REDIRECT_URI'),
    'token_ttl' => 28800,
];
