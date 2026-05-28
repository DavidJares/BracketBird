<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\SetupController;
use App\Controllers\AuthController;
use App\Controllers\AdminDashboardController;
use App\Controllers\TournamentAdminAuthController;
use App\Controllers\TournamentController;
use App\Controllers\PublicViewController;
use App\Router;

$services = require __DIR__ . '/../src/bootstrap.php';
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$appEnv = strtolower((string) ($services['config']['app']['env'] ?? 'prod'));
$displayErrors = $appEnv === 'dev' || $appEnv === 'local';
ini_set('display_errors', $displayErrors ? '1' : '0');
ini_set('display_startup_errors', $displayErrors ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: https://api.qrserver.com; frame-src https://www.google.com https://www.google.com/maps; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

$router = new Router();
$homeController = new HomeController($services);
$setupController = new SetupController($services);
$authController = new AuthController($services);
$adminDashboardController = new AdminDashboardController($services);
$tournamentAdminAuthController = new TournamentAdminAuthController($services);
$tournamentController = new TournamentController($services);
$publicViewController = new PublicViewController($services);

$router->get('/', [$homeController, 'index']);
$router->get('/setup', [$setupController, 'index']);
$router->post('/setup', [$setupController, 'store']);

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
    $scriptDirectory = $scriptDirectory === '/' || $scriptDirectory === '.' ? '' : rtrim($scriptDirectory, '/');
}

$path = is_string($requestUriPath) && $requestUriPath !== '' ? $requestUriPath : '/';

if ($scriptDirectory !== '' && strncmp($path, $scriptDirectory, strlen($scriptDirectory)) === 0) {
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
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '403 Forbidden';
        exit;
    }
}

$router->dispatch($method, $path);
