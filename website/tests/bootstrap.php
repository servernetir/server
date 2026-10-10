<?php

if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
    fwrite(STDERR, 'Tests require an isolated checkout without cached application configuration.'.PHP_EOL);
    exit(1);
}

// Fail once with an actionable prerequisite error, instead of dozens of
// misleading application failures (uploads, archives, money formatting, tunnels).
$required = ['curl', 'dom', 'fileinfo', 'gd', 'intl', 'mbstring', 'pdo_sqlite', 'sodium', 'zip'];
$missing = array_values(array_filter($required, fn ($extension) => ! extension_loaded($extension)));

if ($missing !== []) {
    fwrite(STDERR, 'Test PHP runtime is missing extensions: '.implode(', ', $missing).PHP_EOL);
    exit(1);
}

require dirname(__DIR__).'/vendor/autoload.php';
