<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Database;
use App\Models\LoginAttemptModel;
use App\Support\Language;
use App\Support\Translator;
use PDOException;

abstract class BaseController
{
    private const LOGIN_ATTEMPT_WINDOW_SECONDS = 900;
    private const DEFAULT_AUTH_IDLE_TIMEOUT_SECONDS = 1800;
    private const DEFAULT_AUTH_ABSOLUTE_TIMEOUT_SECONDS = 43200;
    private const MIN_AUTH_IDLE_TIMEOUT_SECONDS = 60;
    private const MAX_AUTH_IDLE_TIMEOUT_SECONDS = 86400;
    private const MIN_AUTH_ABSOLUTE_TIMEOUT_SECONDS = 600;
    private const MAX_AUTH_ABSOLUTE_TIMEOUT_SECONDS = 604800;
    private const SECURITY_EVENTS = [
        'first_admin_created',
        'superadmin_login_succeeded',
        'tournament_admin_login_succeeded',
        'superadmin_logout',
        'tournament_admin_logout',
        'tournament_deleted',
        'tournament_admin_password_changed',
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $services;

    private ?LoginAttemptModel $loginAttemptModel = null;

    private ?bool $loginAttemptStorageAvailable = null;

    private ?string $dummyPasswordHash = null;

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
        $viewFile = __DIR__ . '/../views/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new \RuntimeException(sprintf('View "%s" not found.', $view));
        }

        $config = $this->services['config'] ?? [];
        $flash = $this->pullFlash();
        $currentSuperadmin = $this->currentSuperadmin();
        $currentTournamentAdmin = $this->currentTournamentAdmin();
        $csrfToken = $this->csrfToken();
        $csrfField = fn (): string => $this->csrfField();
        $languages = Language::available();
        $currentLanguage = Language::resolve();
        $translator = new Translator($currentLanguage);
        $t = fn (string $key, array $params = []): string => $translator->translate($key, $params);
        $e = fn (string $key, array $params = []): string => htmlspecialchars($translator->translate($key, $params), ENT_QUOTES, 'UTF-8');
        $url = fn (string $path = '/'): string => $this->url($path);
        $absoluteUrl = fn (string $path = '/'): string => $this->canonicalOrigin() . $this->url($path);
        extract($data, EXTR_SKIP);

        require __DIR__ . '/../views/layout.php';
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

    protected function canonicalOrigin(): string
    {
        $config = $this->services['config'] ?? [];
        $appConfig = is_array($config) && is_array($config['app'] ?? null)
            ? $config['app']
            : [];
        $configuredOrigin = $this->validatedConfiguredOrigin($appConfig['url'] ?? null);
        if ($configuredOrigin !== null) {
            return $configuredOrigin;
        }

        $httpsState = $_SERVER['HTTPS'] ?? '';
        $scheme = is_string($httpsState)
            && in_array(strtolower(trim($httpsState)), ['1', 'on'], true)
            ? 'https'
            : 'http';
        $requestHost = $_SERVER['HTTP_HOST'] ?? '';
        $host = is_string($requestHost)
            ? $this->validatedHostWithOptionalPort($requestHost)
            : null;

        return $scheme . '://' . ($host ?? 'localhost');
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

    /**
     * @param mixed $value
     */
    private function validatedConfiguredOrigin($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > 2048) {
            return null;
        }

        $parts = parse_url($value);
        if (
            !is_array($parts)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || (array_key_exists('path', $parts) && $parts['path'] !== '/')
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $this->validatedHost((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        if (
            !in_array($scheme, ['http', 'https'], true)
            || $host === null
            || ($port !== null && (!is_int($port) || $port < 1 || $port > 65535))
        ) {
            return null;
        }

        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
    }

    private function validatedHostWithOptionalPort(string $value): ?string
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > 300
            || preg_match('/[\x00-\x20\x7f\/\\\\?#@]/', $value) === 1
        ) {
            return null;
        }

        if (str_starts_with($value, '[')) {
            if (preg_match('/^(\[[0-9a-f:.]+\])(?::([0-9]{1,5}))?$/iD', $value, $matches) !== 1) {
                return null;
            }

            $rawHost = (string) ($matches[1] ?? '');
            $rawPort = $matches[2] ?? null;
        } else {
            if (preg_match('/^([^:]+)(?::([0-9]{1,5}))?$/D', $value, $matches) !== 1) {
                return null;
            }

            $rawHost = (string) ($matches[1] ?? '');
            $rawPort = $matches[2] ?? null;
        }

        $host = $this->validatedHost($rawHost);
        if ($host === null) {
            return null;
        }

        if ($rawPort === null || $rawPort === '') {
            return $host;
        }

        $port = (int) $rawPort;
        return $port >= 1 && $port <= 65535 ? $host . ':' . $port : null;
    }

    private function validatedHost(string $value): ?string
    {
        $value = strtolower($value);
        if (preg_match('/^\[([0-9a-f:.]+)\]$/D', $value, $matches) === 1) {
            $address = (string) ($matches[1] ?? '');
            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? '[' . $address . ']'
                : null;
        }

        if ($value === '' || strlen($value) > 253 || str_contains($value, ':')) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $value;
        }

        $labels = explode('.', $value);
        foreach ($labels as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1
            ) {
                return null;
            }
        }

