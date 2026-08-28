<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\SetupController;
use App\Controllers\LanguageController;
use App\Controllers\AuthController;
use App\Controllers\AdminDashboardController;
use App\Controllers\TournamentAdminAuthController;
use App\Controllers\TournamentController;
use App\Controllers\PublicViewController;
use App\Router;

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
header_remove('X-Powered-By');

set_exception_handler(static function (Throwable $throwable): void {
    error_log(sprintf(
        'Unhandled %s in %s:%d',
        $throwable::class,
        $throwable->getFile(),
        $throwable->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header_remove('X-Powered-By');
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    }

echo "500 Internal Server Error\n";
});

$services = require __DIR__ . '/../src/bootstrap.php';

$httpsValue = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
$isHttps = ($httpsValue !== '' && $httpsValue !== 'off' && $httpsValue !== '0')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

$configuredUrl = $services['config']['app']['url'] ?? null;
if (is_string($configuredUrl) && trim($configuredUrl) !== '') {
    $configuredScheme = parse_url(trim($configuredUrl), PHP_URL_SCHEME);
    $isHttps = $isHttps || (is_string($configuredScheme) && strtolower($configuredScheme) === 'https');
}

$trustProxyValue = $services['config']['app']['trust_proxy'] ?? false;
$trustProxy = is_bool($trustProxyValue)
    ? $trustProxyValue
    : filter_var($trustProxyValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;

if ($trustProxy) {
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($forwardedProto !== '') {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = $forwardedProto;
        $isHttps = $isHttps || $forwardedProto === 'https';
    } else {
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    }
} else {
    unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
}

if ($isHttps) {
    $_SERVER['HTTPS'] = 'on';
}

$configuredBasePath = null;
$rawBasePath = $services['config']['app']['base_path'] ?? null;
if (is_string($rawBasePath)) {
    $rawBasePath = trim($rawBasePath);
    if ($rawBasePath === '') {
        $configuredBasePath = '';
    } else {
        $configuredBasePath = '/' . trim($rawBasePath, '/');
        if ($configuredBasePath === '/') {
            $configuredBasePath = '';
        }
    }
}

$scriptDirectory = '';
if ($configuredBasePath !== null) {
    $scriptDirectory = $configuredBasePath;
} else {
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $scriptDirectory = str_replace('\\', '/', dirname($scriptName));
    $scriptDirectory = $scriptDirectory === '/' || $scriptDirectory === '.'
        ? ''
        : rtrim($scriptDirectory, '/');
}

$sessionCookiePath = $scriptDirectory !== '' ? $scriptDirectory : '/';
$sessionName = $scriptDirectory === ''
    ? 'BRACKETBIRDSESSID'
    : 'BRACKETBIRD' . strtoupper(substr(hash('sha256', $scriptDirectory), 0, 12));

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name($sessionName);
session_cache_limiter('');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $sessionCookiePath,
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (!session_start()) {
    throw new RuntimeException('Unable to start the application session.');
}

$appEnv = strtolower((string) ($services['config']['app']['env'] ?? 'prod'));
$displayErrors = $appEnv === 'dev' || $appEnv === 'local';
ini_set('display_errors', $displayErrors ? '1' : '0');
ini_set('display_startup_errors', $displayErrors ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: https://api.qrserver.com; frame-src https://www.google.com https://www.google.com/maps; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

$router = new Router();
$homeController = new HomeController($services);
$setupController = new SetupController($services);
$languageController = new LanguageController($services);
$authController = new AuthController($services);
$adminDashboardController = new AdminDashboardController($services);
$tournamentAdminAuthController = new TournamentAdminAuthController($services);
$tournamentController = new TournamentController($services);
$publicViewController = new PublicViewController($services);
$router->setNotFoundHandler([$homeController, 'notFound']);

$router->get('/', [$homeController, 'index']);
$router->get('/setup', [$setupController, 'index']);
$router->post('/setup', [$setupController, 'store']);
$router->post('/language', [$languageController, 'switch']);

$router->get('/admin/login', [$authController, 'loginForm']);
$router->post('/admin/login', [$authController, 'login']);
$router->post('/admin/logout', [$authController, 'logout']);

$router->get('/admin/dashboard', [$adminDashboardController, 'index']);
$router->post('/admin/tournaments/create', [$adminDashboardController, 'createTournament']);
$router->post('/admin/tournaments/delete', [$adminDashboardController, 'deleteTournament']);

$router->get('/admin/tournament', [$tournamentController, 'detail']);
$router->get('/admin/tournament/{section}', [$tournamentController, 'detailSection']);
$router->get('/admin/tournament/print/schedule', [$tournamentController, 'printSchedule']);
$router->get('/admin/tournament/print/schedule-by-court', [$tournamentController, 'printScheduleByCourt']);
$router->get('/admin/tournament/print/schedule-by-group', [$tournamentController, 'printScheduleByGroup']);
$router->get('/admin/tournament/print/group-matrix', [$tournamentController, 'printGroupMatrix']);
$router->get('/admin/tournament/print/knockout', [$tournamentController, 'printKnockout']);
$router->post('/admin/tournament/update', [$tournamentController, 'update']);
$router->post('/admin/tournament/teams/create', [$tournamentController, 'createTeam']);
$router->post('/admin/tournament/teams/update', [$tournamentController, 'updateTeam']);
$router->post('/admin/tournament/teams/delete', [$tournamentController, 'deleteTeam']);
$router->post('/admin/tournament/teams/assign', [$tournamentController, 'assignTeamGroup']);
$router->post('/admin/tournament/teams/assign-auto', [$tournamentController, 'autoAssignTeams']);
$router->post('/admin/tournament/matches/generate', [$tournamentController, 'generateGroupMatches']);
$router->get('/admin/tournament/matches/{matchId}', [$tournamentController, 'groupMatchDetail']);
$router->post('/admin/tournament/matches/{matchId}/start', [$tournamentController, 'startGroupMatch']);
$router->post('/admin/tournament/matches/{matchId}/score', [$tournamentController, 'saveGroupMatchScore']);
$router->post('/admin/tournament/matches/{matchId}/reset', [$tournamentController, 'resetGroupMatchResult']);
$router->post('/admin/tournament/knockout/generate', [$tournamentController, 'generateKnockoutMatches']);
$router->get('/admin/tournament/knockout/{matchId}', [$tournamentController, 'knockoutMatchDetail']);
$router->post('/admin/tournament/knockout/{matchId}/score', [$tournamentController, 'saveKnockoutMatchScore']);
$router->post('/admin/tournament/public-view/update', [$tournamentController, 'updatePublicView']);

$router->get('/tournament/{slug}/login', [$tournamentAdminAuthController, 'loginForm']);
$router->post('/tournament/{slug}/login', [$tournamentAdminAuthController, 'login']);
$router->post('/tournament/{slug}/logout', [$tournamentAdminAuthController, 'logout']);
$router->get('/tournament/{slug}/admin', [$tournamentController, 'detailBySlug']);
$router->get('/tournament/{slug}/admin/{section}', [$tournamentController, 'detailBySlugSection']);
$router->get('/tournament/{slug}/admin/print/schedule', [$tournamentController, 'printScheduleBySlug']);
$router->get('/tournament/{slug}/admin/print/schedule-by-court', [$tournamentController, 'printScheduleByCourtBySlug']);
$router->get('/tournament/{slug}/admin/print/schedule-by-group', [$tournamentController, 'printScheduleByGroupBySlug']);
$router->get('/tournament/{slug}/admin/print/group-matrix', [$tournamentController, 'printGroupMatrixBySlug']);
$router->get('/tournament/{slug}/admin/print/knockout', [$tournamentController, 'printKnockoutBySlug']);
$router->post('/tournament/{slug}/admin/update', [$tournamentController, 'updateBySlug']);
$router->post('/tournament/{slug}/admin/teams/create', [$tournamentController, 'createTeamBySlug']);
$router->post('/tournament/{slug}/admin/teams/update', [$tournamentController, 'updateTeamBySlug']);
$router->post('/tournament/{slug}/admin/teams/delete', [$tournamentController, 'deleteTeamBySlug']);
$router->post('/tournament/{slug}/admin/teams/assign', [$tournamentController, 'assignTeamGroupBySlug']);
$router->post('/tournament/{slug}/admin/teams/assign-auto', [$tournamentController, 'autoAssignTeamsBySlug']);
$router->post('/tournament/{slug}/admin/matches/generate', [$tournamentController, 'generateGroupMatchesBySlug']);
$router->get('/tournament/{slug}/admin/matches/{matchId}', [$tournamentController, 'groupMatchDetailBySlug']);
$router->post('/tournament/{slug}/admin/matches/{matchId}/start', [$tournamentController, 'startGroupMatchBySlug']);
$router->post('/tournament/{slug}/admin/matches/{matchId}/score', [$tournamentController, 'saveGroupMatchScoreBySlug']);
$router->post('/tournament/{slug}/admin/matches/{matchId}/reset', [$tournamentController, 'resetGroupMatchResultBySlug']);
$router->post('/tournament/{slug}/admin/knockout/generate', [$tournamentController, 'generateKnockoutMatchesBySlug']);
$router->get('/tournament/{slug}/admin/knockout/{matchId}', [$tournamentController, 'knockoutMatchDetailBySlug']);
$router->post('/tournament/{slug}/admin/knockout/{matchId}/score', [$tournamentController, 'saveKnockoutMatchScoreBySlug']);
$router->post('/tournament/{slug}/admin/public-view/update', [$tournamentController, 'updatePublicViewBySlug']);

$router->get('/public/{slug}/overview', [$publicViewController, 'overview']);
$router->get('/public/{slug}/next', [$publicViewController, 'nextMatches']);
$router->get('/public/{slug}/standings', [$publicViewController, 'standings']);
$router->get('/public/{slug}/schedule', [$publicViewController, 'schedule']);
$router->get('/public/{slug}/knockout', [$publicViewController, 'knockout']);
$router->get('/public/{slug}/results', [$publicViewController, 'results']);
$router->get('/public/{slug}/display', [$publicViewController, 'display']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$path = is_string($requestUriPath) && $requestUriPath !== '' ? $requestUriPath : '/';

if (
    $scriptDirectory !== ''
    && ($path === $scriptDirectory || str_starts_with($path, $scriptDirectory . '/'))
) {
    $path = substr($path, strlen($scriptDirectory));
    if (!is_string($path) || $path === '') {
        $path = '/';
    }
}

if ($path === '/index.php') {
    $path = '/';
} elseif (strncmp($path, '/index.php/', strlen('/index.php/')) === 0) {
    $path = substr($path, strlen('/index.php'));
}

if (!is_string($path) || $path === '') {
    $path = '/';
}

$requiresNoStore = $path === '/setup'
    || str_starts_with($path, '/setup/')
    || $path === '/language'
    || str_starts_with($path, '/language/')
    || $path === '/admin'
    || str_starts_with($path, '/admin/')
    || $path === '/tournament'
    || str_starts_with($path, '/tournament/');

if ($requiresNoStore) {
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

if (strtoupper($method) === 'POST') {
    $sessionToken = $_SESSION['_csrf_token'] ?? '';
    $postedToken = $_POST['_csrf_token'] ?? '';
    if (
        !is_string($sessionToken)
        || $sessionToken === ''
        || !is_string($postedToken)
        || $postedToken === ''
        || !hash_equals($sessionToken, $postedToken)
    ) {
        $homeController->forbidden();
    }
}

$router->dispatch($method, $path);
