<?php

declare(strict_types=1);

/**
 * Dependency-free integration and security checks.
 *
 * Required environment:
 *   APP_ENV=test
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *
 * DB_NAME must contain a standalone "test" or "audit" segment and must not
 * contain a production/live segment. The runner drops every table in that
 * database before applying migrations, so never point it at retained data.
 */

final class TestFailure extends RuntimeException
{
}

final class TestSuite
{
    private int $assertions = 0;

    public function same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new TestFailure(sprintf(
                '%s (expected %s, got %s)',
                $label,
                $this->export($expected),
                $this->export($actual)
            ));
        }

        $this->pass($label);
    }

    public function true(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new TestFailure($label);
        }

        $this->pass($label);
    }

    public function contains(string $needle, string $haystack, string $label): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new TestFailure(sprintf('%s (missing %s)', $label, $this->export($needle)));
        }

        $this->pass($label);
    }

    public function notContains(string $needle, string $haystack, string $label): void
    {
        if (str_contains($haystack, $needle)) {
            throw new TestFailure(sprintf('%s (unexpected %s)', $label, $this->export($needle)));
        }

        $this->pass($label);
    }

    public function count(): int
    {
        return $this->assertions;
    }

    private function pass(string $label): void
    {
        $this->assertions++;
        fwrite(STDOUT, sprintf("ok %d - %s\n", $this->assertions, $label));
    }

    private function export(mixed $value): string
    {
        $exported = var_export($value, true);
        if (strlen($exported) > 300) {
            return substr($exported, 0, 297) . '...';
        }

        return $exported;
    }
}

final class HttpResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body
    ) {
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];
        if ($values === []) {
            return null;
        }

        return $values[count($values) - 1];
    }
}

final class HttpClient
{
    /**
     * @var array<string, string>
     */
    private array $cookies = [];

    public function __construct(
        private string $host,
        private int $port
    ) {
    }

    public function get(string $path): HttpResponse
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function post(string $path, array $data): HttpResponse
    {
        return $this->request('POST', $path, $data);
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * @param array<string, scalar|null> $data
     */
    private function request(string $method, string $path, array $data = []): HttpResponse
    {
        if (!str_starts_with($path, '/') || preg_match('/[\r\n]/', $path) === 1) {
            throw new InvalidArgumentException('HTTP test paths must be absolute and contain no newlines.');
        }

        $body = $method === 'POST'
            ? http_build_query($data, '', '&', PHP_QUERY_RFC3986)
            : '';
        $headers = [
            sprintf('%s %s HTTP/1.1', $method, $path),
            sprintf('Host: %s:%d', $this->host, $this->port),
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: en',
            'Connection: close',
        ];

        if ($this->cookies !== []) {
            $cookiePairs = [];
            foreach ($this->cookies as $name => $value) {
                $cookiePairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $cookiePairs);
        }

        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        $request = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $errorNumber = 0;
        $errorMessage = '';
        $stream = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errorNumber,
            $errorMessage,
            5
        );
        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf(
                'Could not connect to test server (%d: %s).',
                $errorNumber,
                $errorMessage
            ));
        }

        stream_set_timeout($stream, 10);
        $offset = 0;
        $length = strlen($request);
        while ($offset < $length) {
            $written = fwrite($stream, substr($request, $offset));
            if ($written === false || $written === 0) {
                fclose($stream);
                throw new RuntimeException('Could not write the complete HTTP test request.');
            }
            $offset += $written;
        }

        $rawResponse = stream_get_contents($stream);
        $metadata = stream_get_meta_data($stream);
        fclose($stream);
        if ($rawResponse === false || ($metadata['timed_out'] ?? false) === true) {
            throw new RuntimeException('Timed out while reading the HTTP test response.');
        }

        $response = $this->parseResponse($rawResponse);
        $this->storeCookies($response);
        return $response;
    }

    private function parseResponse(string $rawResponse): HttpResponse
    {
        $separator = strpos($rawResponse, "\r\n\r\n");
        if ($separator === false) {
            throw new RuntimeException('Test server returned a malformed HTTP response.');
        }

        $rawHeaders = substr($rawResponse, 0, $separator);
        $body = substr($rawResponse, $separator + 4);
        $headerLines = explode("\r\n", $rawHeaders);
        $statusLine = array_shift($headerLines);
        if (!is_string($statusLine) || preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $statusLine, $matches) !== 1) {
            throw new RuntimeException('Test server returned a malformed HTTP status line.');
        }

        $headers = [];
        foreach ($headerLines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            if ($name === '') {
                continue;
            }
            $headers[$name] ??= [];
            $headers[$name][] = $value;
        }

        $transferEncoding = strtolower(implode(',', $headers['transfer-encoding'] ?? []));
        if (str_contains($transferEncoding, 'chunked')) {
            $body = $this->decodeChunkedBody($body);
        }

        return new HttpResponse((int) $matches[1], $headers, $body);
    }

    private function decodeChunkedBody(string $body): string
    {
        $decoded = '';
        $offset = 0;
        $bodyLength = strlen($body);

        while ($offset < $bodyLength) {
            $lineEnd = strpos($body, "\r\n", $offset);
            if ($lineEnd === false) {
                throw new RuntimeException('Malformed chunked HTTP response.');
            }

            $sizeLine = trim(substr($body, $offset, $lineEnd - $offset));
            $sizeToken = explode(';', $sizeLine, 2)[0];
            if ($sizeToken === '' || preg_match('/^[0-9a-f]+$/i', $sizeToken) !== 1) {
                throw new RuntimeException('Malformed HTTP chunk size.');
            }

            $chunkSize = hexdec($sizeToken);
            $offset = $lineEnd + 2;
            if ($chunkSize === 0) {
                break;
            }
            if ($offset + $chunkSize > $bodyLength) {
                throw new RuntimeException('Truncated chunked HTTP response.');
            }

            $decoded .= substr($body, $offset, $chunkSize);
            $offset += $chunkSize + 2;
        }

        return $decoded;
    }

    private function storeCookies(HttpResponse $response): void
    {
        foreach ($response->headers['set-cookie'] ?? [] as $setCookie) {
            $pair = explode(';', $setCookie, 2)[0];
            $equals = strpos($pair, '=');
            if ($equals === false) {
                continue;
            }

            $name = trim(substr($pair, 0, $equals));
            $value = trim(substr($pair, $equals + 1));
            if ($name === '' || preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
                continue;
            }

            if ($value === '' || stripos($setCookie, 'Max-Age=0') !== false) {
                unset($this->cookies[$name]);
                continue;
            }

            $this->cookies[$name] = $value;
        }
    }
}

final class PhpTestServer
{
    /**
     * @var resource|null
     */
    private $process;

    /**
     * @param resource $process
     */
    private function __construct(
        $process,
        private string $host,
        private int $port,
        private string $stdoutPath,
        private string $stderrPath
    ) {
        $this->process = $process;
    }

    public static function start(string $projectRoot, string $tempRoot, string $setupToken): self
    {
        $host = '127.0.0.1';
        $port = self::freePort($host);
        $instanceName = 'server-' . $port . '-' . bin2hex(random_bytes(4));
        $sessionDirectory = $tempRoot . DIRECTORY_SEPARATOR . $instanceName . '-sessions';
        if (!mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
            throw new RuntimeException('Could not create the isolated PHP session directory.');
        }

        $stdoutPath = $tempRoot . DIRECTORY_SEPARATOR . $instanceName . '-stdout.log';
        $stderrPath = $tempRoot . DIRECTORY_SEPARATOR . $instanceName . '-stderr.log';
        $publicRoot = $projectRoot . DIRECTORY_SEPARATOR . 'public';
        $router = $publicRoot . DIRECTORY_SEPARATOR . 'index.php';
        $command = [
            PHP_BINARY,
            '-d',
            'session.save_path=' . $sessionDirectory,
            '-d',
            'display_errors=0',
            '-S',
            $host . ':' . $port,
            '-t',
            $publicRoot,
            $router,
        ];

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['APP_ENV'] = 'test';
        $environment['APP_URL'] = sprintf('http://%s:%d', $host, $port);
        $environment['APP_BASE_PATH'] = '';
        $environment['APP_TRUST_PROXY'] = '0';
        $environment['APP_TIMEZONE'] = 'UTC';
        $environment['APP_SETUP_TOKEN'] = $setupToken;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'ab'],
            2 => ['file', $stderrPath, 'ab'],
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $projectRoot,
            $environment,
            ['bypass_shell' => true, 'suppress_errors' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the PHP built-in test server.');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $server = new self($process, $host, $port, $stdoutPath, $stderrPath);
        try {
            $server->waitUntilReady();
        } catch (Throwable $throwable) {
            $logs = $server->logs();
            $server->stop();
            throw new RuntimeException(
                $throwable->getMessage() . ($logs === '' ? '' : "\nServer log:\n" . $logs),
                0,
                $throwable
            );
        }

        return $server;
    }

    public function client(): HttpClient
    {
        return new HttpClient($this->host, $this->port);
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        $status = proc_get_status($this->process);
        if (($status['running'] ?? false) === true) {
            proc_terminate($this->process);
        }
        proc_close($this->process);
        $this->process = null;
    }

    public function logs(): string
    {
        $parts = [];
        foreach ([$this->stdoutPath, $this->stderrPath] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if (is_string($contents) && trim($contents) !== '') {
                $parts[] = trim($contents);
            }
        }

        $logs = implode("\n", $parts);
        if (strlen($logs) > 8000) {
            return substr($logs, -8000);
        }

        return $logs;
    }

    private function waitUntilReady(): void
    {
        $lastError = null;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $status = proc_get_status($this->process);
            if (($status['running'] ?? false) !== true) {
                throw new RuntimeException('PHP built-in test server exited during startup.');
            }

            try {
                $this->client()->get('/');
                return;
            } catch (Throwable $throwable) {
                $lastError = $throwable;
                usleep(100000);
            }
        }

        throw new RuntimeException(
            'PHP built-in test server did not become ready.'
            . ($lastError instanceof Throwable ? ' ' . $lastError->getMessage() : '')
        );
    }

    private static function freePort(string $host): int
    {
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_server(
            'tcp://' . $host . ':0',
            $errorNumber,
            $errorMessage
        );
        if (!is_resource($socket)) {
            throw new RuntimeException(sprintf(
                'Could not reserve a local test port (%d: %s).',
                $errorNumber,
                $errorMessage
            ));
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($address) || preg_match('/:(\d+)$/', $address, $matches) !== 1) {
            throw new RuntimeException('Could not determine the reserved local test port.');
        }

        return (int) $matches[1];
    }
}

/**
 * @return array{host:string,port:int,name:string,user:string,pass:string,charset:string}
 */
function validatedDatabaseEnvironment(): array
{
    if (getenv('APP_ENV') !== 'test') {
        throw new RuntimeException('Prerequisite: set APP_ENV exactly to "test".');
    }

    $required = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];
    $values = [];
    foreach ($required as $name) {
        $value = getenv($name);
        if ($value === false) {
            throw new RuntimeException('Prerequisite: explicitly set ' . implode(', ', $required) . '.');
        }
        $values[$name] = $value;
    }

    $databaseName = trim($values['DB_NAME']);
    if (
        $databaseName === ''
        || preg_match('/(?:^|[_-])(?:test|audit)(?:[_-]|$)/i', $databaseName) !== 1
        || preg_match('/(?:^|[_-])(?:prod|production|live)(?:[_-]|$)/i', $databaseName) === 1
    ) {
        throw new RuntimeException(
            'Safety gate: DB_NAME needs a standalone "test" or "audit" segment and no production/live segment.'
        );
    }

    $portValue = trim($values['DB_PORT']);
    if (!ctype_digit($portValue) || (int) $portValue < 1 || (int) $portValue > 65535) {
        throw new RuntimeException('Prerequisite: DB_PORT must be an integer from 1 to 65535.');
    }
    if (trim($values['DB_HOST']) === '' || trim($values['DB_USER']) === '') {
        throw new RuntimeException('Prerequisite: DB_HOST and DB_USER must be non-empty.');
    }

    $charset = getenv('DB_CHARSET');
    $charset = is_string($charset) && $charset !== '' ? $charset : 'utf8mb4';
    if (preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
        throw new RuntimeException('Prerequisite: DB_CHARSET contains unsupported characters.');
    }

    return [
        'host' => trim($values['DB_HOST']),
        'port' => (int) $portValue,
        'name' => $databaseName,
        'user' => trim($values['DB_USER']),
        'pass' => $values['DB_PASS'],
        'charset' => $charset,
    ];
}