        return $value;
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

    protected function requestPostRawString(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? $value : '';
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

    protected function rotateCsrfToken(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
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

    protected function reserveLoginAttempt(string $scope, int $maxAttempts = 5, int $lockSeconds = 300): bool
    {
        $maxAttempts = max(2, min(100, $maxAttempts));
        $lockSeconds = max(30, min(86400, $lockSeconds));
        $clientHash = $this->loginClientHash();
        $model = $this->persistentLoginAttemptModel();
        if ($model instanceof LoginAttemptModel) {
            try {
                return $model->reserve(
                    $scope,
                    $clientHash,
                    $maxAttempts,
                    $lockSeconds,
                    self::LOGIN_ATTEMPT_WINDOW_SECONDS
                );
            } catch (PDOException $exception) {
                if (!$this->isMissingLoginAttemptTable($exception)) {
                    throw $exception;
                }

                $this->loginAttemptStorageAvailable = false;
                $this->loginAttemptModel = null;
            }
        }

        $fallbackKey = $this->loginThrottleFallbackKey($scope, $clientHash);
        $state = $this->sessionLoginThrottleState($fallbackKey);
        $now = time();
        $attempts = (int) ($state['attempts'] ?? 0);
        $lockedUntil = (int) ($state['locked_until'] ?? 0);
        $updatedAt = (int) ($state['updated_at'] ?? 0);
        if ($lockedUntil > $now) {
            return false;
        }

        if (
            ($lockedUntil > 0 && $lockedUntil <= $now)
            || ($updatedAt > 0 && ($now - $updatedAt) >= self::LOGIN_ATTEMPT_WINDOW_SECONDS)
        ) {
            $attempts = 0;
        }

        if ($attempts >= $maxAttempts) {
            $_SESSION['_login_throttle'][$fallbackKey] = [
                'attempts' => $attempts,
                'locked_until' => $now + $lockSeconds,
                'updated_at' => $now,
            ];
            return false;
        }

        $attempts++;

        $updated = [
            'attempts' => $attempts,
            'locked_until' => 0,
            'updated_at' => $now,
        ];
        if ($attempts >= $maxAttempts) {
            $updated['locked_until'] = $now + $lockSeconds;
        }

        $_SESSION['_login_throttle'][$fallbackKey] = $updated;
        return true;
    }

    protected function resetLoginThrottle(string $scope): void
    {
        $clientHash = $this->loginClientHash();
        $model = $this->persistentLoginAttemptModel();
        if ($model instanceof LoginAttemptModel) {
            try {
                $model->reset($scope, $clientHash);
            } catch (PDOException $exception) {
                if (!$this->isMissingLoginAttemptTable($exception)) {
                    throw $exception;
                }

                $this->loginAttemptStorageAvailable = false;
                $this->loginAttemptModel = null;
            }
        }

        $this->resetSessionLoginThrottle($this->loginThrottleFallbackKey($scope, $clientHash));
    }

    /**
     * @param array<string, int> $numericIds
     */
    protected function logSecurityEvent(string $event, array $numericIds = []): void
    {
        if (!in_array($event, self::SECURITY_EVENTS, true)) {
            throw new \InvalidArgumentException('Unsupported security event.');
        }

        $payload = [
            'security_event' => $event,
            'occurred_at' => gmdate(\DateTimeInterface::ATOM),
        ];
        foreach ($numericIds as $key => $value) {
            if (
                !is_string($key)
                || preg_match('/^[a-z][a-z0-9_]*_id$/D', $key) !== 1
                || !is_int($value)
                || $value <= 0
            ) {
                throw new \InvalidArgumentException('Security event context must contain positive numeric IDs only.');
            }

            $payload[$key] = $value;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            error_log($encoded);
        }
    }

    protected function passwordInputIsValid(string $password): bool
    {
        $length = strlen($password);
        return $length > 0 && $length <= 72;
    }

    protected function removeManagedPublicLogo(string $logoPath): bool
    {
        $logoPath = trim($logoPath);
        if ($logoPath === '') {
            return true;
        }

        if (
            preg_match(
                '#^uploads/tournament_logos/logo_[0-9]+_[a-f0-9]{16}\.(?:png|jpe?g|webp)$#D',
                $logoPath
            ) !== 1
        ) {
            return true;
        }

        $fullPath = dirname(__DIR__, 2) . '/public/' . $logoPath;
        if (!is_file($fullPath) && !is_link($fullPath)) {
            return true;
        }

        return @unlink($fullPath);
    }

    protected function verifyPasswordOrDummy(string $password, ?string $storedHash): bool
    {
        $inputIsValid = $this->passwordInputIsValid($password);
        $dummyHash = $this->runtimeDummyPasswordHash();
        $storedHashInfo = is_string($storedHash) && $storedHash !== ''
            ? password_get_info($storedHash)
            : [];
        $hasStoredHash = ($storedHashInfo['algoName'] ?? 'unknown') !== 'unknown';
        $candidate = $inputIsValid ? $password : 'invalid-password-input';
        $verificationHash = $hasStoredHash ? (string) $storedHash : $dummyHash;
        $verified = password_verify($candidate, $verificationHash);

        return $inputIsValid && $hasStoredHash && $verified;
    }

    private function runtimeDummyPasswordHash(): string
    {
        if (is_string($this->dummyPasswordHash) && $this->dummyPasswordHash !== '') {
            return $this->dummyPasswordHash;
        }

        $hash = password_hash('bracketbird-runtime-dummy-password', PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Could not initialize password verification.');
        }

        $this->dummyPasswordHash = $hash;
        return $hash;
    }

    /**
     * @param array<string, mixed> $identity
     */
    protected function establishAuthentication(
        string $role,
        array $identity,
        ?string $credentialFingerprint = null
    ): void {
        if (!in_array($role, ['superadmin', 'tournament_admin'], true)) {
            throw new \InvalidArgumentException('Unsupported authentication role.');
        }

        if (
            $role === 'tournament_admin'
            && (!is_string($credentialFingerprint) || preg_match('/^[a-f0-9]{64}$/', $credentialFingerprint) !== 1)
        ) {
            throw new \InvalidArgumentException('Tournament credential fingerprint is required.');
        }

        $this->clearAuthenticationState();
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Could not rotate the authenticated session.');
        }

        if ($role === 'tournament_admin') {
            $identity['credential_fingerprint'] = $credentialFingerprint;
            $_SESSION['_auth_credential_fingerprints'][$role] = $credentialFingerprint;
        }

        $now = time();
        $_SESSION[$role] = $identity;
        $_SESSION['_auth_meta'][$role] = [
            'authenticated_at' => $now,
            'last_activity_at' => $now,
        ];
        $this->rotateCsrfToken();
    }

    protected function endAuthentication(): void
    {
        $this->clearAuthenticationState();
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Could not rotate the signed-out session.');
        }

        $this->rotateCsrfToken();
    }

