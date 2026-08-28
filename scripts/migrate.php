<?php

declare(strict_types=1);

use App\Models\MigrationModel;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header_remove('X-Powered-By');
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found';
    exit(1);
}

$services = require __DIR__ . '/../src/bootstrap.php';

try {
    $migrationModel = new MigrationModel($services['db']);
    $applied = $migrationModel->migrate(__DIR__ . '/../src/migrations');

    echo sprintf("Migrations done. Applied: %d\n", $applied);
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Migration failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
