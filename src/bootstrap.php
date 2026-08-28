<?php

declare(strict_types=1);

use App\Config\Config;
use App\Models\Database;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', '/', $relativeClass) . '.php';
    $candidates = [
        __DIR__ . '/' . $relativePath,
    ];

    $segments = explode('/', $relativePath);
    if (isset($segments[0]) && $segments[0] !== '') {
        $segmentsLowerFirst = $segments;
        $segmentsLowerFirst[0] = strtolower($segmentsLowerFirst[0]);
        $candidates[] = __DIR__ . '/' . implode('/', $segmentsLowerFirst);
    }

    $candidates[] = __DIR__ . '/' . strtolower($relativePath);

    foreach ($candidates as $file) {
        if (!is_file($file)) {
            continue;
        }

        require $file;
        return;
    }
});

$config = Config::load(__DIR__ . '/config');
$timezone = $config['app']['timezone'] ?? null;
if (is_string($timezone) && trim($timezone) !== '') {
    try {
        date_default_timezone_set((new DateTimeZone(trim($timezone)))->getName());
    } catch (Throwable) {
        date_default_timezone_set('UTC');
    }
}

return [
    'config' => $config,
    'db' => new Database($config['db'] ?? []),
];