    private function resetSessionLoginThrottle(string $fallbackKey): void
    {
        if (!isset($_SESSION['_login_throttle']) || !is_array($_SESSION['_login_throttle'])) {
            return;
        }

        unset($_SESSION['_login_throttle'][$fallbackKey]);
    }

    /**
     * @return array{attempts:int,locked_until:int,updated_at:int}
     */
    private function sessionLoginThrottleState(string $fallbackKey): array
    {
        $store = $_SESSION['_login_throttle'] ?? null;
        if (!is_array($store)) {
            return ['attempts' => 0, 'locked_until' => 0, 'updated_at' => 0];
        }

        $raw = $store[$fallbackKey] ?? null;
        if (!is_array($raw)) {
            return ['attempts' => 0, 'locked_until' => 0, 'updated_at' => 0];
        }

        return [
            'attempts' => (int) ($raw['attempts'] ?? 0),
            'locked_until' => (int) ($raw['locked_until'] ?? 0),
            'updated_at' => (int) ($raw['updated_at'] ?? 0),
        ];
    }

    private function persistentLoginAttemptModel(): ?LoginAttemptModel
    {
        if ($this->loginAttemptStorageAvailable === false) {
            return null;
        }

        if ($this->loginAttemptModel instanceof LoginAttemptModel) {
            return $this->loginAttemptModel;
        }

        $model = new LoginAttemptModel($this->db());
        if (!$model->tableExists()) {
            $this->loginAttemptStorageAvailable = false;
            return null;
        }

        $this->loginAttemptStorageAvailable = true;
        $this->loginAttemptModel = $model;

        return $model;
    }

    private function isMissingLoginAttemptTable(PDOException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = is_array($exception->errorInfo ?? null)
            ? (int) ($exception->errorInfo[1] ?? 0)
            : 0;

        return $sqlState === '42S02' || $driverCode === 1146;
    }

    private function loginClientHash(): string
    {
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($remoteAddress) || trim($remoteAddress) === '') {
            $remoteAddress = 'unknown';
        }

