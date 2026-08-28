<?php

declare(strict_types=1);

return [
    'app' => [
        // Canonical public origin, without a path. Required in production for
        // generated absolute URLs, e.g. 'https://tournaments.example.com'.
        'url' => null,
        // Use '' for domain root (https://example.com),
        // or '/my/sub/path' when app is served from subdirectory.
        'base_path' => '',
        // Optional server fallback timezone, e.g. 'Europe/Prague'.
        // Public "Now" labels use the viewer browser timezone when JavaScript is available.
        'timezone' => null,
        // Enable only behind a trusted reverse proxy that overwrites
        // X-Forwarded-Proto. Direct clients must not reach the origin server.
        'trust_proxy' => false,
        // Prefer APP_SETUP_TOKEN. If environment variables are unavailable, uncomment
        // and replace this with a cryptographically random value of at least 32 bytes.
        // 'setup_token' => 'replace-me',
        // Temporary WEDOS/shared-hosting migration token. Use at least 32 random
        // bytes, then remove this setting immediately after migrations succeed.
        // 'migration_token' => 'replace-with-a-random-one-time-token',
        // Optional auth lifetime overrides. Unsigned decimal seconds are clamped
        // to 60..86400 idle and 600..604800 absolute; invalid values use the
        // defaults (30 minutes idle, 12 hours absolute). Effective idle cannot
        // exceed absolute.
        // 'auth_idle_timeout_seconds' => 1800,
        // 'auth_absolute_timeout_seconds' => 43200,
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'ftt',
        'username' => 'ftt_user',
        'password' => 'change-me',
        'charset' => 'utf8mb4',
    ],
];