/**
 * This is the only destructive database helper. Its caller must first pass
 * validatedDatabaseEnvironment() and verify SELECT DATABASE() exactly.
 */
function resetDisposableDatabase(PDO $pdo): void
{
    $statement = $pdo->query(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    );
    $tables = $statement->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($tables)) {
        throw new RuntimeException('Could not enumerate disposable database tables.');
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($tables as $table) {
            if (!is_string($table) || $table === '') {
                continue;
            }
            $pdo->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

/**
 * @param array<string, scalar|null> $parameters
 */
function dbExecute(PDO $pdo, string $sql, array $parameters = []): PDOStatement
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}

/**
 * @param array<string, scalar|null> $parameters
 */
function dbScalar(PDO $pdo, string $sql, array $parameters = []): mixed
{
    return dbExecute($pdo, $sql, $parameters)->fetchColumn();
}

/**
 * @param array<string, scalar|null> $parameters
 * @return array<string, mixed>|null
 */
function dbRow(PDO $pdo, string $sql, array $parameters = []): ?array
{
    $row = dbExecute($pdo, $sql, $parameters)->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * @param array<string, scalar|null> $parameters
 * @return list<array<string, mixed>>
 */
function dbRows(PDO $pdo, string $sql, array $parameters = []): array
{
    $rows = dbExecute($pdo, $sql, $parameters)->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? array_values($rows) : [];
}

function csrfToken(HttpResponse $response): string
{
    if (
        preg_match(
            '/<meta\s+name=["\']csrf-token["\']\s+content=["\']([^"\']+)["\']/i',
            $response->body,
            $matches
        ) !== 1
    ) {
        throw new TestFailure('Could not extract the CSRF token from an HTML response.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function assertRedirect(TestSuite $tests, HttpResponse $response, string $location, string $label): void
{
    $tests->true(
        $response->status === 302 && $response->header('location') === $location,
        $label
    );
}

/**
 * @return array<string, string|int>
 */
function tournamentPayload(string $name, string $password): array
{
    return [
        'name' => $name,
        'event_date' => '2030-06-01',
        'start_time' => '09:00',
        'location' => 'Prague',
        'admin_password' => $password,
        'number_of_groups' => '1',
        'number_of_courts' => '1',
        'match_duration_minutes' => '20',
        'advancing_teams_count' => '2',
        'group_stage_mode' => 'fixed_2_sets',
        'knockout_mode' => 'best_of_3',
    ];
}

/**
 * @return array{
 *     match:array<string, int|string|null>,
 *     sets:list<array{set_number:int,score_a:int,score_b:int}>
 * }
 */
function matchState(PDO $pdo, int $matchId): array
{
    $row = dbRow(
        $pdo,
        'SELECT id, tournament_id, status, winner_team_id, sets_summary_a, sets_summary_b, lock_version
         FROM matches
         WHERE id = :id',
        ['id' => $matchId]
    );
    if ($row === null) {
        throw new TestFailure('Expected match fixture was not found.');
    }

    $sets = [];
    foreach (
        dbRows(
            $pdo,
            'SELECT set_number, score_a, score_b
             FROM match_sets
             WHERE match_id = :match_id
             ORDER BY set_number',
            ['match_id' => $matchId]
        ) as $set
    ) {
        $sets[] = [
            'set_number' => (int) $set['set_number'],
            'score_a' => (int) $set['score_a'],
            'score_b' => (int) $set['score_b'],
        ];
    }

    return [
        'match' => [
            'id' => (int) $row['id'],
            'tournament_id' => (int) $row['tournament_id'],
            'status' => (string) $row['status'],
            'winner_team_id' => $row['winner_team_id'] === null ? null : (int) $row['winner_team_id'],
            'sets_summary_a' => (int) $row['sets_summary_a'],
            'sets_summary_b' => (int) $row['sets_summary_b'],
            'lock_version' => (int) $row['lock_version'],
        ],
        'sets' => $sets,
    ];
}

function makeTempRoot(): string
{
    $path = rtrim(sys_get_temp_dir(), '/\\')
        . DIRECTORY_SEPARATOR
        . 'bracketbird-tests-'
        . getmypid()
        . '-'
        . bin2hex(random_bytes(6));
    if (!mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create the integration-test temporary directory.');
    }

    return $path;
}

function removeTempRoot(?string $path): void
{
    if ($path === null || !is_dir($path)) {
        return;
    }

    $resolvedPath = realpath($path);
    $resolvedTemp = realpath(sys_get_temp_dir());
    if (
        !is_string($resolvedPath)
        || !is_string($resolvedTemp)
        || !str_starts_with(
            strtolower($resolvedPath),
            strtolower(rtrim($resolvedTemp, '/\\') . DIRECTORY_SEPARATOR . 'bracketbird-tests-')
        )
    ) {
        throw new RuntimeException('Refusing to clean an unexpected temporary path.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolvedPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($resolvedPath);
}

$tests = new TestSuite();
$projectRoot = dirname(__DIR__);
$tempRoot = null;
$activeServer = null;
$managedLogoFixtureAbsolutePath = null;
$managedLogoFixtureDirectory = null;
$managedLogoFixtureDirectoryCreated = false;
$exitCode = 0;

try {
    $database = validatedDatabaseEnvironment();
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('Prerequisite: enable the pdo_mysql PHP extension.');
    }
    if (!function_exists('mb_strlen')) {
        throw new RuntimeException('Prerequisite: enable mbstring for Unicode boundary checks.');
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Prerequisite: proc_open must be available to start the PHP test server.');
    }

    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $database['host'],
            $database['port'],
            $database['name'],
            $database['charset']
        ),
        $database['user'],
        $database['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $selectedDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!hash_equals($database['name'], $selectedDatabase)) {
        throw new RuntimeException(sprintf(
            'Safety gate: connected database %s does not exactly match DB_NAME %s.',
            $selectedDatabase,
            $database['name']
        ));
    }
    $tests->true(true, 'destructive database safety gate accepts only the explicit disposable database');

    resetDisposableDatabase($pdo);
    $services = require $projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    $migrationModel = new App\Models\MigrationModel($services['db']);
    $migrationsDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'migrations';
    $migrationFiles = glob($migrationsDirectory . DIRECTORY_SEPARATOR . '*.php');
    if (!is_array($migrationFiles) || $migrationFiles === []) {
        throw new RuntimeException('No application migrations were found.');
    }

    $firstMigrationCount = $migrationModel->migrate($migrationsDirectory);
    $tests->same(
        count($migrationFiles),
        $firstMigrationCount,
        'all migrations apply to a clean disposable database'
    );
    $tests->same(
        count($migrationFiles),
        (int) dbScalar($pdo, 'SELECT COUNT(*) FROM schema_migrations'),
        'every migration version is recorded'
    );
    $tests->same(
        0,
        (int) dbScalar($pdo, "SELECT COUNT(*) FROM schema_migration_steps WHERE status <> 'complete'"),
        'every migration step completes cleanly'
    );
    $tests->true(
        $migrationModel->allMigrationsAreComplete($migrationsDirectory),
        'setup migration readiness accepts the complete current migration set'
    );

    $expectedMigrationStepCount = (int) dbScalar(
        $pdo,
        'SELECT COUNT(*) FROM schema_migration_steps'
    );
    $pdo->exec('DROP TABLE schema_migration_steps');
    $tests->same(
        0,
        $migrationModel->migrate($migrationsDirectory),
        'legacy version-only migration state is upgraded without replaying DDL'
    );
    $tests->same(
        $expectedMigrationStepCount,
        (int) dbScalar($pdo, 'SELECT COUNT(*) FROM schema_migration_steps'),
        'legacy migration upgrade backfills every current statement hash'
    );
    $tests->true(
        $migrationModel->allMigrationsAreComplete($migrationsDirectory),
        'backfilled legacy migration state passes exact readiness checks'
    );

    $migrationIntegrityStep = dbRow(
        $pdo,
        'SELECT version, step_number, statement_hash
         FROM schema_migration_steps
         ORDER BY version DESC, step_number DESC
         LIMIT 1'
    );
    if (!is_array($migrationIntegrityStep)) {
        throw new RuntimeException('No migration step was available for integrity checks.');
    }
    $migrationIntegrityParameters = [
        'version' => (string) $migrationIntegrityStep['version'],
        'step_number' => (int) $migrationIntegrityStep['step_number'],
    ];

    dbExecute(
        $pdo,
        'UPDATE schema_migration_steps
         SET statement_hash = :statement_hash
         WHERE version = :version AND step_number = :step_number',
        $migrationIntegrityParameters + ['statement_hash' => str_repeat('0', 64)]
    );
    $tests->true(
        !$migrationModel->allMigrationsAreComplete($migrationsDirectory),
        'setup migration readiness rejects a changed statement hash'
    );
    dbExecute(
        $pdo,
        'UPDATE schema_migration_steps
         SET statement_hash = :statement_hash
         WHERE version = :version AND step_number = :step_number',
        $migrationIntegrityParameters + ['statement_hash' => (string) $migrationIntegrityStep['statement_hash']]
    );

    dbExecute(
        $pdo,
        "UPDATE schema_migration_steps
         SET status = 'failed'
         WHERE version = :version AND step_number = :step_number",
        $migrationIntegrityParameters
    );
    $tests->true(
        !$migrationModel->allMigrationsAreComplete($migrationsDirectory),
        'setup migration readiness rejects a non-complete step'
    );
    dbExecute(
        $pdo,
        "UPDATE schema_migration_steps
         SET status = 'complete'
         WHERE version = :version AND step_number = :step_number",
        $migrationIntegrityParameters
    );

    dbExecute(
        $pdo,
        "INSERT INTO schema_migration_steps
            (version, step_number, statement_hash, status, error_message, started_at, completed_at)
         VALUES (:version, 60000, :statement_hash, 'complete', NULL, NOW(), NOW())",
        [
            'version' => (string) $migrationIntegrityStep['version'],
            'statement_hash' => str_repeat('a', 64),
        ]
    );
    $tests->true(
        !$migrationModel->allMigrationsAreComplete($migrationsDirectory),
        'setup migration readiness rejects an unexpected step count'
    );
    dbExecute(
        $pdo,
        'DELETE FROM schema_migration_steps
         WHERE version = :version AND step_number = 60000',
        ['version' => (string) $migrationIntegrityStep['version']]
    );

    dbExecute(
        $pdo,
        "INSERT INTO schema_migrations (version, description, created_at)
         VALUES ('future_unknown', 'Unknown future migration fixture', NOW())"
    );
    $tests->true(
        !$migrationModel->allMigrationsAreComplete($migrationsDirectory),
        'setup migration readiness rejects an unknown recorded version'
    );
    $unknownMigrationWasRefused = false;
    try {
        $migrationModel->migrate($migrationsDirectory);
    } catch (Throwable) {
        $unknownMigrationWasRefused = true;
    }
    $tests->true(
        $unknownMigrationWasRefused,
        'migration runner refuses a database newer than the application release'
    );
    dbExecute(
        $pdo,
        "DELETE FROM schema_migrations WHERE version = 'future_unknown'"
    );

    $tests->same(
        0,
        $migrationModel->migrate($migrationsDirectory),
        'a second migration pass is idempotent'
    );

    dbExecute(
        $pdo,
        'DELETE FROM schema_migrations WHERE version = :version',
        ['version' => (string) $migrationIntegrityStep['version']]
    );
    dbExecute(
        $pdo,
        "UPDATE schema_migration_steps
         SET status = 'running', completed_at = NULL
         WHERE version = :version AND step_number = :step_number",
        $migrationIntegrityParameters
    );
    $interruptedMigrationWasRefused = false;
    try {
        $migrationModel->migrate($migrationsDirectory);
    } catch (Throwable) {
        $interruptedMigrationWasRefused = true;
    }
    $tests->true(
        $interruptedMigrationWasRefused,
        'migration runner refuses to replay an interrupted running step'
    );
    dbExecute(
        $pdo,
        "UPDATE schema_migration_steps
         SET status = 'complete', completed_at = NOW()
         WHERE version = :version AND step_number = :step_number",
        $migrationIntegrityParameters
    );
    $tests->same(
        1,
        $migrationModel->migrate($migrationsDirectory),
        'verified completed DDL can resume by recording its migration version'
    );
    $tests->true(
        $migrationModel->allMigrationsAreComplete($migrationsDirectory),
        'migration readiness is restored after explicit interrupted-step recovery'
    );

    foreach (
        [
            'superadmins',
            'tournaments',
            'tournament_groups',
            'teams',
            'matches',
            'match_sets',
            'login_attempts',
        ] as $requiredTable
    ) {
        $tests->same(
            1,
            (int) dbScalar(
                $pdo,
                'SELECT COUNT(*)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name',
                ['table_name' => $requiredTable]
            ),
            'migration creates table ' . $requiredTable
        );
    }
    $tests->same(
        1,
        (int) dbScalar(
            $pdo,
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name',
            ['table_name' => 'tournaments', 'column_name' => 'state_version']
        ),
        'migration adds the monotonic tournament state version'
    );

    $scheduler = new App\Support\GroupStageScheduler();
    $schedulerStart = new DateTimeImmutable('2030-06-01 09:30:00');
    $buildSchedulerFixture = static function (array $groupSizes): array {
        $fixture = [];
        foreach ($groupSizes as $groupId => $teamCount) {
            for ($teamNumber = 1; $teamNumber <= $teamCount; $teamNumber++) {
                $fixture[$groupId][] = [
                    'id' => ($groupId * 100) + $teamNumber,
                    'name' => sprintf('Group %d Team %02d', $groupId, $teamNumber),
                ];
            }
        }

        return $fixture;
    };

    $balancedFixture = $buildSchedulerFixture([1 => 6, 2 => 6]);
    $balancedSchedule = $scheduler->schedule($balancedFixture, 3, 20, $schedulerStart);
    $tests->same(30, count($balancedSchedule), 'fair scheduler preserves all two-group round-robin matches');
    $tests->same(
        $balancedSchedule,
        $scheduler->schedule($balancedFixture, 3, 20, $schedulerStart),
        'fair scheduler is deterministic for identical inputs'
    );
    $tests->same(
        [1, 2, 1, 2, 1, 2, 1, 2, 1, 2, 1, 2],
        array_map(
            static fn (array $match): int => (int) $match['group_id'],
            array_slice($balancedSchedule, 0, 12)
        ),
        'equal groups are interleaved instead of scheduled group by group'
    );
    $threeGroupOpening = $scheduler->schedule(
        $buildSchedulerFixture([1 => 6, 2 => 6, 3 => 6]),
        3,
        20,
        $schedulerStart
    );
    $tests->same(
        [1, 2, 3, 2, 3, 1, 3, 1, 2],
        array_map(
            static fn (array $match): int => (int) $match['group_id'],
            array_slice($threeGroupOpening, 0, 9)
        ),
        'three equal groups rotate across successive three-court time slots'
    );

    $expectedPairKeys = [];
    foreach ([1 => 6, 2 => 6] as $groupId => $teamCount) {
        for ($teamA = 1; $teamA <= $teamCount; $teamA++) {
            for ($teamB = $teamA + 1; $teamB <= $teamCount; $teamB++) {
                $expectedPairKeys[] = sprintf(
                    '%d:%d:%d',
                    $groupId,
                    ($groupId * 100) + $teamA,
                    ($groupId * 100) + $teamB
                );
            }
        }
    }
    $actualPairKeys = array_map(
        static fn (array $match): string => sprintf(
            '%d:%d:%d',
            (int) $match['group_id'],
            (int) $match['team_a_id'],
            (int) $match['team_b_id']
        ),
        $balancedSchedule
    );
    sort($expectedPairKeys);
    sort($actualPairKeys);
    $tests->same(
        $expectedPairKeys,
        $actualPairKeys,
        'circle scheduling preserves the identity and orientation of every pairing'
    );

    $firstRoundTeamsByGroup = [1 => [], 2 => []];
    $firstRoundMatchCountByGroup = [1 => 0, 2 => 0];
    foreach ($balancedSchedule as $match) {
        $groupId = (int) $match['group_id'];
        if ($firstRoundMatchCountByGroup[$groupId] >= 3) {
            continue;
        }

        $firstRoundTeamsByGroup[$groupId][(int) $match['team_a_id']] = true;
        $firstRoundTeamsByGroup[$groupId][(int) $match['team_b_id']] = true;
        $firstRoundMatchCountByGroup[$groupId]++;
    }
    $tests->true(
        count($firstRoundTeamsByGroup[1]) === 6 && count($firstRoundTeamsByGroup[2]) === 6,
        'each six-team circle round uses every team exactly once'
    );

    $schedulerScenarios = [
        'three groups and three courts' => [[1 => 6, 2 => 6, 3 => 6], 3],
        'three groups and two courts' => [[1 => 6, 2 => 6, 3 => 6], 2],
        'two groups and one court' => [[1 => 6, 2 => 6], 1],
        'unequal groups including an odd group' => [[1 => 4, 2 => 5, 3 => 6], 3],
    ];
    foreach ($schedulerScenarios as $scenarioName => [$groupSizes, $courtCount]) {
        $scenarioSchedule = $scheduler->schedule(
            $buildSchedulerFixture($groupSizes),
            $courtCount,
            20,
            $schedulerStart
        );
        $expectedMatchCount = 0;
        foreach ($groupSizes as $teamCount) {
            $expectedMatchCount += intdiv($teamCount * ($teamCount - 1), 2);
        }

        $pairKeys = [];
        $slotIndexes = [];
        $validSchedule = count($scenarioSchedule) === $expectedMatchCount;
        foreach ($scenarioSchedule as $index => $match) {
            $plannedStart = (string) $match['planned_start'];
            $teamAId = (int) $match['team_a_id'];
            $teamBId = (int) $match['team_b_id'];
            $pairKey = sprintf('%d:%d:%d', (int) $match['group_id'], $teamAId, $teamBId);
            if (isset($pairKeys[$pairKey])) {
                $validSchedule = false;
            }
            $pairKeys[$pairKey] = true;

            if (!isset($slotIndexes[$plannedStart])) {
                $slotIndexes[$plannedStart] = [];
            }
            $slotIndexes[$plannedStart][] = $index;
            if (
                (int) $match['schedule_order'] !== $index + 1
                || (int) $match['court_number'] !== count($slotIndexes[$plannedStart])
            ) {
                $validSchedule = false;
            }
        }

        $previousSlot = null;
        foreach ($slotIndexes as $plannedStart => $indexes) {
            if ($previousSlot !== null) {
                $expectedStart = (new DateTimeImmutable($previousSlot))
                    ->add(new DateInterval('PT20M'))
                    ->format('Y-m-d H:i:s');
                if ($plannedStart !== $expectedStart) {
                    $validSchedule = false;
                }
            }
            $previousSlot = $plannedStart;

            $teamsUsedInSlot = [];
            foreach ($indexes as $index) {
                $match = $scenarioSchedule[$index];
                foreach ([(int) $match['team_a_id'], (int) $match['team_b_id']] as $teamId) {
                    if (isset($teamsUsedInSlot[$teamId])) {
                        $validSchedule = false;
                    }
                    $teamsUsedInSlot[$teamId] = true;
                }
            }

            if (count($indexes) >= $courtCount) {
                continue;
            }
            $lastIndexInSlot = max($indexes);
            for ($laterIndex = $lastIndexInSlot + 1; $laterIndex < count($scenarioSchedule); $laterIndex++) {
                $laterMatch = $scenarioSchedule[$laterIndex];
                if (
                    !isset($teamsUsedInSlot[(int) $laterMatch['team_a_id']])
                    && !isset($teamsUsedInSlot[(int) $laterMatch['team_b_id']])
                ) {
                    $validSchedule = false;
                    break;
                }
            }
        }

        $tests->true(
            $validSchedule,
            'fair scheduler preserves pairings, slots, courts, and utilization for ' . $scenarioName
        );
    }

    $tournamentController = new App\Controllers\TournamentController($services);
    $tournamentControllerReflection = new ReflectionClass($tournamentController);

    $authTimeouts = new ReflectionMethod(App\Controllers\BaseController::class, 'authTimeouts');
    $authTimeouts->setAccessible(true);
    $shortTimeoutServices = $services;
    $shortTimeoutServices['config']['app']['auth_idle_timeout_seconds'] = '60';
    $shortTimeoutServices['config']['app']['auth_absolute_timeout_seconds'] = '600';
    $shortTimeoutController = new App\Controllers\TournamentController($shortTimeoutServices);
    $tests->same(
        ['idle' => 60, 'absolute' => 600],
        $authTimeouts->invoke($shortTimeoutController),
        'short operator-configured authentication lifetimes are honored'
    );

    $clampedTimeoutServices = $services;
    $clampedTimeoutServices['config']['app']['auth_idle_timeout_seconds'] = '1';
    $clampedTimeoutServices['config']['app']['auth_absolute_timeout_seconds'] = '9999999';
    $clampedTimeoutController = new App\Controllers\TournamentController($clampedTimeoutServices);
    $tests->same(
        ['idle' => 60, 'absolute' => 604800],
        $authTimeouts->invoke($clampedTimeoutController),
        'authentication lifetimes are clamped to documented bounds'
    );

    $buildBracket = $tournamentControllerReflection->getMethod('buildKnockoutBracketMatches');
    $buildBracket->setAccessible(true);
    $seededTeams = [];
    for ($seed = 1; $seed <= 8; $seed++) {
        $seededTeams[] = ['team_id' => $seed];
    }
    $standardBracket = $buildBracket->invoke($tournamentController, $seededTeams, 8);
    $tests->same(7, count($standardBracket), 'eight seeds produce seven knockout matches');
    $firstRoundPairs = array_map(
        static fn (array $match): array => [
            (int) ($match['team_a_id'] ?? 0),
            (int) ($match['team_b_id'] ?? 0),
        ],
        array_slice($standardBracket, 0, 4)
    );
    $tests->same(
        [[1, 8], [5, 4], [3, 6], [7, 2]],
        $firstRoundPairs,
        'standard bracket keeps the top two seeds in opposite halves'
    );
    $tests->same(
        ['winner:r1:m1', 'winner:r1:m2'],
        [
            (string) ($standardBracket[4]['team_a_source'] ?? ''),
            (string) ($standardBracket[4]['team_b_source'] ?? ''),
        ],
        'first semifinal consumes the first bracket half'
    );
    $tests->same(
        ['winner:r1:m3', 'winner:r1:m4'],
        [
            (string) ($standardBracket[5]['team_a_source'] ?? ''),
            (string) ($standardBracket[5]['team_b_source'] ?? ''),
        ],
        'second semifinal consumes the opposite bracket half'
    );

    $buildStandings = $tournamentControllerReflection->getMethod('buildGroupStandings');
    $buildStandings->setAccessible(true);
    $fixedDrawStandings = $buildStandings->invoke(
        $tournamentController,
        [['id' => 1, 'name' => 'A']],
        [
            ['id' => 101, 'group_id' => 1, 'team_name' => 'One'],
            ['id' => 202, 'group_id' => 1, 'team_name' => 'Two'],
        ],
        [[
            'id' => 501,
            'group_id' => 1,
            'team_a_id' => 101,
            'team_b_id' => 202,
            'winner_team_id' => 101,
            'sets_summary_a' => 1,
            'sets_summary_b' => 1,
        ]],
        [
            501 => [
                ['set_number' => 1, 'score_a' => 21, 'score_b' => 10],
                ['set_number' => 2, 'score_a' => 10, 'score_b' => 20],
            ],
        ],
        'fixed_2_sets'
    );
    $fixedDrawByTeam = [];
    foreach ($fixedDrawStandings[1] ?? [] as $row) {
        $fixedDrawByTeam[(int) ($row['team_id'] ?? 0)] = $row;
    }
    $tests->true(
        (int) ($fixedDrawByTeam[101]['draws'] ?? 0) === 1
        && (int) ($fixedDrawByTeam[202]['draws'] ?? 0) === 1
        && (int) ($fixedDrawByTeam[101]['tournament_points'] ?? 0) === 1
        && (int) ($fixedDrawByTeam[202]['tournament_points'] ?? 0) === 1,
        'fixed-two-set group split is scored as a draw despite its stored total-points winner'
    );

    $resolveTies = $tournamentControllerReflection->getMethod('resolveStandingsTieClusters');
    $resolveTies->setAccessible(true);
    $threeWayTie = $resolveTies->invoke(
        $tournamentController,
        [
            ['team_id' => 1, 'tournament_points' => 4, 'point_diff' => 1, 'points_for' => 20],
            ['team_id' => 2, 'tournament_points' => 4, 'point_diff' => 5, 'points_for' => 18],
            ['team_id' => 3, 'tournament_points' => 4, 'point_diff' => 3, 'points_for' => 25],
        ],
        ['1:2' => 1, '1:3' => -1, '2:3' => 1]
    );
    $tests->same(
        [2, 3, 1],
        array_map(static fn (array $row): int => (int) $row['team_id'], $threeWayTie),
        'three-way standings ties use a deterministic transitive metric order'
    );

    $validateScore = $tournamentControllerReflection->getMethod('validateScoreInput');
    $validateScore->setAccessible(true);
    $originalPost = $_POST;
    $_POST = [
        'set_1_a' => '21',
        'set_1_b' => '10',
        'set_2_a' => '10',
        'set_2_b' => '20',
    ];
    $fixedKnockoutScore = $validateScore->invoke(
        $tournamentController,
        'fixed_2_sets',
        101,
        202,
        true
    );
    $tests->true(
        is_array($fixedKnockoutScore)
        && (int) ($fixedKnockoutScore['sets_summary_a'] ?? 0) === 1
        && (int) ($fixedKnockoutScore['sets_summary_b'] ?? 0) === 1
        && (int) ($fixedKnockoutScore['winner_team_id'] ?? 0) === 101,
        'fixed-two-set knockout split advances the total-points winner'
    );
    $_POST = $originalPost;
    $_SESSION = [];

    $publicController = new App\Controllers\PublicViewController($services);
    $publicReflection = new ReflectionClass($publicController);
    $advancingSet = $publicReflection->getMethod('advancingTeamIdSet');
    $advancingSet->setAccessible(true);
    $advancingIds = $advancingSet->invoke(
        $publicController,
        [['id' => 1], ['id' => 2]],
        [
            1 => [
                ['team_id' => 11, 'tournament_points' => 6, 'point_diff' => 10, 'points_for' => 60],
                ['team_id' => 12, 'tournament_points' => 2, 'point_diff' => 1, 'points_for' => 30],
            ],
            2 => [
                ['team_id' => 21, 'tournament_points' => 5, 'point_diff' => 7, 'points_for' => 55],
                ['team_id' => 22, 'tournament_points' => 4, 'point_diff' => 5, 'points_for' => 50],
            ],
        ],
        3
    );
    $tests->same(
        [11, 21, 22],
        array_keys($advancingIds),
        'public standings highlight guaranteed qualifiers plus the actual wildcard'
    );

    $_SESSION = [
        'superadmin' => ['id' => 1, 'username' => 'expired'],
        '_auth_meta' => [
            'superadmin' => [
                'authenticated_at' => time() - 4000,
                'last_activity_at' => time() - 4000,
            ],
        ],
    ];
    $currentSuperadmin = $tournamentControllerReflection->getMethod('currentSuperadmin');
    $currentSuperadmin->setAccessible(true);
    $tests->same(
        null,
        $currentSuperadmin->invoke($tournamentController),
        'expired authentication metadata clears the superadmin identity'
    );
    $tests->true(
        !isset($_SESSION['superadmin'])
        && is_string($_SESSION['_csrf_token'] ?? null)
        && strlen((string) $_SESSION['_csrf_token']) === 64,
        'authentication expiry rotates CSRF state'
    );
    $_SESSION = [];

    $tempRoot = makeTempRoot();

    $parallelThrottleHelper = $tempRoot . DIRECTORY_SEPARATOR . 'parallel-throttle.php';
    $parallelThrottleSource = <<<'PHP'
<?php

declare(strict_types=1);

$projectRoot = (string) ($argv[1] ?? '');
$scope = (string) ($argv[2] ?? '');
$clientHash = (string) ($argv[3] ?? '');
$services = require $projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php';
$model = new App\Models\LoginAttemptModel($services['db']);
fwrite(STDOUT, $model->reserve($scope, $clientHash, 5, 300, 900) ? '1' : '0');
PHP;
    if (file_put_contents($parallelThrottleHelper, $parallelThrottleSource) === false) {
        throw new RuntimeException('Could not create the parallel throttle test helper.');
    }

    $parallelThrottleScope = 'integration-parallel-throttle';
    $parallelThrottleClientHash = hash('sha256', 'parallel-client');
    $parallelThrottleScopeHash = hash('sha256', $parallelThrottleScope);
    dbExecute(
        $pdo,
        'INSERT INTO login_attempts
            (scope_hash, client_hash, attempts, locked_until, updated_at)
         VALUES (:scope_hash, :client_hash, 0, NULL, NOW())',
        [
            'scope_hash' => $parallelThrottleScopeHash,
            'client_hash' => $parallelThrottleClientHash,
        ]
    );

    $parallelThrottleProcesses = [];
    for ($processIndex = 0; $processIndex < 8; $processIndex++) {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                $parallelThrottleHelper,
                $projectRoot,
                $parallelThrottleScope,
                $parallelThrottleClientHash,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $projectRoot,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a parallel throttle test process.');
        }
        fclose($pipes[0]);
        $parallelThrottleProcesses[] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    $parallelReservationsAllowed = 0;
    foreach ($parallelThrottleProcesses as $parallelProcess) {
        $stdout = stream_get_contents($parallelProcess['stdout']);
        $stderr = stream_get_contents($parallelProcess['stderr']);
        fclose($parallelProcess['stdout']);
        fclose($parallelProcess['stderr']);
        $processExitCode = proc_close($parallelProcess['process']);
        if ($processExitCode !== 0) {
            throw new RuntimeException(
                'Parallel throttle helper failed: ' . trim(is_string($stderr) ? $stderr : '')
            );
        }
        if (trim(is_string($stdout) ? $stdout : '') === '1') {
            $parallelReservationsAllowed++;
        }
    }
    $tests->same(
        5,
        $parallelReservationsAllowed,
        'parallel login reservations atomically enforce the shared attempt limit'
    );
    $parallelThrottleRow = dbRow(
        $pdo,
        'SELECT attempts, locked_until > NOW() AS is_locked
         FROM login_attempts
         WHERE scope_hash = :scope_hash AND client_hash = :client_hash',
        [
            'scope_hash' => $parallelThrottleScopeHash,
            'client_hash' => $parallelThrottleClientHash,
        ]
    );
    $tests->true(
        is_array($parallelThrottleRow)
        && (int) ($parallelThrottleRow['attempts'] ?? 0) === 5
        && (int) ($parallelThrottleRow['is_locked'] ?? 0) === 1,
        'parallel throttle state records exactly five reservations and one active lock'
    );
    dbExecute(
        $pdo,
        'DELETE FROM login_attempts WHERE scope_hash = :scope_hash AND client_hash = :client_hash',
        [
            'scope_hash' => $parallelThrottleScopeHash,
            'client_hash' => $parallelThrottleClientHash,
        ]
    );

    $failClosedServer = PhpTestServer::start($projectRoot, $tempRoot, '');
    try {
        $failClosedResponse = $failClosedServer->client()->get('/setup');
        $tests->same(404, $failClosedResponse->status, 'setup fails closed when no strong setup token is configured');
        $tests->same(0, (int) dbScalar($pdo, 'SELECT COUNT(*) FROM superadmins'), 'fail-closed setup creates no account');
    } finally {
        $failClosedServer->stop();
    }

    $setupToken = 'integration-setup-' . bin2hex(random_bytes(24));
    $activeServer = PhpTestServer::start($projectRoot, $tempRoot, $setupToken);

    $superadminUsername = 'audit_admin';
    $superadminPassword = 'SuperAdminPass!123';
    $superadmin = $activeServer->client();

    dbExecute(
        $pdo,
        "UPDATE schema_migration_steps
         SET status = 'failed'
         WHERE version = :version AND step_number = :step_number",
        $migrationIntegrityParameters
    );
    $migrationUnavailable = $superadmin->get('/setup');
    $tests->same(200, $migrationUnavailable->status, 'incomplete migrations render setup as unavailable');
    $tests->contains(
        'Database migrations are incomplete or could not be verified.',
        $migrationUnavailable->body,
        'setup does not expose its form while migration integrity is incomplete'
    );
    $migrationUnavailablePost = $superadmin->post('/setup', [
        '_csrf_token' => csrfToken($migrationUnavailable),
        'setup_token' => $setupToken,
        'username' => $superadminUsername,
        'password' => $superadminPassword,
    ]);
    $tests->same(200, $migrationUnavailablePost->status, 'setup submission fails closed while migrations are incomplete');
    $tests->same(
        0,
        (int) dbScalar($pdo, 'SELECT COUNT(*) FROM superadmins'),
        'incomplete migrations prevent first-admin creation'
    );
    dbExecute(
        $pdo,
        "UPDATE schema_migration_steps
         SET status = 'complete'
         WHERE version = :version AND step_number = :step_number",
        $migrationIntegrityParameters
    );

    $setupForm = $superadmin->get('/setup');
    $tests->same(200, $setupForm->status, 'valid setup token exposes first-run setup');
    $setupCsrf = csrfToken($setupForm);

    $missingCsrf = $superadmin->post('/setup', [
        'setup_token' => $setupToken,
        'username' => $superadminUsername,
        'password' => $superadminPassword,
    ]);
    $tests->same(403, $missingCsrf->status, 'POST without CSRF token is rejected');
    $tests->same(0, (int) dbScalar($pdo, 'SELECT COUNT(*) FROM superadmins'), 'missing CSRF cannot mutate setup state');

    $wrongCsrf = $superadmin->post('/setup', [
        '_csrf_token' => str_repeat('0', 64),
        'setup_token' => $setupToken,
        'username' => $superadminUsername,
        'password' => $superadminPassword,
    ]);
    $tests->same(403, $wrongCsrf->status, 'POST with mismatched CSRF token is rejected');
    $tests->same(0, (int) dbScalar($pdo, 'SELECT COUNT(*) FROM superadmins'), 'mismatched CSRF cannot mutate setup state');

    $wrongSetupToken = $superadmin->post('/setup', [
        '_csrf_token' => $setupCsrf,
        'setup_token' => 'incorrect-setup-token',
        'username' => $superadminUsername,
        'password' => $superadminPassword,
    ]);
    assertRedirect($tests, $wrongSetupToken, '/setup', 'incorrect setup token returns to setup');
    $tests->same(0, (int) dbScalar($pdo, 'SELECT COUNT(*) FROM superadmins'), 'incorrect setup token creates no account');

    $setupCsrf = csrfToken($superadmin->get('/setup'));
    $validSetup = $superadmin->post('/setup', [
        '_csrf_token' => $setupCsrf,
        'setup_token' => $setupToken,
        'username' => $superadminUsername,
        'password' => $superadminPassword,
    ]);
    assertRedirect($tests, $validSetup, '/admin/login', 'valid first setup creates the login handoff');
    $tests->same(1, (int) dbScalar($pdo, 'SELECT COUNT(*) FROM superadmins'), 'valid first setup creates exactly one account');
    $storedAdmin = dbRow(
        $pdo,
        'SELECT username, password_hash FROM superadmins WHERE username = :username',
        ['username' => $superadminUsername]
    );
    $tests->true(
        is_array($storedAdmin)
        && (string) $storedAdmin['password_hash'] !== $superadminPassword
        && password_verify($superadminPassword, (string) $storedAdmin['password_hash']),
        'first setup stores a verifiable password hash, not plaintext'
    );
    $tests->same(404, $superadmin->get('/setup')->status, 'setup becomes unavailable after first account creation');

    $loginForm = $superadmin->get('/admin/login');
    $tests->same(200, $loginForm->status, 'superadmin login form is available');
    $loginCsrf = csrfToken($loginForm);
    $sqliLogin = $superadmin->post('/admin/login', [
        '_csrf_token' => $loginCsrf,
        'username' => "' OR 1=1 -- ",
        'password' => 'irrelevant-password',
    ]);
    assertRedirect($tests, $sqliLogin, '/admin/login', 'SQL-injection-shaped username cannot authenticate');
    assertRedirect(
        $tests,
        $superadmin->get('/admin/dashboard'),
        '/admin/login',
        'invalid superadmin login leaves protected dashboard unauthenticated'
    );

    $loginCsrf = csrfToken($superadmin->get('/admin/login'));
    $wrongPassword = $superadmin->post('/admin/login', [
        '_csrf_token' => $loginCsrf,
        'username' => $superadminUsername,
        'password' => 'WrongPassword!123',
    ]);
    assertRedirect($tests, $wrongPassword, '/admin/login', 'valid username with invalid password cannot authenticate');

    $loginForm = $superadmin->get('/admin/login');
    $preLoginSessionId = $superadmin->cookie('BRACKETBIRDSESSID');
    $validLogin = $superadmin->post('/admin/login', [
        '_csrf_token' => csrfToken($loginForm),
        'username' => $superadminUsername,
        'password' => $superadminPassword,
    ]);
    assertRedirect($tests, $validLogin, '/admin/dashboard', 'valid superadmin credentials authenticate');
    $postLoginSessionId = $superadmin->cookie('BRACKETBIRDSESSID');
    $tests->true(
        is_string($preLoginSessionId)
        && is_string($postLoginSessionId)
        && $preLoginSessionId !== $postLoginSessionId,
        'superadmin login rotates the session ID'
    );

    $dashboard = $superadmin->get('/admin/dashboard');
    $tests->same(200, $dashboard->status, 'authenticated superadmin can open dashboard');
    $dashboardCsrf = csrfToken($dashboard);
    $preLogoutSessionId = $superadmin->cookie('BRACKETBIRDSESSID');
    $logout = $superadmin->post('/admin/logout', ['_csrf_token' => $dashboardCsrf]);
    assertRedirect($tests, $logout, '/admin/login', 'superadmin logout returns to login');
    $postLogoutSessionId = $superadmin->cookie('BRACKETBIRDSESSID');
    $tests->true(
        is_string($preLogoutSessionId)
        && is_string($postLogoutSessionId)
        && $preLogoutSessionId !== $postLogoutSessionId,
        'superadmin logout rotates the session ID'
    );
    assertRedirect(
        $tests,
        $superadmin->get('/admin/dashboard'),
        '/admin/login',
        'superadmin logout clears access to protected routes'
    );

    $loginForm = $superadmin->get('/admin/login');
    $preReloginSessionId = $superadmin->cookie('BRACKETBIRDSESSID');
    $relogin = $superadmin->post('/admin/login', [
        '_csrf_token' => csrfToken($loginForm),
        'username' => $superadminUsername,
        'password' => $superadminPassword,
    ]);
    assertRedirect($tests, $relogin, '/admin/dashboard', 'superadmin can authenticate again after logout');
    $tests->true(
        is_string($preReloginSessionId)
        && $preReloginSessionId !== $superadmin->cookie('BRACKETBIRDSESSID'),
        're-authentication rotates the signed-out session ID'
    );
    $dashboard = $superadmin->get('/admin/dashboard');
    $dashboardCsrf = csrfToken($dashboard);

    $validBoundaryBase = tournamentPayload('Boundary Candidate', 'TournamentBoundary!1');
    $invalidTournamentCases = [
        'empty tournament name' => ['name' => '   '],
        '151-character tournament name' => ['name' => str_repeat('N', 151)],
        '151-character location' => ['location' => str_repeat('L', 151)],
        'seven-byte tournament password' => ['admin_password' => '1234567'],
        '73-byte tournament password' => ['admin_password' => str_repeat('P', 73)],
        'invalid calendar date' => ['event_date' => '2030-02-30'],
        '24:00 start time' => ['start_time' => '24:00'],
        'zero groups' => ['number_of_groups' => '0'],
        '33 groups' => ['number_of_groups' => '33'],
        'zero courts' => ['number_of_courts' => '0'],
        '100 courts' => ['number_of_courts' => '100'],
        'zero-minute match duration' => ['match_duration_minutes' => '0'],
        '241-minute match duration' => ['match_duration_minutes' => '241'],
        'one advancing team' => ['advancing_teams_count' => '1'],
        '65 advancing teams' => ['advancing_teams_count' => '65'],
        'invalid group-stage mode' => ['group_stage_mode' => 'fixed_2_sets OR 1=1'],
        'invalid knockout mode' => ['knockout_mode' => 'best_of_7'],
    ];
    foreach ($invalidTournamentCases as $label => $changes) {
        $payload = array_merge($validBoundaryBase, $changes, ['_csrf_token' => $dashboardCsrf]);
        $response = $superadmin->post('/admin/tournaments/create', $payload);
        assertRedirect($tests, $response, '/admin/dashboard', $label . ' is rejected');
        $tests->same(
            0,
            (int) dbScalar($pdo, 'SELECT COUNT(*) FROM tournaments'),
            $label . ' creates no tournament'
        );
    }

    $alphaPassword = 'TournamentAlpha!1';
    $betaPassword = 'TournamentBeta!2';
    $alphaCreate = $superadmin->post(
        '/admin/tournaments/create',
        array_merge(
            tournamentPayload('Audit Alpha', $alphaPassword),
            ['_csrf_token' => $dashboardCsrf]
        )
    );
    $tests->true(
        $alphaCreate->status === 302
        && is_string($alphaCreate->header('location'))
        && str_starts_with($alphaCreate->header('location'), '/admin/tournament?id='),
        'valid alpha tournament is created'
    );
    $betaCreate = $superadmin->post(
        '/admin/tournaments/create',
        array_merge(
            tournamentPayload('Audit Beta', $betaPassword),
            ['_csrf_token' => $dashboardCsrf]
        )
    );
    $tests->true(
        $betaCreate->status === 302
        && is_string($betaCreate->header('location'))
        && str_starts_with($betaCreate->header('location'), '/admin/tournament?id='),
        'valid beta tournament is created'
    );
    $tests->same(2, (int) dbScalar($pdo, 'SELECT COUNT(*) FROM tournaments'), 'two valid tournament fixtures exist');

    $alpha = dbRow($pdo, 'SELECT id, slug FROM tournaments WHERE name = :name', ['name' => 'Audit Alpha']);
    $beta = dbRow($pdo, 'SELECT id, slug FROM tournaments WHERE name = :name', ['name' => 'Audit Beta']);
    if ($alpha === null || $beta === null) {
        throw new TestFailure('Could not load tournament fixtures after creation.');
    }
    $alphaId = (int) $alpha['id'];
    $alphaSlug = (string) $alpha['slug'];
    $betaId = (int) $beta['id'];
    $betaSlug = (string) $beta['slug'];

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $freshLoginClient = $activeServer->client();
        $freshLoginForm = $freshLoginClient->get(
            '/tournament/' . rawurlencode($betaSlug) . '/login'
        );
        $freshLoginFailure = $freshLoginClient->post(
            '/tournament/' . rawurlencode($betaSlug) . '/login',
            [
                '_csrf_token' => csrfToken($freshLoginForm),
                'password' => 'WrongFreshCookiePassword!' . $attempt,
            ]
        );
        assertRedirect(
            $tests,
            $freshLoginFailure,
            '/tournament/' . $betaSlug . '/login',
            'fresh-cookie login failure ' . $attempt . ' remains generically denied'
        );
    }
    $throttleRow = dbRow(
        $pdo,
        'SELECT attempts, locked_until > NOW() AS is_locked
         FROM login_attempts
         WHERE scope_hash = :scope_hash
           AND client_hash = :client_hash',
        [
            'scope_hash' => hash('sha256', 'tournament_admin:id:' . $betaId),
            'client_hash' => hash('sha256', '127.0.0.1'),
        ]
    );
    $tests->true(
        is_array($throttleRow)
        && (int) ($throttleRow['attempts'] ?? 0) === 5
        && (int) ($throttleRow['is_locked'] ?? 0) === 1,
        'persistent throttle caps attempts across discarded session cookies'
    );

    $stateMatchModel = new App\Models\MatchModel($services['db']);
    $betaStateVersion = (int) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    );
    $tests->same(
        App\Models\MatchModel::WRITE_SAVED,
        $stateMatchModel->replaceGroupMatches($betaId, $betaStateVersion, []),
        'first generation replacement accepts the captured state version'
    );
    $tests->same(
        $betaStateVersion + 1,
        (int) dbScalar(
            $pdo,
            'SELECT state_version FROM tournaments WHERE id = :id',
            ['id' => $betaId]
        ),
        'successful generation advances tournament state exactly once'
    );
    $tests->same(
        App\Models\MatchModel::WRITE_STALE,
        $stateMatchModel->replaceGroupMatches($betaId, $betaStateVersion, []),
        'second generation using the same captured version is rejected as stale'
    );
    $tests->same(
        $betaStateVersion + 1,
        (int) dbScalar(
            $pdo,
            'SELECT state_version FROM tournaments WHERE id = :id',
            ['id' => $betaId]
        ),
        'stale generation does not advance tournament state'
    );

    $stateTeamModel = new App\Models\TeamModel($services['db']);
    $stateTeamId = $stateTeamModel->create($betaId, 'State Version Fixture', '');
    $betaStateAfterTeam = (int) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    );
    $tests->same(
        $betaStateVersion + 2,
        $betaStateAfterTeam,
        'team mutation advances the same tournament state version'
    );
    $tests->same(
        App\Models\MatchModel::WRITE_STALE,
        $stateMatchModel->replaceGroupMatches($betaId, $betaStateVersion + 1, []),
        'upstream team mutation prevents stale match generation'
    );
    $tests->same(
        0,
        (int) dbScalar(
            $pdo,
            "SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id AND stage = 'group'",
            ['tournament_id' => $betaId]
        ),
        'stale generation creates no derived match rows'
    );

    $forcedGenerationFailure = false;
    try {
        $stateMatchModel->replaceGroupMatches(
            $betaId,
            $betaStateAfterTeam,
            [[
                'group_id' => PHP_INT_MAX,
                'team_a_id' => PHP_INT_MAX - 1,
                'team_b_id' => PHP_INT_MAX - 2,
                'court_number' => 1,
                'schedule_order' => 1,
                'planned_start' => '2030-06-01 09:00:00',
            ]]
        );
    } catch (Throwable) {
        $forcedGenerationFailure = true;
    }
    $tests->true($forcedGenerationFailure, 'invalid generated rows fail transactionally');
    $tests->same(
        $betaStateAfterTeam,
        (int) dbScalar(
            $pdo,
            'SELECT state_version FROM tournaments WHERE id = :id',
            ['id' => $betaId]
        ),
        'failed generation rolls back its tournament state change'
    );

    $stateTournamentModel = new App\Models\TournamentModel($services['db']);
    $winningSettings = tournamentPayload('Audit Beta Winner', '');
    $winningSettings['slug'] = $betaSlug;
    $winningSettings['location'] = 'Brno';
    $tests->same(
        App\Models\TournamentModel::UPDATE_UPDATED,
        $stateTournamentModel->update(
            $betaId,
            $winningSettings,
            $betaStateAfterTeam
        ),
        'whole-form tournament update accepts its rendered state version'
    );
    $losingStaleSettings = tournamentPayload('Audit Beta', '');
    $losingStaleSettings['slug'] = $betaSlug;
    $losingStaleSettings['location'] = 'Ostrava';
    $tests->same(
        App\Models\TournamentModel::UPDATE_STALE,
        $stateTournamentModel->update(
            $betaId,
            $losingStaleSettings,
            $betaStateAfterTeam
        ),
        'stale whole-form tournament update is rejected'
    );
    $tests->same(
        ['Audit Beta Winner', 'Brno'],
        array_values(dbRow(
            $pdo,
            'SELECT name, location FROM tournaments WHERE id = :id',
            ['id' => $betaId]
        ) ?? []),
        'stale settings cannot restore older field values'
    );

    $betaVersionBeforeSecondTeam = (int) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    );
    $stateTeamTwoId = $stateTeamModel->create($betaId, 'State Version Fixture Two', '');
    $betaGroupId = (int) dbScalar(
        $pdo,
        'SELECT id
         FROM tournament_groups
         WHERE tournament_id = :tournament_id
         ORDER BY sort_order
         LIMIT 1',
        ['tournament_id' => $betaId]
    );
    $betaAssignments = [
        $stateTeamId => $betaGroupId,
        $stateTeamTwoId => $betaGroupId,
    ];
    $tests->same(
        App\Models\TeamModel::WRITE_STALE,
        $stateTeamModel->bulkUpdateGroupAssignments(
            $betaId,
            $betaAssignments,
            $betaVersionBeforeSecondTeam
        ),
        'stale automatic assignment cannot overwrite newer team state'
    );
    $tests->same(
        0,
        (int) dbScalar(
            $pdo,
            'SELECT COUNT(*)
             FROM teams
             WHERE tournament_id = :tournament_id AND group_id IS NOT NULL',
            ['tournament_id' => $betaId]
        ),
        'rejected stale assignment changes no team'
    );
    $betaAssignmentVersion = (int) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    );
    $tests->same(
        App\Models\TeamModel::WRITE_UPDATED,
        $stateTeamModel->bulkUpdateGroupAssignments(
            $betaId,
            $betaAssignments,
            $betaAssignmentVersion
        ),
        'current automatic assignment succeeds transactionally'
    );

    $betaGenerationVersion = (int) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    );
    $tests->same(
        App\Models\MatchModel::WRITE_SAVED,
        $stateMatchModel->replaceGroupMatches(
            $betaId,
            $betaGenerationVersion,
            [[
                'group_id' => $betaGroupId,
                'team_a_id' => $stateTeamId,
                'team_b_id' => $stateTeamTwoId,
                'court_number' => 1,
                'schedule_order' => 1,
                'planned_start' => '2030-06-01 09:00:00',
            ]]
        ),
        'structural-reset fixture is generated at the current tournament version'
    );
    $structuralSettingsVersion = (int) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    );
    $structuralSettings = tournamentPayload('Audit Beta Winner', '');
    $structuralSettings['slug'] = $betaSlug;
    $structuralSettings['location'] = 'Brno';
    $structuralSettings['number_of_courts'] = '2';
    $tests->same(
        App\Models\TournamentModel::UPDATE_REQUIRES_MATCH_RESET,
        $stateTournamentModel->update(
            $betaId,
            $structuralSettings,
            $structuralSettingsVersion,
            true,
            false
        ),
        'structural settings update requires explicit match-reset confirmation'
    );
    $tests->same(
        1,
        (int) dbScalar(
            $pdo,
            'SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id',
            ['tournament_id' => $betaId]
        ),
        'unconfirmed structural settings preserve generated matches'
    );

    $stateTeamModel->create($betaId, 'Concurrent Structural Fixture', '');
    $tests->same(
        App\Models\TournamentModel::UPDATE_STALE,
        $stateTournamentModel->update(
            $betaId,
            $structuralSettings,
            $structuralSettingsVersion,
            true,
            true
        ),
        'stale destructive confirmation is rejected after tournament state changes'
    );
    $tests->same(
        1,
        (int) dbScalar(
            $pdo,
            'SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id',
            ['tournament_id' => $betaId]
        ),
        'stale destructive confirmation cannot delete regenerated matches'
    );
    $currentStructuralVersion = (int) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    );
    $tests->same(
        App\Models\TournamentModel::UPDATE_UPDATED,
        $stateTournamentModel->update(
            $betaId,
            $structuralSettings,
            $currentStructuralVersion,
            true,
            true
        ),
        'current confirmed structural update succeeds'
    );
    $tests->same(
        0,
        (int) dbScalar(
            $pdo,
            'SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id',
            ['tournament_id' => $betaId]
        ),
        'confirmed structural update removes generated matches atomically'
    );

    $publicSettingsVersion = (int) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    );
    $publicSettingsResult = $stateTournamentModel->savePublicViewSettings(
        $betaId,
        $publicSettingsVersion,
        true,
        true,
        20,
        'light',
        'Beta Broadcast',
        'Public scope isolation fixture',
        null,
        'https://example.com',
        ''
    );
    $tests->same(
        App\Models\TournamentModel::UPDATE_UPDATED,
        (string) ($publicSettingsResult['status'] ?? ''),
        'Public View general settings save at the captured version'
    );
    $tests->same(
        App\Models\TournamentModel::UPDATE_STALE,
        $stateTournamentModel->savePublicScreens(
            $betaId,
            $publicSettingsVersion,
            ['overview' => ['is_enabled' => 1, 'sort_order' => 1]]
        ),
        'stale Public View screen list is rejected'
    );
    $tests->same(
        'Beta Broadcast',
        (string) dbScalar(
            $pdo,
            'SELECT public_title_override FROM tournaments WHERE id = :id',
            ['id' => $betaId]
        ),
        'screen-list submission cannot overwrite general Public View settings'
    );
    $publicScreensVersion = (int) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    );
    $tests->same(
        App\Models\TournamentModel::UPDATE_UPDATED,
        $stateTournamentModel->savePublicScreens(
            $betaId,
            $publicScreensVersion,
            ['overview' => ['is_enabled' => 1, 'sort_order' => 1]]
        ),
        'current Public View screen-list update succeeds independently'
    );
    $tests->same(
        'Beta Broadcast',
        (string) dbScalar(
            $pdo,
            'SELECT public_title_override FROM tournaments WHERE id = :id',
            ['id' => $betaId]
        ),
        'screen-list persistence remains scoped to screen rows'
    );

    $managedLogoOldPath = 'uploads/tournament_logos/logo_2000000000_aaaaaaaaaaaaaaaa.png';
    $managedLogoWinnerPath = 'uploads/tournament_logos/logo_2000000001_bbbbbbbbbbbbbbbb.png';
    $managedLogoLoserPath = 'uploads/tournament_logos/logo_2000000002_cccccccccccccccc.png';
    $logoSeedVersion = (int) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    );
    $logoSeedResult = $stateTournamentModel->savePublicViewSettings(
        $betaId,
        $logoSeedVersion,
        true,
        true,
        20,
        'light',
        'Beta Broadcast',
        'Public scope isolation fixture',
        $managedLogoOldPath,
        'https://example.com',
        ''
    );
    $tests->same(
        App\Models\TournamentModel::UPDATE_UPDATED,
        (string) ($logoSeedResult['status'] ?? ''),
        'managed-logo race fixture is stored'
    );
    $logoRaceVersion = (int) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    );
    $logoWinnerResult = $stateTournamentModel->savePublicViewSettings(
        $betaId,
        $logoRaceVersion,
        true,
        true,
        20,
        'light',
        'Beta Broadcast',
        'Public scope isolation fixture',
        $managedLogoWinnerPath,
        'https://example.com',
        ''
    );
    $logoLoserResult = $stateTournamentModel->savePublicViewSettings(
        $betaId,
        $logoRaceVersion,
        true,
        true,
        20,
        'light',
        'Beta Broadcast stale',
        'Stale logo contender',
        $managedLogoLoserPath,
        'https://example.org',
        ''
    );
    $tests->same(
        [
            App\Models\TournamentModel::UPDATE_UPDATED,
            $managedLogoOldPath,
        ],
        [
            (string) ($logoWinnerResult['status'] ?? ''),
            (string) ($logoWinnerResult['previous_logo_path'] ?? ''),
        ],
        'winning logo update returns the actual locked previous path'
    );
    $tests->same(
        App\Models\TournamentModel::UPDATE_STALE,
        (string) ($logoLoserResult['status'] ?? ''),
        'concurrent stale logo update is rejected'
    );
    $tests->same(
        [$managedLogoWinnerPath, 'Beta Broadcast'],
        array_values(dbRow(
            $pdo,
            'SELECT public_logo_path, public_title_override
             FROM tournaments
             WHERE id = :id',
            ['id' => $betaId]
        ) ?? []),
        'losing logo update cannot restore a deleted path or stale general settings'
    );

    assertRedirect(
        $tests,
        $superadmin->get('/admin/tournament?id=' . rawurlencode('1 OR 1=1')),
        '/admin/dashboard',
        'SQL-injection-shaped tournament query ID is rejected'
    );

    $deleteWithoutConfirmation = $superadmin->post('/admin/tournaments/delete', [
        '_csrf_token' => $dashboardCsrf,
        'tournament_id' => (string) $betaId,
        'state_version' => (string) dbScalar(
            $pdo,
            'SELECT state_version FROM tournaments WHERE id = :id',
            ['id' => $betaId]
        ),
    ]);
    assertRedirect(
        $tests,
        $deleteWithoutConfirmation,
        '/admin/dashboard',
        'tournament deletion requires explicit confirmation'
    );
    $tests->same(1, (int) dbScalar(
        $pdo,
        'SELECT COUNT(*) FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    ), 'unconfirmed tournament deletion preserves the row');

    $sqliDelete = $superadmin->post('/admin/tournaments/delete', [
        '_csrf_token' => $dashboardCsrf,
        'tournament_id' => $betaId . ' OR 1=1',
        'state_version' => (string) dbScalar(
            $pdo,
            'SELECT state_version FROM tournaments WHERE id = :id',
            ['id' => $betaId]
        ),
        'confirm_delete' => '1',
    ]);
    assertRedirect($tests, $sqliDelete, '/admin/dashboard', 'SQL-injection-shaped deletion ID is rejected');
    $tests->same(2, (int) dbScalar($pdo, 'SELECT COUNT(*) FROM tournaments'), 'SQL-shaped deletion ID deletes no tournament');

    $anonymous = $activeServer->client();
    assertRedirect(
        $tests,
        $anonymous->get('/admin/dashboard'),
        '/admin/login',
        'unauthenticated user cannot open superadmin dashboard'
    );
    assertRedirect(
        $tests,
        $anonymous->get('/tournament/' . rawurlencode($alphaSlug) . '/admin'),
        '/tournament/' . $alphaSlug . '/login',
        'unauthenticated user cannot open tournament administration'
    );

    $tournamentAdmin = $activeServer->client();
    $alphaLoginForm = $tournamentAdmin->get('/tournament/' . rawurlencode($alphaSlug) . '/login');
    $tests->same(200, $alphaLoginForm->status, 'tournament-admin login form is available');
    $invalidTournamentLogin = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/login',
        [
            '_csrf_token' => csrfToken($alphaLoginForm),
            'password' => 'WrongTournamentPassword!1',
        ]
    );
    assertRedirect(
        $tests,
        $invalidTournamentLogin,
        '/tournament/' . $alphaSlug . '/login',
        'invalid tournament-admin password cannot authenticate'
    );
    assertRedirect(
        $tests,
        $tournamentAdmin->get('/tournament/' . rawurlencode($alphaSlug) . '/admin'),
        '/tournament/' . $alphaSlug . '/login',
        'invalid tournament-admin login leaves the route protected'
    );

    $alphaLoginForm = $tournamentAdmin->get('/tournament/' . rawurlencode($alphaSlug) . '/login');
    $preTournamentLoginSessionId = $tournamentAdmin->cookie('BRACKETBIRDSESSID');
    $validTournamentLogin = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/login',
        [
            '_csrf_token' => csrfToken($alphaLoginForm),
            'password' => $alphaPassword,
        ]
    );
    assertRedirect(
        $tests,
        $validTournamentLogin,
        '/tournament/' . $alphaSlug . '/admin',
        'valid tournament-admin credentials authenticate'
    );
    $postTournamentLoginSessionId = $tournamentAdmin->cookie('BRACKETBIRDSESSID');
    $tests->true(
        is_string($preTournamentLoginSessionId)
        && is_string($postTournamentLoginSessionId)
        && $preTournamentLoginSessionId !== $postTournamentLoginSessionId,
        'tournament-admin login rotates the session ID'
    );

    $alphaTeamsPage = $tournamentAdmin->get(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/teams'
    );
    $tests->same(200, $alphaTeamsPage->status, 'authenticated tournament admin can open its tournament');
    $tournamentCsrf = csrfToken($alphaTeamsPage);

    assertRedirect(
        $tests,
        $tournamentAdmin->get('/tournament/' . rawurlencode($betaSlug) . '/admin'),
        '/tournament/' . $betaSlug . '/login',
        'tournament admin cannot cross into another tournament'
    );
    $betaTeamCount = (int) dbScalar(
        $pdo,
        'SELECT COUNT(*) FROM teams WHERE tournament_id = :tournament_id',
        ['tournament_id' => $betaId]
    );
    $crossTournamentCreate = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($betaSlug) . '/admin/teams/create',
        [
            '_csrf_token' => $tournamentCsrf,
            'tournament_id' => (string) $betaId,
            'return_section' => 'teams',
            'team_name' => 'Cross Tournament Intruder',
            'description' => '',
        ]
    );
    assertRedirect(
        $tests,
        $crossTournamentCreate,
        '/tournament/' . $betaSlug . '/login',
        'cross-tournament team mutation is denied'
    );
    $tests->same(
        $betaTeamCount,
        (int) dbScalar(
            $pdo,
            'SELECT COUNT(*) FROM teams WHERE tournament_id = :tournament_id',
            ['tournament_id' => $betaId]
        ),
        'denied cross-tournament mutation changes no rows'
    );

    assertRedirect(
        $tests,
        $tournamentAdmin->get('/admin/dashboard'),
        '/admin/login',
        'tournament admin cannot open superadmin-only dashboard'
    );
    $tournamentAdminDelete = $tournamentAdmin->post('/admin/tournaments/delete', [
        '_csrf_token' => $tournamentCsrf,
        'tournament_id' => (string) $betaId,
        'confirm_delete' => '1',
    ]);
    assertRedirect(
        $tests,
        $tournamentAdminDelete,
        '/admin/login',
        'tournament admin cannot call superadmin-only deletion'
    );
    $tests->same(1, (int) dbScalar(
        $pdo,
        'SELECT COUNT(*) FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    ), 'superadmin-only denial preserves the tournament');

    $unicode150 = str_repeat("\u{017D}", 150);
    $unicode151 = str_repeat("\u{017D}", 151);
    $tests->same(150, mb_strlen($unicode150, 'UTF-8'), 'Unicode fixture contains exactly 150 characters');
    $createUnicode150 = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/teams/create',
        [
            '_csrf_token' => $tournamentCsrf,
            'tournament_id' => (string) $alphaId,
            'return_section' => 'teams',
            'team_name' => $unicode150,
            'description' => 'Accepted Unicode boundary team',
        ]
    );
    assertRedirect(
        $tests,
        $createUnicode150,
        '/tournament/' . $alphaSlug . '/admin/teams',
        '150-character Unicode team name is accepted'
    );
    $tests->same(1, (int) dbScalar(
        $pdo,
        'SELECT COUNT(*) FROM teams WHERE tournament_id = :tournament_id AND team_name = :team_name',
        ['tournament_id' => $alphaId, 'team_name' => $unicode150]
    ), '150-character Unicode team name is stored intact');

    $teamCountBefore151 = (int) dbScalar(
        $pdo,
        'SELECT COUNT(*) FROM teams WHERE tournament_id = :tournament_id',
        ['tournament_id' => $alphaId]
    );
    $createUnicode151 = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/teams/create',
        [
            '_csrf_token' => $tournamentCsrf,
            'tournament_id' => (string) $alphaId,
            'return_section' => 'teams',
            'team_name' => $unicode151,
            'description' => 'Rejected Unicode boundary team',
        ]
    );
    assertRedirect(
        $tests,
        $createUnicode151,
        '/tournament/' . $alphaSlug . '/admin/teams',
        '151-character Unicode team name is rejected'
    );
    $tests->same(
        $teamCountBefore151,
        (int) dbScalar(
            $pdo,
            'SELECT COUNT(*) FROM teams WHERE tournament_id = :tournament_id',
            ['tournament_id' => $alphaId]
        ),
        '151-character Unicode team name creates no row'
    );

    $xssTeamName = '<script>alert("xss-team-90731")</script>';
    $xssDescription = '"><img src=x onerror=alert("xss-description-90732")>';
    $createXssTeam = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/teams/create',
        [
            '_csrf_token' => $tournamentCsrf,
            'tournament_id' => (string) $alphaId,
            'return_section' => 'teams',
            'team_name' => $xssTeamName,
            'description' => $xssDescription,
        ]
    );
    assertRedirect(
        $tests,
        $createXssTeam,
        '/tournament/' . $alphaSlug . '/admin/teams',
        'stored-XSS fixture is accepted as inert team data'
    );
    $storedXssTeam = dbRow(
        $pdo,
        'SELECT id, team_name, description
         FROM teams
         WHERE tournament_id = :tournament_id AND team_name = :team_name',
        ['tournament_id' => $alphaId, 'team_name' => $xssTeamName]
    );
    $tests->true(
        is_array($storedXssTeam)
        && $storedXssTeam['team_name'] === $xssTeamName
        && $storedXssTeam['description'] === $xssDescription,
        'stored-XSS fixture remains data in the database'
    );
    $renderedTeams = $tournamentAdmin->get(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/teams'
    );
    $tests->same(200, $renderedTeams->status, 'team management page renders stored fixtures');
    $tests->notContains($xssTeamName, $renderedTeams->body, 'stored team script tag is never rendered raw');
    $tests->notContains($xssDescription, $renderedTeams->body, 'stored team description payload is never rendered raw');
    $tests->contains(
        '&lt;script&gt;alert(&quot;xss-team-90731&quot;)&lt;/script&gt;',
        $renderedTeams->body,
        'stored team script payload is HTML-escaped'
    );
    $tests->contains(
        '&quot;&gt;&lt;img src=x onerror=alert(&quot;xss-description-90732&quot;)&gt;',
        $renderedTeams->body,
        'stored team description payload is HTML-escaped'
    );
    $tournamentCsrf = csrfToken($renderedTeams);

    $unicodeTeam = dbRow(
        $pdo,
        'SELECT id FROM teams WHERE tournament_id = :tournament_id AND team_name = :team_name',
        ['tournament_id' => $alphaId, 'team_name' => $unicode150]
    );
    if ($unicodeTeam === null || $storedXssTeam === null) {
        throw new TestFailure('Expected team fixtures were not found.');
    }
    $alphaGroupId = (int) dbScalar(
        $pdo,
        'SELECT id
         FROM tournament_groups
         WHERE tournament_id = :tournament_id
         ORDER BY sort_order
         LIMIT 1',
        ['tournament_id' => $alphaId]
    );
    dbExecute(
        $pdo,
        'UPDATE teams SET group_id = :group_id, updated_at = NOW() WHERE id = :id AND tournament_id = :tournament_id',
        ['group_id' => $alphaGroupId, 'id' => (int) $unicodeTeam['id'], 'tournament_id' => $alphaId]
    );
    dbExecute(
        $pdo,
        'UPDATE teams SET group_id = :group_id, updated_at = NOW() WHERE id = :id AND tournament_id = :tournament_id',
        ['group_id' => $alphaGroupId, 'id' => (int) $storedXssTeam['id'], 'tournament_id' => $alphaId]
    );
    $tests->same(2, (int) dbScalar(
        $pdo,
        'SELECT COUNT(*) FROM teams WHERE tournament_id = :tournament_id AND group_id = :group_id',
        ['tournament_id' => $alphaId, 'group_id' => $alphaGroupId]
    ), 'two teams are assigned to the match fixture group');

    $generateMatches = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/matches/generate',
        [
            '_csrf_token' => $tournamentCsrf,
            'tournament_id' => (string) $alphaId,
            'return_section' => 'matches',
            'confirm_unassigned' => '1',
        ]
    );
    assertRedirect(
        $tests,
        $generateMatches,
        '/tournament/' . $alphaSlug . '/admin/matches',
        'authorized tournament admin can generate group matches'
    );
    $tests->same(1, (int) dbScalar(
        $pdo,
        "SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id AND stage = 'group'",
        ['tournament_id' => $alphaId]
    ), 'two assigned teams generate exactly one group match');

    $match = dbRow(
        $pdo,
        "SELECT id, team_a_id, team_b_id, status, lock_version
         FROM matches
         WHERE tournament_id = :tournament_id AND stage = 'group'
         LIMIT 1",
        ['tournament_id' => $alphaId]
    );
    if ($match === null) {
        throw new TestFailure('Generated match fixture was not found.');
    }
    $matchId = (int) $match['id'];
    $tests->true(
        $match['status'] === 'scheduled' && (int) $match['lock_version'] === 0,
        'generated match starts scheduled at lock version zero'
    );

    $scorePayload = [
        'tournament_id' => (string) $alphaId,
        'lock_version' => '0',
        'set_1_a' => '21',
        'set_1_b' => '10',
        'set_2_a' => '21',
        'set_2_b' => '15',
        'return_to' => 'matches',
    ];
    $beforeUnauthorizedScore = matchState($pdo, $matchId);
    $anonymousLoginForm = $anonymous->get('/admin/login');
    $unauthorizedScore = $anonymous->post(
        '/admin/tournament/matches/' . $matchId . '/score',
        array_merge($scorePayload, ['_csrf_token' => csrfToken($anonymousLoginForm)])
    );
    assertRedirect(
        $tests,
        $unauthorizedScore,
        '/admin/login',
        'unauthenticated score mutation is denied after passing CSRF validation'
    );
    $tests->same(
        $beforeUnauthorizedScore,
        matchState($pdo, $matchId),
        'unauthorized score mutation changes neither match nor set rows'
    );

    $validScore = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/matches/' . $matchId . '/score',
        array_merge($scorePayload, ['_csrf_token' => $tournamentCsrf])
    );
    assertRedirect(
        $tests,
        $validScore,
        '/tournament/' . $alphaSlug . '/admin/matches',
        'authorized tournament admin can save a valid score'
    );
    $savedScoreState = matchState($pdo, $matchId);
    $tests->true(
        $savedScoreState['match']['status'] === 'finished'
        && $savedScoreState['match']['lock_version'] === 1
        && $savedScoreState['match']['sets_summary_a'] === 2
        && $savedScoreState['match']['sets_summary_b'] === 0
        && $savedScoreState['match']['winner_team_id'] === (int) $match['team_a_id'],
        'valid score finishes the match and increments its lock version once'
    );
    $tests->same(
        [
            ['set_number' => 1, 'score_a' => 21, 'score_b' => 10],
            ['set_number' => 2, 'score_a' => 21, 'score_b' => 15],
        ],
        $savedScoreState['sets'],
        'valid score stores exactly two ordered set rows'
    );

    $repeatedScore = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/matches/' . $matchId . '/score',
        array_merge($scorePayload, ['_csrf_token' => $tournamentCsrf])
    );
    assertRedirect(
        $tests,
        $repeatedScore,
        '/tournament/' . $alphaSlug . '/admin/matches',
        'identical score replay succeeds idempotently despite stale original version'
    );
    $tests->same(
        $savedScoreState,
        matchState($pdo, $matchId),
        'identical score replay does not increment version or rewrite set state'
    );

    $conflictingStaleScore = $scorePayload;
    $conflictingStaleScore['set_1_a'] = '20';
    $conflictingStaleScore['set_2_b'] = '14';
    $staleScoreResponse = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/matches/' . $matchId . '/score',
        array_merge($conflictingStaleScore, ['_csrf_token' => $tournamentCsrf])
    );
    assertRedirect(
        $tests,
        $staleScoreResponse,
        '/tournament/' . $alphaSlug . '/admin/matches/' . $matchId,
        'different score submitted with a stale version returns to match detail'
    );
    $tests->same(
        $savedScoreState,
        matchState($pdo, $matchId),
        'conflicting stale score changes neither match nor set state'
    );

    $generateKnockout = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/knockout/generate',
        [
            '_csrf_token' => $tournamentCsrf,
            'tournament_id' => (string) $alphaId,
        ]
    );
    assertRedirect(
        $tests,
        $generateKnockout,
        '/tournament/' . $alphaSlug . '/admin/knockout',
        'finished group stage can generate a knockout bracket'
    );
    $knockoutFixture = dbRow(
        $pdo,
        "SELECT id, court_number, planned_start
         FROM matches
         WHERE tournament_id = :tournament_id AND stage = 'knockout'
         LIMIT 1",
        ['tournament_id' => $alphaId]
    );
    $tests->true(
        is_array($knockoutFixture)
        && (int) ($knockoutFixture['court_number'] ?? 0) > 0
        && is_string($knockoutFixture['planned_start'] ?? null)
        && (string) $knockoutFixture['planned_start'] !== '',
        'generated knockout rounds receive court and planned-start assignments'
    );

    $changedGroupScore = [
        'tournament_id' => (string) $alphaId,
        'lock_version' => '1',
        'set_1_a' => '21',
        'set_1_b' => '11',
        'set_2_a' => '21',
        'set_2_b' => '16',
        'return_to' => 'matches',
    ];
    $stateBeforeResetConfirmation = matchState($pdo, $matchId);
    $groupEditWithoutConfirmation = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/matches/' . $matchId . '/score',
        array_merge($changedGroupScore, ['_csrf_token' => $tournamentCsrf])
    );
    assertRedirect(
        $tests,
        $groupEditWithoutConfirmation,
        '/tournament/' . $alphaSlug . '/admin/matches/' . $matchId,
        'group-result edit requires confirmation while knockout exists'
    );
    $tests->same(
        $stateBeforeResetConfirmation,
        matchState($pdo, $matchId),
        'unconfirmed group-result edit rolls back without changing score'
    );
    $tests->same(
        1,
        (int) dbScalar(
            $pdo,
            "SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id AND stage = 'knockout'",
            ['tournament_id' => $alphaId]
        ),
        'unconfirmed group-result edit preserves the knockout bracket'
    );

    $confirmedGroupEdit = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/admin/matches/' . $matchId . '/score',
        array_merge(
            $changedGroupScore,
            [
                '_csrf_token' => $tournamentCsrf,
                'confirm_reset_knockout' => '1',
            ]
        )
    );
    assertRedirect(
        $tests,
        $confirmedGroupEdit,
        '/tournament/' . $alphaSlug . '/admin/matches',
        'confirmed group-result edit succeeds'
    );
    $tests->same(
        0,
        (int) dbScalar(
            $pdo,
            "SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id AND stage = 'knockout'",
            ['tournament_id' => $alphaId]
        ),
        'confirmed group-result edit atomically removes the stale knockout bracket'
    );

    $preTournamentLogoutSessionId = $tournamentAdmin->cookie('BRACKETBIRDSESSID');
    $tournamentLogout = $tournamentAdmin->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/logout',
        ['_csrf_token' => $tournamentCsrf]
    );
    assertRedirect(
        $tests,
        $tournamentLogout,
        '/tournament/' . $alphaSlug . '/login',
        'tournament-admin logout returns to tournament login'
    );
    $postTournamentLogoutSessionId = $tournamentAdmin->cookie('BRACKETBIRDSESSID');
    $tests->true(
        is_string($preTournamentLogoutSessionId)
        && is_string($postTournamentLogoutSessionId)
        && $preTournamentLogoutSessionId !== $postTournamentLogoutSessionId,
        'tournament-admin logout rotates the session ID'
    );
    assertRedirect(
        $tests,
        $tournamentAdmin->get('/tournament/' . rawurlencode($alphaSlug) . '/admin'),
        '/tournament/' . $alphaSlug . '/login',
        'tournament-admin logout clears protected access'
    );

    $revocationClient = $activeServer->client();
    $revocationLoginForm = $revocationClient->get(
        '/tournament/' . rawurlencode($alphaSlug) . '/login'
    );
    $revocationLogin = $revocationClient->post(
        '/tournament/' . rawurlencode($alphaSlug) . '/login',
        [
            '_csrf_token' => csrfToken($revocationLoginForm),
            'password' => $alphaPassword,
        ]
    );
    assertRedirect(
        $tests,
        $revocationLogin,
        '/tournament/' . $alphaSlug . '/admin',
        'credential-revocation fixture authenticates with the current tournament password'
    );
    $newAlphaPassword = 'RotatedTournamentPass!456';
    $passwordRotationPayload = tournamentPayload('Audit Alpha', $newAlphaPassword);
    $passwordRotationPayload['_csrf_token'] = $dashboardCsrf;
    $passwordRotationPayload['tournament_id'] = (string) $alphaId;
    $passwordRotationPayload['state_version'] = (string) dbScalar(
        $pdo,
        'SELECT state_version FROM tournaments WHERE id = :id',
        ['id' => $alphaId]
    );
    $passwordRotationPayload['return_section'] = 'tournament';
    $passwordRotation = $superadmin->post(
        '/admin/tournament/update',
        $passwordRotationPayload
    );
    assertRedirect(
        $tests,
        $passwordRotation,
        '/admin/tournament?id=' . $alphaId,
        'superadmin can rotate the tournament-admin password'
    );
    assertRedirect(
        $tests,
        $revocationClient->get('/tournament/' . rawurlencode($alphaSlug) . '/admin'),
        '/tournament/' . $alphaSlug . '/login',
        'password rotation invalidates an already-authenticated tournament session'
    );

    $managedLogoFixtureDirectory = $projectRoot
        . DIRECTORY_SEPARATOR
        . 'public'
        . DIRECTORY_SEPARATOR
        . 'uploads'
        . DIRECTORY_SEPARATOR
        . 'tournament_logos';
    if (!is_dir($managedLogoFixtureDirectory)) {
        if (!mkdir($managedLogoFixtureDirectory, 0755, true) && !is_dir($managedLogoFixtureDirectory)) {
            throw new RuntimeException('Could not create the managed-logo deletion fixture directory.');
        }
        $managedLogoFixtureDirectoryCreated = true;
    }
    $managedLogoFixtureAbsolutePath = $projectRoot
        . DIRECTORY_SEPARATOR
        . 'public'
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $managedLogoWinnerPath);
    if (file_put_contents($managedLogoFixtureAbsolutePath, 'managed-logo-deletion-fixture') === false) {
        throw new RuntimeException('Could not create the managed-logo deletion fixture.');
    }

    $staleTournamentDelete = $superadmin->post('/admin/tournaments/delete', [
        '_csrf_token' => $dashboardCsrf,
        'tournament_id' => (string) $betaId,
        'state_version' => (string) $logoRaceVersion,
        'confirm_delete' => '1',
    ]);
    assertRedirect(
        $tests,
        $staleTournamentDelete,
        '/admin/dashboard',
        'stale tournament deletion confirmation is rejected'
    );
    $tests->true(
        (int) dbScalar(
            $pdo,
            'SELECT COUNT(*) FROM tournaments WHERE id = :id',
            ['id' => $betaId]
        ) === 1
        && is_file($managedLogoFixtureAbsolutePath),
        'stale tournament deletion preserves both row and managed logo'
    );

    $confirmedDelete = $superadmin->post('/admin/tournaments/delete', [
        '_csrf_token' => $dashboardCsrf,
        'tournament_id' => (string) $betaId,
        'state_version' => (string) dbScalar(
            $pdo,
            'SELECT state_version FROM tournaments WHERE id = :id',
            ['id' => $betaId]
        ),
        'confirm_delete' => '1',
    ]);
    assertRedirect(
        $tests,
        $confirmedDelete,
        '/admin/dashboard',
        'authenticated superadmin can confirm tournament deletion'
    );
    $tests->same(0, (int) dbScalar(
        $pdo,
        'SELECT COUNT(*) FROM tournaments WHERE id = :id',
        ['id' => $betaId]
    ), 'confirmed deletion removes the selected tournament');
    $tests->same(0, (int) dbScalar(
        $pdo,
        'SELECT COUNT(*) FROM tournament_groups WHERE tournament_id = :tournament_id',
        ['tournament_id' => $betaId]
    ), 'confirmed deletion cascades to tournament-owned groups');
    $tests->same(0, (int) dbScalar(
        $pdo,
        'SELECT COUNT(*) FROM teams WHERE tournament_id = :tournament_id',
        ['tournament_id' => $betaId]
    ), 'confirmed deletion cascades to tournament-owned teams');
    $tests->true(
        !is_file($managedLogoFixtureAbsolutePath),
        'confirmed tournament deletion removes its managed logo file'
    );
    $tests->same(1, (int) dbScalar(
        $pdo,
        'SELECT COUNT(*) FROM tournaments WHERE id = :id',
        ['id' => $alphaId]
    ), 'confirmed deletion does not affect another tournament');

    fwrite(
        STDOUT,
        sprintf(
            "1..%d\nPASS: integration and security suite completed against disposable database %s.\n",
            $tests->count(),
            $database['name']
        )
    );
} catch (Throwable $throwable) {
    $exitCode = 1;
    fwrite(STDERR, "not ok - " . $throwable->getMessage() . "\n");
    if ($activeServer instanceof PhpTestServer) {
        $logs = $activeServer->logs();
        if ($logs !== '') {
            fwrite(STDERR, "PHP test server log:\n" . $logs . "\n");
        }
    }
} finally {
    if ($activeServer instanceof PhpTestServer) {
        $activeServer->stop();
    }
    if (
        is_string($managedLogoFixtureAbsolutePath)
        && (is_file($managedLogoFixtureAbsolutePath) || is_link($managedLogoFixtureAbsolutePath))
    ) {
        unlink($managedLogoFixtureAbsolutePath);
    }
    if (
        $managedLogoFixtureDirectoryCreated
        && is_string($managedLogoFixtureDirectory)
        && is_dir($managedLogoFixtureDirectory)
    ) {
        $fixtureDirectoryEntries = scandir($managedLogoFixtureDirectory);
        if (is_array($fixtureDirectoryEntries) && count($fixtureDirectoryEntries) === 2) {
            rmdir($managedLogoFixtureDirectory);
        }
    }
    try {
        removeTempRoot($tempRoot);
    } catch (Throwable $cleanupFailure) {
        $exitCode = 1;
        fwrite(STDERR, "Temporary cleanup failed: " . $cleanupFailure->getMessage() . "\n");
    }
}

exit($exitCode);