        return hash('sha256', $remoteAddress);
    }

    private function loginThrottleFallbackKey(string $scope, string $clientHash): string
    {
        return hash('sha256', $scope . "\0" . $clientHash);
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
            $this->clearAuthenticationRole('superadmin');
            return null;
        }

        if (!$this->authSessionIsCurrent('superadmin')) {
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
     * @return array{id: int, slug: string, name: string, credential_fingerprint: string}|null
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
        $credentialFingerprint = $sessionData['credential_fingerprint'] ?? null;
        if (!is_string($credentialFingerprint) || preg_match('/^[a-f0-9]{64}$/', $credentialFingerprint) !== 1) {
            $fingerprints = $_SESSION['_auth_credential_fingerprints'] ?? null;
            $credentialFingerprint = is_array($fingerprints)
                ? ($fingerprints['tournament_admin'] ?? null)
                : null;
        }

        if (
            !is_int($id)
            || $id <= 0
            || !is_string($slug)
            || $slug === ''
            || !is_string($name)
            || $name === ''
            || !is_string($credentialFingerprint)
            || preg_match('/^[a-f0-9]{64}$/', $credentialFingerprint) !== 1
        ) {
            $this->clearAuthenticationRole('tournament_admin');
            return null;
        }

        if (!$this->authSessionIsCurrent('tournament_admin')) {
            return null;
        }

        return [
            'id' => $id,
            'slug' => $slug,
            'name' => $name,
            'credential_fingerprint' => $credentialFingerprint,
        ];
    }

    private function authSessionIsCurrent(string $role): bool
    {
        $metaStore = $_SESSION['_auth_meta'] ?? null;
        $meta = is_array($metaStore) ? ($metaStore[$role] ?? null) : null;
        if (!is_array($meta)) {
            $this->clearAuthenticationRole($role);
            return false;
        }

        $authenticatedAt = $meta['authenticated_at'] ?? null;
        $lastActivityAt = $meta['last_activity_at'] ?? null;
        if (!is_int($authenticatedAt) || $authenticatedAt <= 0 || !is_int($lastActivityAt) || $lastActivityAt <= 0) {
            $this->clearAuthenticationRole($role);
            return false;
        }

        $now = time();
        if ($authenticatedAt > $now + 60 || $lastActivityAt > $now + 60) {
            $this->clearAuthenticationRole($role);
            return false;
        }

        $timeouts = $this->authTimeouts();
        if (
            ($now - $authenticatedAt) >= $timeouts['absolute']
            || ($now - $lastActivityAt) >= $timeouts['idle']
        ) {
            $this->clearAuthenticationRole($role);
            return false;
        }

        $_SESSION['_auth_meta'][$role]['last_activity_at'] = $now;
        return true;
    }

    /**
     * @return array{idle: int, absolute: int}
     */
    private function authTimeouts(): array
    {
        $config = $this->services['config'] ?? [];
        $appConfig = is_array($config) && is_array($config['app'] ?? null)
            ? $config['app']
            : [];

        $idle = $this->clampedPositiveInt(
            $appConfig['auth_idle_timeout_seconds'] ?? null,
            self::DEFAULT_AUTH_IDLE_TIMEOUT_SECONDS,
            self::MIN_AUTH_IDLE_TIMEOUT_SECONDS,
            self::MAX_AUTH_IDLE_TIMEOUT_SECONDS
        );
        $absolute = $this->clampedPositiveInt(
            $appConfig['auth_absolute_timeout_seconds'] ?? null,
            self::DEFAULT_AUTH_ABSOLUTE_TIMEOUT_SECONDS,
            self::MIN_AUTH_ABSOLUTE_TIMEOUT_SECONDS,
            self::MAX_AUTH_ABSOLUTE_TIMEOUT_SECONDS
        );

        return [
            'idle' => min($idle, $absolute),
            'absolute' => $absolute,
        ];
    }

    /**
     * @param mixed $value
     */
    private function clampedPositiveInt($value, int $default, int $minimum, int $maximum): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;
        } else {
            $parsed = $default;
        }

        return max($minimum, min($maximum, $parsed));
    }

    private function clearAuthenticationState(): void
    {
        unset(
            $_SESSION['superadmin'],
            $_SESSION['tournament_admin'],
            $_SESSION['_auth_meta'],
            $_SESSION['_auth_credential_fingerprints']
        );
    }

    private function clearAuthenticationRole(string $role): void
    {
        unset($_SESSION[$role]);
        if (isset($_SESSION['_auth_meta']) && is_array($_SESSION['_auth_meta'])) {
            unset($_SESSION['_auth_meta'][$role]);
        }
        if (isset($_SESSION['_auth_credential_fingerprints']) && is_array($_SESSION['_auth_credential_fingerprints'])) {
            unset($_SESSION['_auth_credential_fingerprints'][$role]);
        }
        $this->rotateCsrfToken();
    }
}
