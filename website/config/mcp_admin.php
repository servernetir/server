<?php

return [
    // Canonical values never come from Host/X-Forwarded-Host or tool arguments.
    'origin' => 'https://console.servernet.cloud',
    'resource' => 'https://console.servernet.cloud/api/mcp/admin',
    'ssh_registry' => env('MCP_ADMIN_SSH_REGISTRY', '/home/servernetcloud/.config/servernet/mcp/targets.json'),
    'ssh_binary' => '/usr/bin/ssh',
    'ssh_timeout' => 30,
    'output_limit' => 16384,
    // Fixed production roots for read-only release review, never tool input.
    'release_app_root' => '/home/servernetcloud/servernet_app',
    'release_public_root' => '/home/servernetcloud/public_html',
];
