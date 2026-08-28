<?php

declare(strict_types=1);

$authIdleTimeout = getenv('APP_AUTH_IDLE_TIMEOUT_SECONDS');
$authAbsoluteTimeout = getenv('APP_AUTH_ABSOLUTE_TIMEOUT_SECONDS');

return [
    'app' => [
        'name' => 'BracketBird',
        'env' => getenv('APP_ENV') ?: 'prod',
        'url' => getenv('APP_URL') ?: null,
        'base_path' => getenv('APP_BASE_PATH') ?: null,
        'timezone' => getenv('APP_TIMEZONE') ?: null,
        'trust_proxy' => filter_var(
            getenv('APP_TRUST_PROXY') ?: false,
            FILTER_VALIDATE_BOOLEAN
        ),
        'setup_token' => getenv('APP_SETUP_TOKEN') ?: '',
        // Disabled unless explicitly configured for the temporary, token-protected
        // shared-hosting migration runner. Remove immediately after migration.
        'migration_token' => getenv('APP_MIGRATION_TOKEN') ?: '',
        'auth_idle_timeout_seconds' => $authIdleTimeout === false ? 1800 : $authIdleTimeout,
        'auth_absolute_timeout_seconds' => $authAbsoluteTimeout === false ? 43200 : $authAbsoluteTimeout,
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_NAME') ?: '',
        'username' => getenv('DB_USER') ?: '',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    ],
];
