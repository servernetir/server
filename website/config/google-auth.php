<?php

return [
    'enabled' => env('GOOGLE_AUTH_ENABLED', false),
    'client_id' => env('GOOGLE_AUTH_CLIENT_ID'),
    'client_secret' => env('GOOGLE_AUTH_CLIENT_SECRET'),
    // Enable after configuring People API and the optional phone scope in Google Cloud.
    'phone_enabled' => env('GOOGLE_AUTH_PHONE_ENABLED', false),
];
