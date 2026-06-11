<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Database;
use App\Support\Language;
use App\Support\Translator;

abstract class BaseController
{
    /**
     * @var array<string, mixed>
     */
    protected array $services;

    /**
     * @param array<string, mixed> $services
     */
    public function __construct(array $services)
    {
        $this->services = $services;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = []): void
    {
        $viewFile = __DIR__ . '/../Views/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new \RuntimeException(sprintf('View "%s" not found.', $view));
        }

        $config = $this->services['config'] ?? [];
        $flash = $this->pullFlash();
        $csrfToken = $this->csrfToken();
        $csrfField = fn (): string => $this->csrfField();
        $currentSuperadmin = $this->currentSuperadmin();
        $currentTournamentAdmin = $this->currentTournamentAdmin();
        $languages = Language::available();
        $currentLanguage = Language::resolve();
        $translator = new Translator($currentLanguage);
        $t = fn (string $key, array $params = []): string => $translator->translate($key, $params);
        $e = fn (string $key, array $params = []): string => htmlspecialchars($translator->translate($key, $params), ENT_QUOTES, 'UTF-8');
        $url = fn (string $path = '/'): string => $this->url($path);
        extract($data, EXTR_SKIP);

        require __DIR__ . '/../Views/layout.php';
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . $this->url($path));
        exit;
    }

    protected function url(string $path = '/'): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $normalizedPath = '/' . ltrim($path, '/');
        if ($path === '' || $path === '/') {
            $normalizedPath = '/';
        }

        $basePath = $this->basePath();
        if ($basePath === '') {
            return $normalizedPath;
        }

        return $basePath . $normalizedPath;
    }

    protected function basePath(): string
    {
        $configuredBasePath = $this->configuredBasePath();
        if ($configuredBasePath !== null) {
            return $configuredBasePath;
        }

        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $basePath = str_replace('\\', '/', dirname($scriptName));
        if ($basePath === '.' || $basePath === '/') {
            return '';
        }

        return rtrim($basePath, '/');
    }

    private function configuredBasePath(): ?string
    {
        $config = $this->services['config'] ?? [];
        $raw = $config['app']['base_path'] ?? null;
        if (!is_string($raw)) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $normalized = '/' . trim($raw, '/');
        return $normalized === '/' ? '' : $normalized;
    }

    protected function db(): Database
    {
        $database = $this->services['db'] ?? null;
        if (!$database instanceof Database) {
            throw new \RuntimeException('Database service is not available.');
        }

        return $database;
    }

    protected function requestPostString(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    protected function requestGetInt(string $key): int
    {
        $value = $_GET[$key] ?? null;
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || !ctype_digit($value)) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * @param array<string, string|int|float> $params
     */
    protected function translate(string $key, array $params = []): string
    {
        return (new Translator(Language::resolve()))->translate($key, $params);
    }

    protected function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'] = [
            'type' => $type,
            'message' => $this->translate($message),
        ];
    }

    protected function csrfToken(): string
    {
        $token = $_SESSION['_csrf_token'] ?? '';
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    protected function csrfField(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($this->csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    protected function requireCsrfToken(): void
    {
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

    protected function isLoginRateLimited(string $scope, int $maxAttempts = 5, int $lockSeconds = 300): bool
    {
        $state = $this->loginThrottleState($scope);
        $now = time();
        $lockedUntil = (int) ($state['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            return true;
        }

        if ($lockedUntil > 0 && $lockedUntil <= $now) {
            $this->resetLoginThrottle($scope);
        }

        return false;
    }

    protected function recordLoginFailure(string $scope, int $maxAttempts = 5, int $lockSeconds = 300): void
    {
        $state = $this->loginThrottleState($scope);
        $attempts = (int) ($state['attempts'] ?? 0);
        $attempts++;

        $updated = [
            'attempts' => $attempts,
            'locked_until' => 0,
        ];
        if ($attempts >= $maxAttempts) {
            $updated['locked_until'] = time() + $lockSeconds;
        }

        $_SESSION['_login_throttle'][$scope] = $updated;
    }

    protected function resetLoginThrottle(string $scope): void
    {
        if (!isset($_SESSION['_login_throttle']) || !is_array($_SESSION['_login_throttle'])) {
            return;
        }

        unset($_SESSION['_login_throttle'][$scope]);
    }

    /**
     * @return array{attempts:int,locked_until:int}
     */
    private function loginThrottleState(string $scope): array
    {
        $store = $_SESSION['_login_throttle'] ?? null;
        if (!is_array($store)) {
            return ['attempts' => 0, 'locked_until' => 0];
        }

        $raw = $store[$scope] ?? null;
        if (!is_array($raw)) {
            return ['attempts' => 0, 'locked_until' => 0];
        }

        return [
            'attempts' => (int) ($raw['attempts'] ?? 0),
            'locked_until' => (int) ($raw['locked_until'] ?? 0),
        ];
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private function pullFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        if (!is_array($flash)) {
            return null;
        }

        $type = $flash['type'] ?? '';
        $message = $flash['message'] ?? '';

        if (!is_string($type) || !is_string($message) || $type === '' || $message === '') {
            return null;
        }

        return [
            'type' => $type,
            'message' => $message,
        ];
    }

    /**
     * @return array{id: int, username: string}|null
     */
    protected function currentSuperadmin(): ?array
    {
        $superadmin = $_SESSION['superadmin'] ?? null;
        if (!is_array($superadmin)) {
            return null;
        }

        $id = $superadmin['id'] ?? 0;
        $username = $superadmin['username'] ?? '';

        if (!is_int($id) || $id <= 0 || !is_string($username) || $username === '') {
            return null;
        }

        return [
            'id' => $id,
            'username' => $username,
        ];
    }

    protected function requireSuperadminAuth(): void
    {
        if ($this->currentSuperadmin() !== null) {
            return;
        }

        $this->setFlash('error', 'Please sign in as superadmin.');
        $this->redirect('/admin/login');
    }

    /**
     * Accepts HH:MM or HH:MM:SS, returns normalized HH:MM.
     * Returns empty string for empty input and null for invalid value.
     */
    protected function normalizeTimeHHMMOrEmpty(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value) !== 1) {
            return null;
        }

        return substr($value, 0, 5);
    }

    protected function requestRouteString(string $key): string
    {
        $routeParams = $_SERVER['_route_params'] ?? null;
        if (!is_array($routeParams)) {
            return '';
        }

        $value = $routeParams[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return array{id: int, slug: string, name: string}|null
     */
    protected function currentTournamentAdmin(): ?array
    {
        $sessionData = $_SESSION['tournament_admin'] ?? null;
        if (!is_array($sessionData)) {
            return null;
        }

        $id = $sessionData['id'] ?? 0;
        $slug = $sessionData['slug'] ?? '';
        $name = $sessionData['name'] ?? '';

        if (!is_int($id) || $id <= 0 || !is_string($slug) || $slug === '' || !is_string($name) || $name === '') {
            return null;
        }

        return [
            'id' => $id,
            'slug' => $slug,
            'name' => $name,
        ];
    }
}
