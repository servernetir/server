<?php

return [
    'enabled' => (bool) env('SERVERNET_STUDIO_ENABLED', false),
    'client_id' => 'servernet-studio',
    'client_secret' => env('SERVERNET_STUDIO_CLIENT_SECRET'),
    'redirect_uri' => env('SERVERNET_STUDIO_REDIRECT_URI'),
    'token_ttl' => 28800,
    'free_openrouter_key' => env('SERVERNET_STUDIO_OPENROUTER_FREE_KEY'),
    'downloads_enabled' => (bool) env('SERVERNET_STUDIO_DOWNLOADS_ENABLED', false),
    'downloads_directory' => env('SERVERNET_STUDIO_DOWNLOADS_DIRECTORY', public_path('downloads/studio')),
    'release_manifest' => storage_path('app/studio/release.json'),
    'preview_directory' => env('SERVERNET_STUDIO_PREVIEW_DIRECTORY',
        dirname(env('SERVERNET_STUDIO_DOWNLOADS_DIRECTORY', public_path('downloads/studio'))).'/studio-preview'),
    'preview_manifest' => storage_path('app/studio/preview.json'),
];
