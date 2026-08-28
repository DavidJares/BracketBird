<?php

declare(strict_types=1);

return [
    'version' => '20260724_000012_create_login_attempts',
    'description' => 'Create persistent storage for atomic login throttling.',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS login_attempts (
            scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            client_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (scope_hash, client_hash),
            KEY idx_login_attempts_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
