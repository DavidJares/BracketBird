<?php

declare(strict_types=1);

use App\Models\MigrationModel;

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header_remove('X-Powered-By');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$notFound = static function (): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found';
    exit(1);
};

try {
    $services = require __DIR__ . '/../src/bootstrap.php';
} catch (Throwable $throwable) {
    error_log(sprintf(
        'Migration bootstrap failed: %s in %s:%d',
        $throwable::class,
        $throwable->getFile(),
        $throwable->getLine()
    ));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Migration bootstrap failed. Check the server error log.';
    exit(1);
}

$config = $services['config'] ?? [];
$appConfig = is_array($config) && is_array($config['app'] ?? null)
    ? $config['app']
    : [];
$configuredToken = $appConfig['migration_token'] ?? '';

if (!is_string($configuredToken) || strlen($configuredToken) < 32) {
    $notFound();
}

$httpsValue = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
$isHttps = ($httpsValue !== '' && $httpsValue !== 'off' && $httpsValue !== '0')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
$configuredUrl = $appConfig['url'] ?? null;
if (is_string($configuredUrl) && trim($configuredUrl) !== '') {
    $configuredScheme = parse_url(trim($configuredUrl), PHP_URL_SCHEME);
    $isHttps = $isHttps || (is_string($configuredScheme) && strtolower($configuredScheme) === 'https');
}

if (!$isHttps) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'HTTPS is required.';
    exit(1);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BracketBird database migration</title>
</head>
<body>
    <main>
        <h1>BracketBird database migration</h1>
        <p>Back up the database before continuing. Submit the temporary migration token configured in <code>src/config/local.php</code>.</p>
        <form method="post">
            <label for="migration_token">Migration token</label>
            <input id="migration_token" name="migration_token" type="password" required autocomplete="off">
            <button type="submit">Run migrations</button>
        </form>
    </main>
</body>
</html>
HTML;
    exit(0);
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo '405 Method Not Allowed';
    exit(1);
}

$submittedToken = $_POST['migration_token'] ?? '';
if (
    !is_string($submittedToken)
    || strlen($submittedToken) > 1024
    || !hash_equals($configuredToken, $submittedToken)
) {
    $notFound();
}

try {
    $migrationModel = new MigrationModel($services['db']);
    $applied = $migrationModel->migrate(__DIR__ . '/../src/migrations');

    header('Content-Type: text/plain; charset=utf-8');
    echo sprintf(
        "Migrations completed successfully. Applied versions: %d\nRemove migration_token from local.php now and switch the application back to the limited WEDOS web database user.\n",
        $applied
    );
    exit(0);
} catch (Throwable $throwable) {
    error_log(sprintf(
        'Web migration failed: %s in %s:%d',
        $throwable::class,
        $throwable->getFile(),
        $throwable->getLine()
    ));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Migration failed: ' . $throwable->getMessage();
    exit(1);
}
